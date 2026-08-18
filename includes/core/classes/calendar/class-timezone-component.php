<?php
/**
 * `VTIMEZONE` generation for the iCalendar responses.
 *
 * This file defines the `Timezone_Component` class, which turns a tz-database
 * identifier into the RFC 5545 component that gives it meaning. GatherPress
 * emits `DTSTART;TZID=America/New_York:20300615T143000` rather than a bare UTC
 * instant, because an `RRULE` cannot be correctly attached to a UTC-anchored
 * start for anything but a fixed-offset series -- and RFC 5545 section 3.2.19
 * requires that every `TZID` parameter refer to a `VTIMEZONE` carried in the
 * same `VCALENDAR`. Without one the output is not merely under-specified, it is
 * invalid, and clients are free to reject or misplace the event.
 *
 * @package GatherPress\Core\Calendar
 * @since 0.36.0
 */

namespace GatherPress\Core\Calendar;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use DateTimeZone;
use GatherPress\Core\Event\Recurrence\Timezone_Guard;
use GatherPress\Core\Traits\Singleton;

/**
 * `VTIMEZONE` generation for the iCalendar responses.
 *
 * Definitions are derived from `DateTimeZone::getTransitions()` rather than
 * from a bundled table, so they track whatever tz database the host carries.
 * Where a zone's transitions are regular -- the same weekday ordinal of the
 * same month at the same wall clock, year after year -- one `STANDARD` and one
 * `DAYLIGHT` sub-component carry an `RRULE` and describe the zone indefinitely
 * in both directions, which is what an open-ended series needs. Where they are
 * not, each transition in range is written out on its own.
 *
 * @since 0.36.0
 */
final class Timezone_Component {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Years of transition history read behind the current moment.
	 *
	 * One year is enough to observe the offset in force before the first
	 * transition ahead of us, which is what `TZOFFSETFROM` reports.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	private const LOOKBEHIND_YEARS = 1;

	/**
	 * Years of transitions read ahead of the current moment.
	 *
	 * Three is the smallest window that shows a yearly pattern repeating often
	 * enough to be called regular rather than coincidental. Dates beyond it are
	 * covered by the emitted `RRULE`, which is unbounded.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	private const LOOKAHEAD_YEARS = 3;

	/**
	 * Rendered definitions, keyed by tz-database identifier.
	 *
	 * A feed commonly carries many events in one zone, and the transition list
	 * is the same for all of them.
	 *
	 * @since 0.36.0
	 * @var array<string, string>
	 */
	private array $rendered = array();

	/**
	 * Every `VTIMEZONE` the components in an iCal body need to be valid.
	 *
	 * Derived from the body rather than from the events that produced it, so
	 * the invariant it exists to hold -- every `TZID` referenced is defined --
	 * is true by construction rather than by two code paths agreeing.
	 *
	 * @since 0.36.0
	 *
	 * @param string $body One or more assembled `VEVENT` components.
	 *
	 * @return string The definitions, in first-reference order, or '' when none are referenced.
	 */
	public function render_for_body( string $body ): string {
		preg_match_all( '/;TZID=([^:;\r\n]+)/', $body, $matches );

		$components = array();

		foreach ( array_unique( $matches[1] ) as $tzid ) {
			$component = $this->render( $tzid );

			if ( '' !== $component ) {
				$components[] = $component;
			}
		}

		return implode( "\r\n", $components );
	}

	/**
	 * The `VTIMEZONE` component defining one tz-database identifier.
	 *
	 * @since 0.36.0
	 *
	 * @param string $tzid A tz-database identifier.
	 *
	 * @return string The component, or '' when the identifier names no known zone.
	 */
	public function render( string $tzid ): string {
		if ( ! isset( $this->rendered[ $tzid ] ) ) {
			$this->rendered[ $tzid ] = $this->build( $tzid );
		}

		return $this->rendered[ $tzid ];
	}

