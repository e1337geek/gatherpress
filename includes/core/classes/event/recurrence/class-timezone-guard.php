<?php
/**
 * Guards the fixed-offset timezone hazard.
 *
 * GatherPress normalizes timezones through `Utility::maybe_convert_utc_offset()`
 * and may therefore hold a value such as `UTC+5:30` where a tz-database
 * identifier belongs. PHP classifies those as timezone type 1, which carries no
 * DST rules at all, so a recurring series anchored on one silently drifts.
 * REQ-3 refuses them.
 *
 * Validation is positive, not merely a colon check: the string must appear in
 * `timezone_identifiers_list()` and must contain no colon. The colon test is
 * kept because it is the same test `rlanvin/php-rrule` applies before silently
 * rewriting `DTSTART` to UTC, and keeping it makes the guard's intent legible.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use InvalidArgumentException;

/**
 * Class Timezone_Guard.
 *
 * Stateless validator. Called from `Expander::expand()` and
 * `Recurrence\Meta::set_recurrence()`.
 *
 * @since 0.36.0
 */
final class Timezone_Guard {

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Report whether a timezone string is a named tz-database identifier.
	 *
	 * @since 0.36.0
	 *
	 * @param string $timezone Timezone string to validate.
	 *
	 * @return bool True when the string is a named identifier carrying DST rules.
	 */
	public static function is_named( string $timezone ): bool {
		return false;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Assert that a timezone string is a named tz-database identifier.
	 *
	 * @since 0.36.0
	 *
	 * @param string $timezone Timezone string to validate.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the timezone is a fixed offset or otherwise unnamed.
	 */
	public static function assert_named( string $timezone ): void {
	}
}
