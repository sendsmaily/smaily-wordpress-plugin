<?php

namespace Smaily_Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily_Connect\Includes\Logger;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Includes\Smaily_Client;

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
		// (it would double-remind against the new pipeline). CON-182 removed
		// the two passes with the legacy table they read and wrote.
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
}
