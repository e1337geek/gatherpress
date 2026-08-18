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
 * Where a zone's transitions are regular -- the same offsets and name on the
 * same weekday ordinal of the same month at the same wall clock, year after
 * year -- one `STANDARD` and one `DAYLIGHT` sub-component carry an `RRULE` and
 * describe the zone for as long as that policy holds. Where they are not, each
 * transition in range is written out on its own.
 *
 * The range is the one the body covers, never the one the request happens to
 * fall in. RFC 5545 section 3.6.5 requires the definition to be valid for every
 * instant the components it serves refer to, and resolves an instant against
 * the observance with the last onset *before* it -- so a definition whose
 * earliest onset postdates a 2020 event does not define that event's offset at
 * all, however correct it is about today.
 *
 * @since 0.36.0
 */
final class Timezone_Component {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Years of transition history read behind the earliest instant in range.
	 *
	 * One year is enough to observe the offset in force before the first
	 * transition after it, which is what `TZOFFSETFROM` reports.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	private const LOOKBEHIND_YEARS = 1;

	/**
	 * Years of transitions read ahead of the latest instant in range.
	 *
	 * Three is the smallest window that shows a yearly pattern repeating often
	 * enough to be called regular rather than coincidental. It is also what an
	 * open-ended rule gets ahead of today, since such a rule names no last
	 * instant of its own; dates past it are covered by the emitted `RRULE`.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	private const LOOKAHEAD_YEARS = 3;

	/**
	 * Rendered definitions, keyed by identifier and the range they were built for.
	 *
	 * A feed commonly carries many events in one zone, and the transition list
	 * is the same for all of them -- but only for the same range, which is why
	 * the range is part of the key rather than assumed away.
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
	 * The range each definition covers is derived from the same body, for the
	 * same reason: the instants a definition has to be valid for are the ones
	 * written into the components it accompanies.
	 *
	 * @param string $body One or more assembled `VEVENT` components.
	 *
	 * @return string The definitions, in first-reference order, or '' when none are referenced.
	 */
	public function render_for_body( string $body ): string {
		preg_match_all( '/;TZID=([^:;\r\n]+)/', $body, $matches );

		[ $from, $to ] = $this->range_of( $body );

		$components = array();

		foreach ( array_unique( $matches[1] ) as $tzid ) {
			$component = $this->render( $tzid, $from, $to );

			if ( '' !== $component ) {
				$components[] = $component;
			}
		}

		return implode( "\r\n", $components );
	}

	/**
	 * The span of instants an assembled body refers to.
	 *
	 * Every date-time value in the body counts, whatever property carries it:
	 * a `DTSTART`, the `RECURRENCE-ID` of a single-occurrence download, an
	 * `EXDATE` exclusion, and the `UNTIL` bound of a rule are all moments a
	 * client has to resolve against an observance. They are read as UTC
	 * regardless of the `TZID` qualifying them, which is wrong by at most a
	 * day's offset and irrelevant at the scale the window is measured in.
	 *
	 * Two floors are applied. The span always contains the present, so a feed
	 * of purely historical events still defines the zone a client is reading it
	 * in; and a rule with neither `UNTIL` nor `COUNT` has no last instant, so it
	 * extends the span to the lookahead horizon rather than ending at whatever
	 * concrete date happened to be written next to it.
	 *
	 * @since 0.36.0
	 *
	 * @param string $body One or more assembled `VEVENT` components.
	 *
	 * @return array{0:int,1:int} The earliest and latest instants, as Unix timestamps.
	 */
	private function range_of( string $body ): array {
		$now = time();

		preg_match_all( '/(\d{8})T(\d{6})/', $body, $moments, PREG_SET_ORDER );

		$instants = array( $now );

		foreach ( $moments as $moment ) {
			$instant = strtotime( $moment[1] . 'T' . $moment[2] . 'Z' );

			if ( false !== $instant ) {
				$instants[] = $instant;
			}
		}

		if ( $this->has_open_ended_rule( $body ) ) {
			$instants[] = $now + ( self::LOOKAHEAD_YEARS * YEAR_IN_SECONDS );
		}

		return array( min( $instants ), max( $instants ) );
	}

