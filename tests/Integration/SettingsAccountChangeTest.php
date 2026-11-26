<?php
/**
 * Integration: a Smaily account change removes the workflows picked from the
 * replaced account, and a subdomain that is not one plain name is refused.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\PipelineFixture;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * CON-431: a workflow belongs to the Smaily account it was picked from.
 * After a Connection save puts another Smaily account (another subdomain) in
 * a saved account's place, or leaves the account-per-language mode, the
 * workflows of the replaced accounts are gone, and the automations concerned
 * send nothing — the Event Log says "no workflow" — until the merchant
 * chooses a workflow of the new account. Another API user of the same
 * subdomain is the same account and keeps them.
 */
final class SettingsAccountChangeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
		PipelineFixture::seed_credentials();
		update_option(
			'smly_plus_credentials_transactional',
			array(
				'subdomain' => 'txsub',
				'username'  => 'tx',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'tx-password' ),
			)
		);
		update_option( 'smly_plus_multilingual_mode', 'single' );
		update_option(
			'smaily_connect_abandoned_cart_status',
			array(
				'enabled'          => true,
				'autoresponder_id' => 77,
			)
		);
		$this->map( 'welcome', 'default', 'default', '101' );
		$this->map( 'abandoned_cart', 'default', 'default', '102' );
		$this->map( 'order_confirmation', 'default', 'transactional', '201' );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		EnvScrub::reset();
		parent::tearDown();
	}

	public function test_another_account_removes_its_workflows_and_the_welcome_then_sends_nothing(): void {
		$response = $this->save_connection( 'https://othersub.sendsmaily.net/', 'single' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'default' ), $response->get_data()['workflowsRemoved'] );
		self::assertSame( 'othersub', get_option( 'smaily_connect_api_credentials' )['subdomain'], 'A pasted account address is saved as its subdomain.' );
		self::assertSame( array( 'order_confirmation' ), $this->mapped_triggers(), 'Only the replaced account\'s workflows go.' );
		self::assertSame( 0, \Smaily_Connect\Includes\Options::abandoned_cart_status()['autoresponder_id'], 'The legacy reminder workflow was the old account\'s too.' );

		// The welcome now sends nothing, and the Event Log says why.
		$id     = ( new EventQueue() )->enqueue( HookHandler::EVENT_AUTOMATION_WELCOME, '1', array( 'email' => 'shopper@example.test' ) );
		$bodies = PipelineFixture::flush( EventQueue::FLUSH_HOOK );

		self::assertSame( array(), $bodies, 'Nothing is posted to Smaily.' );
		$listed = RestRequestHelper::listed_smaily_events()[ (int) $id ] ?? array();
		self::assertTrue( $listed['skipped'] ?? false );
		self::assertSame( 'no_workflow', $listed['skip_reason'] ?? null );
	}

	public function test_another_api_user_of_the_same_account_keeps_the_workflows(): void {
		$response = $this->save_connection( 'TestSub', 'single', 'another-api-user' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array(), $response->get_data()['workflowsRemoved'] );
		self::assertSame( array( 'abandoned_cart', 'order_confirmation', 'welcome' ), $this->mapped_triggers() );
	}

	public function test_leaving_the_account_per_language_mode_removes_the_language_accounts_workflows(): void {
		update_option( 'smly_plus_multilingual_mode', 'A' );
		$this->map( 'welcome', 'et', 'account_et', '301' );

		$response = $this->save_connection( 'testsub', 'B' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'account_et' ), $response->get_data()['workflowsRemoved'] );
		self::assertSame( array( 'abandoned_cart', 'order_confirmation', 'welcome' ), $this->mapped_triggers() );
	}

	public function test_a_subdomain_that_is_not_one_plain_name_is_refused_and_nothing_changes(): void {
		$response = $this->save_connection( 'my shop', 'single' );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'subdomain', $response->get_data()['errors'][0]['field'] );
		self::assertSame( 'testsub', get_option( 'smaily_connect_api_credentials' )['subdomain'] );
		self::assertSame( array( 'abandoned_cart', 'order_confirmation', 'welcome' ), $this->mapped_triggers() );
	}

	private function save_connection( string $subdomain, string $mode, string $username = 'tester' ): \WP_REST_Response {
		return RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'connection',
				'data' => array(
					'smailyCredentials'          => array(
						'subdomain' => $subdomain,
						'username'  => $username,
						'password'  => 'new-password',
					),
					'multilingualMode'           => $mode,
					'perLanguageAccounts'        => array(),
					'defaultFallbackAccountKey'  => 'default',
					'transactionalEmailsEnabled' => true,
					'transactionalCredentials'   => array(
						'subdomain' => 'txsub',
						'username'  => 'tx',
						'password'  => '',
					),
				),
			)
		);
	}

	private function map( string $trigger, string $language, string $account_key, string $workflow_id ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'smly_plus_automation_mapping',
			array(
				'trigger_type'        => $trigger,
				'language'            => $language,
				'account_key'         => $account_key,
				'workflow_id'         => $workflow_id,
				'is_default_fallback' => 1,
			)
		);
	}

	/** @return string[] The trigger types still mapped to a workflow, sorted. */
	private function mapped_triggers(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$triggers = $wpdb->get_col( "SELECT trigger_type FROM {$wpdb->prefix}smly_plus_automation_mapping ORDER BY trigger_type" );

		return array_map( 'strval', $triggers );
	}
}
