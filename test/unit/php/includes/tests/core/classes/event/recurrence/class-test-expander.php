<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Expander.
 *
 * Covers the calendar arithmetic: weekly week-bucket intervals, both monthly
 * modes, the `COUNT` budget, the horizon, and the derived iteration budget. The
 * daylight saving cases live in `class-test-expander-dst.php`.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Event\Recurrence\Expander;
use GatherPress\Core\Event\Recurrence\Rule;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;

/**
 * Class Test_Expander.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Expander
 */
class Test_Expander extends Base {

	/**
	 * Shared occurrence fixtures.
	 */
	use Occurrence_Fixtures;

	/**
	 * Build a rule, skipping the test while `Rule` is still a frozen skeleton.
	 *
	 * `class-rule.php` belongs to another lane. Until its implementation lands,
	 * `Rule::from_array()` returns null and no expander test can run.
	 *
	 * @since 0.36.0
	 *
	 * @param array $values Decoded recurrence values.
	 *
	 * @return Rule The rule.
	 */
	protected function make_rule( array $values ): Rule {
		$rule = Rule::from_array( $values );

		if ( ! $rule instanceof Rule ) {
			$this->markTestSkipped( 'Rule::from_array() has no implementation yet; it belongs to another lane.' );
		}

		return $rule;
	}

	/**
	 * Get the reference bi-weekly Tuesday/Thursday rule values.
	 *
	 * @since 0.36.0
	 *
	 * @return array The rule values.
	 */
	protected function reference_rule_values(): array {
		return array(
			'frequency' => Rule::FREQUENCY_WEEKLY,
			'interval'  => 2,
			'weekdays'  => array( 2, 4 ),
			'end_type'  => Rule::END_TYPE_COUNT,
			'count'     => 5,
		);
	}

	/**
	 * Get the series anchor for the reference rule.
	 *
	 * @since 0.36.0
	 *
	 * @return DateTimeImmutable Thursday 2026-09-03 18:00 in America/New_York.
	 */
	protected function reference_anchor(): DateTimeImmutable {
		return new DateTimeImmutable( $this->reference_anchor_start, new DateTimeZone( 'America/New_York' ) );
	}

	/**
	 * Reduce an expansion to a list of `Y-m-d H:i:s` local datetimes.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable[] $occurrences Expanded occurrences.
	 *
	 * @return string[] Local datetimes.
	 */
	protected function to_local_strings( array $occurrences ): array {
		return array_map(
			static function ( DateTimeImmutable $occurrence ): string {
				return $occurrence->format( 'Y-m-d H:i:s' );
			},
			$occurrences
		);
	}

	/**
	 * A bi-weekly Tuesday/Thursday rule bounded by a date yields the reference set.
	 *
	 * @covers ::expand
	 * @covers ::next_candidate_date
	 * @covers ::next_scanned_date
	 * @covers ::matches
	 * @covers ::matches_weekly
	 * @covers ::week_index
	 * @covers ::materialize
	 * @covers ::past_until
	 * @covers ::iteration_budget
	 * @covers ::day_scan_limit
	 * @covers ::date_only
	 *
	 * @return void
	 */
	public function test_weekly_interval_two_tuesday_thursday_until(): void {
		$values             = $this->reference_rule_values();
		$values['end_type'] = Rule::END_TYPE_UNTIL;
		$values['until']    = '2026-10-01';
		unset( $values['count'] );

		$timezone = new DateTimeZone( 'America/New_York' );
		$expected = array_column( $this->expected_weekly_set(), 'datetime_start' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule( $values ),
			$this->reference_anchor(),
			$timezone,
			new DateTimeImmutable( '2027-09-03 18:00:00', $timezone )
		);

		$this->assertSame(
			$expected,
			$this->to_local_strings( $occurrences ),
			'Failed to assert that the bi-weekly Tue/Thu rule yields the reference occurrence set.'
		);
	}

	/**
	 * A bi-weekly rule anchored on a Thursday skips the whole following week.
	 *
	 * The anchor falls late in its Monday-start week, which is the only shape
	 * that distinguishes week-bucket arithmetic from a seven-day delta.
	 *
	 * @covers ::expand
	 * @covers ::matches_weekly
	 * @covers ::week_index
	 *
	 * @return void
	 */
	public function test_biweekly_tuesday_thursday_anchored_on_thursday_skips_the_following_week(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule( $this->reference_rule_values() ),
			$this->reference_anchor(),
			$timezone,
			new DateTimeImmutable( '2027-09-03 18:00:00', $timezone )
		);

		$dates = array_map(
			static function ( DateTimeImmutable $occurrence ): string {
				return $occurrence->format( 'Y-m-d' );
			},
			$occurrences
		);