	/**
	 * Whether any rule in the body recurs without a last instant.
	 *
	 * @since 0.36.0
	 *
	 * @param string $body One or more assembled `VEVENT` components.
	 *
	 * @return bool True when a rule carries neither `UNTIL` nor `COUNT`.
	 */
	private function has_open_ended_rule( string $body ): bool {
		preg_match_all( '/^RRULE:(.*)$/m', $body, $rules );

		foreach ( $rules[1] as $rule ) {
			if ( ! str_contains( $rule, 'UNTIL=' ) && ! str_contains( $rule, 'COUNT=' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The `VTIMEZONE` component defining one tz-database identifier.
	 *
	 * @since 0.36.0
	 *
	 * @param string   $tzid A tz-database identifier.
	 * @param int|null $from Earliest instant the definition must cover; defaults to now.
	 * @param int|null $to   Latest instant the definition must cover; defaults to now.
	 *
	 * @return string The component, or '' when the identifier names no known zone.
	 */
	public function render( string $tzid, ?int $from = null, ?int $to = null ): string {
		$from = $from ?? time();
		$to   = $to ?? time();
		$key  = sprintf( '%s|%d|%d', $tzid, $from, $to );

		if ( ! isset( $this->rendered[ $key ] ) ) {
			$this->rendered[ $key ] = $this->build( $tzid, $from, $to );
		}

		return $this->rendered[ $key ];
	}

	/**
	 * Build one identifier's definition from the tz database.
	 *
	 * @since 0.36.0
	 *
	 * @param string $tzid A tz-database identifier.
	 * @param int    $from Earliest instant the definition must cover.
	 * @param int    $to   Latest instant the definition must cover.
	 *
	 * @return string The component, or '' when the identifier names no known zone.
	 */
	private function build( string $tzid, int $from, int $to ): string {
		// `Timezone_Guard::is_named()` validates positively against
		// `timezone_identifiers_list()`, which is what makes the constructor
		// below unable to throw. A fixed offset never reaches here at all: it
		// is never written into a `TZID` parameter in the first place.
		if ( ! Timezone_Guard::is_named( $tzid ) ) {
			return '';
		}

		$zone = new DateTimeZone( $tzid );
		// The lookbehind is what puts an observance *before* the earliest
		// instant in range, which is the one that instant resolves against.
		$transitions = $zone->getTransitions(
			$from - ( self::LOOKBEHIND_YEARS * YEAR_IN_SECONDS ),
			$to + ( self::LOOKAHEAD_YEARS * YEAR_IN_SECONDS )
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
	 * `RRULE`, which describes the zone from the first onset in range onward --
	 * what an open-ended series needs, and what keeps the definition small. An
	 * `RRULE` generates nothing before its own `DTSTART`, which is why the
	 * window is anchored to the range the body covers rather than to the
	 * request: a rule that starts after the event it is meant to explain leaves
	 * that event with no observance to resolve against. Irregular transitions
	 * are written out individually instead, because a rule that does not hold is
	 * worse than no rule.
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

		$regular = $this->is_regular( $transitions );
		$lines   = array();

		foreach ( $regular ? array( $transitions[0] ) : $transitions as $transition ) {
			$lines[] = sprintf( 'BEGIN:%s', $type );
			$lines[] = sprintf( 'TZOFFSETFROM:%s', $this->as_utc_offset( $transition['from'] ) );
			$lines[] = sprintf( 'TZOFFSETTO:%s', $this->as_utc_offset( $transition['to'] ) );
			$lines[] = sprintf( 'TZNAME:%s', $transition['abbr'] );
			$lines[] = sprintf( 'DTSTART:%s', $transition['local'] );

			if ( $regular ) {
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
	 * zone may not follow. Two or more that agree on month, weekday ordinal,
	 * wall clock, **both offsets and name** do establish one.
	 *
	 * The offsets are the half that is easy to leave out and expensive to get
	 * wrong. A zone can keep transitioning on the same yearly position while
	 * changing what it transitions *to* -- which is exactly what a jurisdiction
	 * abolishing daylight saving looks like in tzdata, and what Alberta's
	 * scheduled move reads as today: two first-Sunday-of-November transitions at
	 * 02:00, the first to -0700 and the second to -0600. Comparing position
	 * alone calls that pair regular and emits an unbounded rule that keeps
	 * moving clients to an offset the zone stopped using, an hour wrong for
	 * every later date. The abbreviation is included for the same reason one
	 * step earlier: a name change is a policy change even where the offsets
	 * happen to coincide, and the emitted `TZNAME` would otherwise be a
	 * transition's name applied to a different observance.
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
				'%d:%s:%s:%d:%d:%s',
				$transition['month'],
				$transition['byday'],
				substr( $transition['local'], -6 ),
				$transition['from'],
				$transition['to'],
				$transition['abbr']
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
