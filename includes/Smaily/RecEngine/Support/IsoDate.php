<?php
/**
 * Single source of truth for rec-engine wire datetime formatting.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Formats a Unix timestamp as the rec-engine's wire datetime: ISO 8601 in
 * UTC with a **`Z` suffix** (`2026-05-19T10:15:23Z`), per RECENGINE_API_CONTRACT
 * §base context.
 *
 * Why this exists (F3-1 single-source, applied to datetime): the engine
 * validates every timestamp with a strict Zod `.datetime()` that rejects a
 * numeric offset — `2026-05-19T10:15:23+00:00` (PHP's `date('c')` / `'c'`
 * format) fails as "Invalid datetime", only `...Z` passes. The 3.3.4
 * customers live-walk caught it on `first_seen_at`; `CatalogPayloadBuilder`'s
 * `on_sale_until` had the same latent bug. Routing every builder's datetime
 * through one helper means the next endpoint (orders, W5) can't repeat it.
 * (LESSONS §2.4 — the mock didn't validate the format, so only the live
 * engine surfaced it.)
 */
final class IsoDate {

	/**
	 * @param int $timestamp Unix timestamp (seconds since epoch, UTC).
	 *
	 * @return string ISO 8601 UTC with a `Z` suffix.
	 */
	public static function to_z( int $timestamp ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
