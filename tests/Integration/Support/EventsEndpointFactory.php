<?php
/**
 * Test-support helper — the EventsEndpoint as the REST registry builds it.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Bootstrap;
use Smaily\Connect\REST\EventsEndpoint;
use Smaily\Connect\Smaily\TransactionalResend;

/**
 * Why a helper: every Event Log integration test needs the endpoint wired the
 * way the registry wires it — its write half carries the "Send again" service
 * (PRO-2324), resolved lazily off the Bootstrap container — while the read
 * tests never exercise that half. One factory, so a change to how the endpoint
 * is constructed lands in one place instead of drifting between test files.
 */
final class EventsEndpointFactory {

	public static function create(): EventsEndpoint {
		return new EventsEndpoint(
			static function (): TransactionalResend {
				return Bootstrap::instance()->transactional_resend();
			}
		);
	}
}