		$this->assertSame(
			array( '2026-09-03', '2026-09-15', '2026-09-17', '2026-09-29', '2026-10-01' ),
			$dates,
			'Failed to assert that the week following a Thursday anchor is empty and the week after yields both days.'
		);
		$this->assertNotContains(
			'2026-09-08',
			$dates,
			'Failed to assert that the Tuesday of the skipped week is absent.'
		);
		$this->assertNotContains(
			'2026-09-10',
			$dates,
			'Failed to assert that the Thursday of the skipped week is absent.'
		);
		$this->assertNotContains(
			'2026-09-01',
			$dates,
			'Failed to assert that the walk begins at the anchor rather than at the anchor week.'
		);
	}

	/**
	 * A day-of-month rule on the 15th yields twelve occurrences in a year.
	 *
	 * @covers ::expand
	 * @covers ::next_monthly_date
	 * @covers ::monthly_date_for_offset
	 * @covers ::day_of_month_date
	 * @covers ::months_apart
	 *
	 * @return void
	 */
	public function test_monthly_day_of_month_fifteenth_yields_twelve(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency'    => Rule::FREQUENCY_MONTHLY,
					'interval'     => 1,
					'monthly_mode' => Rule::MONTHLY_MODE_DAY_OF_MONTH,
					'monthly_day'  => 15,
					'end_type'     => Rule::END_TYPE_UNTIL,
					'until'        => '2026-12-31',
				)
			),
			new DateTimeImmutable( '2026-01-15 09:00:00', $timezone ),
			$timezone,
			new DateTimeImmutable( '2027-01-15 09:00:00', $timezone )
		);

		$this->assertCount(
			12,
			$occurrences,
			'Failed to assert that a day-of-month rule on the 15th yields twelve occurrences.'
		);
		$this->assertSame(
			'2026-12-15 09:00:00',
			end( $occurrences )->format( 'Y-m-d H:i:s' ),
			'Failed to assert that the final day-of-month occurrence falls in December.'
		);
	}

	/**
	 * A day-of-month rule on the 31st yields only the months that have a 31st.
	 *
	 * PRD F-1 conformance: the date is skipped, never rolled forward to the 1st.
	 *
	 * @covers ::expand
	 * @covers ::next_monthly_date
	 * @covers ::monthly_date_for_offset
	 * @covers ::day_of_month_date
	 *
	 * @return void
	 */
	public function test_monthly_day_of_month_thirty_first_yields_seven_not_twelve(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency'    => Rule::FREQUENCY_MONTHLY,
					'interval'     => 1,
					'monthly_mode' => Rule::MONTHLY_MODE_DAY_OF_MONTH,
					'monthly_day'  => 31,
					'end_type'     => Rule::END_TYPE_UNTIL,
					'until'        => '2026-12-31',
				)
			),
			new DateTimeImmutable( '2026-01-31 09:00:00', $timezone ),
			$timezone,
			new DateTimeImmutable( '2027-01-31 09:00:00', $timezone )
		);

		$this->assertSame(
			array(
				'2026-01-31',
				'2026-03-31',
				'2026-05-31',
				'2026-07-31',
				'2026-08-31',
				'2026-10-31',
				'2026-12-31',
			),
			array_map(
				static function ( DateTimeImmutable $occurrence ): string {
					return $occurrence->format( 'Y-m-d' );
				},
				$occurrences
			),
			'Failed to assert that the 31st yields seven occurrences and never rolls forward to the 1st.'
		);
	}

	/**
	 * A day-of-month rule on the 31st at a wide interval still delivers its whole count.
	 *
	 * The monthly walk steps by the interval, so a bound expressed in absolute
	 * months would examine `bound / interval` candidates and stop early. At
	 * interval 43 the gap between the fifth and sixth occurrence is 258 months —
	 * six candidate months, but more than two hundred calendar ones.
	 *
	 * @covers ::expand
	 * @covers ::next_monthly_date
	 *
	 * @return void
	 */
	public function test_monthly_day_of_month_thirty_first_at_interval_forty_three_yields_six(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency'    => Rule::FREQUENCY_MONTHLY,
					'interval'     => 43,
					'monthly_mode' => Rule::MONTHLY_MODE_DAY_OF_MONTH,
					'monthly_day'  => 31,
					'end_type'     => Rule::END_TYPE_COUNT,
					'count'        => 6,
				)
			),
			new DateTimeImmutable( '2024-03-31 09:00:00', $timezone ),
			$timezone,
			new DateTimeImmutable( '2025-03-31 09:00:00', $timezone )
		);

		$this->assertSame(
			array( '2024-03-31', '2027-10-31', '2031-05-31', '2034-12-31', '2038-07-31', '2060-01-31' ),
			array_map(
				static function ( DateTimeImmutable $occurrence ): string {
					return $occurrence->format( 'Y-m-d' );
				},
				$occurrences
			),
			'Failed to assert that a wide monthly interval delivers its whole count.'
		);
	}

	/**
	 * An anchor expressed in another timezone is read in the series timezone.
	 *
	 * The wall clock belongs to the series, so a UTC-expressed anchor an hour
	 * past midnight is a nine o'clock evening event in New York, not a one
	 * o'clock morning one.
	 *
	 * @covers ::expand
	 *
	 * @return void
	 */
	public function test_expand_normalizes_a_foreign_timezone_anchor_and_horizon(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$utc      = new DateTimeZone( 'UTC' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency' => Rule::FREQUENCY_DAILY,
					'interval'  => 1,
					'end_type'  => Rule::END_TYPE_NEVER,
				)
			),
			new DateTimeImmutable( '2026-09-04 01:00:00', $utc ),
			$timezone,
			new DateTimeImmutable( '2026-09-06 01:00:00', $utc )
		);

		$this->assertSame(
			array( '2026-09-03 21:00:00', '2026-09-04 21:00:00', '2026-09-05 21:00:00' ),
			$this->to_local_strings( $occurrences ),
			'Failed to assert that a UTC-expressed anchor and horizon are read in the series timezone.'
		);
	}

	/**
	 * An nth-weekday rule on the third Thursday tracks the weekday, not the date.
	 *
	 * @covers ::expand
	 * @covers ::monthly_date_for_offset
	 * @covers ::nth_weekday_of_month
	 *
	 * @return void
	 */
	public function test_monthly_nth_weekday_third_thursday(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency'       => Rule::FREQUENCY_MONTHLY,
					'interval'        => 1,
					'monthly_mode'    => Rule::MONTHLY_MODE_NTH_WEEKDAY,
					'monthly_ordinal' => 3,
					'monthly_weekday' => 4,
					'end_type'        => Rule::END_TYPE_UNTIL,
					'until'           => '2026-06-30',
				)
			),
			new DateTimeImmutable( '2026-01-15 19:00:00', $timezone ),
			$timezone,
			new DateTimeImmutable( '2027-01-15 19:00:00', $timezone )
		);

		$this->assertSame(
			array( '2026-01-15', '2026-02-19', '2026-03-19', '2026-04-16', '2026-05-21', '2026-06-18' ),
			array_map(
				static function ( DateTimeImmutable $occurrence ): string {
					return $occurrence->format( 'Y-m-d' );
				},
				$occurrences
			),
			'Failed to assert that the third Thursday is resolved per month.'
		);
	}

	/**
	 * A "last Wednesday" rule lands on the 4th in four-Wednesday months and the 5th in five-Wednesday months.
	 *
	 * January through March 2026 have four Wednesdays; April 2026 has five.
	 *
	 * @covers ::expand
	 * @covers ::nth_weekday_of_month
	 *
	 * @return void
	 */
	public function test_monthly_last_wednesday_across_four_and_five_wednesday_months(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency'       => Rule::FREQUENCY_MONTHLY,
					'interval'        => 1,
					'monthly_mode'    => Rule::MONTHLY_MODE_NTH_WEEKDAY,
					'monthly_ordinal' => -1,
					'monthly_weekday' => 3,
					'end_type'        => Rule::END_TYPE_UNTIL,
					'until'           => '2026-04-30',
				)
			),
			new DateTimeImmutable( '2026-01-28 19:00:00', $timezone ),
			$timezone,
			new DateTimeImmutable( '2027-01-28 19:00:00', $timezone )
		);

		$this->assertSame(
			array( '2026-01-28', '2026-02-25', '2026-03-25', '2026-04-29' ),
			array_map(
				static function ( DateTimeImmutable $occurrence ): string {
					return $occurrence->format( 'Y-m-d' );
				},
				$occurrences
			),
			'Failed to assert that "last Wednesday" tracks the final Wednesday of each month.'
		);
	}

	/**
	 * A count-bounded rule yields exactly the requested number of occurrences.
	 *
	 * @covers ::expand
	 *
	 * @return void
	 */
	public function test_count_yields_exactly_n(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule( $this->reference_rule_values() ),
			$this->reference_anchor(),
			$timezone,
			new DateTimeImmutable( '2027-09-03 18:00:00', $timezone )
		);

		$this->assertCount(
			5,
			$occurrences,
			'Failed to assert that a COUNT=5 rule yields exactly five occurrences.'
		);
		$this->assertSame(
			array_column( $this->expected_weekly_set(), 'datetime_start' ),
			$this->to_local_strings( $occurrences ),
			'Failed to assert that the COUNT=5 rule yields the reference occurrence set.'
		);
	}

	/**
	 * A rule that can never match terminates instead of running to the iteration cap.
	 *
	 * @covers ::expand
	 * @covers ::next_scanned_date
	 *
	 * @return void
	 */
	public function test_never_matching_rule_terminates_within_iteration_cap(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency' => Rule::FREQUENCY_WEEKLY,
					'interval'  => 1,
					'weekdays'  => array(),
					'end_type'  => Rule::END_TYPE_COUNT,
					'count'     => 5,
				)
			),
			$this->reference_anchor(),
			$timezone,
			new DateTimeImmutable( '2027-09-03 18:00:00', $timezone )
		);

		$this->assertSame(
			array(),
			$occurrences,
			'Failed to assert that a rule matching no weekday yields no occurrences.'
		);
	}

	/**
	 * An open-ended rule stops at the horizon.
	 *
	 * @covers ::expand
	 * @covers ::matches_daily
	 *
	 * @return void
	 */
	public function test_open_ended_rule_stops_at_horizon(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency' => Rule::FREQUENCY_DAILY,
					'interval'  => 1,
					'end_type'  => Rule::END_TYPE_NEVER,
				)
			),
			$this->reference_anchor(),
			$timezone,
			new DateTimeImmutable( '2026-09-10 18:00:00', $timezone )
		);

		$this->assertCount(
			8,
			$occurrences,
			'Failed to assert that an open-ended daily rule stops at the horizon.'
		);
		$this->assertSame(
			'2026-09-10 18:00:00',
			end( $occurrences )->format( 'Y-m-d H:i:s' ),
			'Failed to assert that the final occurrence falls on the horizon date.'
		);
	}

	/**
	 * A monthly `COUNT=500` rule yields five hundred occurrences, which a flat iteration cap truncates.
	 *
	 * @covers ::expand
	 * @covers ::iteration_budget
	 *
	 * @return void
	 */
	public function test_count_five_hundred_monthly_yields_exactly_five_hundred(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency'    => Rule::FREQUENCY_MONTHLY,
					'interval'     => 1,
					'monthly_mode' => Rule::MONTHLY_MODE_DAY_OF_MONTH,
					'monthly_day'  => 15,
					'end_type'     => Rule::END_TYPE_COUNT,
					'count'        => 500,
				)
			),
			new DateTimeImmutable( '2026-01-15 09:00:00', $timezone ),
			$timezone,
			new DateTimeImmutable( '2027-01-15 09:00:00', $timezone )
		);

		$this->assertCount(
			500,
			$occurrences,
			'Failed to assert that a monthly COUNT=500 rule yields five hundred occurrences.'
		);
		$this->assertSame(
			'2067-08-15',
			end( $occurrences )->format( 'Y-m-d' ),
			'Failed to assert that the five hundredth monthly occurrence is forty-one years out.'
		);
	}

	/**
	 * A weekly `COUNT=500` rule ignores a twelve-month horizon.
	 *
	 * @covers ::expand
	 * @covers ::iteration_budget
	 *
	 * @return void
	 */
	public function test_count_five_hundred_weekly_ignores_twelve_month_horizon(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$occurrences = ( new Expander() )->expand(
			$this->make_rule(
				array(
					'frequency' => Rule::FREQUENCY_WEEKLY,
					'interval'  => 1,
					'weekdays'  => array( 4 ),
					'end_type'  => Rule::END_TYPE_COUNT,
					'count'     => 500,
				)
			),
			$this->reference_anchor(),
			$timezone,
			new DateTimeImmutable( '2027-09-03 18:00:00', $timezone )
		);

		$this->assertCount(
			500,
			$occurrences,
			'Failed to assert that a weekly COUNT=500 rule ignores the horizon.'
		);
		$this->assertSame(
			'2036-03-27',
			end( $occurrences )->format( 'Y-m-d' ),
			'Failed to assert that the five hundredth weekly occurrence falls well beyond the horizon.'
		);
	}

	/**
	 * A non-count rule derives its budget from the horizon.
	 *
	 * @covers ::iteration_budget
	 *
	 * @return void
	 */
	public function test_iteration_budget_for_horizon_bounded_rule(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$values   = $this->reference_rule_values();

		$values['end_type'] = Rule::END_TYPE_NEVER;
		unset( $values['count'] );

		$this->assertSame(
			11,
			Utility::invoke_hidden_method(
				new Expander(),
				'iteration_budget',
				array(
					$this->make_rule( $values ),
					$this->reference_anchor(),
					new DateTimeImmutable( '2026-09-13 18:00:00', $timezone ),
				)
			),
			'Failed to assert that a horizon-bounded budget is the day span plus one.'
		);
	}

	/**
	 * A count-bounded rule derives a budget large enough for the whole count.
	 *
	 * @covers ::iteration_budget
	 *
	 * @return void
	 */
	public function test_iteration_budget_for_count_bounded_rule(): void {
		$timezone = new DateTimeZone( 'America/New_York' );

		$this->assertSame(
			366 + ( 5 * 7 * 2 ),
			Utility::invoke_hidden_method(
				new Expander(),
				'iteration_budget',
				array(
					$this->make_rule( $this->reference_rule_values() ),
					$this->reference_anchor(),
					new DateTimeImmutable( '2026-09-13 18:00:00', $timezone ),
				)
			),
			'Failed to assert that a count-bounded budget covers the whole count.'
		);
	}

	/**
	 * The budget is clamped to `MAX_ITERATIONS`.
	 *
	 * @covers ::iteration_budget
	 *
	 * @return void
	 */
	public function test_iteration_budget_is_clamped_to_max_iterations(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$values   = $this->reference_rule_values();

		$values['end_type'] = Rule::END_TYPE_NEVER;
		unset( $values['count'] );

		$this->assertSame(
			Expander::MAX_ITERATIONS,
			Utility::invoke_hidden_method(
				new Expander(),
				'iteration_budget',
				array(
					$this->make_rule( $values ),
					$this->reference_anchor(),
					new DateTimeImmutable( '4026-09-03 18:00:00', $timezone ),
				)
			),
			'Failed to assert that the derived budget is clamped to MAX_ITERATIONS.'
		);
	}

	/**
	 * Dispatching a daily rule returns the next daily date.
	 *
	 * @covers ::next_candidate_date
	 *
	 * @return void
	 */
	public function test_next_candidate_date_dispatches_daily(): void {
		$anchor = new DateTimeImmutable( '2026-09-03', new DateTimeZone( 'UTC' ) );
		$rule   = $this->make_rule(
			array(
				'frequency' => Rule::FREQUENCY_DAILY,
				'interval'  => 3,
				'end_type'  => Rule::END_TYPE_NEVER,
			)
		);

		$candidate = Utility::invoke_hidden_method(
			new Expander(),
			'next_candidate_date',
			array( $rule, $anchor->modify( '+1 day' ), $anchor )
		);

		$this->assertSame(
			'2026-09-06',
			$candidate->format( 'Y-m-d' ),
			'Failed to assert that a daily rule advances by its interval.'
		);
	}

	/**
	 * Dispatching a monthly rule returns the next monthly date.
	 *
	 * @covers ::next_candidate_date
	 * @covers ::next_monthly_date
	 *
	 * @return void
	 */
	public function test_next_candidate_date_dispatches_monthly(): void {
		$anchor = new DateTimeImmutable( '2026-01-15', new DateTimeZone( 'UTC' ) );
		$rule   = $this->make_rule(
			array(
				'frequency'    => Rule::FREQUENCY_MONTHLY,
				'interval'     => 2,
				'monthly_mode' => Rule::MONTHLY_MODE_DAY_OF_MONTH,
				'monthly_day'  => 15,
				'end_type'     => Rule::END_TYPE_NEVER,
			)
		);

		$candidate = Utility::invoke_hidden_method(
			new Expander(),
			'next_candidate_date',
			array( $rule, $anchor->modify( '+1 day' ), $anchor )
		);

		$this->assertSame(
			'2026-03-15',
			$candidate->format( 'Y-m-d' ),
			'Failed to assert that a monthly rule advances by its interval in months.'
		);
	}

	/**
	 * An unknown frequency yields no candidate rather than a fatal.
	 *
	 * @covers ::next_candidate_date
	 * @covers ::matches
	 * @covers ::day_scan_limit
	 *
	 * @return void
	 */
	public function test_next_candidate_date_returns_null_for_unknown_frequency(): void {
		$anchor = new DateTimeImmutable( '2026-09-03', new DateTimeZone( 'UTC' ) );
		$rule   = $this->make_rule(
			array(
				'frequency' => 'yearly',
				'interval'  => 1,
				'end_type'  => Rule::END_TYPE_NEVER,
			)
		);

		$expander = new Expander();

		$this->assertNull(
			Utility::invoke_hidden_method( $expander, 'next_candidate_date', array( $rule, $anchor, $anchor ) ),
			'Failed to assert that an unknown frequency yields no candidate.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method( $expander, 'matches', array( $rule, $anchor, $anchor ) ),
			'Failed to assert that an unknown frequency matches nothing.'
		);
		$this->assertSame(
			0,
			Utility::invoke_hidden_method( $expander, 'day_scan_limit', array( $rule ) ),
			'Failed to assert that an unknown frequency has no scan window.'
		);
	}

	/**
	 * The day scan returns null when nothing matches inside its window.
	 *
	 * @covers ::next_scanned_date
	 * @covers ::day_scan_limit
	 *
	 * @return void
	 */
	public function test_next_scanned_date_returns_null_when_window_is_exhausted(): void {
		$anchor = new DateTimeImmutable( '2026-09-03', new DateTimeZone( 'UTC' ) );
		$rule   = $this->make_rule(
			array(
				'frequency' => Rule::FREQUENCY_WEEKLY,
				'interval'  => 1,
				'weekdays'  => array(),
				'end_type'  => Rule::END_TYPE_NEVER,
			)
		);

		$this->assertNull(
			Utility::invoke_hidden_method(
				new Expander(),
				'next_scanned_date',
				array( $rule, $anchor, $anchor )
			),
			'Failed to assert that an unsatisfiable weekly rule exhausts its scan window.'
		);
		$this->assertSame(
			14,
			Utility::invoke_hidden_method( new Expander(), 'day_scan_limit', array( $rule ) ),
			'Failed to assert that a weekly scan window spans the interval plus one week.'
		);
	}

	/**
	 * The day scan returns the next matching date.
	 *
	 * @covers ::next_scanned_date
	 *
	 * @return void
	 */
	public function test_next_scanned_date_returns_the_next_matching_date(): void {
		$anchor = new DateTimeImmutable( '2026-09-03', new DateTimeZone( 'UTC' ) );

		$candidate = Utility::invoke_hidden_method(
			new Expander(),
			'next_scanned_date',
			array( $this->make_rule( $this->reference_rule_values() ), $anchor->modify( '+1 day' ), $anchor )
		);

		$this->assertSame(
			'2026-09-15',
			$candidate->format( 'Y-m-d' ),
			'Failed to assert that the day scan skips the intervening week and returns the next Tuesday.'
		);
	}

	/**
	 * The monthly walk returns the next matching date.
	 *
	 * @covers ::next_monthly_date
	 *
	 * @return void
	 */
	public function test_next_monthly_date_returns_the_next_matching_date(): void {
		$anchor = new DateTimeImmutable( '2026-01-31', new DateTimeZone( 'UTC' ) );
		$rule   = $this->make_rule(
			array(
				'frequency'    => Rule::FREQUENCY_MONTHLY,
				'interval'     => 1,
				'monthly_mode' => Rule::MONTHLY_MODE_DAY_OF_MONTH,
				'monthly_day'  => 31,
				'end_type'     => Rule::END_TYPE_NEVER,
			)
		);

		$candidate = Utility::invoke_hidden_method(
			new Expander(),
			'next_monthly_date',
			array( $rule, $anchor->modify( '+1 day' ), $anchor )
		);

		$this->assertSame(
			'2026-03-31',
			$candidate->format( 'Y-m-d' ),
			'Failed to assert that the monthly walk skips February and returns the next 31st.'
		);
	}

	/**
	 * The monthly walk returns null when no month in its window resolves a date.
	 *
	 * @covers ::next_monthly_date
	 * @covers ::monthly_date_for_offset
	 *
	 * @return void
	 */
	public function test_next_monthly_date_returns_null_when_no_month_resolves(): void {
		$anchor = new DateTimeImmutable( '2026-01-15', new DateTimeZone( 'UTC' ) );
		$rule   = $this->make_rule(
			array(
				'frequency'       => Rule::FREQUENCY_MONTHLY,
				'interval'        => 1,
				'monthly_mode'    => Rule::MONTHLY_MODE_NTH_WEEKDAY,
				'monthly_ordinal' => 6,
				'monthly_weekday' => 4,
				'end_type'        => Rule::END_TYPE_NEVER,
			)
		);

		$this->assertNull(
			Utility::invoke_hidden_method(
				new Expander(),
				'next_monthly_date',
				array( $rule, $anchor, $anchor )
			),
			'Failed to assert that an unresolvable ordinal yields no monthly candidate.'
		);
	}

	/**
	 * The daily predicate accepts interval multiples and rejects everything else.
	 *
	 * @covers ::matches_daily
	 *
	 * @return void
	 */
	public function test_matches_daily_covers_both_outcomes(): void {
		$anchor   = new DateTimeImmutable( '2026-09-03', new DateTimeZone( 'UTC' ) );
		$expander = new Expander();
		$rule     = $this->make_rule(
			array(
				'frequency' => Rule::FREQUENCY_DAILY,
				'interval'  => 3,
				'end_type'  => Rule::END_TYPE_NEVER,
			)
		);

		$this->assertTrue(
			Utility::invoke_hidden_method(
				$expander,
				'matches_daily',
				array( $rule, $anchor->modify( '+6 days' ), $anchor )
			),
			'Failed to assert that a date an interval multiple away matches.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'matches_daily',
				array( $rule, $anchor->modify( '+4 days' ), $anchor )
			),
			'Failed to assert that a date off the interval does not match.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'matches_daily',
				array( $rule, $anchor->modify( '-3 days' ), $anchor )
			),
			'Failed to assert that a date before the anchor does not match.'
		);
	}

	/**
	 * The weekly predicate tests both the weekday list and the week bucket.
	 *
	 * @covers ::matches_weekly
	 * @covers ::week_index
	 *
	 * @return void
	 */
	public function test_matches_weekly_covers_both_outcomes(): void {
		$anchor   = new DateTimeImmutable( '2026-09-03', new DateTimeZone( 'UTC' ) );
		$expander = new Expander();
		$rule     = $this->make_rule( $this->reference_rule_values() );

		$this->assertTrue(
			Utility::invoke_hidden_method(
				$expander,
				'matches_weekly',
				array( $rule, new DateTimeImmutable( '2026-09-15', new DateTimeZone( 'UTC' ) ), $anchor )
			),
			'Failed to assert that a Tuesday two week buckets on matches.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'matches_weekly',
				array( $rule, new DateTimeImmutable( '2026-09-16', new DateTimeZone( 'UTC' ) ), $anchor )
			),
			'Failed to assert that a Wednesday does not match the weekday list.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'matches_weekly',
				array( $rule, new DateTimeImmutable( '2026-09-08', new DateTimeZone( 'UTC' ) ), $anchor )
			),
			'Failed to assert that a Tuesday one week bucket on does not match.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'matches_weekly',
				array( $rule, new DateTimeImmutable( '2026-08-18', new DateTimeZone( 'UTC' ) ), $anchor )
			),
			'Failed to assert that a Tuesday two week buckets before the anchor does not match.'
		);
	}

	/**
	 * The monthly predicate tests the month interval and the resolved date.
	 *
	 * @covers ::matches_monthly
	 * @covers ::months_apart
	 *
	 * @return void
	 */
	public function test_matches_monthly_covers_both_outcomes(): void {
		$utc      = new DateTimeZone( 'UTC' );
		$anchor   = new DateTimeImmutable( '2026-01-15', $utc );
		$expander = new Expander();
		$rule     = $this->make_rule(
			array(
				'frequency'    => Rule::FREQUENCY_MONTHLY,
				'interval'     => 2,
				'monthly_mode' => Rule::MONTHLY_MODE_DAY_OF_MONTH,
				'monthly_day'  => 15,
				'end_type'     => Rule::END_TYPE_NEVER,
			)
		);

		$this->assertTrue(
			Utility::invoke_hidden_method(
				$expander,
				'matches_monthly',
				array( $rule, new DateTimeImmutable( '2026-03-15', $utc ), $anchor )
			),
			'Failed to assert that the 15th two months on matches.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'matches_monthly',
				array( $rule, new DateTimeImmutable( '2026-02-15', $utc ), $anchor )
			),
			'Failed to assert that a month off the interval does not match.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'matches_monthly',
				array( $rule, new DateTimeImmutable( '2026-03-16', $utc ), $anchor )
			),
			'Failed to assert that the wrong day of an on-interval month does not match.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'matches_monthly',
				array( $rule, new DateTimeImmutable( '2025-11-15', $utc ), $anchor )
			),
			'Failed to assert that a month before the anchor does not match.'
		);
	}

	/**
	 * Dispatching `matches()` reaches every frequency branch.
	 *
	 * @covers ::matches
	 *
	 * @return void
	 */
	public function test_matches_dispatches_every_frequency(): void {
		$utc      = new DateTimeZone( 'UTC' );
		$expander = new Expander();
		$anchor   = new DateTimeImmutable( '2026-01-15', $utc );

		$this->assertTrue(
			Utility::invoke_hidden_method(
				$expander,
				'matches',
				array(
					$this->make_rule(
						array(
							'frequency' => Rule::FREQUENCY_DAILY,
							'interval'  => 1,
							'end_type'  => Rule::END_TYPE_NEVER,
						)
					),
					$anchor->modify( '+1 day' ),
					$anchor,
				)
			),
			'Failed to assert that matches() dispatches a daily rule.'
		);
		$this->assertTrue(
			Utility::invoke_hidden_method(
				$expander,
				'matches',
				array(
					$this->make_rule( $this->reference_rule_values() ),
					new DateTimeImmutable( '2026-09-15', $utc ),
					new DateTimeImmutable( '2026-09-03', $utc ),
				)
			),
			'Failed to assert that matches() dispatches a weekly rule.'
		);
		$this->assertTrue(
			Utility::invoke_hidden_method(
				$expander,
				'matches',
				array(
					$this->make_rule(
						array(
							'frequency'    => Rule::FREQUENCY_MONTHLY,
							'interval'     => 1,
							'monthly_mode' => Rule::MONTHLY_MODE_DAY_OF_MONTH,
							'monthly_day'  => 15,
							'end_type'     => Rule::END_TYPE_NEVER,
						)
					),
					new DateTimeImmutable( '2026-02-15', $utc ),
					$anchor,
				)
			),
			'Failed to assert that matches() dispatches a monthly rule.'
		);
	}

	/**
	 * The month distance is signed.
	 *
	 * @covers ::months_apart
	 *
	 * @return void
	 */
	public function test_months_apart_is_signed(): void {
		$utc      = new DateTimeZone( 'UTC' );
		$expander = new Expander();

		$this->assertSame(
			14,
			Utility::invoke_hidden_method(
				$expander,
				'months_apart',
				array( new DateTimeImmutable( '2026-01-15', $utc ), new DateTimeImmutable( '2027-03-01', $utc ) )
			),
			'Failed to assert that a later month yields a positive distance.'
		);
		$this->assertSame(
			-2,
			Utility::invoke_hidden_method(
				$expander,
				'months_apart',
				array( new DateTimeImmutable( '2026-03-15', $utc ), new DateTimeImmutable( '2026-01-15', $utc ) )
			),
			'Failed to assert that an earlier month yields a negative distance.'
		);
	}

	/**
	 * The scan window is derived per frequency.
	 *
	 * @covers ::day_scan_limit
	 *
	 * @return void
	 */
	public function test_day_scan_limit_per_frequency(): void {
		$expander = new Expander();

		$this->assertSame(
			3,
			Utility::invoke_hidden_method(
				$expander,
				'day_scan_limit',
				array(
					$this->make_rule(
						array(
							'frequency' => Rule::FREQUENCY_DAILY,
							'interval'  => 3,
							'end_type'  => Rule::END_TYPE_NEVER,
						)
					),
				)
			),
			'Failed to assert that a daily scan window is the interval.'
		);
		$this->assertSame(
			21,
			Utility::invoke_hidden_method(
				$expander,
				'day_scan_limit',
				array( $this->make_rule( $this->reference_rule_values() ) )
			),
			'Failed to assert that a weekly scan window is the interval in days plus one week.'
		);
	}

	/**
	 * Resolving an nth weekday covers the ordinal, the "last" ordinal, and the missing case.
	 *
	 * @covers ::nth_weekday_of_month
	 *
	 * @return void
	 */
	public function test_nth_weekday_of_month_covers_every_return_path(): void {
		$expander = new Expander();

		$this->assertSame(
			'2026-02-19',
			Utility::invoke_hidden_method( $expander, 'nth_weekday_of_month', array( 2026, 2, 4, 3 ) ),
			'Failed to assert that the third Thursday of February 2026 resolves.'
		);
		$this->assertSame(
			'2026-04-29',
			Utility::invoke_hidden_method( $expander, 'nth_weekday_of_month', array( 2026, 4, 3, -1 ) ),
			'Failed to assert that the last Wednesday of a five-Wednesday month resolves.'
		);
		$this->assertSame(
			'2026-03-25',
			Utility::invoke_hidden_method( $expander, 'nth_weekday_of_month', array( 2026, 3, 3, -1 ) ),
			'Failed to assert that the last Wednesday of a four-Wednesday month resolves.'
		);
		$this->assertNull(
			Utility::invoke_hidden_method( $expander, 'nth_weekday_of_month', array( 2026, 2, 4, 5 ) ),
			'Failed to assert that a month without a fifth Thursday resolves to null.'
		);
	}

	/**
	 * Resolving a day of the month covers the valid and invalid cases.
	 *
	 * @covers ::day_of_month_date
	 *
	 * @return void
	 */
	public function test_day_of_month_date_covers_both_return_paths(): void {
		$expander = new Expander();

		$this->assertSame(
			'2026-01-31',
			Utility::invoke_hidden_method( $expander, 'day_of_month_date', array( 2026, 1, 31 ) ),
			'Failed to assert that a day the month has resolves.'
		);
		$this->assertNull(
			Utility::invoke_hidden_method( $expander, 'day_of_month_date', array( 2026, 2, 31 ) ),
			'Failed to assert that a day the month lacks resolves to null.'
		);
	}

	/**
	 * Resolving a monthly offset covers both modes and the missing case.
	 *
	 * @covers ::monthly_date_for_offset
	 *
	 * @return void
	 */
	public function test_monthly_date_for_offset_covers_every_mode(): void {
		$utc      = new DateTimeZone( 'UTC' );
		$anchor   = new DateTimeImmutable( '2026-01-31', $utc );
		$expander = new Expander();
		$by_day   = $this->make_rule(
			array(
				'frequency'    => Rule::FREQUENCY_MONTHLY,
				'interval'     => 1,
				'monthly_mode' => Rule::MONTHLY_MODE_DAY_OF_MONTH,
				'monthly_day'  => 31,
				'end_type'     => Rule::END_TYPE_NEVER,
			)
		);

		$this->assertSame(
			'2026-03-31',
			Utility::invoke_hidden_method(
				$expander,
				'monthly_date_for_offset',
				array( $by_day, $anchor, 2 )
			)->format( 'Y-m-d' ),
			'Failed to assert that a day-of-month offset resolves.'
		);
		$this->assertNull(
			Utility::invoke_hidden_method( $expander, 'monthly_date_for_offset', array( $by_day, $anchor, 1 ) ),
			'Failed to assert that a day-of-month offset into February resolves to null.'
		);
		$this->assertSame(
			'2026-02-19',
			Utility::invoke_hidden_method(
				$expander,
				'monthly_date_for_offset',
				array(
					$this->make_rule(
						array(
							'frequency'       => Rule::FREQUENCY_MONTHLY,
							'interval'        => 1,
							'monthly_mode'    => Rule::MONTHLY_MODE_NTH_WEEKDAY,
							'monthly_ordinal' => 3,
							'monthly_weekday' => 4,
							'end_type'        => Rule::END_TYPE_NEVER,
						)
					),
					$anchor,
					1,
				)
			)->format( 'Y-m-d' ),
			'Failed to assert that an nth-weekday offset resolves.'
		);
	}

	/**
	 * Materializing covers the valid, unparsable, and nonexistent-time paths.
	 *
	 * @covers ::materialize
	 *
	 * @return void
	 */
	public function test_materialize_covers_every_return_path(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$expander = new Expander();

		$this->assertSame(
			'2026-09-03 18:00:00',
			Utility::invoke_hidden_method(
				$expander,
				'materialize',
				array( '2026-09-03', '18:00:00', $timezone )
			)->format( 'Y-m-d H:i:s' ),
			'Failed to assert that an existing local time materializes.'
		);
		$this->assertNull(
			Utility::invoke_hidden_method(
				$expander,
				'materialize',
				array( 'not-a-date', '18:00:00', $timezone )
			),
			'Failed to assert that an unparsable date materializes to null.'
		);
		$this->assertNull(
			Utility::invoke_hidden_method(
				$expander,
				'materialize',
				array( '2026-03-08', '02:30:00', $timezone )
			),
			'Failed to assert that a nonexistent local time materializes to null.'
		);
		$this->assertNull(
			Utility::invoke_hidden_method(
				$expander,
				'materialize',
				array( '2011-12-30', '09:00:00', new DateTimeZone( 'Pacific/Apia' ) )
			),
			'Failed to assert that a date the zone never had materializes to null.'
		);
	}

	/**
	 * The week index buckets a Monday-start week.
	 *
	 * @covers ::week_index
	 *
	 * @return void
	 */
	public function test_week_index_buckets_monday_start_weeks(): void {
		$utc      = new DateTimeZone( 'UTC' );
		$expander = new Expander();
		$monday   = Utility::invoke_hidden_method(
			$expander,
			'week_index',
			array( new DateTimeImmutable( '2026-08-31', $utc ) )
		);
		$sunday   = Utility::invoke_hidden_method(
			$expander,
			'week_index',
			array( new DateTimeImmutable( '2026-09-06', $utc ) )
		);
		$next     = Utility::invoke_hidden_method(
			$expander,
			'week_index',
			array( new DateTimeImmutable( '2026-09-07', $utc ) )
		);

		$this->assertSame(
			$monday,
			$sunday,
			'Failed to assert that Monday and the following Sunday share a week bucket.'
		);
		$this->assertSame(
			$monday + 1,
			$next,
			'Failed to assert that the next Monday starts the next week bucket.'
		);
	}

	/**
	 * Reducing a datetime to a date drops the time and normalizes to UTC.
	 *
	 * @covers ::date_only
	 *
	 * @return void
	 */
	public function test_date_only_drops_the_time(): void {
		$date = Utility::invoke_hidden_method(
			new Expander(),
			'date_only',
			array( $this->reference_anchor() )
		);

		$this->assertSame(
			'2026-09-03 00:00:00 UTC',
			$date->format( 'Y-m-d H:i:s T' ),
			'Failed to assert that a datetime reduces to a UTC-midnight date.'
		);
	}

	/**
	 * The end-date guard covers a bounded rule on both sides and an unbounded rule.
	 *
	 * @covers ::past_until
	 *
	 * @return void
	 */
	public function test_past_until_covers_every_outcome(): void {
		$utc      = new DateTimeZone( 'UTC' );
		$expander = new Expander();
		$values   = $this->reference_rule_values();

		$values['end_type'] = Rule::END_TYPE_UNTIL;
		$values['until']    = '2026-10-01';
		unset( $values['count'] );

		$bounded = $this->make_rule( $values );

		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'past_until',
				array( $bounded, new DateTimeImmutable( '2026-10-01', $utc ) )
			),
			'Failed to assert that the end date itself is not past the end date.'
		);
		$this->assertTrue(
			Utility::invoke_hidden_method(
				$expander,
				'past_until',
				array( $bounded, new DateTimeImmutable( '2026-10-02', $utc ) )
			),
			'Failed to assert that the day after the end date is past it.'
		);
		$this->assertFalse(
			Utility::invoke_hidden_method(
				$expander,
				'past_until',
				array(
					$this->make_rule( $this->reference_rule_values() ),
					new DateTimeImmutable( '2099-01-01', $utc ),
				)
			),
			'Failed to assert that a rule without an end date is never past one.'
		);
	}
}
