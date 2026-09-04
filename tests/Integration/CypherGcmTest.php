<?php
/**
 * Integration: the Cypher v2 (GCM) format + the legacy-blob re-encryption
 * migration, exercised against the REAL Cypher class (the unit suite runs
 * against a spy shim — see tests/bootstrap.php — so real crypto behaviour
 * is only provable here).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily_Connect\Includes\Cypher;

/**
 * What FABLE_AUDIT §4#2 bug this guards against:
 *
 *   The legacy blob format used a STATIC IV (a prefix of AUTH_KEY) for
 *   every encryption and persisted that IV inside the stored value — so
 *   every DB dump leaked an AUTH_KEY prefix, and equal plaintexts produced
 *   equal ciphertexts. The fix: encrypt() writes a versioned `smy2:` GCM
 *   blob with a random per-message nonce; decrypt() still reads legacy
 *   blobs; Activation::run() re-encrypts every stored secret forward.
 *
 *   These tests pin all three properties — if encrypt() ever regresses to
 *   a deterministic format, the distinct-blobs assertion fails; if legacy
 *   decryption breaks, stored pre-upgrade secrets would be lost silently.
 */
final class CypherGcmTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
	}

	/**
	 * Replicates the PRE-upgrade encrypt() (AES-256-CBC, static AUTH_KEY-prefix
	 * IV stored in the blob) so the tests can fabricate genuine legacy blobs.
	 */
	private static function legacy_encrypt( string $password ): string {
		$salt = hash( 'sha256', SECURE_AUTH_KEY );
		$iv   = substr( AUTH_KEY, 0, (int) openssl_cipher_iv_length( 'AES-256-CBC' ) );
		$raw  = (string) openssl_encrypt( $password, 'AES-256-CBC', $salt, OPENSSL_RAW_DATA, $iv );
		$hmac = hash_hmac( 'sha256', $raw, $salt, true );

		return base64_encode( $iv . $hmac . $raw );
	}

	public function test_encrypt_writes_v2_and_round_trips(): void {
		$blob = Cypher::encrypt( 's3cret-api-password' );

		self::assertTrue( Cypher::is_v2( $blob ), 'encrypt() must write the v2 prefix' );
		self::assertSame( 's3cret-api-password', Cypher::decrypt( $blob ) );
	}

	public function test_encrypt_is_not_deterministic(): void {
		// The legacy format's static IV made equal plaintexts produce equal
		// ciphertexts; the random GCM nonce must break that.
		self::assertNotSame(
			Cypher::encrypt( 'same-plaintext' ),
			Cypher::encrypt( 'same-plaintext' )
		);
	}

	public function test_legacy_blob_still_decrypts(): void {
		self::assertSame(
			'pre-upgrade-secret',
			Cypher::decrypt( self::legacy_encrypt( 'pre-upgrade-secret' ) )
		);
	}

	public function test_tampered_v2_blob_returns_empty(): void {
		$blob    = Cypher::encrypt( 'tamper-me' );
		$decoded = base64_decode( substr( $blob, strlen( Cypher::V2_PREFIX ) ) );
		// Flip the last ciphertext byte; GCM's tag check must reject it.
		$decoded[ strlen( $decoded ) - 1 ] = chr( ord( $decoded[ strlen( $decoded ) - 1 ] ) ^ 0x01 );

		self::assertSame( '', Cypher::decrypt( Cypher::V2_PREFIX . base64_encode( $decoded ) ) );
	}

	public function test_activation_reencrypts_every_stored_location(): void {
		// Seed every Cypher-blob location in the LEGACY format.
		update_option(
			Credentials::LEGACY_OPTION_KEY,
			array(
				'subdomain' => 'acme',
				'username'  => 'alice',
				'password'  => self::legacy_encrypt( 'default-secret' ),
			)
		);
		update_option(
			Credentials::PHASE2_OPTION_PREFIX . 'acme_et',
			array(
				'subdomain' => 'acme-et',
				'username'  => 'bob',
				'password'  => self::legacy_encrypt( 'per-account-secret' ),
			)
		);
		update_option( RecEngineSettings::OPTION_API_KEY, self::legacy_encrypt( 'rec-engine-key' ), false );

		Activation::run();

		$default     = get_option( Credentials::LEGACY_OPTION_KEY );
		$per_account = get_option( Credentials::PHASE2_OPTION_PREFIX . 'acme_et' );
		$rec_key     = (string) get_option( RecEngineSettings::OPTION_API_KEY );

		self::assertTrue( Cypher::is_v2( (string) $default['password'] ), 'default creds migrated to v2' );
		self::assertTrue( Cypher::is_v2( (string) $per_account['password'] ), 'per-account creds migrated to v2' );
		self::assertTrue( Cypher::is_v2( $rec_key ), 'rec-engine key migrated to v2' );

		self::assertSame( 'default-secret', Cypher::decrypt( (string) $default['password'] ) );
		self::assertSame( 'per-account-secret', Cypher::decrypt( (string) $per_account['password'] ) );
		self::assertSame( 'rec-engine-key', Cypher::decrypt( $rec_key ) );

		// Untouched fields survive.
		self::assertSame( 'acme', $default['subdomain'] );
		self::assertSame( 'bob', $per_account['username'] );
	}

	public function test_activation_is_idempotent_and_preserves_undecryptable_blobs(): void {
		// An undecryptable value (e.g. WP salts rotated since it was written)
		// must be left byte-identical — overwriting it would silently destroy
		// the "credential needs re-entering" evidence.
		$garbage = base64_encode( random_bytes( 64 ) );
		update_option(
			Credentials::LEGACY_OPTION_KEY,
			array(
				'subdomain' => 'acme',
				'username'  => 'alice',
				'password'  => $garbage,
			)
		);

		Activation::run();
		$after_first = get_option( Credentials::LEGACY_OPTION_KEY );
		self::assertSame( $garbage, $after_first['password'], 'undecryptable blob left untouched' );

		// Idempotency on an already-migrated value: the v2 blob is skipped by
		// the prefix check, so a second run leaves it byte-identical.
		$v2 = Cypher::encrypt( 'stable-secret' );
		update_option( RecEngineSettings::OPTION_API_KEY, $v2, false );

		Activation::run();
		self::assertSame( $v2, (string) get_option( RecEngineSettings::OPTION_API_KEY ) );
	}
}
