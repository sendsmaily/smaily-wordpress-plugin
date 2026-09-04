<?php
/**
 * The setup-wizard completion flag — one key, one accessor.
 *
 * @package Smaily\Connect\Settings
 */

declare(strict_types=1);

namespace Smaily\Connect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads "has the merchant confirmed setup" (the wizard's Step 6 Finish).
 *
 * The flag gates the live checkout/registration sync, the cart tracker, the
 * abandoned-cart sweeper, the daily contact-sync tick and the Settings screen.
 * Each of those used to read the option raw with its own spelling, which is
 * exactly the class of bug PRO-1742 fixed for the contact-sync switch (a gate
 * reading a key nothing ever wrote) — so the key is defined once here and
 * every gate calls `completed()` (PRO-2292).
 */
final class SetupState {

	/**
	 * The wizard-completion flag. Written by the wizard's Finish route
	 * (`SettingsEndpoint::save_finish()`) and by nothing else.
	 */
	public const OPTION_SETUP_COMPLETED = 'smly_plus_setup_completed';

	/** True once the merchant has finished the setup wizard. */
	public static function completed(): bool {
		return (bool) get_option( self::OPTION_SETUP_COMPLETED, false );
	}
}
