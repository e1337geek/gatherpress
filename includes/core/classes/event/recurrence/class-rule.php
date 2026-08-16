<?php
/**
 * Value object describing one recurrence rule.
 *
 * One rule per event, permanently (PRD D-5). The rule is stored as decomposed
 * post meta — a single writable `gatherpress_recurrence` JSON blob plus ten
 * derived read-only mirrors — never as a serialized RFC 5545 string and never
 * in a table of its own. `Rule::from_post()` reconstructs the value object from
 * the mirrors, which is what keeps the blob a pure write-boundary artifact.
 *
 * `to_rrule_string()` is the REQ-14 export seam. It exists and is unit-tested
 * from day one, and has no production caller in the POC.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use DateTimeImmutable;

/**
 * Class Rule.
 *
 * Immutable description of a repeating schedule. Not a singleton: an event has
 * a rule, so the object is constructed per event rather than shared.
 *
 * @since 0.36.0
 */
final class Rule {

	/**
	 * Frequency: repeats every N days.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const FREQUENCY_DAILY = 'daily';

	/**
	 * Frequency: repeats on selected weekdays every N weeks.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const FREQUENCY_WEEKLY = 'weekly';

	/**
	 * Frequency: repeats every N months.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const FREQUENCY_MONTHLY = 'monthly';

	/**
	 * Monthly mode: a fixed day number, such as the 15th.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const MONTHLY_MODE_DAY_OF_MONTH = 'day_of_month';

	/**
	 * Monthly mode: an ordinal weekday, such as the last Wednesday.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const MONTHLY_MODE_NTH_WEEKDAY = 'nth_weekday';

	/**
	 * End type: the series never ends on its own.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const END_TYPE_NEVER = 'never';

	/**
	 * End type: the series ends on a date.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const END_TYPE_UNTIL = 'until';

	/**
	 * End type: the series ends after a number of occurrences.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const END_TYPE_COUNT = 'count';

	/**
	 * Week start, Monday. PRD D-8, and the RFC 5545 default. Not configurable.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const WEEK_START = 1;

	/**
	 * Authoring cap on `COUNT`, validated at the meta boundary. See `Expander::iteration_budget()`.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const MAX_COUNT = 730;

	/**
	 * Authoring cap on `INTERVAL`, validated at the meta boundary. See `Expander::iteration_budget()`.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const MAX_INTERVAL = 52;

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Reconstruct a rule from a post's derived recurrence mirrors.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to read the recurrence mirrors from.
	 *
	 * @return Rule|null The rule, or null when the post carries no recurrence.
	 * @phpstan-ignore-next-line -- T0 skeleton; the non-null return lands with the implementation.
	 */
	public static function from_post( int $post_id ): ?Rule {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Build a rule from a decoded value array.
	 *
	 * @since 0.36.0
	 *
	 * @param array $values Decoded recurrence values.
	 *
	 * @return Rule|null The rule, or null when the values do not describe one.
	 * @phpstan-ignore-next-line -- T0 skeleton; the non-null return lands with the implementation.
	 */
	public static function from_array( array $values ): ?Rule {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Get the repeat frequency.
	 *
	 * @since 0.36.0
	 *
	 * @return string One of the `FREQUENCY_*` constants.
	 */
	public function frequency(): string {
		return '';
	}

	/**
	 * Get the repeat interval, clamped to at least one.
	 *
	 * @since 0.36.0
	 *
	 * @return int The interval, never below 1 and never above `MAX_INTERVAL`.
	 */
	public function interval(): int {
		return 0;
	}

	/**
	 * Get the weekdays a weekly rule repeats on.
	 *
	 * @since 0.36.0
	 *
	 * @return int[] Weekday numbers, 0 for Sunday through 6 for Saturday.
	 */
	public function weekdays(): array {
		return array();
	}

	/**
	 * Get the monthly repeat mode.
	 *
	 * @since 0.36.0
	 *
	 * @return string One of the `MONTHLY_MODE_*` constants.
	 */
	public function monthly_mode(): string {
		return '';
	}

	/**
	 * Get the day of the month a day-of-month rule repeats on.
	 *
	 * @since 0.36.0
	 *
	 * @return int A day number from 1 to 31.
	 */
	public function monthly_day(): int {
		return 0;
	}

	/**
	 * Get the ordinal an nth-weekday rule repeats on.
	 *
	 * @since 0.36.0
	 *
	 * @return int An ordinal from 1 to 4, or -1 for "last".
	 */
	public function monthly_ordinal(): int {
		return 0;
	}

	/**
	 * Get the weekday an nth-weekday rule repeats on.
	 *
	 * @since 0.36.0
	 *
	 * @return int A weekday number from 0 for Sunday through 6 for Saturday.
	 */
	public function monthly_weekday(): int {
		return 0;
	}

	/**
	 * Get how the series ends.
	 *
	 * @since 0.36.0
	 *
	 * @return string One of the `END_TYPE_*` constants.
	 */
	public function end_type(): string {
		return '';
	}

	/**
	 * Get the date the series ends on.
	 *
	 * @since 0.36.0
	 *
	 * @return DateTimeImmutable|null The end date, or null when the rule does not end on a date.
	 * @phpstan-ignore-next-line -- T0 skeleton; the non-null return lands with the implementation.
	 */
	public function until(): ?DateTimeImmutable {
		return null;
	}

	/**
	 * Get how many occurrences the series produces.
	 *
	 * @since 0.36.0
	 *
	 * @return int The count, never above `MAX_COUNT`, or 0 when the rule is not count-bounded.
	 */
	public function count(): int {
		return 0;
	}

	/**
	 * Report whether the rule describes an expandable schedule.
	 *
	 * @since 0.36.0
	 *
	 * @return bool True when the rule is complete and internally consistent.
	 */
	public function is_valid(): bool {
		return false;
	}

	/**
	 * Convert the rule back to its decomposed value array.
	 *
	 * @since 0.36.0
	 *
	 * @return array The rule's values, keyed as the `gatherpress_recurrence` blob is.
	 */
	public function to_array(): array {
		return array();
	}

	/**
	 * Serialize the rule as an RFC 5545 `RRULE` string.
	 *
	 * The REQ-14 export seam. Unit-tested against a fixture table, with no
	 * production caller in the POC.
	 *
	 * @since 0.36.0
	 *
	 * @return string The `RRULE` value, without the property name.
	 */
	public function to_rrule_string(): string {
		return '';
	}
}
