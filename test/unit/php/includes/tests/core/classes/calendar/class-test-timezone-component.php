<?php
/**
 * Class handles unit tests for GatherPress\Core\Calendar\Timezone_Component.
 *
 * The behavior of this class through a real request is covered in
 * `Test_Calendar_Recurrence`, which is where the requirement lives: a feed
 * defines every timezone its components reference. This file exists for the
 * branches xdebug will not trace through a same-class delegation -- every
 * method below `render()` is private and reached only from it -- plus the two
 * degenerate transition lists a real tz database never hands back.
 *
 * @package GatherPress\Core\Calendar
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Calendar;

use GatherPress\Core\Calendar\Timezone_Component;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;

/**
 * Class Test_Timezone_Component.
 *
 * @coversDefaultClass \GatherPress\Core\Calendar\Timezone_Component
 *
 * @since 0.36.0
 */
class Test_Timezone_Component extends Base {

	/**
	 * A transition list entry in the shape `DateTimeZone::getTransitions()` returns.
	 *
	 * @since 0.36.0
	 *
	 * @param string $moment UTC moment of the change, parseable by `strtotime()`.
	 * @param int    $offset Offset in force after the change, in seconds.
	 * @param bool   $isdst  Whether the offset after the change is a daylight one.
	 * @param string $abbr   Abbreviation in force after the change.
	 *
	 * @return array The entry.
	 */
	protected function transition( string $moment, int $offset, bool $isdst, string $abbr ): array {
		return array(
			'ts'     => strtotime( $moment . ' UTC' ),
			'time'   => $moment,
			'offset' => $offset,
			'isdst'  => $isdst,
			'abbr'   => $abbr,
		);
	}

	/**
	 * An identifier that names no zone produces no definition at all.
	 *
	 * A fixed UTC offset is never written into a `TZID` parameter, so it never
	 * reaches here in production -- but an empty `VTIMEZONE` would be worse than
	 * none, and `new DateTimeZone( 'UTC+5' )` throws.
	 *
	 * @covers ::build
	 * @covers ::render
	 *
	 * @return void
	 */
	public function test_an_unnamed_timezone_produces_no_definition(): void {
		$instance = Timezone_Component::get_instance();

		$this->assertSame(
			'',
			Utility::invoke_hidden_method( $instance, 'build', array( 'UTC+5' ) ),
			'A fixed UTC offset cannot be named in a TZID and so defines nothing.'
		);
		$this->assertSame(
			'',
			Utility::invoke_hidden_method( $instance, 'build', array( 'Mars/Olympus_Mons' ) ),
			'An identifier absent from the tz database defines nothing either.'
		);
		$this->assertSame(
			'',
			$instance->render( 'Mars/Olympus_Mons' ),
			'And the memoizing entry point answers the same way.'
		);
	}

	/**
	 * A named identifier is wrapped in the component that declares it.
	 *
	 * @covers ::build
	 *
	 * @return void
	 */
	public function test_a_named_timezone_is_wrapped_and_declared(): void {
		$component = Utility::invoke_hidden_method(
			Timezone_Component::get_instance(),
			'build',
			array( 'Europe/Berlin' )
		);

		$this->assertStringStartsWith( "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n", $component );
		$this->assertStringEndsWith( "\r\nEND:VTIMEZONE", $component );
	}

	/**
	 * The second and later calls for one identifier are served from the memo.
	 *
	 * A feed commonly carries many events in one zone; the transition list is
	 * the same for all of them and reading it is the expensive part.
	 *
	 * @covers ::render
	 *
	 * @return void
	 */
	public function test_a_definition_is_built_once_per_identifier(): void {
		$instance = Timezone_Component::get_instance();
		$first    = $instance->render( 'Europe/Berlin' );

		Utility::set_and_get_hidden_property( $instance, 'rendered', array( 'Europe/Berlin' => 'SENTINEL' ) );

		$this->assertNotSame( '', $first, 'The first call must build something for the memo to hold.' );
		$this->assertSame(
			'SENTINEL',
			$instance->render( 'Europe/Berlin' ),
			'A second call for the same identifier must be served from the memo rather than rebuilt.'
		);

		Utility::set_and_get_hidden_property( $instance, 'rendered', array() );
	}

	/**
	 * An empty transition list still yields a usable standard sub-component.
	 *
	 * `getTransitions()` always returns at least the entry describing the state
	 * at the range start, so this is the defensive arm rather than an authored
	 * one -- but the fallback has to name an offset, and a `VTIMEZONE` carrying
	 * no sub-component at all is invalid.
	 *
	 * @covers ::sub_components
	 *
	 * @return void
	 */
	public function test_an_empty_transition_list_falls_back_to_utc(): void {
		$this->assertSame(
			array(
				'BEGIN:STANDARD',
				'TZOFFSETFROM:+0000',
				'TZOFFSETTO:+0000',
				'TZNAME:UTC',
				'DTSTART:19700101T000000',
				'END:STANDARD',
			),
			Utility::invoke_hidden_method(
				Timezone_Component::get_instance(),
				'sub_components',
				array( array() )
			),
			'With nothing to describe, the definition still declares an offset rather than being empty.'
		);
	}

