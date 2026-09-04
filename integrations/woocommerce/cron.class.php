<?php

namespace Smaily_Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\AutomationRouter;
use Smaily\Connect\Support\ContactLanguageResolver;
use Smaily_Connect\Includes\Logger;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Includes\Smaily_Client;
use WC_Product;
use WP_User;

class Cron {
	/**
	 * Service name.
	 * @var string
	 */
	const SERVICE = 'woocommerce_cron';

	/**
	 * @varInstance of Options.
	 */
	private $options;

	/**
	 * Logger
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Options $options Instance of Options.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
		$this->logger  = new Logger( self::SERVICE );
	}

	/**
	 * Register hooks for the cron.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Register the custom schedule early
		add_filter( 'cron_schedules', array( $this, 'smaily_cron_schedules' ) );
		// F3-53: smaily_connect_cron_sync_subscribers is deliberately NOT
		// registered. F3-48.3 stopped the AS tick from bridging to
		// smaily_sync_subscribers (its language source is cron-unsafe — the
		// F3-47 clobber), but the callback stayed registered and a legacy
		// WP-Cron event surviving/re-armed on a client site fired the
		// mass-send daily anyway. The new contact-sync path owns this; the
		// method stays for the upstream diff but nothing may invoke it.
		//
		// PRO-1195: the two abandoned-cart hooks
		// (smaily_connect_cron_abandoned_carts_status / _email) are
		// deliberately NOT registered either. The namespaced pipeline
		// (CartHookHandler → CartAbandonmentSweeper → CartFlusher) owns
		// abandoned cart; the AS smly_plus_abandoned_cart tick no longer
		// bridges here, and — the F3-53 lesson — a stray surviving legacy
		// WP-Cron event must not be able to fire the retired pass either
		// (it would double-remind against the new pipeline). The methods
		// stay for the upstream diff but nothing may invoke them.
	}

	/**
	 * Custom cron schedule for smaily Cron.
	 *
	 * DECIDED (2026-06-11, upstream-merge prep): the interval registration
	 * STAYS. The BETA fork migrated all smaily_connect_cron_* WP-Cron
	 * entries to Action Scheduler (sub-PR 5.D), so no Smaily-owned cron
	 * uses 'smaily_connect_15_minutes' anymore — but the schedule name is
	 * public API (any plugin/theme may have passed it to
	 * wp_schedule_event()), and silently removing it would leave such
	 * events unschedulable. Cost of keeping: one array entry per
	 * cron_schedules filter call. Revisit only with evidence (a grep on a
	 * real install showing no third-party usage) — absence of pain is not
	 * that evidence.
	 *
	 * @param array $schedules Schedules array.
	 * @return array $schedules Updated array.
	 */
	public function smaily_cron_schedules( $schedules ) {
		$schedules['smaily_connect_15_minutes'] = array(
			'interval' => 900,
			'display'  => esc_html__( 'In every 15 minutes', 'smaily-connect' ),
		);

		return $schedules;
	}

