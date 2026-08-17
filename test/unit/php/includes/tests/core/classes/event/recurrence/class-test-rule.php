<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Rule.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateTimeImmutable;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Recurrence\Expander;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Rule;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;

/**
 * Class Test_Rule.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Rule
 */
class Test_Rule extends Base {

	use Occurrence_Fixtures;

	/**
	 * `from_post()` returns null for a post carrying no recurrence rule.
	 *
	 * @covers ::from_post
	 *
	 * @return void
	 */
	public function test_from_post_returns_null_without_rule(): void {
		$post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );

		$this->assertNull( Rule::from_post( $post_id ) );
	}

	/**
	 * `from_post()` reconstructs a weekly rule from the derived mirrors, and
	 * every mirror holds the expected typed value after `set_recurrence()`.
	 *
	 * @covers ::from_post
	 * @covers ::from_array
	 * @covers ::__construct
	 * @covers ::frequency
	 * @covers ::interval
	 * @covers ::weekdays
	 * @covers ::monthly_mode
	 * @covers ::monthly_day
	 * @covers ::monthly_ordinal
	 * @covers ::monthly_weekday
	 * @covers ::end_type
	 * @covers ::until
	 * @covers ::count
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_from_post_round_trips_weekly_rule(): void {
		$post_id = $this->create_recurring_event(
			array(
				'frequency' => 'weekly',
				'interval'  => 2,
				'weekdays'  => array( 2, 4 ),
				'end_type'  => 'count',
				'count'     => 5,
			)
		);

		Meta::get_instance()->set_recurrence( $post_id );

		$this->assertSame( 'weekly', get_post_meta( $post_id, 'gatherpress_recurrence_frequency', true ) );
		$this->assertSame( '2', get_post_meta( $post_id, 'gatherpress_recurrence_interval', true ) );
		$this->assertSame( 'TU,TH', get_post_meta( $post_id, 'gatherpress_recurrence_byday', true ) );
		$this->assertSame( '', get_post_meta( $post_id, 'gatherpress_recurrence_monthly_mode', true ) );
		$this->assertSame( '0', get_post_meta( $post_id, 'gatherpress_recurrence_monthly_day', true ) );
		$this->assertSame( '0', get_post_meta( $post_id, 'gatherpress_recurrence_monthly_ordinal', true ) );
		$this->assertSame( '0', get_post_meta( $post_id, 'gatherpress_recurrence_monthly_weekday', true ) );
		$this->assertSame( 'count', get_post_meta( $post_id, 'gatherpress_recurrence_end_type', true ) );
		$this->assertSame( '', get_post_meta( $post_id, 'gatherpress_recurrence_until', true ) );
		$this->assertSame( '5', get_post_meta( $post_id, 'gatherpress_recurrence_count', true ) );

		$rule = Rule::from_post( $post_id );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertSame( 'weekly', $rule->frequency() );
		$this->assertSame( 2, $rule->interval() );
		$this->assertSame( array( 2, 4 ), $rule->weekdays() );
		$this->assertSame( 'count', $rule->end_type() );
		$this->assertSame( 5, $rule->count() );
		$this->assertNull( $rule->until() );
	}

	/**
	 * `from_post()` reconstructs a monthly day-of-month rule.
	 *
	 * @covers ::from_post
	 * @covers ::from_array
	 *
	 * @return void
	 */
	public function test_from_post_round_trips_monthly_day_of_month_rule(): void {
		$post_id = $this->create_recurring_event(
			array(
				'frequency'    => 'monthly',
				'interval'     => 1,
				'monthly_mode' => 'day_of_month',
				'monthly_day'  => 15,
				'end_type'     => 'until',
				'until'        => '2027-06-15',
			)
		);

		Meta::get_instance()->set_recurrence( $post_id );

		$rule = Rule::from_post( $post_id );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertSame( 'monthly', $rule->frequency() );
		$this->assertSame( 'day_of_month', $rule->monthly_mode() );
		$this->assertSame( 15, $rule->monthly_day() );
		$this->assertSame( 'until', $rule->end_type() );
		$this->assertInstanceOf( DateTimeImmutable::class, $rule->until() );
		$this->assertSame( '2027-06-15', $rule->until()->format( 'Y-m-d' ) );
		$this->assertSame( 0, $rule->count() );
	}

	/**
	 * `from_post()` reconstructs a monthly nth-weekday rule, including "last".
	 *
	 * @covers ::from_post
	 * @covers ::from_array
	 *
	 * @return void
	 */
	public function test_from_post_round_trips_monthly_nth_weekday_last_rule(): void {
		$post_id = $this->create_recurring_event(
			array(
				'frequency'       => 'monthly',
				'interval'        => 1,
				'monthly_mode'    => 'nth_weekday',
				'monthly_ordinal' => -1,
				'monthly_weekday' => 3,
				'end_type'        => 'never',
			)
		);

		Meta::get_instance()->set_recurrence( $post_id );

		$this->assertSame( '-1', get_post_meta( $post_id, 'gatherpress_recurrence_monthly_ordinal', true ) );

		$rule = Rule::from_post( $post_id );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertSame( 'nth_weekday', $rule->monthly_mode() );
		$this->assertSame( -1, $rule->monthly_ordinal() );
		$this->assertSame( 3, $rule->monthly_weekday() );
		$this->assertSame( 'never', $rule->end_type() );
	}

	/**
	 * A zero or negative interval clamps up to one.
	 *
	 * @covers ::from_array
	 * @covers ::interval
	 *
	 * @return void
	 */
	public function test_interval_zero_and_negative_clamp_to_one(): void {
		$zero     = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => 0,
				'end_type'  => 'never',
			)
		);
		$negative = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => -5,
				'end_type'  => 'never',
			)
		);

		$this->assertInstanceOf( Rule::class, $zero );
		$this->assertSame( 1, $zero->interval() );
		$this->assertInstanceOf( Rule::class, $negative );
		$this->assertSame( 1, $negative->interval() );
	}

	/**
	 * `UNTIL` and `COUNT` are mutually exclusive; both present is rejected.
	 *
	 * @covers ::from_array
	 *
	 * @return void
	 */
	public function test_until_and_count_are_mutually_exclusive(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => 1,
				'end_type'  => 'count',
				'until'     => '2026-01-01',
				'count'     => 5,
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * A `COUNT` above `Rule::MAX_COUNT` is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_count_above_max_is_rejected(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => 1,
				'end_type'  => 'count',
				'count'     => Rule::MAX_COUNT + 1,
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * An `INTERVAL` above `Rule::MAX_INTERVAL` is rejected outright, never clamped.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_interval_above_max_is_rejected(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => Rule::MAX_INTERVAL + 1,
				'end_type'  => 'count',
				'count'     => 1,
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * A rule whose worst-case iteration budget exceeds `Expander::MAX_ITERATIONS`
	 * is rejected at the boundary, not silently truncated at expansion time.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_rule_whose_iteration_budget_exceeds_backstop_is_rejected(): void {
		$rule = Rule::from_array(
			array(
				'frequency'    => 'monthly',
				'interval'     => 10,
				'end_type'     => 'count',
				'count'        => 730,
				'monthly_mode' => 'day_of_month',
				'monthly_day'  => 1,
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * A weekly rule with no weekdays is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_weekly_without_weekdays_is_rejected(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array(),
				'end_type'  => 'never',
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * A rule naming an unrecognized frequency is rejected at the boundary.
	 *
	 * REQ-11's other side: `Expander`'s defensive `default => null` arms
	 * (`next_candidate_date()`, `matches()`, `day_scan_limit()`) exist so an
	 * unknown frequency yields zero occurrences rather than a fatal, in case
	 * one is ever handed to the expander directly. This asserts the boundary
	 * this rule is actually supposed to be enforced at: `from_array()` never
	 * lets an unrecognized frequency reach the expander in the first place.
	 *
	 * The fixture value used to be `yearly`, which was correct until REQ-11
	 * landed the fourth frequency. It is now `fortnightly` -- a plausible but
	 * genuinely unsupported frequency -- so the boundary stays covered from
	 * the rejecting side while `test_from_array_accepts_yearly_frequency()`
	 * covers the accepting side.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_from_array_rejects_unrecognized_frequency(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'fortnightly',
				'interval'  => 1,
				'end_type'  => 'never',
			)
		);

		$this->assertNull(
			$rule,
			'Failed to assert that an unrecognized frequency is rejected.'
		);
	}

	/**
	 * A yearly rule is accepted at the boundary and needs no monthly fields.
	 *
	 * PRD section 2.1 and REQ-11: yearly repeats every N years with the month
	 * and day derived from the series start, so it carries no `monthly_mode`,
	 * no weekday list and no mode switch of its own. `BYMONTH`, `BYYEARDAY`
	 * and `BYWEEKNO` are permanent non-goals (PRD section 10 item 5).
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 * @covers ::frequency
	 * @covers ::interval
	 *
	 * @return void
	 */
	public function test_from_array_accepts_yearly_frequency(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'yearly',
				'interval'  => 3,
				'end_type'  => 'never',
			)
		);

		$this->assertInstanceOf(
			Rule::class,
			$rule,
			'Failed to assert that a yearly rule is accepted at the boundary.'
		);
		$this->assertSame(
			Rule::FREQUENCY_YEARLY,
			$rule->frequency(),
			'Failed to assert that the yearly frequency round-trips.'
		);
		$this->assertSame(
			3,
			$rule->interval(),
			'Failed to assert that the yearly interval round-trips.'
		);
	}

	/**
	 * A yearly rule serializes to `FREQ=YEARLY` with no `BY*` part.
	 *
	 * @covers ::to_rrule_string
	 *
	 * @return void
	 */
	public function test_to_rrule_string_emits_freq_yearly(): void {
		$fixtures = array(
			// Yearly, never-ending, interval 1 -- interval is omitted at 1.
			array(
				'values'   => array(
					'frequency' => 'yearly',
					'interval'  => 1,
					'end_type'  => 'never',
				),
				'expected' => 'FREQ=YEARLY',
			),
			// Yearly, count-bounded, interval 3.
			array(
				'values'   => array(
					'frequency' => 'yearly',
					'interval'  => 3,
					'end_type'  => 'count',
					'count'     => 5,
				),
				'expected' => 'FREQ=YEARLY;INTERVAL=3;COUNT=5',
			),
			// Yearly, until-bounded -- no BYMONTH, ever.
			array(
				'values'   => array(
					'frequency' => 'yearly',
					'interval'  => 1,
					'end_type'  => 'until',
					'until'     => '2031-02-28',
				),
				'expected' => 'FREQ=YEARLY;UNTIL=20310228',
			),
		);

		foreach ( $fixtures as $fixture ) {
			$rule = Rule::from_array( $fixture['values'] );

			$this->assertInstanceOf(
				Rule::class,
				$rule,
				'Fixture rule failed to build: ' . wp_json_encode( $fixture['values'] )
			);
			$this->assertSame( $fixture['expected'], $rule->to_rrule_string() );
		}
	}

	/**
	 * A yearly `COUNT` rule is rejected exactly where its budget crosses
	 * `Expander::MAX_ITERATIONS`, using 366 days per occurrence.
	 *
	 * The budget arithmetic is `count * per_frequency * interval + 366`, so at
	 * 366 days per yearly occurrence the largest accepted product of count and
	 * interval is 545: `545 * 366 * 1 + 366 = 199,836` fits inside the 200,000
	 * backstop, and one more occurrence -- `546 * 366 + 366 = 200,202` -- does
	 * not. The interval side of the product is asserted independently so a
	 * multiplier that ignored `interval` could not pass.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_yearly_iteration_budget_is_enforced_at_the_meta_boundary(): void {
		// Tie the boundary to `Expander::MAX_ITERATIONS` rather than to the
		// literals 545 and 546: if the backstop ever moves, these two fail
		// loudly instead of leaving the rule assertions below silently
		// asserting the wrong boundary.
		$this->assertLessThanOrEqual(
			Expander::MAX_ITERATIONS,
			( 545 * 366 * 1 ) + 366,
			'Failed to assert that the accepted-side budget fits the backstop.'
		);
		$this->assertGreaterThan(
			Expander::MAX_ITERATIONS,
			( 546 * 366 * 1 ) + 366,
			'Failed to assert that the rejected-side budget exceeds the backstop.'
		);

		$this->assertInstanceOf(
			Rule::class,
			Rule::from_array(
				array(
					'frequency' => 'yearly',
					'interval'  => 1,
					'end_type'  => 'count',
					'count'     => 545,
				)
			),
			'Failed to assert that a yearly rule inside the budget is accepted.'
		);
		$this->assertNull(
			Rule::from_array(
				array(
					'frequency' => 'yearly',
					'interval'  => 1,
					'end_type'  => 'count',
					'count'     => 546,
				)
			),
			'Failed to assert that a yearly rule over the budget is rejected.'
		);

		// The same count either side of the interval boundary: 100 occurrences
		// at interval 5 fit, at interval 6 they do not.
		$this->assertInstanceOf(
			Rule::class,
			Rule::from_array(
				array(
					'frequency' => 'yearly',
					'interval'  => 5,
					'end_type'  => 'count',
					'count'     => 100,
				)
			),
			'Failed to assert that interval widens the budget rather than being ignored.'
		);
		$this->assertNull(
			Rule::from_array(
				array(
					'frequency' => 'yearly',
					'interval'  => 6,
					'end_type'  => 'count',
					'count'     => 100,
				)
			),
			'Failed to assert that a wider yearly interval is rejected on budget.'
		);
	}

	/**
	 * A weekly rule with a weekday outside 0-6 is rejected, whether the whole
	 * list is out of range or only one entry is -- an unchecked value here
	 * would leave an undefined `WEEKDAY_CODES` lookup at write-mirror and
	 * RRULE-export time.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_weekly_with_out_of_range_weekday_is_rejected(): void {
		$too_high = Rule::from_array(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( 7 ),
				'end_type'  => 'never',
			)
		);
		$negative = Rule::from_array(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( -1 ),
				'end_type'  => 'never',
			)
		);
		$mixed    = Rule::from_array(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( -1, 3 ),
				'end_type'  => 'never',
			)
		);

		$this->assertNull( $too_high );
		$this->assertNull( $negative );
		$this->assertNull( $mixed );
	}

	/**
	 * A monthly rule with neither a recognized monthly mode is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_monthly_with_unrecognized_mode_is_rejected(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'monthly',
				'interval'  => 1,
				'end_type'  => 'never',
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * A monthly day-of-month rule with a day outside 1-31 is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_monthly_day_of_month_out_of_range_is_rejected(): void {
		$too_low  = Rule::from_array(
			array(
				'frequency'    => 'monthly',
				'interval'     => 1,
				'monthly_mode' => 'day_of_month',
				'monthly_day'  => 0,
				'end_type'     => 'never',
			)
		);
		$too_high = Rule::from_array(
			array(
				'frequency'    => 'monthly',
				'interval'     => 1,
				'monthly_mode' => 'day_of_month',
				'monthly_day'  => 32,
				'end_type'     => 'never',
			)
		);

		$this->assertNull( $too_low );
		$this->assertNull( $too_high );
	}

	/**
	 * A monthly nth-weekday rule with an invalid ordinal or weekday is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_monthly_nth_weekday_out_of_range_is_rejected(): void {
		$bad_ordinal = Rule::from_array(
			array(
				'frequency'       => 'monthly',
				'interval'        => 1,
				'monthly_mode'    => 'nth_weekday',
				'monthly_ordinal' => 0,
				'monthly_weekday' => 2,
				'end_type'        => 'never',
			)
		);
		$bad_weekday = Rule::from_array(
			array(
				'frequency'       => 'monthly',
				'interval'        => 1,
				'monthly_mode'    => 'nth_weekday',
				'monthly_ordinal' => 1,
				'monthly_weekday' => 7,
				'end_type'        => 'never',
			)
		);

		$this->assertNull( $bad_ordinal );
		$this->assertNull( $bad_weekday );
	}

	/**
	 * An unrecognized `end_type` is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_unrecognized_end_type_is_rejected(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => 1,
				'end_type'  => 'bogus',
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * A `never`-ending rule carrying a stray `until` is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_never_end_type_rejects_stray_until(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => 1,
				'end_type'  => 'never',
				'until'     => '2026-01-01',
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * A `never`-ending rule carrying a stray `count` is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_never_end_type_rejects_stray_count(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => 1,
				'end_type'  => 'never',
				'count'     => 3,
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * An `until`-ending rule with no `until` value is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_until_end_type_without_until_value_is_rejected(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => 1,
				'end_type'  => 'until',
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * A malformed `until` string does not parse into a date and is rejected.
	 *
	 * @covers ::from_array
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_unparseable_until_is_rejected(): void {
		$rule = Rule::from_array(
			array(
				'frequency' => 'daily',
				'interval'  => 1,
				'end_type'  => 'until',
				'until'     => 'not-a-date',
			)
		);

		$this->assertNull( $rule );
	}

	/**
	 * `is_valid()`'s `count`-arm `until` guard, unreachable through
	 * `from_array()` because its own pre-check already rejects any rule
	 * carrying both a count and an until, is exercised directly by
	 * constructing the object via reflection.
	 *
	 * @covers ::__construct
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_is_valid_rejects_count_end_type_carrying_until_when_built_directly(): void {
		$rule = $this->build_rule_directly(
			array(
				'daily',
				1,
				array(),
				'',
				0,
				0,
				0,
				'count',
				new DateTimeImmutable( '2026-01-01' ),
				5,
			)
		);

		$this->assertFalse( $rule->is_valid() );
	}

	/**
	 * `is_valid()`'s `until`-arm `count` guard, unreachable through
	 * `from_array()` for the same reason, is exercised directly by
	 * constructing the object via reflection.
	 *
	 * @covers ::is_valid
	 *
	 * @return void
	 */
	public function test_is_valid_rejects_until_end_type_carrying_count_when_built_directly(): void {
		$rule = $this->build_rule_directly(
			array(
				'daily',
				1,
				array(),
				'',
				0,
				0,
				0,
				'until',
				new DateTimeImmutable( '2026-01-01' ),
				5,
			)
		);

		$this->assertFalse( $rule->is_valid() );
	}

	/**
	 * `to_array()` round-trips through `from_array()`.
	 *
	 * @covers ::to_array
	 *
	 * @return void
	 */
	public function test_to_array_round_trips_through_from_array(): void {
		$original = array(
			'frequency'       => 'monthly',
			'interval'        => 3,
			'weekdays'        => array(),
			'monthly_mode'    => 'nth_weekday',
			'monthly_day'     => 0,
			'monthly_ordinal' => 2,
			'monthly_weekday' => 5,
			'end_type'        => 'never',
			'until'           => '',
			'count'           => 0,
		);

		$rule = Rule::from_array( $original );

		$this->assertInstanceOf( Rule::class, $rule );
		$this->assertSame( $original, $rule->to_array() );
	}

	/**
	 * `to_rrule_string()` matches a fixture table covering daily, weekly, and
	 * both monthly modes, each with a different end-of-series shape.
	 *
	 * @covers ::to_rrule_string
	 *
	 * @return void
	 */
	public function test_to_rrule_string_matches_fixture_table(): void {
		$fixtures = array(
			// Daily, count-bounded, interval 1 -- interval is omitted at 1.
			array(
				'values'   => array(
					'frequency' => 'daily',
					'interval'  => 1,
					'end_type'  => 'count',
					'count'     => 10,
				),
				'expected' => 'FREQ=DAILY;COUNT=10',
			),
			// Daily, until-bounded, interval 3.
			array(
				'values'   => array(
					'frequency' => 'daily',
					'interval'  => 3,
					'end_type'  => 'until',
					'until'     => '2026-12-31',
				),
				'expected' => 'FREQ=DAILY;INTERVAL=3;UNTIL=20261231',
			),
			// Weekly, never-ending, multiple weekdays, interval 1.
			array(
				'values'   => array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( 1, 3, 5 ),
					'end_type'  => 'never',
				),
				'expected' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
			),
			// Weekly, count-bounded, biweekly, matching the guide's reference rule.
			array(
				'values'   => array(
					'frequency' => 'weekly',
					'interval'  => 2,
					'weekdays'  => array( 2, 4 ),
					'end_type'  => 'count',
					'count'     => 5,
				),
				'expected' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH;COUNT=5',
			),
			// Monthly day-of-month, until-bounded.
			array(
				'values'   => array(
					'frequency'    => 'monthly',
					'interval'     => 1,
					'monthly_mode' => 'day_of_month',
					'monthly_day'  => 15,
					'end_type'     => 'until',
					'until'        => '2027-06-15',
				),
				'expected' => 'FREQ=MONTHLY;BYMONTHDAY=15;UNTIL=20270615',
			),
			// Monthly nth-weekday, "last", never-ending.
			array(
				'values'   => array(
					'frequency'       => 'monthly',
					'interval'        => 1,
					'monthly_mode'    => 'nth_weekday',
					'monthly_ordinal' => -1,
					'monthly_weekday' => 3,
					'end_type'        => 'never',
				),
				'expected' => 'FREQ=MONTHLY;BYDAY=-1WE',
			),
		);

		foreach ( $fixtures as $fixture ) {
			$rule = Rule::from_array( $fixture['values'] );

			$this->assertInstanceOf(
				Rule::class,
				$rule,
				'Fixture rule failed to build: ' . wp_json_encode( $fixture['values'] )
			);
			$this->assertSame( $fixture['expected'], $rule->to_rrule_string() );
		}
	}

	/**
	 * Direct `Utility::invoke_hidden_method()` coverage for every return path
	 * of `is_valid_monthly_shape()` and `is_valid_end_shape()`.
	 *
	 * `is_valid()` already exercises both helpers black-box through
	 * `from_array()` above, but xdebug does not reliably trace a private
	 * same-class helper invoked from a short delegation like `is_valid()` --
	 * see the "Extracted same-class helpers and xdebug coverage tracing" rule
	 * in AGENTS.md. Invoking each helper directly is the documented fix.
	 *
	 * @covers ::is_valid_monthly_shape
	 * @covers ::is_valid_end_shape
	 *
	 * @return void
	 */
	public function test_is_valid_helpers_direct_invoke_covers_every_branch(): void {
		$day_of_month_valid = $this->build_rule_directly(
			array( 'monthly', 1, array(), 'day_of_month', 15, 0, 0, 'never', null, 0 )
		);
		$this->assertTrue(
			Utility::invoke_hidden_method( $day_of_month_valid, 'is_valid_monthly_shape' )
		);

		$day_of_month_invalid = $this->build_rule_directly(
			array( 'monthly', 1, array(), 'day_of_month', 0, 0, 0, 'never', null, 0 )
		);
		$this->assertFalse(
			Utility::invoke_hidden_method( $day_of_month_invalid, 'is_valid_monthly_shape' )
		);

		$nth_weekday_valid = $this->build_rule_directly(
			array( 'monthly', 1, array(), 'nth_weekday', 0, 1, 3, 'never', null, 0 )
		);
		$this->assertTrue(
			Utility::invoke_hidden_method( $nth_weekday_valid, 'is_valid_monthly_shape' )
		);

		$nth_weekday_invalid = $this->build_rule_directly(
			array( 'monthly', 1, array(), 'nth_weekday', 0, 0, 3, 'never', null, 0 )
		);
		$this->assertFalse(
			Utility::invoke_hidden_method( $nth_weekday_invalid, 'is_valid_monthly_shape' )
		);

		$unrecognized_mode = $this->build_rule_directly(
			array( 'monthly', 1, array(), 'bogus', 0, 0, 0, 'never', null, 0 )
		);
		$this->assertFalse(
			Utility::invoke_hidden_method( $unrecognized_mode, 'is_valid_monthly_shape' )
		);

		$until_valid = $this->build_rule_directly(
			array( 'daily', 1, array(), '', 0, 0, 0, 'until', new DateTimeImmutable( '2026-01-01' ), 0 )
		);
		$this->assertTrue(
			Utility::invoke_hidden_method( $until_valid, 'is_valid_end_shape' )
		);

		$until_missing = $this->build_rule_directly(
			array( 'daily', 1, array(), '', 0, 0, 0, 'until', null, 0 )
		);
		$this->assertFalse(
			Utility::invoke_hidden_method( $until_missing, 'is_valid_end_shape' )
		);

		$count_valid = $this->build_rule_directly(
			array( 'daily', 1, array(), '', 0, 0, 0, 'count', null, 5 )
		);
		$this->assertTrue(
			Utility::invoke_hidden_method( $count_valid, 'is_valid_end_shape' )
		);

		$count_out_of_range = $this->build_rule_directly(
			array( 'daily', 1, array(), '', 0, 0, 0, 'count', null, 0 )
		);
		$this->assertFalse(
			Utility::invoke_hidden_method( $count_out_of_range, 'is_valid_end_shape' )
		);

		$count_over_budget = $this->build_rule_directly(
			array( 'monthly', 10, array(), '', 0, 0, 0, 'count', null, 730 )
		);
		$this->assertFalse(
			Utility::invoke_hidden_method( $count_over_budget, 'is_valid_end_shape' )
		);

		// A frequency outside FREQUENCY_* is unreachable through from_array()
		// -- is_valid()'s own top-level check rejects it before is_valid_end_shape()
		// ever runs -- but the per-frequency budget lookup still carries a
		// defensive `?? daily` fallback for it. Exercise that arm directly.
		$count_with_unmapped_frequency = $this->build_rule_directly(
			array( 'bogus', 1, array(), '', 0, 0, 0, 'count', null, 5 )
		);
		$this->assertTrue(
			Utility::invoke_hidden_method( $count_with_unmapped_frequency, 'is_valid_end_shape' )
		);

		$never_valid = $this->build_rule_directly(
			array( 'daily', 1, array(), '', 0, 0, 0, 'never', null, 0 )
		);
		$this->assertTrue(
			Utility::invoke_hidden_method( $never_valid, 'is_valid_end_shape' )
		);

		$never_with_stray_until = $this->build_rule_directly(
			array( 'daily', 1, array(), '', 0, 0, 0, 'never', new DateTimeImmutable( '2026-01-01' ), 0 )
		);
		$this->assertFalse(
			Utility::invoke_hidden_method( $never_with_stray_until, 'is_valid_end_shape' )
		);

		$never_with_stray_count = $this->build_rule_directly(
			array( 'daily', 1, array(), '', 0, 0, 0, 'never', null, 3 )
		);
		$this->assertFalse(
			Utility::invoke_hidden_method( $never_with_stray_count, 'is_valid_end_shape' )
		);
	}
}
