<?php
/**
 * Single source of truth for WP user → rec-engine customer-object mapping.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

/**
 * Translates a WP_User into one entry of the
 * `POST /api/v1/ingest/customers` `customers[]` array
 * (RECENGINE_API_CONTRACT.md §4, W4 email-first contract).
 *
 * Identity is `email` (W4 / D1): the engine UPSERTs on (tenant_id, email),
 * lowercasing on ingest and matching case-insensitively. There is no
 * `smaily_contact_id`. The builder lowercases the email itself so the wire
 * value already matches the engine's stored key (cleanliness, not
 * correctness — the engine would lowercase regardless).
 *
 * Unlike CatalogPayloadBuilder there is no expand(): a customer is a single
 * ingestable unit (variable products fan out into variations; users do not).
 * The HookHandler enqueues one row per user, and build() maps that row's
 * WP_User + its queue-row event_uuid into the wire object.
 *
 * event_uuid → event_id is applied here (the same rename CatalogPayloadBuilder
 * does) so queue.event_uuid == HTTP body.event_id holds and the engine can
 * dedup a retried row (F3-7 variant-A idempotency, per-item event_id).
 *
 * Empty-value policy mirrors CatalogPayloadBuilder / SubscriberPayloadBuilder
 * (F2-10): an OPTIONAL field whose source is empty is OMITTED rather than sent
 * as "" / null. The engine UPSERTs on email, so an absent field leaves the
 * engine's existing value intact while an empty one would clobber it —
 * absent != empty. The always-present keys are `email` (identity), `event_id`,
 * and `external_id` (the WP user id, always available).
 *
 * Consent is NOT part of the customers contract (W4 removed it entirely — the
 * engine no longer accepts, stores, or processes any `consent.*` field; Smaily
 * owns consent). The builder deliberately sends no consent data.
 *
 * Not final: tests subclass to stub the WooCommerce / locale reads (billing
 * country + phone, user locale) without standing up WC_Customer. Same
 * rationale as CatalogPayloadBuilder and Smaily\Client.
 */
class CustomerPayloadBuilder {

	/**
	 * Build the customer wire object for one WP user + its queue event_uuid.
	 *
	 * @return array<string, mixed>
	 */
	public function build( \WP_User $user, string $event_uuid ): array {
		$payload = array(
			// queue.event_uuid → wire body.event_id (the per-customer
			// idempotency key, same rename as catalog).
			'event_id'    => $event_uuid,
			// Identity — lowercased so the wire value matches the engine's
			// case-insensitive (tenant_id, email) key.
			'email'       => $this->email( $user ),
			'external_id' => (string) $user->ID,
		);

		$first_name = trim( (string) ( $user->first_name ?? '' ) );
		if ( $first_name !== '' ) {
			$payload['first_name'] = $first_name;
		}

		$last_name = trim( (string) ( $user->last_name ?? '' ) );
		if ( $last_name !== '' ) {
			$payload['last_name'] = $last_name;
		}

		$wc = $this->woocommerce_fields( $user );
		if ( $wc['country'] !== '' ) {
			$payload['country'] = $wc['country'];
		}
		if ( $wc['phone'] !== '' ) {
			$payload['phone'] = $wc['phone'];
		}

		$language = $this->language( $user );
		if ( $language !== '' ) {
			$payload['language'] = $language;
		}

		$first_seen_at = $this->first_seen_at( $user );
		if ( $first_seen_at !== '' ) {
			$payload['first_seen_at'] = $first_seen_at;
		}

		return $payload;
	}

	private function email( \WP_User $user ): string {
		return strtolower( trim( (string) ( $user->user_email ?? '' ) ) );
	}

	/**
	 * Registration timestamp as ISO 8601 UTC. WP stores user_registered as a
	 * 'Y-m-d H:i:s' string in GMT, parsed as UTC and emitted via IsoDate
	 * (the `Z`-suffix form the engine's strict Zod requires). The engine keeps
	 * the earliest value on update, so sending the true registration time is
	 * safe to repeat.
	 */
	private function first_seen_at( \WP_User $user ): string {
		$registered = trim( (string) ( $user->user_registered ?? '' ) );
		if ( $registered === '' || $registered === '0000-00-00 00:00:00' ) {
			return '';
		}
		$timestamp = strtotime( $registered . ' UTC' );
		if ( $timestamp === false ) {
			return '';
		}
		return IsoDate::to_z( $timestamp );
	}

	/**
	 * Billing country (ISO 3166-1 alpha-2, upper-cased) and phone from the
	 * user's WooCommerce customer record. Protected so tests can stub the
	 * lookup without a real WC_Customer; returns empties when WooCommerce is
	 * absent or the customer can't be loaded.
	 *
	 * @return array{country: string, phone: string}
	 */
	protected function woocommerce_fields( \WP_User $user ): array {
		$empty = array(
			'country' => '',
			'phone'   => '',
		);

		if ( ! class_exists( '\WC_Customer' ) ) {
			return $empty;
		}

		try {
			$customer = new \WC_Customer( (int) $user->ID );
		} catch ( \Exception $e ) {
			return $empty;
		}

		return array(
			'country' => strtoupper( trim( (string) $customer->get_billing_country() ) ),
			'phone'   => trim( (string) $customer->get_billing_phone() ),
		);
	}

	/**
	 * The user's language as an ISO 639-1 code (the language part of the WP
	 * locale, e.g. `et_EE` → `et`). Protected so tests can drive it without
	 * WordPress. Empty when the locale can't be resolved.
	 */
	protected function language( \WP_User $user ): string {
		if ( ! function_exists( 'get_user_locale' ) ) {
			return '';
		}
		$locale = trim( (string) get_user_locale( $user ) );
		if ( $locale === '' ) {
			return '';
		}
		$parts = preg_split( '/[_-]/', $locale );
		if ( ! is_array( $parts ) || ! isset( $parts[0] ) || $parts[0] === '' ) {
			return '';
		}
		return strtolower( $parts[0] );
	}
}