	/**
	 * Synchronizes contact information between Smaily and WooCommerce.
	 * Logs response from Smaily to smaily-cron file.
	 *
	 * @return void
	 */
	public function smaily_sync_subscribers() {
		if ( ! get_option( Options::SUBSCRIBER_SYNC_ENABLED_OPTION ) ) {
			return;
		}

		$request  = new Smaily_Client( $this->options );
		$response = $request->list_unsubscribers();
		if ( empty( $response ) ) {
			$this->logger->error( 'Failed to get unsubscribers - received an empty response' );
			return;
		}

		if ( isset( $response['error'] ) ) {
			$this->logger->error( sprintf( 'Receiving unsusbsribers failed with an error: %s', $response['error'] ) );
			return;
		}

		if ( isset( $response['code'] ) && $response['code'] !== 200 ) {
			$this->logger->error( sprintf( 'Unable to retrieve unsubscribed users: %s', wp_json_encode( $response ) ) );
			return;
		}

		$unsubscribers_emails = array();
		foreach ( $response['body'] as $value ) {
			$unsubscribers_emails[] = $value['email'];
		}

		// Change WooCommerce subscriber status based on Smaily unsubscribers.
		foreach ( $unsubscribers_emails as $user_email ) {
			$wordpress_unsubscriber = get_user_by( 'email', $user_email );
			if ( ! empty( $wordpress_unsubscriber ) ) {
				update_user_meta( $wordpress_unsubscriber->ID, 'user_newsletter', 0, 1 );
			}
		}

		// Get all users with subscribed status.
		$users = get_users(
			array(
				'meta_key'   => 'user_newsletter', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		if ( empty( $users ) ) {
			$this->logger->info( 'No subscribers for synchronization!' );
			return;
		}

		$list = array();
		foreach ( $users as $user ) {
			$subscriber = Data_Handler::get_user_data( $user->ID );
			array_push( $list, $subscriber );
		}

		$response = $request->update_subscribers( $list );
		if ( empty( $response ) ) {
			$this->logger->error( 'Failed to send subscribers to Smaily - received an empty response' );
			return;
		}

		if ( isset( $response['error'] ) ) {
			$this->logger->error( sprintf( 'Failed to send subscribers to Smaily with an error: %s', $response['error'] ) );
			return;
		}

		if ( isset( $response['body']['code'] ) && $response['body']['code'] !== 101 ) {
			$this->logger->error( sprintf( 'Unable to send subscribers to Smaily: %s', wp_json_encode( $response ) ) );
			return;
		}
	}

	/**
	 * Abandoned carts synchronization to Smaily API
	 *
	 * @return void
	 */
	public function smaily_abandoned_carts_email() {
		// Normalized read (F3-54): the option may hold the legacy array OR
		// the bare boolean the pre-3.4.3 Settings wrote — a raw
		// $status['enabled'] on the boolean's stored string was a PHP 8
		// fatal on every tick (the Prike crash loop).
		$status = Options::abandoned_cart_status();
		if ( ! $status['enabled'] ) {
			return;
		}

		$sync_fields = get_option(
			Options::ABANDONED_CART_FIELDS_OPTION,
			Options::ABANDONED_CART_DEFAULT_FIELDS
		);

		/*
		 * Backlog guard (F3-37): a reminder is only sent for carts the
		 * customer touched RECENTLY; anything older is expired (marked
		 * mail_sent, never emailed). A stale reminder is worthless, and a
		 * re-armed scheduler must never mass-mail history: this pipeline's
		 * `abandoned AND mail_sent IS NULL` query has no time bound, so a
		 * dormant period (dead WP-Cron — exactly the pilot site's state at
		 * the 1.x→2.x upgrade) accumulates a backlog that the first tick
		 * after re-arming would drain in one mass send. The age signal is
		 * cart_updated, NOT cart_abandoned_time: the status pass stamps the
		 * latter with NOW() when it (re)marks a cart, so after a dormant
		 * period every historical cart looks freshly abandoned by that
		 * column. Timestamps are compared as epoch ints — cart_updated reads
		 * back from MySQL in 'Y-m-d H:i:s' while the writer used the Z-form,
		 * and a string compare across the two formats breaks on the
		 * separator byte (' ' < 'T') for same-day values.
		 */
		$max_age  = (int) apply_filters( 'smaily_connect_abandoned_cart_max_age_seconds', DAY_IN_SECONDS );
		$expired  = 0;
		$unmapped = 0;

		foreach ( $this->get_abandoned_carts() as $cart ) {
			$updated_ts = isset( $cart['cart_updated'] ) ? strtotime( (string) $cart['cart_updated'] ) : false;
			if ( $updated_ts !== false && $updated_ts < time() - $max_age ) {
				$this->update_mail_sent_status( $cart['customer_id'] );
				++$expired;
				continue;
			}

			/*
			 * Poison-row guard (F3-53): cart_content this pipeline didn't
			 * write (an older/foreign plugin version's rows surviving an
			 * in-place module swap) can deserialize to something other than
			 * a cart-items array. Such a row can never be emailed — mark it
			 * terminally (observable in the log) instead of leaving it
			 * mail_sent NULL, where it would be retried forever.
			 */
			$cart_content = maybe_unserialize( $cart['cart_content'] );
			if ( empty( $cart_content ) ) {
				continue;
			}
			if ( ! is_array( $cart_content ) ) {
				$this->logger->error(
					sprintf(
						'Abandoned cart for customer %d has malformed cart_content (%s) - marked sent without emailing.',
						(int) $cart['customer_id'],
						gettype( $cart_content )
					)
				);
				$this->update_mail_sent_status( $cart['customer_id'] );
				continue;
			}

			$user = get_userdata( $cart['customer_id'] );
			if ( empty( $user ) || empty( $user->user_email ) ) {
				continue;
			}

			/*
			 * Per-cart Throwable backstop (F3-53): a data-shape error in one
			 * cart is deterministic — it would recur on every 15-minute tick
			 * and, uncaught, abort the WHOLE pass before the other carts (the
			 * Prike PHP 8 "Cannot access offset of type string on string"
			 * fatal loop). Mark the cart terminally and move on.
			 */
			try {
				$addresses = $this->prepare_user_data( $user, $sync_fields );
				$products  = $this->prepare_products_data( $cart_content, $sync_fields );

				/*
				 * New-path dispatch first (F3-54): a wizard-configured store
				 * maps the abandoned-cart workflow in the automation-mapping
				 * table; AutomationRouter resolves it (multilingual modes,
				 * the contact-sync force_opt_in policy, F3-44 exchange
				 * capture). The pre-3.4.3 Settings stopped writing
				 * autoresponder_id, so on those stores the mapping table is
				 * the ONLY workflow source. ApiException = transient — leave
				 * the cart unmarked (retried next tick), same semantics as
				 * the legacy error-array path below.
				 */
				$router = $this->automation_router();
				if ( $router instanceof AutomationRouter ) {
					$contact = array( 'email' => $user->user_email );
					if ( isset( $addresses['language'] ) && $addresses['language'] !== '' ) {
						$contact['language'] = $addresses['language'];
					}

					try {
						if ( $router->trigger_automation( 'abandoned_cart', $contact, array_merge( $addresses, $products ) ) ) {
							$this->update_mail_sent_status( $cart['customer_id'] );
							continue;
						}
					} catch ( ApiException $e ) {
						$this->logger->error( sprintf( 'Failed to send abandoned cart email (mapped workflow) with an error: %s', $e->getMessage() ) );
						continue;
					}
				}

				/*
				 * No mapping row — legacy fallback: a pre-wizard store's
				 * option array still carries the merchant's autoresponder id.
				 * Enabled with NEITHER source is a config gap: the cart stays
				 * unmarked (it sends once the merchant maps a workflow; the
				 * backlog guard expires anything older than the window) and
				 * the pass logs one line, not one per cart.
				 */
				if ( $status['autoresponder_id'] <= 0 ) {
					++$unmapped;
					continue;
				}

				$request  = new Smaily_Client( $this->options );
				$response = $request->trigger_automation(
					$status['autoresponder_id'],
					array( array_merge( $addresses, $products ) ),
					false
				);
			} catch ( \Throwable $e ) {
				$this->logger->error(
					sprintf(
						'Abandoned cart for customer %d failed with %s: %s - marked sent without emailing.',
						(int) $cart['customer_id'],
						get_class( $e ),
						$e->getMessage()
					)
				);
				$this->update_mail_sent_status( $cart['customer_id'] );
				continue;
			}

			/*
			 * Per-cart error handling (F3-37): log and move to the NEXT cart.
			 * The pre-fix `return` aborted the whole loop on the first failure
			 * without marking anything — hiding the failure (the rest of the
			 * batch silently waited) and growing the very backlog the guard
			 * above expires. An errored cart keeps mail_sent NULL, so it is
			 * retried next tick until it sends or ages past the guard window.
			 */
			if ( empty( $response ) ) {
				$this->logger->error( 'Failed to trigger abandoned cart email flow - received an empty response' );
				continue;
			}

			if ( isset( $response['error'] ) ) {
				$this->logger->error( sprintf( 'Failed to send abandoned cart email with an error: %s', $response['error'] ) );
				continue;
			}

			if ( isset( $response['body']['code'] ) && $response['body']['code'] !== 101 ) {
				$this->logger->error( sprintf( 'Failed to send abandoned cart email: %s', wp_json_encode( $response ) ) );
				continue;
			}

			$this->update_mail_sent_status( $cart['customer_id'] );
		}

		if ( $expired > 0 ) {
			$this->logger->error(
				sprintf(
					'Backlog guard: expired %d abandoned cart(s) older than the reminder window (%d s) without emailing (filter: smaily_connect_abandoned_cart_max_age_seconds).',
					$expired,
					$max_age
				)
			);
		}

		if ( $unmapped > 0 ) {
			$this->logger->error(
				sprintf(
					'Abandoned cart is enabled but no workflow is configured (no automation mapping, no legacy autoresponder id) - %d cart(s) left pending. Map an abandoned-cart workflow in the plugin settings.',
					$unmapped
				)
			);
		}
	}

	/**
	 * The new-path automation router, when the namespaced bootstrap is
	 * loaded (always, in the combined plugin — the class_exists guard is
	 * belt-and-braces for a partial load). Protected so tests can inject
	 * a double.
	 *
	 * @return AutomationRouter|null
	 */
	protected function automation_router() {
		if ( ! class_exists( '\Smaily\Connect\Bootstrap' ) ) {
			return null;
		}

		return \Smaily\Connect\Bootstrap::instance()->automation_router();
	}

	/**
	 * Get product sale display price without html tags.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @return string
	 */
	public function get_sale_price_with_tax( $product ) {
		return $this->format_price( Helper::get_current_price_with_tax( $product ) );
	}

	/**
	 * Get product regular display price without html tags.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @return string
	 */
	public function get_base_price_with_tax( $product ) {
		return $this->format_price( Helper::get_regular_price_with_tax( $product ) );
	}

	/**
	 * Format a numeric price into a plain-text string using WooCommerce formatting.
	 *
	 * @param float|string $amount
	 * @return string
	 */
	private function format_price( $amount ) {
		return wp_strip_all_tags( html_entity_decode( wc_price( $amount ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) );
	}

	/**
	 * Update mail_sent and mail_sent_time status in abandoned cart table.
	 *
	 * @param int $customer_id Customer ID.
	 * @return void
	 */
	public function update_mail_sent_status( $customer_id ) {
		global $wpdb;

		$table = $wpdb->prefix . Cart::ABANDONED_CART_TABLE_NAME;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'mail_sent'      => 1,
				'mail_sent_time' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			array(
				'customer_id' => $customer_id,
			)
		);
	}

	/**
	 * Get abandoned carts from smaily_abandoned_carts table.
	 *
	 * @return array
	 */
	public function get_abandoned_carts() {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM `' . $wpdb->prefix . Cart::ABANDONED_CART_TABLE_NAME . '` WHERE cart_status = %s AND mail_sent IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a plugin constant
				'abandoned'
			),
			'ARRAY_A'
		);
	}

