<?php
/**
 * Integration: a retried contact sync never undoes a newer subscription
 * change that already reached Smaily (CON-395).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\StaleSubscription;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * The shopper's subscription changes go through the REAL hooks (consent
 * mode: a `user_newsletter` change queues a contact sync with
 * `is_unsubscribed`), the REAL queue and the REAL Flusher on its Action
 * Scheduler hook, and the Event Log's REAL routes. Only the Smaily transport
 * is faked, at the pre_http_request seam (SmailyRetryPolicyTest's pattern).
 */
final class StaleContactSyncTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		HookHandler::reset_seen();
		RestRequestHelper::login_as_admin();

		update_option( 'smly_plus_setup_completed', true );
		update_option( ContactSyncMode::OPTION_SYNC_ENABLED, '1' );
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		$this->forget_mode_memo();
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	protected function tearDown(): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		HookHandler::reset_seen();
		$this->forget_mode_memo();

		$bootstrap = Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_a_retried_subscribe_does_not_undo_a_later_delivered_unsubscribe(): void {
		$user_id = $this->make_user();

		// The shopper subscribes; that delivery fails and waits for its retry.
		$subscribe = $this->change_subscription( $user_id, 1 );
		$this->flush_against( 500 );
		self::assertSame( EventQueue::STATUS_PENDING, $this->row( $subscribe )['status'] );

		// The shopper unsubscribes; that delivery succeeds.
		$unsubscribe = $this->change_subscription( $user_id, 0 );
		$bodies      = $this->flush_against( 200 );
		self::assertSame( array( 1 ), $this->statuses_sent( $bodies ), 'Only the unsubscribe is due, and it goes out.' );
		self::assertSame( EventQueue::STATUS_SENT, $this->row( $unsubscribe )['status'] );

		// The retry of the subscribe runs.
		$this->make_due( $subscribe );
		$bodies = $this->flush_against( 200 );

		self::assertSame( array(), $bodies, 'Nothing may mark the contact subscribed again.' );
		$row = $this->row( $subscribe );
		self::assertSame( EventQueue::STATUS_SENT, $row['status'], 'The stale row is finished, never retried again.' );
		self::assertNull( $row['sent_payload'], 'Nothing was sent for it.' );
		self::assertSame( StaleSubscription::REASON, EventQueue::skip_reason( (string) $row['last_response'] ) );

		// The Event Log reads it as skipped, with its reason — never as sent.
		$logged = $this->listed( $subscribe, array() );
		self::assertTrue( $logged['skipped'] );
		self::assertSame( StaleSubscription::REASON, $logged['skip_reason'] );
		self::assertNull( $this->listed( $subscribe, array( 'status' => 'sent' ), false ), 'The Sent filter leaves it out.' );
	}

	public function test_send_again_refuses_a_failed_subscribe_after_a_later_delivered_unsubscribe(): void {
		$user_id = $this->make_user();

		$subscribe = $this->change_subscription( $user_id, 1 );
		$this->flush_against( 401 );
		self::assertSame( EventQueue::STATUS_FAILED, $this->row( $subscribe )['status'] );

		$this->change_subscription( $user_id, 0 );
		$this->flush_against( 200 );

		// The list offers no Retry and says why.
		$logged = $this->listed( $subscribe, array( 'status' => 'failed' ) );
		self::assertSame( StaleSubscription::REASON, $logged['retry_refusal'] );
		self::assertSame( StaleSubscription::message(), $logged['retry_refusal_message'] );

		$response = RestRequestHelper::post(
			'/events/retry',
			array(
				'source' => 'smaily',
				'id'     => $subscribe,
			)
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'contact_sync_retry_refused', $response->get_data()['error'] );
		self::assertSame( StaleSubscription::REASON, $response->get_data()['reason'] );
		self::assertSame( StaleSubscription::message(), $response->get_data()['message'] );
		self::assertSame( EventQueue::STATUS_FAILED, $this->row( $subscribe )['status'], 'A refused retry changes nothing.' );

		// "Retry all failed" revives it, and the Flusher closes it unsent.
		self::assertSame( 200, RestRequestHelper::post( '/events/retry', array() )->get_status() );
		self::assertSame( array(), $this->flush_against( 200 ) );
		self::assertSame( StaleSubscription::REASON, EventQueue::skip_reason( (string) $this->row( $subscribe )['last_response'] ) );
	}

	public function test_a_late_unsubscribe_is_still_delivered(): void {
		$user_id = $this->make_user();

		$this->change_subscription( $user_id, 1 );
		$this->flush_against( 200 );

		// The unsubscribe fails and waits; a later subscribe is delivered.
		$unsubscribe = $this->change_subscription( $user_id, 0 );
		$this->flush_against( 500 );
		$this->change_subscription( $user_id, 1 );
		self::assertSame( array( 0 ), $this->statuses_sent( $this->flush_against( 200 ) ) );

		$this->make_due( $unsubscribe );
		self::assertSame( array( 1 ), $this->statuses_sent( $this->flush_against( 200 ) ), 'An unsubscribe goes out however late.' );
		self::assertNotNull( $this->row( $unsubscribe )['sent_payload'] );
	}

	public function test_a_subscribe_is_sent_when_no_newer_change_reached_smaily(): void {
		$user_id = $this->make_user();

		// A later data sync (no subscription status) is delivered, and a later
		// unsubscribe has failed: neither is a newer choice that reached Smaily.
		$subscribe = $this->change_subscription( $user_id, 1 );
		$this->flush_against( 500 );
		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => 'Renamed',
			)
		);
		self::assertSame( array(), $this->statuses_sent( $this->flush_against( 200 ) ), 'The data sync carries no status.' );

		$unsubscribe = $this->change_subscription( $user_id, 0 );
		$this->flush_against( 401 );
		self::assertSame( EventQueue::STATUS_FAILED, $this->row( $unsubscribe )['status'] );

		$this->make_due( $subscribe );
		self::assertSame( array( 0 ), $this->statuses_sent( $this->flush_against( 200 ) ), 'The subscribe goes out as before.' );
		self::assertNotNull( $this->row( $subscribe )['sent_payload'] );
	}

	// --- helpers -------------------------------------------------------------

	private function make_user(): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_stale_' . wp_generate_password( 6, false ),
				'user_email' => 'stale-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		return $user_id;
	}

	/**
	 * Change the shopper's newsletter choice through the meta write the store
	 * makes, and return the id of the contact sync it queued.
	 */
	private function change_subscription( int $user_id, int $subscribed ): int {
		global $wpdb;

		HookHandler::reset_seen();
		update_user_meta( $user_id, ContactAudience::OPTIN_META, $subscribed );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s AND entity_id = %s",
				HookHandler::EVENT_CONTACT_SYNC,
				$user_id . ':consent'
			)
		);
		self::assertGreaterThan( 0, $id, 'The change queued a contact sync.' );
		self::assertSame( 1 - $subscribed, (int) json_decode( (string) $this->row( $id )['payload'], true )['is_unsubscribed'] );

		return $id;
	}

	/**
	 * Run the real flush hook with the Smaily transport answering $status, and
	 * return every contact POSTed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function flush_against( int $status ): array {
		$contacts = array();
		$fake     = static function ( $pre, $args ) use ( &$contacts, $status ) {
			$body = $args['body'] ?? null;
			$body = is_string( $body ) ? json_decode( $body, true ) : $body;
			foreach ( is_array( $body ) ? $body : array() as $contact ) {
				$contacts[] = $contact;
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'code' => $status === 200 ? 101 : 0 ) ),
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $contacts;
	}

	/**
	 * The `is_unsubscribed` values among the POSTed contacts that carry one.
	 *
	 * @param array<int, array<string, mixed>> $contacts
	 *
	 * @return array<int, int>
	 */
	private function statuses_sent( array $contacts ): array {
		$statuses = array();
		foreach ( $contacts as $contact ) {
			if ( is_array( $contact ) && isset( $contact['is_unsubscribed'] ) ) {
				$statuses[] = (int) $contact['is_unsubscribed'];
			}
		}

		return $statuses;
	}

	/**
	 * The row as the Event Log lists it.
	 *
	 * @param array<string, string> $query
	 *
	 * @return array<string, mixed>|null
	 */
	private function listed( int $id, array $query, bool $must_exist = true ): ?array {
		$response = RestRequestHelper::get( '/events', array_merge( array( 'source' => 'smaily' ), $query ) );
		self::assertSame( 200, $response->get_status() );

		foreach ( $response->get_data()['events'] as $event ) {
			if ( (int) $event['id'] === $id ) {
				return $event;
			}
		}
		if ( $must_exist ) {
			self::fail( 'The row must be listed in the Event Log.' );
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smly_plus_event_queue WHERE id = %d", $id ),
			ARRAY_A
		);
		self::assertIsArray( $row );

		return $row;
	}

	/** Bring a parked row forward so the next pass sees it as due. */
	private function make_due( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'smly_plus_event_queue', array( 'next_retry_at' => null ), array( 'id' => $id ) );
	}

	/** Clear the registered HookHandler's contact-sync mode memo (PRO-4093). */
	private function forget_mode_memo(): void {
		global $wp_filter;

		foreach ( $wp_filter['woocommerce_created_customer']->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && $function[0] instanceof HookHandler ) {
					foreach ( array( 'mode', 'audience' ) as $memo ) {
						$prop = new \ReflectionProperty( HookHandler::class, $memo );
						$prop->setAccessible( true );
						$prop->setValue( $function[0], null );
					}
				}
			}
		}
	}
}
