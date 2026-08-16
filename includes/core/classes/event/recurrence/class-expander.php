<?php
/**
 * Expands a recurrence rule into concrete datetimes.
 *
 * Pure by contract: no database access, no globals, no side effects, so the
 * hardest piece of the subsystem is testable in isolation.
 *
 * PRD C-4 — the contract is datetime-valued, never date-valued, from the first
 * commit. PRD C-3 — the wall-clock time is read once from the anchor and applied
 * in the series timezone; interval arithmetic never runs on a wall-clock-bearing
 * datetime. RFC 5545 section 3.3.10 — a nonexistent local time in a
 * spring-forward gap is skipped and does not consume a `COUNT` budget.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use DateTimeImmutable;
use DateTimeZone;

/**
 * Class Expander.
 *
 * Not a singleton and not stateful: an expansion is a function of its
 * arguments, which is what makes the DST and interval cases cheap to test.
 *
 * @since 0.36.0
 */
final class Expander {

	/**
	 * Absolute backstop on candidate steps.
	 *
	 * Not the working bound — see `iteration_budget()`. It exists only so a bug
	 * in the budget calculation cannot hang a request.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const MAX_ITERATIONS = 200000;

	/**
	 * Hard cap on an authored `COUNT`, rejected above this at the meta boundary.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const MAX_COUNT = 730;

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Derive the candidate-step budget from the rule rather than guessing it.
	 *
	 * The walk is day-by-day, so the budget is expressed in days rather than in
	 * occurrences: a `COUNT=500` monthly rule needs roughly 15,200 day-steps.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule    Rule being expanded.
	 * @param DateTimeImmutable $anchor  Series anchor start.
	 * @param DateTimeImmutable $through Horizon the expansion runs to.
	 *
	 * @return int The lesser of the derived budget and `MAX_ITERATIONS`.
	 */
	protected function iteration_budget( Rule $rule, DateTimeImmutable $anchor, DateTimeImmutable $through ): int {
		return 0;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Expand a rule into its occurrence datetimes.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule         Rule being expanded.
	 * @param DateTimeImmutable $anchor_start Series anchor start, source of the wall-clock time.
	 * @param DateTimeZone      $timezone     Series timezone, which must be a named identifier.
	 * @param DateTimeImmutable $through      Horizon, ignored by a count-bounded rule.
	 *
	 * @return DateTimeImmutable[] Ordered ascending. Never contains a nonexistent local time.
	 */
	public function expand(
		Rule $rule,
		DateTimeImmutable $anchor_start,
		DateTimeZone $timezone,
		DateTimeImmutable $through
	): array {
		return array();
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Advance to the next date the rule could match.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule   Rule being expanded.
	 * @param DateTimeImmutable $cursor Date-only cursor to advance from.
	 *
	 * @return DateTimeImmutable|null The next candidate date, or null when the walk is exhausted.
	 * @phpstan-ignore-next-line -- T0 skeleton; the non-null return lands with the implementation.
	 */
	protected function next_candidate_date( Rule $rule, DateTimeImmutable $cursor ): ?DateTimeImmutable {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Report whether a candidate date satisfies the rule.
	 *
	 * The anchor is a parameter because weekly interval arithmetic is relative
	 * to the anchor's Monday-start week, not to the cursor.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule      Rule being expanded.
	 * @param DateTimeImmutable $candidate Candidate date under test.
	 * @param DateTimeImmutable $anchor    Series anchor, the origin of interval arithmetic.
	 *
	 * @return bool True when the candidate is an occurrence date.
	 */
	protected function matches( Rule $rule, DateTimeImmutable $candidate, DateTimeImmutable $anchor ): bool {
		return false;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Resolve the nth weekday of a month to a date.
	 *
	 * @since 0.36.0
	 *
	 * @param int $year    Four-digit year.
	 * @param int $month   Month number, 1 through 12.
	 * @param int $weekday Weekday number, 0 for Sunday through 6 for Saturday.
	 * @param int $ordinal Ordinal from 1 to 4, or -1 for "last".
	 *
	 * @return string|null A `Y-m-d` date, or null when the month has no such weekday.
	 * @phpstan-ignore-next-line -- T0 skeleton; the non-null return lands with the implementation.
	 */
	protected function nth_weekday_of_month( int $year, int $month, int $weekday, int $ordinal ): ?string {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Apply a wall-clock time to a date in the series timezone.
	 *
	 * @since 0.36.0
	 *
	 * @param string       $date     Date in `Y-m-d` form.
	 * @param string       $time     Wall-clock time in `H:i:s` form.
	 * @param DateTimeZone $timezone Series timezone.
	 *
	 * @return DateTimeImmutable|null The datetime, or null when the local time does not exist.
	 * @phpstan-ignore-next-line -- T0 skeleton; the non-null return lands with the implementation.
	 */
	protected function materialize( string $date, string $time, DateTimeZone $timezone ): ?DateTimeImmutable {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Get the Monday-start week index for a date.
	 *
	 * A weekly rule matches when `week_index( $candidate ) - week_index( $anchor )`
	 * is divisible by the rule's interval.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $date Date to bucket.
	 *
	 * @return int The week bucket, which may be negative for pre-1970 dates.
	 */
	protected function week_index( DateTimeImmutable $date ): int {
		return 0;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}