	/**
	 * Discard the memoized definitions.
	 *
	 * The transition list a definition is built from is fixed for the life of a
	 * request; this exists so a test can prove the memo is a memo.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->rendered = array();
	}

	/**
	 * Build one identifier's definition from the tz database.
	 *
	 * @since 0.36.0
	 *
	 * @param string $tzid A tz-database identifier.
	 *
	 * @return string The component, or '' when the identifier names no known zone.
	 */
	private function build( string $tzid ): string {
		// `Timezone_Guard::is_named()` validates positively against
		// `timezone_identifiers_list()`, which is what makes the constructor
		// below unable to throw. A fixed offset never reaches here at all: it
		// is never written into a `TZID` parameter in the first place.
		if ( ! Timezone_Guard::is_named( $tzid ) ) {
			return '';
		}

		$zone        = new DateTimeZone( $tzid );
		$now         = time();
		$transitions = $zone->getTransitions(
			$now - ( self::LOOKBEHIND_YEARS * YEAR_IN_SECONDS ),
			$now + ( self::LOOKAHEAD_YEARS * YEAR_IN_SECONDS )
		);

		$lines = array_merge(
			array( 'BEGIN:VTIMEZONE', sprintf( 'TZID:%s', $tzid ) ),
			$this->sub_components( $transitions ),
			array( 'END:VTIMEZONE' )
		);

		return implode( "\r\n", $lines );
	}

	/**
	 * The `STANDARD` and `DAYLIGHT` sub-components a transition list produces.
	 *
	 * `STANDARD` is emitted before `DAYLIGHT` regardless of which the window
	 * happened to start in, so the output of a given zone does not depend on
	 * the time of year the feed was requested.
	 *
	 * @since 0.36.0
	 *
	 * @param array $transitions Entries as `DateTimeZone::getTransitions()` returns them.
	 *
	 * @return string[] The sub-component lines, flattened.
	 */
	private function sub_components( array $transitions ): array {
		$head    = $transitions[0] ?? array(
			'offset' => 0,
			'abbr'   => 'UTC',
			'isdst'  => false,
		);
		$changes = array_slice( $transitions, 1 );

		// A zone that never changes offset within the window -- UTC,
		// Asia/Kolkata, a zone that abolished daylight saving before it -- has
		// no transition to describe, but still needs a definition, because its
		// identifier is named in a `TZID` parameter. It is written as the
		// degenerate case RFC 5545 allows: one `STANDARD` moving from its own
		// offset to itself, effective from the start of the epoch, with no rule.
		if ( array() === $changes ) {
			return $this->sub_component(
				'STANDARD',
				array(
					array(
						'from'  => (int) $head['offset'],
						'to'    => (int) $head['offset'],
						'abbr'  => (string) $head['abbr'],
						'local' => '19700101T000000',
						'month' => 1,
						'byday' => '',
					),
				)
			);
		}

		$grouped  = array(
			'STANDARD' => array(),
			'DAYLIGHT' => array(),
		);
		$previous = (int) $head['offset'];

		foreach ( $changes as $change ) {
			$type               = $change['isdst'] ? 'DAYLIGHT' : 'STANDARD';
			$grouped[ $type ][] = $this->describe_transition( $change, $previous );
			$previous           = (int) $change['offset'];
		}

		return array_merge(
			$this->sub_component( 'STANDARD', $grouped['STANDARD'] ),
			$this->sub_component( 'DAYLIGHT', $grouped['DAYLIGHT'] )
		);
	}

	/**
	 * Describe one transition in the terms a sub-component is written from.
	 *
	 * The effective moment is the local wall clock *before* the change, per
	 * RFC 5545 section 3.6.5: the `DTSTART` of a sub-component is expressed in
	 * the offset the zone is leaving, which is `TZOFFSETFROM`.
	 *
	 * @since 0.36.0
	 *
	 * @param array $change   One entry from `DateTimeZone::getTransitions()`.
	 * @param int   $previous The offset in force immediately before it, in seconds.
	 *
	 * @return array The transition's offsets, abbreviation, local moment and yearly position.
	 */
	private function describe_transition( array $change, int $previous ): array {
		$local = (int) $change['ts'] + $previous;
		$day   = (int) gmdate( 'j', $local );
		$last  = (int) gmdate( 't', $local );

		// A transition inside the final seven days of its month is "the last
		// <weekday>", which is how the European rules are written and the only
		// form that survives a month whose length varies. Anything earlier
		// counts forward from the first.
		$ordinal = ( ( $last - $day ) < 7 ) ? -1 : ( intdiv( $day - 1, 7 ) + 1 );

		return array(
			'from'  => $previous,
			'to'    => (int) $change['offset'],
			'abbr'  => (string) $change['abbr'],
			'local' => gmdate( 'Ymd\THis', $local ),
			'month' => (int) gmdate( 'n', $local ),
			'byday' => sprintf( '%d%s', $ordinal, strtoupper( substr( gmdate( 'D', $local ), 0, 2 ) ) ),
		);
	}