	/**
	 * A list holding only the range-start entry describes one unchanging offset.
	 *
	 * @covers ::sub_components
	 *
	 * @return void
	 */
	public function test_a_list_with_no_changes_describes_one_unchanging_offset(): void {
		$this->assertSame(
			array(
				'BEGIN:STANDARD',
				'TZOFFSETFROM:+0530',
				'TZOFFSETTO:+0530',
				'TZNAME:IST',
				'DTSTART:19700101T000000',
				'END:STANDARD',
			),
			Utility::invoke_hidden_method(
				Timezone_Component::get_instance(),
				'sub_components',
				array( array( $this->transition( '2026-01-01 00:00:00', 19800, false, 'IST' ) ) )
			),
			'A zone that never changes moves from its own offset to itself, with no rule.'
		);
	}

	/**
	 * Standard precedes daylight whichever the range happened to open in.
	 *
	 * The window starts wherever "now" falls, so a zone read in July opens on a
	 * daylight offset and the same zone read in January opens on a standard one.
	 * The emitted definition must not depend on that.
	 *
	 * @covers ::sub_components
	 *
	 * @return void
	 */
	public function test_standard_precedes_daylight_whichever_the_window_opens_in(): void {
		$opening_in_daylight = array(
			$this->transition( '2026-07-01 00:00:00', -14400, true, 'EDT' ),
			$this->transition( '2026-11-01 06:00:00', -18000, false, 'EST' ),
			$this->transition( '2027-03-14 07:00:00', -14400, true, 'EDT' ),
		);

		$lines = Utility::invoke_hidden_method(
			Timezone_Component::get_instance(),
			'sub_components',
			array( $opening_in_daylight )
		);

		$this->assertSame( 'BEGIN:STANDARD', $lines[0], 'The standard sub-component is written first.' );
		$this->assertContains( 'BEGIN:DAYLIGHT', $lines, 'And the daylight one after it.' );
		$this->assertLessThan(
			array_search( 'BEGIN:DAYLIGHT', $lines, true ),
			array_search( 'BEGIN:STANDARD', $lines, true ),
			'Their order is fixed rather than following the order the transitions arrived in.'
		);
	}

	/**
	 * A transition is placed by the offset it leaves, and named by its position
	 * in the month.
	 *
	 * @covers ::describe_transition
	 *
	 * @return void
	 */
	public function test_describe_transition_places_and_names_a_change(): void {
		$instance = Timezone_Component::get_instance();

		// The European autumn change: 01:00 UTC, leaving CEST (+0200), on the
		// last Sunday of October.
		$this->assertSame(
			array(
				'from'  => 7200,
				'to'    => 3600,
				'abbr'  => 'CET',
				'local' => '20261025T030000',
				'month' => 10,
				'byday' => '-1SU',
			),
			Utility::invoke_hidden_method(
				$instance,
				'describe_transition',
				array( $this->transition( '2026-10-25 01:00:00', 3600, false, 'CET' ), 7200 )
			),
			'A change in the last seven days of its month is the last weekday, timed in the offset it leaves.'
		);

		// The US spring change: 07:00 UTC, leaving EST (-0500), on the second
		// Sunday of March.
		$this->assertSame(
			array(
				'from'  => -18000,
				'to'    => -14400,
				'abbr'  => 'EDT',
				'local' => '20260308T020000',
				'month' => 3,
				'byday' => '2SU',
			),
			Utility::invoke_hidden_method(
				$instance,
				'describe_transition',
				array( $this->transition( '2026-03-08 07:00:00', -14400, true, 'EDT' ), -18000 )
			),
			'A change earlier in its month counts forward from the first weekday.'
		);
	}

	/**
	 * Nothing to describe produces no sub-component rather than an empty one.
	 *
	 * The arm a zone that has abolished daylight saving lands on: it has
	 * standard transitions in the window and no daylight ones.
	 *
	 * @covers ::sub_component
	 *
	 * @return void
	 */
	public function test_a_type_with_no_transitions_emits_nothing(): void {
		$this->assertSame(
			array(),
			Utility::invoke_hidden_method(
				Timezone_Component::get_instance(),
				'sub_component',
				array( 'DAYLIGHT', array() )
			)
		);
	}