	/**
	 * Update abandoned cart status based on cutoff time.
	 *
	 * @return void
	 */
	public function smaily_abandoned_carts_status() {
		global $wpdb;
		$results = $this->options->get_settings();

		// Check if abandoned cart is enabled.
		if ( isset( $results['woocommerce']['enable_cart'] ) && (int) $results['woocommerce']['enable_cart'] === 1 ) {
			// Abandoned carts table name.
			$table = $wpdb->prefix . Cart::ABANDONED_CART_TABLE_NAME;
			// Cart cutoff in seconds.
			$cutoff = (int) $results['woocommerce']['cart_cutoff'] * MINUTE_IN_SECONDS;
			// Current UTC timestamp - cutoff.
			$limit = strtotime( gmdate( 'Y-m-d\TH:i:s\Z' ) ) - $cutoff;
			$time  = gmdate( 'Y-m-d\TH:i:s\Z', $limit );

			// Select all carts before cutoff time.
			$carts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT * FROM `' . $table . '` WHERE cart_status = %s AND mail_sent IS NULL AND cart_updated < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a plugin constant
					'open',
					$time
				),
				'ARRAY_A'
			);

			foreach ( $carts as $cart ) {
				// Update abandoned status and time.
				$customer_id = $cart['customer_id'];
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table,
					array(
						'cart_status'         => 'abandoned',
						'cart_abandoned_time' => gmdate( 'Y-m-d\TH:i:s\Z' ),
					),
					array(
						'customer_id' => $customer_id,
					)
				);
			}
		}
	}

	/**
	 * Prepare user data for Smaily API.
	 *
	 * @param WP_User $user User data.
	 * @param array   $options   User options.
	 * @return array
	 */
	private function prepare_user_data( WP_User $user, array $options ) {
		$addresses = array(
			// is_abandoned_cart field is a business requirement.
			// If same account is used for marketing and abandoned cart, then it is necessary to distinguish
			// between the two. The contact can receive abandoned cart emails, but not marketing emails.
			'is_abandoned_cart' => 'true',
		);

		foreach ( $options as $field => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}

			switch ( $field ) {
				case 'store_url':
					$addresses['store'] = get_site_url();
					break;
				case 'user_email':
					$addresses['email'] = $user->user_email;
					break;
				case 'language':
					/*
					 * F3-53 addendum: the legacy helper's fallback is the
					 * context-dependent get_current_language_code() — in this
					 * cron/AS pass that's the F3-47 clobber class (its own
					 * docblock says "not suitable for use in cron jobs").
					 * Route through the resolver; omit when unresolved —
					 * Smaily leaves an absent language intact, '' wipes it.
					 */
					$language = ( new ContactLanguageResolver() )->for_user( $user );
					if ( $language !== '' ) {
						$addresses['language'] = $language;
					}
					break;
				case 'first_name':
					$addresses['first_name'] = $user->first_name;
					break;
				case 'last_name':
					$addresses['last_name'] = $user->last_name;
					break;
				default:
					break;
			}
		}

		return $addresses;
	}

	/**
	 * Prepare products data for Smaily API.
	 *
	 * @param array $cart_data Cart data.
	 * @param array $options   Product options.
	 * @return array
	 */
	private function prepare_products_data( array $cart_data, array $options ) {
		$products = array();

		$product_values = array(
			'product_base_price',
			'product_description',
			'product_image_url',
			'product_name',
			'product_price',
			'product_quantity',
			'product_sku',
		);
		// Add empty product data for addresses. Fields available would be filled out later with data.
		// Required for legacy API so that all fields are always updated.
		foreach ( $product_values as $key ) {
			for ( $i = 1; $i < 11; $i++ ) {
				$products[ $key . '_' . $i ] = '';
			}
		}

		$selected_fields = array_intersect( $product_values, array_keys( array_filter( $options ) ) );
		if ( ! empty( $selected_fields ) ) {
			$products_data = array();
			foreach ( $cart_data as $cart_item ) {
				// A cart item this pipeline didn't write (foreign/older rows
				// after an in-place module swap) may be a bare string — on
				// PHP 8 an offset read on it is fatal, not a notice (F3-53).
				if ( ! is_array( $cart_item ) || ! isset( $cart_item['product_id'] ) || ! is_scalar( $cart_item['product_id'] ) ) {
					continue;
				}

				$product = array();

				// Get product details if selected from user settings.
				$details = wc_get_product( $cart_item['product_id'] );
				if ( ! $details ) {
					continue;
				}

				foreach ( $selected_fields as $selected_field ) {
					switch ( $selected_field ) {
						case 'product_name':
							$product['product_name'] = $details->get_name();
							break;
						case 'product_description':
							$product['product_description'] = $details->get_description();
							break;
						case 'product_sku':
							$product['product_sku'] = $details->get_sku();
							break;
						case 'product_quantity':
							$product['product_quantity'] = isset( $cart_item['quantity'] ) && is_scalar( $cart_item['quantity'] )
								? (string) $cart_item['quantity']
								: '';
							break;
						case 'product_price':
							$product['product_price'] = $this->get_sale_price_with_tax( $details );
							break;
						case 'product_base_price':
							$product['product_base_price'] = $this->get_base_price_with_tax( $details );
							break;
						case 'product_image_url':
							$url = $this->get_product_image_url( $details );
							if ( ! empty( $url ) ) {
								$product['product_image_url'] = $url;
							}
							break;
					}
				}

				$products_data[] = $product;
			}

			// Append products array to API api call. Up to 10 product details.
			$i = 1;
			foreach ( $products_data as $product ) {
				if ( $i > 10 ) {
					$products['over_10_products'] = 'true';
					break;
				}

				foreach ( $product as $key => $value ) {
					$products[ $key . '_' . $i ] = htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
				}
				++$i;
			}
		}

		return $products;
	}

	/**
	 * Get product images.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return string
	 */
	private function get_product_image_url( WC_Product $product ) {
		$image_url = '';

		if ( $product->get_image_id() ) {
			$image_url = wp_get_attachment_url( (int) $product->get_image_id() );
		}

		// Default to featured image.
		if ( ! empty( $image_url ) ) {
			return $image_url;
		}

		// Try to get first gallery image.
		$gallery_image_ids = $product->get_gallery_image_ids();
		if ( ! empty( $gallery_image_ids ) ) {
			return wp_get_attachment_url( $gallery_image_ids[0] );
		}

		return '';
	}
}
