<?php
/**
 * Immutable Smaily API credentials value object.
 *
 * @package Smaily\Connect\Settings
 */

declare(strict_types=1);

namespace Smaily\Connect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Triple of (subdomain, username, password) that a Smaily\Client needs to
 * authenticate against https://{subdomain}.sendsmaily.net.
 *
 * Logically immutable — see WorkflowMatch for the same rationale on why
 * the three properties aren't marked `readonly` (PHP 8.1+ syntax, plugin
 * floor is 8.0).
 */
final class CredentialSet {

	public string $subdomain;
	public string $username;
	public string $password;

	public function __construct( string $subdomain, string $username, string $password ) {
		$this->subdomain = $subdomain;
		$this->username  = $username;
		$this->password  = $password;
	}

	/**
	 * True when every field has a non-empty value — required before any
	 * Smaily API call can actually be issued.
	 */
	public function is_complete(): bool {
		return $this->subdomain !== '' && $this->username !== '' && $this->password !== '';
	}

	/**
	 * True when this set belongs to the given account — i.e. both the
	 * subdomain and the username are the ones stored here. Callers reusing
	 * a stored password check this first, so a set never vouches for an
	 * account it was not stored for (PRO-2286).
	 */
	public function matches( string $subdomain, string $username ): bool {
		return $this->subdomain === $subdomain && $this->username === $username;
	}
}