	/**
	 * Irregular transitions are enumerated rather than given a rule that would
	 * misdescribe them.
	 *
	 * The zone this stands in for is a real one: a jurisdiction that moves its
	 * change date, or adopts and then drops daylight saving mid-window. Writing
	 * an unbounded yearly `RRULE` from the first of those would claim a rule the
	 * zone does not follow, for every year after the window -- worse than
	 * enumerating, which is merely incomplete.
	 *
	 * @covers ::sub_component
	 * @covers ::is_regular
	 *
	 * @return void
	 */
	public function test_irregular_transitions_are_enumerated_without_a_rule(): void {
		$instance  = Timezone_Component::get_instance();
		$irregular = array(
			Utility::invoke_hidden_method(
				$instance,
				'describe_transition',
				array( $this->transition( '2026-03-08 07:00:00', -14400, true, 'EDT' ), -18000 )
			),
			// The following year, a month later and on a different ordinal.
			Utility::invoke_hidden_method(
				$instance,
				'describe_transition',
				array( $this->transition( '2027-04-11 07:00:00', -14400, true, 'EDT' ), -18000 )
			),
		);

		$lines = Utility::invoke_hidden_method( $instance, 'sub_component', array( 'DAYLIGHT', $irregular ) );

		$this->assertSame(
			2,
			count( array_keys( $lines, 'BEGIN:DAYLIGHT', true ) ),
			'Two transitions that do not agree are written out as two sub-components.'
		);
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$lines,
					static function ( string $line ): bool {
						return str_starts_with( $line, 'RRULE:' );
					}
				)
			),
			'Neither carries a rule, because no single rule describes both.'
		);
	}

	/**
	 * Transitions that agree collapse to one sub-component carrying an unbounded
	 * yearly rule.
	 *
	 * This is what lets a definition read three years of transitions and still
	 * describe an open-ended series correctly: the rule extends the pattern in
	 * both directions, where an enumeration would run out.
	 *
	 * @covers ::sub_component
	 * @covers ::is_regular
	 *
	 * @return void
	 */
	public function test_regular_transitions_collapse_to_one_sub_component_with_a_rule(): void {
		$instance = Timezone_Component::get_instance();
		$regular  = array(
			Utility::invoke_hidden_method(
				$instance,
				'describe_transition',
				array( $this->transition( '2026-03-08 07:00:00', -14400, true, 'EDT' ), -18000 )
			),
			Utility::invoke_hidden_method(
				$instance,
				'describe_transition',
				array( $this->transition( '2027-03-14 07:00:00', -14400, true, 'EDT' ), -18000 )
			),
		);

		$this->assertSame(
			array(
				'BEGIN:DAYLIGHT',
				'TZOFFSETFROM:-0500',
				'TZOFFSETTO:-0400',
				'TZNAME:EDT',
				'DTSTART:20260308T020000',
				'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
				'END:DAYLIGHT',
			),
			Utility::invoke_hidden_method( $instance, 'sub_component', array( 'DAYLIGHT', $regular ) ),
			'Two second-Sundays-of-March become one sub-component ruled to every second Sunday of March.'
		);
	}

	/**
	 * One observation is not a pattern.
	 *
	 * @covers ::is_regular
	 *
	 * @return void
	 */
	public function test_regularity_needs_more_than_one_observation(): void {
		$instance   = Timezone_Component::get_instance();
		$transition = Utility::invoke_hidden_method(
			$instance,
			'describe_transition',
			array( $this->transition( '2026-03-08 07:00:00', -14400, true, 'EDT' ), -18000 )
		);

		$this->assertFalse(
			Utility::invoke_hidden_method( $instance, 'is_regular', array( array( $transition ) ) ),
			'A single transition cannot establish a rule that will be written as unbounded.'
		);
		$this->assertTrue(
			Utility::invoke_hidden_method( $instance, 'is_regular', array( array( $transition, $transition ) ) ),
			'Two that agree on month, ordinal and wall clock do.'
		);
	}

	/**
	 * Offsets are rendered in the RFC 5545 `UTC-OFFSET` form on both sides of
	 * zero, and to the minute.
	 *
	 * @covers ::as_utc_offset
	 *
	 * @return void
	 */
	public function test_offsets_render_on_both_sides_of_zero_and_to_the_minute(): void {
		$instance = Timezone_Component::get_instance();

		$this->assertSame(
			array( '-0500', '+0100', '+0000', '+0530', '-0930', '+1400' ),
			array_map(
				static function ( int $seconds ) use ( $instance ): string {
					return Utility::invoke_hidden_method( $instance, 'as_utc_offset', array( $seconds ) );
				},
				array( -18000, 3600, 0, 19800, -34200, 50400 )
			),
			'Zones west of UTC are negative, zero is positive by convention, and half-hour zones keep their minutes.'
		);
	}
}
