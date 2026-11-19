<?php
/**
 * Integration: the invitation a store with no connected Smaily account sees
 * in the WordPress admin (CON-321).
 *
 * Runs the REAL NotificationManager with the REAL ReportBuilder, so
 * "connected" is read from the stored credentials the way the installation
 * report reads it — a main marketing account, a Transactional account alone
 * does not count.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class SmailyInviteNoticeTest extends TestCase {

	private const INVITE_TEXT = 'Connect your store to Smaily to sync contacts and set up automated emails.';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		EnvScrub::reset();
		parent::tearDown();
	}

	private function render(): string {
		$manager = new NotificationManager(
			new RecEngineSettings(),
			static fn (): RecEngineClient => new RecEngineClient( 'sk_unused', 'https://unused.test' ),
			static fn (): ?SmailyClient => null
		);

		ob_start();
		$manager->render();

		return (string) ob_get_clean();
	}

	private function store_account( string $option_key ): void {
		update_option(
			$option_key,
			array(
				'subdomain' => 'demo',
				'username'  => 'alice',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 's3cret' ),
			)
		);
	}

	public function test_a_store_with_no_smaily_account_is_invited(): void {
		$html = $this->render();

		self::assertStringContainsString( self::INVITE_TEXT, $html );
		self::assertStringContainsString( 'page=smaily-connect-settings#connection', $html );
		self::assertStringContainsString( \Smaily\Connect\Constants::SIGNUP_URL, $html );
	}

	public function test_a_transactional_account_alone_still_gets_the_invitation(): void {
		$this->store_account( Credentials::PHASE2_OPTION_PREFIX . 'transactional' );

		self::assertStringContainsString( self::INVITE_TEXT, $this->render() );
	}

	public function test_a_connected_smaily_account_removes_the_invitation(): void {
		$this->store_account( Credentials::LEGACY_OPTION_KEY );

		self::assertStringNotContainsString( self::INVITE_TEXT, $this->render() );
	}

	public function test_a_dismissal_hides_it_for_ninety_days_and_then_it_returns(): void {
		$manager = new NotificationManager(
			new RecEngineSettings(),
			static fn (): RecEngineClient => new RecEngineClient( 'sk_unused', 'https://unused.test' ),
			static fn (): ?SmailyClient => null
		);
		$manager->dismiss( NotificationManager::INVITE_KEY );

		self::assertStringNotContainsString( self::INVITE_TEXT, $this->render() );

		update_option(
			NotificationManager::OPTION_DISMISSED,
			array( NotificationManager::INVITE_KEY => time() - NotificationManager::INVITE_DISMISS_COOLDOWN - 1 ),
			false
		);

		self::assertStringContainsString( self::INVITE_TEXT, $this->render() );
	}
}