	/**
	 * Render one sub-component type from the transitions belonging to it.
	 *
	 * Regular transitions collapse to a single sub-component carrying a yearly
	 * `RRULE`, which describes the zone beyond the window in both directions --
	 * what an open-ended series needs, and what keeps the definition small.
	 * Irregular ones are written out individually instead, because a rule that
	 * does not hold is worse than no rule.
	 *
	 * @since 0.36.0
	 *
	 * @param string $type        Either `STANDARD` or `DAYLIGHT`.
	 * @param array  $transitions Transitions as `describe_transition()` returns them.
	 *
	 * @return string[] The sub-component lines, flattened.
	 */
	private function sub_component( string $type, array $transitions ): array {
		if ( array() === $transitions ) {
			return array();
		}

		$lines = array();

		foreach ( $this->is_regular( $transitions ) ? array( $transitions[0] ) : $transitions as $transition ) {
			$lines[] = sprintf( 'BEGIN:%s', $type );
			$lines[] = sprintf( 'TZOFFSETFROM:%s', $this->as_utc_offset( $transition['from'] ) );
			$lines[] = sprintf( 'TZOFFSETTO:%s', $this->as_utc_offset( $transition['to'] ) );
			$lines[] = sprintf( 'TZNAME:%s', $transition['abbr'] );
			$lines[] = sprintf( 'DTSTART:%s', $transition['local'] );

			if ( $this->is_regular( $transitions ) ) {
				$lines[] = sprintf(
					'RRULE:FREQ=YEARLY;BYMONTH=%d;BYDAY=%s',
					$transition['month'],
					$transition['byday']
				);
			}

			$lines[] = sprintf( 'END:%s', $type );
		}

		return $lines;
	}

	/**
	 * Whether a set of transitions repeats on the same yearly position.
	 *
	 * A single transition is not regular: one observation cannot establish a
	 * pattern, and writing an unbounded `RRULE` from it would claim a rule the
	 * zone may not follow. Two or more that agree on month, weekday ordinal and
	 * wall clock do establish one.
	 *
	 * @since 0.36.0
	 *
	 * @param array $transitions Transitions as `describe_transition()` returns them.
	 *
	 * @return bool True when one `RRULE` describes every one of them.
	 */
	private function is_regular( array $transitions ): bool {
		if ( count( $transitions ) < 2 ) {
			return false;
		}

		$signature = static function ( array $transition ): string {
			return sprintf(
				'%d:%s:%s',
				$transition['month'],
				$transition['byday'],
				substr( $transition['local'], -6 )
			);
		};

		return 1 === count( array_unique( array_map( $signature, $transitions ) ) );
	}

	/**
	 * Render an offset in seconds as the RFC 5545 `UTC-OFFSET` form.
	 *
	 * @since 0.36.0
	 *
	 * @param int $seconds Offset from UTC, negative to the west.
	 *
	 * @return string The offset as `+HHMM` or `-HHMM`.
	 */
	private function as_utc_offset( int $seconds ): string {
		$absolute = abs( $seconds );

		return sprintf(
			'%s%02d%02d',
			( $seconds < 0 ) ? '-' : '+',
			intdiv( $absolute, HOUR_IN_SECONDS ),
			intdiv( $absolute % HOUR_IN_SECONDS, MINUTE_IN_SECONDS )
		);
	}
}
