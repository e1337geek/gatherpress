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

	/**
	 * How many months past its starting month the monthly walk looks for a date.
	 *
	 * Generous enough for the widest legal gap — a February 29th anchor repeating
	 * every twelve months waits four years for its next date — and small enough
	 * that an unsatisfiable rule terminates immediately.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const MONTH_SCAN_STEPS = 120;

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
		$days = (int) $anchor->diff( $through )->days + 1;

		if ( Rule::END_TYPE_COUNT === $rule->end_type() ) {
			$per = match ( $rule->frequency() ) {
				Rule::FREQUENCY_DAILY  => 1,
				Rule::FREQUENCY_WEEKLY => 7,
				default                => 31,
			};

			// A count-bounded rule outruns the horizon, so the budget covers the
			// whole count plus a year of slack for skipped candidates.
			$days = max( $days, $rule->count() * $per * $rule->interval() + 366 );
		}

		return min( $days, self::MAX_ITERATIONS );
	}

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
		Timezone_Guard::assert_named( $timezone->getName() );

		// PRD C-3: the wall-clock time is read from the anchor once, and the walk
		// itself runs on timezone-free dates so no interval arithmetic can land on
		// a datetime that carries an offset.
		$time       = $anchor_start->format( 'H:i:s' );
		$anchor     = $this->date_only( $anchor_start );
		$cursor     = $anchor;
		$horizon    = $through->format( 'Y-m-d' );
		$results    = array();
		$delivered  = 0;
		$iterations = 0;
		$is_count   = Rule::END_TYPE_COUNT === $rule->end_type();
		$budget     = $is_count ? $rule->count() : PHP_INT_MAX;
		$steps      = $this->iteration_budget( $rule, $anchor_start, $through );

		while ( $delivered < $budget && $iterations < $steps ) {
			++$iterations;
			$candidate = $this->next_candidate_date( $rule, $cursor, $anchor );

			// A count-bounded rule is bounded by its count, never by the horizon:
			// a weekly COUNT=500 spans roughly nine and a half years.
			if ( null === $candidate || ( ! $is_count && $candidate->format( 'Y-m-d' ) > $horizon ) ) {
				break;
			}

			$cursor = $candidate->modify( '+1 day' );

			if ( $this->past_until( $rule, $candidate ) ) {
				break;
			}

			$occurrence = $this->materialize( $candidate->format( 'Y-m-d' ), $time, $timezone );

			// RFC 5545 section 3.3.10: a nonexistent local time is ignored and does
			// not count toward the recurrence set, so the budget is spent here on
			// appending a result rather than earlier on consuming a candidate.
			if ( null !== $occurrence ) {
				$results[] = $occurrence;
				++$delivered;
			}
		}

		return $results;
	}

	/**
	 * Advance to the next date the rule could match.
	 *
	 * The anchor is a parameter because every interval is measured from the
	 * anchor rather than from the cursor.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule   Rule being expanded.
	 * @param DateTimeImmutable $cursor Date-only cursor to advance from.
	 * @param DateTimeImmutable $anchor Series anchor date, the origin of interval arithmetic.
	 *
	 * @return DateTimeImmutable|null The next candidate date, or null when the walk is exhausted.
	 */
	protected function next_candidate_date(
		Rule $rule,
		DateTimeImmutable $cursor,
		DateTimeImmutable $anchor
	): ?DateTimeImmutable {
		return match ( $rule->frequency() ) {
			Rule::FREQUENCY_MONTHLY                       => $this->next_monthly_date( $rule, $cursor, $anchor ),
			Rule::FREQUENCY_DAILY, Rule::FREQUENCY_WEEKLY => $this->next_scanned_date( $rule, $cursor, $anchor ),
			// An unrecognized frequency yields no occurrences rather than a fatal.
			default                                       => null,
		};
	}

	/**
	 * Scan forward a day at a time for the next date a daily or weekly rule matches.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule   Rule being expanded.
	 * @param DateTimeImmutable $cursor Date-only cursor to scan from.
	 * @param DateTimeImmutable $anchor Series anchor date.
	 *
	 * @return DateTimeImmutable|null The next matching date, or null when the window is exhausted.
	 */
	protected function next_scanned_date(
		Rule $rule,
		DateTimeImmutable $cursor,
		DateTimeImmutable $anchor
	): ?DateTimeImmutable {
		$limit = $this->day_scan_limit( $rule );
		$date  = $cursor;
		$found = null;

		for ( $step = 0; $step <= $limit; $step++ ) {
			if ( $this->matches( $rule, $date, $anchor ) ) {
				$found = $date;
				break;
			}

			$date = $date->modify( '+1 day' );
		}

		return $found;
	}

	/**
	 * Walk months rather than days for the next date a monthly rule matches.
	 *
	 * A day-by-day scan cannot serve a monthly rule: a day-of-month rule on the
	 * 31st skips five months a year, and a February 29th rule can wait years.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule   Rule being expanded.
	 * @param DateTimeImmutable $cursor Date-only cursor to walk from.
	 * @param DateTimeImmutable $anchor Series anchor date.
	 *
	 * @return DateTimeImmutable|null The next matching date, or null when no month in the window resolves one.
	 */
	protected function next_monthly_date(
		Rule $rule,
		DateTimeImmutable $cursor,
		DateTimeImmutable $anchor
	): ?DateTimeImmutable {
		$step  = $rule->interval();
		$start = max( 0, (int) ceil( $this->months_apart( $anchor, $cursor ) / $step ) ) * $step;
		$found = null;

		for ( $offset = $start; $offset <= $start + self::MONTH_SCAN_STEPS; $offset += $step ) {
			$date = $this->monthly_date_for_offset( $rule, $anchor, $offset );

			if ( null !== $date && $date >= $cursor ) {
				$found = $date;
				break;
			}
		}

		return $found;
	}

	/**
	 * Resolve the date a monthly rule falls on a given number of months from the anchor.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule   Rule being expanded.
	 * @param DateTimeImmutable $anchor Series anchor date.
	 * @param int               $offset Whole months from the anchor's month.
	 *
	 * @return DateTimeImmutable|null The date, or null when that month has no such date.
	 */
	protected function monthly_date_for_offset(
		Rule $rule,
		DateTimeImmutable $anchor,
		int $offset
	): ?DateTimeImmutable {
		$months = (int) $anchor->format( 'Y' ) * 12 + (int) $anchor->format( 'n' ) - 1 + $offset;
		$year   = intdiv( $months, 12 );
		$month  = $months % 12 + 1;

		$date = Rule::MONTHLY_MODE_NTH_WEEKDAY === $rule->monthly_mode()
			? $this->nth_weekday_of_month( $year, $month, $rule->monthly_weekday(), $rule->monthly_ordinal() )
			: $this->day_of_month_date( $year, $month, $rule->monthly_day() );

		return null === $date ? null : new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Resolve a day number within a month, without rolling a missing day forward.
	 *
	 * PRD F-1: the 31st of a thirty-day month is not an occurrence, and is never
	 * the 1st of the month after.
	 *
	 * @since 0.36.0
	 *
	 * @param int $year  Four-digit year.
	 * @param int $month Month number, 1 through 12.
	 * @param int $day   Day number, 1 through 31.
	 *
	 * @return string|null A `Y-m-d` date, or null when the month has no such day.
	 */
	protected function day_of_month_date( int $year, int $month, int $day ): ?string {
		return checkdate( $month, $day, $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : null;
	}

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
		return match ( $rule->frequency() ) {
			Rule::FREQUENCY_DAILY   => $this->matches_daily( $rule, $candidate, $anchor ),
			Rule::FREQUENCY_WEEKLY  => $this->matches_weekly( $rule, $candidate, $anchor ),
			Rule::FREQUENCY_MONTHLY => $this->matches_monthly( $rule, $candidate, $anchor ),
			// An unrecognized frequency matches nothing rather than fataling.
			default                 => false,
		};
	}

	/**
	 * Report whether a candidate date is an interval multiple of days from the anchor.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule      Rule being expanded.
	 * @param DateTimeImmutable $candidate Candidate date under test.
	 * @param DateTimeImmutable $anchor    Series anchor date.
	 *
	 * @return bool True when the candidate is an occurrence date.
	 */
	protected function matches_daily( Rule $rule, DateTimeImmutable $candidate, DateTimeImmutable $anchor ): bool {
		return $candidate >= $anchor
			&& 0 === (int) $anchor->diff( $candidate )->days % $rule->interval();
	}

	/**
	 * Report whether a candidate date is on a selected weekday in an on-interval week.
	 *
	 * The interval is counted in Monday-start week buckets (PRD D-8), not in
	 * seven-day deltas from the anchor: a Thursday anchor sits late in its own
	 * week, and a day-delta walk lands the next occurrence in the wrong bucket.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule      Rule being expanded.
	 * @param DateTimeImmutable $candidate Candidate date under test.
	 * @param DateTimeImmutable $anchor    Series anchor date.
	 *
	 * @return bool True when the candidate is an occurrence date.
	 */
	protected function matches_weekly( Rule $rule, DateTimeImmutable $candidate, DateTimeImmutable $anchor ): bool {
		$weeks_apart = $this->week_index( $candidate ) - $this->week_index( $anchor );

		return in_array( (int) $candidate->format( 'w' ), $rule->weekdays(), true )
			&& 0 === $weeks_apart % $rule->interval();
	}

	/**
	 * Report whether a candidate date is the rule's date in an on-interval month.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule      Rule being expanded.
	 * @param DateTimeImmutable $candidate Candidate date under test.
	 * @param DateTimeImmutable $anchor    Series anchor date.
	 *
	 * @return bool True when the candidate is an occurrence date.
	 */
	protected function matches_monthly( Rule $rule, DateTimeImmutable $candidate, DateTimeImmutable $anchor ): bool {
		$offset = $this->months_apart( $anchor, $candidate );
		$date   = ( $offset >= 0 && 0 === $offset % $rule->interval() )
			? $this->monthly_date_for_offset( $rule, $anchor, $offset )
			: null;

		return null !== $date && $date->format( 'Y-m-d' ) === $candidate->format( 'Y-m-d' );
	}

	/**
	 * Get the signed number of whole months between two dates.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $from Date to measure from.
	 * @param DateTimeImmutable $to   Date to measure to.
	 *
	 * @return int Months, negative when the second date is earlier.
	 */
	protected function months_apart( DateTimeImmutable $from, DateTimeImmutable $to ): int {
		return ( (int) $to->format( 'Y' ) * 12 + (int) $to->format( 'n' ) )
			- ( (int) $from->format( 'Y' ) * 12 + (int) $from->format( 'n' ) );
	}

	/**
	 * Get how many days forward the day-by-day scan looks before giving up.
	 *
	 * The widest legal gap between consecutive dates, so an unsatisfiable rule —
	 * a weekly rule with no weekdays selected — terminates in one window rather
	 * than running to `MAX_ITERATIONS`.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule $rule Rule being expanded.
	 *
	 * @return int Days to scan, or 0 for a frequency the scan does not serve.
	 */
	protected function day_scan_limit( Rule $rule ): int {
		return match ( $rule->frequency() ) {
			Rule::FREQUENCY_DAILY  => $rule->interval(),
			Rule::FREQUENCY_WEEKLY => $rule->interval() * 7 + 7,
			// Monthly walks months, and an unrecognized frequency scans nothing.
			default                => 0,
		};
	}

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
	 */
	protected function nth_weekday_of_month( int $year, int $month, int $weekday, int $ordinal ): ?string {
		$first = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), new DateTimeZone( 'UTC' ) );
		$span  = (int) $first->format( 't' );

		if ( -1 === $ordinal ) {
			$last = (int) $first->modify( 'last day of this month' )->format( 'w' );
			$day  = $span - ( ( $last - $weekday + 7 ) % 7 );
		} else {
			$lead = (int) $first->format( 'w' );
			$day  = 1 + ( ( $weekday - $lead + 7 ) % 7 ) + ( $ordinal - 1 ) * 7;
		}

		return ( $day >= 1 && $day <= $span ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : null;
	}

	/**
	 * Apply a wall-clock time to a date in the series timezone.
	 *
	 * PHP normalizes a nonexistent local time forward — 02:30 on a spring-forward
	 * date becomes 03:30 — so the detection is a round trip: format the result
	 * back out and compare it to the time that went in.
	 *
	 * @since 0.36.0
	 *
	 * @param string       $date     Date in `Y-m-d` form.
	 * @param string       $time     Wall-clock time in `H:i:s` form.
	 * @param DateTimeZone $timezone Series timezone.
	 *
	 * @return DateTimeImmutable|null The datetime, or null when the local time does not exist.
	 */
	protected function materialize( string $date, string $time, DateTimeZone $timezone ): ?DateTimeImmutable {
		$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . $time, $timezone );

		return ( false === $datetime || $datetime->format( 'H:i:s' ) !== $time ) ? null : $datetime;
	}

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
		$monday = $date->modify( 'monday this week' );

		return (int) floor( $monday->getTimestamp() / ( 7 * DAY_IN_SECONDS ) );
	}

	/**
	 * Reduce a datetime to its date, held in UTC.
	 *
	 * The walk runs on UTC midnights so that stepping a day, bucketing a week,
	 * and comparing two candidates are all free of daylight saving arithmetic.
	 * The series timezone is reapplied in `materialize()` and nowhere else.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $datetime Datetime to reduce.
	 *
	 * @return DateTimeImmutable Midnight UTC on the datetime's own local date.
	 */
	protected function date_only( DateTimeImmutable $datetime ): DateTimeImmutable {
		return new DateTimeImmutable( $datetime->format( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Report whether a candidate date falls after the rule's end date.
	 *
	 * `Rule::until()` is null unless the rule ends on a date, so this needs no
	 * separate end-type test. The comparison is by date, because `UNTIL` is
	 * inclusive of its own day whatever time of day the occurrence carries.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule      Rule being expanded.
	 * @param DateTimeImmutable $candidate Candidate date under test.
	 *
	 * @return bool True when the candidate is past the end date.
	 */
	protected function past_until( Rule $rule, DateTimeImmutable $candidate ): bool {
		$until = $rule->until();

		return null !== $until && $candidate->format( 'Y-m-d' ) > $until->format( 'Y-m-d' );
	}
}
