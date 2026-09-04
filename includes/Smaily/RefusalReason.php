<?php
/**
 * Why Smaily refused — the one place that reads a refusal and names its cause.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * A refused Smaily request has three merchant-visible causes, and the plugin
 * used to report all of them as one (PRO-1686): the health notice said "the
 * Smaily API has been unreachable … until the connection recovers" and the
 * connection test said "Smaily did not accept those credentials", whatever the
 * real reason was. On a Smaily account moved to a freemium package that is
 * false twice over — Smaily is up, the credentials are fine, and no amount of
 * waiting or re-typing changes anything.
 *
 * Probed against the live API on a real freemium account (2026-08-04), every
 * endpoint the plugin uses — `autoresponder.php`, `contact.php` (read and
 * list), `history.php` — answers:
 *
 *   HTTP 403  {"code":227,"message":"A paid package is required."}
 *
 * `227` is Smaily's documented "Paid Plan Required" code
 * (https://smaily.com/help/api/general/response-codes/), and it is a POSITIVE
 * signal: nothing else produces it. It is also returned for the correct
 * credentials, a wrong password, a wrong username and no Authorization header
 * at all — the package check runs BEFORE authentication, so while a package
 * blocks the account the credentials cannot be checked at all. That is why the
 * plan message says so instead of implying the credentials are known good.
 *
 * The remaining classes:
 *   - CREDENTIALS_REJECTED — any other 4xx bar 429. A wrong subdomain answers
 *     404 with an empty body (probed), which belongs here too: the subdomain is
 *     part of the credential triple the merchant typed.
 *   - UNREACHABLE — 429, 5xx, and a transport error (status 0). Only these can
 *     honestly be described as "try again later".
 *
 * Biased the same way RetryPolicy is: anything not recognisably an explicit
 * refusal is treated as the transient case, because telling a merchant to
 * change their package or their password when Smaily merely blipped is the
 * more expensive mistake.
 */
final class RefusalReason {

	/** The request succeeded — no refusal to explain. */
	public const OK = 'ok';

	/** Smaily said the account's package does not include this API. */
	public const PLAN_BLOCKED = 'plan_blocked';

	/** Smaily refused the credential triple (subdomain / username / password). */
	public const CREDENTIALS_REJECTED = 'credentials_rejected';

	/** Smaily never answered, or answered with a failure that may pass. */
	public const UNREACHABLE = 'unreachable';

	/** Smaily response code 227 — "A paid package is required." */
	public const CODE_PAID_PACKAGE_REQUIRED = 227;

	/**
	 * Name the cause behind a failed Smaily request.
	 *
	 * @return self::PLAN_BLOCKED|self::CREDENTIALS_REJECTED|self::UNREACHABLE
	 */
	public static function classify( ApiException $e ): string {
		if ( $e->smaily_code() === self::CODE_PAID_PACKAGE_REQUIRED ) {
			return self::PLAN_BLOCKED;
		}

		$status = $e->getCode();

		if ( $status >= 400 && $status < 500 && $status !== 429 ) {
			return self::CREDENTIALS_REJECTED;
		}

		return self::UNREACHABLE;
	}
}
