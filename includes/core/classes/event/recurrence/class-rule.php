<?php
/**
 * Value object describing one recurrence rule.
 *
 * One rule per event, permanently. The rule is stored as decomposed
 * post meta, namely a single writable `gatherpress_recurrence` JSON blob plus
 * ten derived read-only mirrors. It is never stored as a serialized RFC 5545
 * string and never in a table of its own. `Rule::from_post()` reconstructs the value object from
 * the mirrors, which is what keeps the blob a pure write-boundary artifact.
 *
 * `to_rrule_string()` is the calendar-export seam. It exists and is unit-tested
 * from day one, and has no production caller yet.
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
	 * Frequency: repeats every N years, on the series start's month and day.
	 *
	 * Carries no companion fields of its own. `BYMONTH`, `BYYEARDAY` and
	 * `BYWEEKNO` are permanent non-goals, so yearly has
	 * no mode switch to go with it. The month and the day come from the
	 * series anchor, and the expander reads them there.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const FREQUENCY_YEARLY = 'yearly';

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
	 * Week start, Monday. The RFC 5545 default. Not configurable.
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

	/**
	 * RFC 5545 two-letter weekday codes, indexed 0 (Sunday) through 6 (Saturday).
	 *
	 * Shared with `Meta`, which uses it to translate the single comma-joined
	 * `gatherpress_recurrence_byday` mirror to and from the integer weekday
	 * numbers this class works with internally.
	 *
	 * @since 0.36.0
	 * @var string[]
	 */
	const WEEKDAY_CODES = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );

	/**
	 * Approximate days per occurrence, keyed by frequency, used only for the
	 * `COUNT` iteration-budget check at the meta boundary.
	 *
	 * Deliberately kept in step with `Expander::iteration_budget()`'s own
	 * `match ( $rule->frequency() ) { DAILY => 1, WEEKLY => 7, YEARLY => 366,
	 * default => 31 }`. Both sides land on `count * per_frequency * interval
	 * + 366`. This duplicates the arithmetic rather than sharing it, because `Expander` is
	 * a different task's file and `iteration_budget()` is a `protected`
	 * instance method taking an anchor and horizon this validation-time check
	 * has neither of; a rejection here must be at least as strict as the
	 * expander's own budget, never looser, or an accepted rule could still be
	 * silently truncated at expansion. If the two constants drift, fix this
	 * one to match `Expander`'s, not the other way around: `Expander::MAX_ITERATIONS`
	 * is the actual backstop, and this check exists only to reject before that
	 * backstop is ever reached.
	 *
	 * @since 0.36.0
	 * @var array<string, int>
	 */
	const BUDGET_DAYS_PER_FREQUENCY = array(
		self::FREQUENCY_DAILY   => 1,
		self::FREQUENCY_WEEKLY  => 7,
		self::FREQUENCY_MONTHLY => 31,
		self::FREQUENCY_YEARLY  => 366,
	);

	/**
	 * Class constructor.
	 *
	 * Private: rules are only ever built through `from_array()`, so every
	 * instance that exists has already passed boundary validation. Constructor
	 * property promotion is used deliberately here (rather than the usual house
	 * style of separate property declarations). With ten scalar properties
	 * and no logic of its own, a promoted constructor has no assignment
	 * statements of its own for a coverage tool to under-report.
	 *
	 * @since 0.36.0
	 *
	 * @param string                 $frequency       One of the `FREQUENCY_*` constants.
	 * @param int                    $interval        Repeat interval.
	 * @param int[]                  $weekdays        Weekday numbers for a weekly rule.
	 * @param string                 $monthly_mode    One of the `MONTHLY_MODE_*` constants.
	 * @param int                    $monthly_day     Day of the month for a day-of-month rule.
	 * @param int                    $monthly_ordinal Ordinal for an nth-weekday rule.
	 * @param int                    $monthly_weekday Weekday for an nth-weekday rule.
	 * @param string                 $end_type        One of the `END_TYPE_*` constants.
	 * @param DateTimeImmutable|null $until           End date, when `end_type` is `END_TYPE_UNTIL`.
	 * @param int                    $count           Occurrence count, when `end_type` is `END_TYPE_COUNT`.
	 */
	private function __construct(
		private string $frequency,
		private int $interval,
		private array $weekdays,
		private string $monthly_mode,
		private int $monthly_day,
		private int $monthly_ordinal,
		private int $monthly_weekday,
		private string $end_type,
		private ?DateTimeImmutable $until,
		private int $count
	) {
	}

	/**
	 * Reconstruct a rule from a post's derived recurrence mirrors.
	 *
	 * Reads the ten `gatherpress_recurrence_*` mirrors directly, never the
	 * `gatherpress_recurrence` JSON blob, so the blob stays a pure
	 * write-boundary artifact. `get_post_meta()` is served from WordPress's
	 * per-request meta cache, so this never issues a database round trip once
	 * the post's meta has been primed.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to read the recurrence mirrors from.
	 *
	 * @return Rule|null The rule, or null when the post carries no recurrence.
	 */
	public static function from_post( int $post_id ): ?Rule {
		$frequency = (string) get_post_meta( $post_id, 'gatherpress_recurrence_frequency', true );

		if ( '' === $frequency ) {
			return null;
		}

		$byday    = (string) get_post_meta( $post_id, 'gatherpress_recurrence_byday', true );
		$weekdays = array();

		if ( '' !== $byday ) {
			foreach ( explode( ',', $byday ) as $code ) {
				$index = array_search( $code, self::WEEKDAY_CODES, true );

				if ( false !== $index ) {
					$weekdays[] = $index;
				}
			}
		}

		$until = (string) get_post_meta( $post_id, 'gatherpress_recurrence_until', true );

		return self::from_array(
			array(
				'frequency'       => $frequency,
				'interval'        => (int) get_post_meta( $post_id, 'gatherpress_recurrence_interval', true ),
				'weekdays'        => $weekdays,
				'monthly_mode'    => (string) get_post_meta( $post_id, 'gatherpress_recurrence_monthly_mode', true ),
				'monthly_day'     => (int) get_post_meta( $post_id, 'gatherpress_recurrence_monthly_day', true ),
				'monthly_ordinal' => (int) get_post_meta( $post_id, 'gatherpress_recurrence_monthly_ordinal', true ),
				'monthly_weekday' => (int) get_post_meta( $post_id, 'gatherpress_recurrence_monthly_weekday', true ),
				'end_type'        => (string) get_post_meta( $post_id, 'gatherpress_recurrence_end_type', true ),
				'until'           => $until,
				'count'           => (int) get_post_meta( $post_id, 'gatherpress_recurrence_count', true ),
			)
		);
	}

	/**
	 * Build a rule from a decoded value array.
	 *
	 * Coerces and clamps only values that have a safe, unambiguous coercion (a
	 * sub-one integer interval becomes 1) and rejects, by returning null,
	 * anything else: a non-integer value in an integer field (via
	 * `to_int()`, so `'not-a-number'` never becomes interval 1 and
	 * `'not-a-weekday'` never becomes Sunday), a nonempty `until` that is not
	 * a canonical `Y-m-d` date, anything that cannot be honestly expanded, and
	 * anything that violates RFC 5545 (`UNTIL` and `COUNT` both present,
	 * `COUNT` or `INTERVAL` above their authoring caps, or a `COUNT` rule
	 * whose worst-case iteration budget would exceed
	 * `Expander::MAX_ITERATIONS`). The `UNTIL`/`COUNT` mutual exclusion runs
	 * against the raw field's presence, never against its parse result, so a
	 * malformed `until` cannot slip a `COUNT` rule past the check by failing
	 * to parse.
	 *
	 * @since 0.36.0
	 *
	 * @param array $values Decoded recurrence values.
	 *
	 * @return Rule|null The rule, or null when the values do not describe one.
	 */
	public static function from_array( array $values ): ?Rule {
		$valid = true;

		$integer_defaults = array(
			'interval'        => 1,
			'monthly_day'     => 0,
			'monthly_ordinal' => 0,
			'monthly_weekday' => 0,
			'count'           => 0,
		);
		$integers         = array();

		foreach ( $integer_defaults as $field => $default ) {
			$integer            = self::to_int( $values[ $field ] ?? $default );
			$valid              = $valid && null !== $integer;
			$integers[ $field ] = $integer ?? 0;
		}

		$weekdays = array();

		foreach ( is_array( $values['weekdays'] ?? null ) ? $values['weekdays'] : array() as $weekday ) {
			$weekday_int = self::to_int( $weekday );
			$valid       = $valid && null !== $weekday_int;
			$weekdays[]  = $weekday_int ?? 0;
		}

		$weekdays = array_values( array_unique( $weekdays ) );
		sort( $weekdays );

		// The null coalesce maps both an absent key and an explicit null to
		// '', so nonempty here means the client actually sent a value.
		$raw_until = $values['until'] ?? '';
		$has_until = '' !== $raw_until;
		$until     = null;

		if ( is_string( $raw_until ) && '' !== $raw_until ) {
			// Strict `Y-m-d` parsing, matching the format `to_array()` and
			// `write_mirrors()` emit. `date_create_immutable()` would also
			// accept relative strings like 'tomorrow' or '+1 year' (an end
			// date that depends on when it was saved) and silently roll an
			// invalid calendar date like '2026-02-31' over into March. The
			// leading `!` resets every field the format does not name to the
			// Unix epoch, rather than to the current moment.
			$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw_until );
			$errors = DateTimeImmutable::getLastErrors();
			$clean  = ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] );

			$until = ( false !== $parsed && $clean ) ? $parsed : null;
		}

		// A nonempty `until` that did not parse, whether malformed or not a
		// string at all, is rejected rather than erased. RFC 5545 also forbids
		// a rule carrying both an end date and an occurrence count; that
		// exclusion is checked on the raw field's presence so an unparseable
		// `until` cannot evade it.
		if ( ! $valid
			|| ( $has_until && null === $until )
			|| ( $has_until && $integers['count'] > 0 )
		) {
			return null;
		}

		$rule = new self(
			(string) ( $values['frequency'] ?? '' ),
			$integers['interval'] < 1 ? 1 : $integers['interval'],
			$weekdays,
			(string) ( $values['monthly_mode'] ?? '' ),
			$integers['monthly_day'],
			$integers['monthly_ordinal'],
			$integers['monthly_weekday'],
			(string) ( $values['end_type'] ?? '' ),
			$until,
			$integers['count']
		);

		return $rule->is_valid() ? $rule : null;
	}

	/**
	 * Read a decoded scalar as an integer, or null when it is not one.
	 *
	 * Accepts a real integer or a complete-match canonical integer string
	 * (`'3'`, `'-1'`), the two forms a JSON body and a form serialization
	 * legitimately produce for the same field. Everything else, including
	 * booleans, floats, zero-padded strings, and arbitrary words, returns
	 * null so the caller rejects the value instead of `intval()` silently
	 * turning it into a different schedule.
	 *
	 * @since 0.36.0
	 *
	 * @param mixed $value Decoded value to read.
	 *
	 * @return int|null The integer, or null when the value is not an integer.
	 */
	private static function to_int( $value ): ?int {
		$result = null;

		if ( is_int( $value ) ) {
			$result = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^-?(?:0|[1-9][0-9]*)$/', $value ) ) {
			$result = (int) $value;
		}

		return $result;
	}

	/**
	 * Get the repeat frequency.
	 *
	 * @since 0.36.0
	 *
	 * @return string One of the `FREQUENCY_*` constants.
	 */
	public function frequency(): string {
		return $this->frequency;
	}

	/**
	 * Get the repeat interval, clamped to at least one.
	 *
	 * @since 0.36.0
	 *
	 * @return int The interval, never below 1 and never above `MAX_INTERVAL`.
	 */
	public function interval(): int {
		return $this->interval;
	}

	/**
	 * Get the weekdays a weekly rule repeats on.
	 *
	 * @since 0.36.0
	 *
	 * @return int[] Weekday numbers, 0 for Sunday through 6 for Saturday.
	 */
	public function weekdays(): array {
		return $this->weekdays;
	}

	/**
	 * Get the monthly repeat mode.
	 *
	 * @since 0.36.0
	 *
	 * @return string One of the `MONTHLY_MODE_*` constants.
	 */
	public function monthly_mode(): string {
		return $this->monthly_mode;
	}

	/**
	 * Get the day of the month a day-of-month rule repeats on.
	 *
	 * @since 0.36.0
	 *
	 * @return int A day number from 1 to 31.
	 */
	public function monthly_day(): int {
		return $this->monthly_day;
	}

	/**
	 * Get the ordinal an nth-weekday rule repeats on.
	 *
	 * @since 0.36.0
	 *
	 * @return int An ordinal from 1 to 4, or -1 for "last".
	 */
	public function monthly_ordinal(): int {
		return $this->monthly_ordinal;
	}

	/**
	 * Get the weekday an nth-weekday rule repeats on.
	 *
	 * @since 0.36.0
	 *
	 * @return int A weekday number from 0 for Sunday through 6 for Saturday.
	 */
	public function monthly_weekday(): int {
		return $this->monthly_weekday;
	}

	/**
	 * Get how the series ends.
	 *
	 * @since 0.36.0
	 *
	 * @return string One of the `END_TYPE_*` constants.
	 */
	public function end_type(): string {
		return $this->end_type;
	}

	/**
	 * Get the date the series ends on.
	 *
	 * @since 0.36.0
	 *
	 * @return DateTimeImmutable|null The end date, or null when the rule does not end on a date.
	 */
	public function until(): ?DateTimeImmutable {
		return $this->until;
	}

	/**
	 * Get how many occurrences the series produces.
	 *
	 * @since 0.36.0
	 *
	 * @return int The count, never above `MAX_COUNT`, or 0 when the rule is not count-bounded.
	 */
	public function count(): int {
		return $this->count;
	}

	/**
	 * Report whether the rule describes an expandable schedule.
	 *
	 * Checked once inside `from_array()` before a `Rule` is ever handed back
	 * to a caller, so every `Rule` in existence is already valid. This
	 * method is what `from_array()` uses to decide, and remains public so a
	 * caller holding a `Rule` can re-confirm the same contract.
	 *
	 * @since 0.36.0
	 *
	 * @return bool True when the rule is complete and internally consistent.
	 */
	public function is_valid(): bool {
		$valid_frequencies = array(
			self::FREQUENCY_DAILY,
			self::FREQUENCY_WEEKLY,
			self::FREQUENCY_MONTHLY,
			self::FREQUENCY_YEARLY,
		);
		$valid_end_types   = array( self::END_TYPE_NEVER, self::END_TYPE_UNTIL, self::END_TYPE_COUNT );

		// A weekly rule needs at least one weekday, and every weekday it names
		// must be a real day, 0 (Sunday) through 6 (Saturday). An unchecked
		// out-of-range value (e.g. 7, or -1) would otherwise leave an
		// undefined WEEKDAY_CODES lookup at write-mirror and RRULE-export time.
		$weekly_weekdays_valid = self::FREQUENCY_WEEKLY !== $this->frequency
			|| ( array() !== $this->weekdays && array() === array_diff( $this->weekdays, range( 0, 6 ) ) );

		// Guard every top-level shape requirement in one place: a recognized
		// frequency and end type, an interval within bounds, and a weekly
		// rule's weekdays all within range.
		if ( ! in_array( $this->frequency, $valid_frequencies, true )
			|| $this->interval < 1
			|| $this->interval > self::MAX_INTERVAL
			|| ! in_array( $this->end_type, $valid_end_types, true )
			|| ! $weekly_weekdays_valid
		) {
			return false;
		}

		if ( self::FREQUENCY_MONTHLY === $this->frequency && ! $this->is_valid_monthly_shape() ) {
			return false;
		}

		return $this->is_valid_end_shape();
	}

	/**
	 * Report whether the monthly-specific fields are internally consistent.
	 *
	 * @since 0.36.0
	 *
	 * @return bool True when the monthly mode and its companion fields agree.
	 */
	private function is_valid_monthly_shape(): bool {
		if ( self::MONTHLY_MODE_DAY_OF_MONTH === $this->monthly_mode ) {
			return $this->monthly_day >= 1 && $this->monthly_day <= 31;
		}

		if ( self::MONTHLY_MODE_NTH_WEEKDAY === $this->monthly_mode ) {
			$valid_ordinals = array( 1, 2, 3, 4, -1 );

			return in_array( $this->monthly_ordinal, $valid_ordinals, true )
				&& $this->monthly_weekday >= 0
				&& $this->monthly_weekday <= 6;
		}

		return false;
	}

	/**
	 * Report whether the end-of-series fields are internally consistent,
	 * including the `COUNT` iteration-budget backstop.
	 *
	 * @since 0.36.0
	 *
	 * @return bool True when the end shape is consistent and, for a `COUNT`
	 *              rule, honestly expandable within `Expander::MAX_ITERATIONS`.
	 */
	private function is_valid_end_shape(): bool {
		if ( self::END_TYPE_UNTIL === $this->end_type ) {
			return null !== $this->until && 0 === $this->count;
		}

		if ( self::END_TYPE_COUNT === $this->end_type ) {
			if ( $this->count < 1 || $this->count > self::MAX_COUNT || null !== $this->until ) {
				return false;
			}

			// Matches Expander::iteration_budget()'s own `match` fallback of 31
			// (monthly-shaped) for anything outside DAILY/WEEKLY. It is
			// unreachable through from_array() today, since is_valid() rejects an
			// unrecognized frequency before this runs, but kept aligned so a
			// future frequency added to one side is never looser on this one.
			$per_frequency = self::BUDGET_DAYS_PER_FREQUENCY[ $this->frequency ]
				?? self::BUDGET_DAYS_PER_FREQUENCY[ self::FREQUENCY_MONTHLY ];
			$budget        = ( $this->count * $per_frequency * $this->interval ) + 366;

			return $budget <= Expander::MAX_ITERATIONS;
		}

		// END_TYPE_NEVER: neither an end date nor a count may be set.
		return null === $this->until && 0 === $this->count;
	}

	/**
	 * Convert the rule back to its decomposed value array.
	 *
	 * @since 0.36.0
	 *
	 * @return array The rule's values, keyed as the `gatherpress_recurrence` blob is.
	 */
	public function to_array(): array {
		return array(
			'frequency'       => $this->frequency,
			'interval'        => $this->interval,
			'weekdays'        => $this->weekdays,
			'monthly_mode'    => $this->monthly_mode,
			'monthly_day'     => $this->monthly_day,
			'monthly_ordinal' => $this->monthly_ordinal,
			'monthly_weekday' => $this->monthly_weekday,
			'end_type'        => $this->end_type,
			'until'           => $this->until?->format( 'Y-m-d' ) ?? '',
			'count'           => $this->count,
		);
	}

	/**
	 * Serialize the rule as an RFC 5545 `RRULE` string.
	 *
	 * The calendar-export seam. Unit-tested against a fixture table, with no
	 * production caller yet. `WKST` is never emitted because
	 * `WEEK_START` (Monday) is already RFC 5545's default. `UNTIL` is emitted
	 * as a bare `Ymd` date. The rule carries no time-of-day of its own, so
	 * that comes from the series anchor at expansion time.
	 *
	 * The `FREQ=` value is the uppercased frequency, so a yearly rule needs no
	 * arm of its own here. It emits no `BY*` part at all: RFC 5545 defaults the
	 * month and month-day of a `FREQ=YEARLY` rule to the `DTSTART`'s, which is
	 * exactly the intended behavior, and `BYMONTH` is a permanent non-goal.
	 *
	 * @since 0.36.0
	 *
	 * @return string The `RRULE` value, without the property name.
	 */
	public function to_rrule_string(): string {
		$parts = array( 'FREQ=' . strtoupper( $this->frequency ) );

		if ( $this->interval > 1 ) {
			$parts[] = 'INTERVAL=' . $this->interval;
		}

		if ( self::FREQUENCY_WEEKLY === $this->frequency ) {
			$codes   = array_map(
				fn( int $weekday ) => self::WEEKDAY_CODES[ $weekday ],
				$this->weekdays
			);
			$parts[] = 'BYDAY=' . implode( ',', $codes );
		} elseif ( self::FREQUENCY_MONTHLY === $this->frequency ) {
			$parts[] = self::MONTHLY_MODE_DAY_OF_MONTH === $this->monthly_mode
				? 'BYMONTHDAY=' . $this->monthly_day
				: 'BYDAY=' . $this->monthly_ordinal . self::WEEKDAY_CODES[ $this->monthly_weekday ];
		}

		if ( self::END_TYPE_UNTIL === $this->end_type && $this->until instanceof DateTimeImmutable ) {
			$parts[] = 'UNTIL=' . $this->until->format( 'Ymd' );
		} elseif ( self::END_TYPE_COUNT === $this->end_type ) {
			$parts[] = 'COUNT=' . $this->count;
		}

		return implode( ';', $parts );
	}
}
