<?php
/**
 * Class handles unit tests for REQ-14, the recurrence half of the calendar
 * export.
 *
 * Every assertion here is driven through a real request: `go_to()` the endpoint
 * URL, then `Calendar\Setup::get_ics_body()`, which is what the `.ics` template
 * calls. Calling `Calendar::get_ical_event_string()` directly would skip
 * `Recurrence\Rewrite::parse_request()` and `Recurrence\Context::sync()`, and
 * those two are where the series-versus-occurrence decision is actually made.
 *
 * @package GatherPress\Core\Calendar
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Calendar\Calendar;
use GatherPress\Core\Calendar\Setup as Calendar_Setup;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Event\Recurrence\Context;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Query as Recurrence_Query;
use GatherPress\Core\Event\Recurrence\Rewrite;
use GatherPress\Core\Setup;
use GatherPress\Core\Topic;
use GatherPress\Core\Venue\Venue;
use GatherPress\Tests\Base;
use GatherPress\Tests\Core\Event\Recurrence\Occurrence_Fixtures;
use PMC\Unit_Test\Utility;

/**
 * Class Test_Calendar_Recurrence.
 *
 * @coversDefaultClass \GatherPress\Core\Calendar\Calendar
 * @group              endpoints
 *
 * @since 0.36.0
 */
class Test_Calendar_Recurrence extends Base {

	use Occurrence_Fixtures;

	/**
	 * Named timezone every fixture in this file is authored in.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const TIMEZONE = 'America/New_York';

	/**
	 * Wall-clock start every fixture in this file uses.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const WALL_START = '18:00:00';

	/**
	 * How far the fixture anchor sits from now.
	 *
	 * Behind now by default -- see `anchor()`. The aggregate-feed test moves it
	 * ahead, because `Event\Query::get_events_list()` selects its `upcoming`
	 * bucket from the series *anchor* rather than from its occurrences, so a
	 * series whose anchor has passed is not in any aggregate feed to carry a
	 * rule. That is a query-layer limitation outside REQ-14's serializer, and
	 * this property is where it is visible rather than hidden.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	protected string $anchor_offset = '-10 days';

	/**
	 * The rewrite state this file found, restored in `tearDown()`.
	 *
	 * @since 0.36.0
	 * @var array<string, mixed>
	 */
	protected array $rewrite_state = array();

	/**
	 * Ensure the occurrence table exists, and snapshot the rewrite state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		global $wp_rewrite;

		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );
		Context::flush_resolved();
		Context::get_instance()->clear();

		$this->rewrite_state = array(
			'structure'    => $wp_rewrite->permalink_structure,
			'permastructs' => $wp_rewrite->extra_permastructs,
		);
	}

	/**
	 * Put the rewrite state back the way this file found it.
	 *
	 * `$wp_rewrite` is a global object, not an option, so nothing it holds is
	 * undone by the per-test transaction rollback. Two things leak, and only
	 * the second is obvious. `permalink_structure` is the obvious one.
	 * `extra_permastructs` is not: `enable_pretty_permalinks()` re-runs `init`,
	 * which re-registers every post type *while* a pretty structure is in
	 * place, and `WP_Rewrite::init()` does not clear that array -- so
	 * `get_extra_permastruct( 'gatherpress_event' )` keeps answering for the
	 * rest of the process and every later test's event permalinks silently
	 * become pretty ones.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rewrite;

		$wp_rewrite->extra_permastructs = $this->rewrite_state['permastructs'];

		update_option( 'permalink_structure', $this->rewrite_state['structure'] );
		$wp_rewrite->init();
		$wp_rewrite->flush_rules();

		parent::tearDown();
	}

	/**
	 * Turn on pretty permalinks and register every rewrite rule the calendar
	 * and recurrence subsystems own.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function enable_pretty_permalinks(): void {
		global $wp_rewrite;

		update_option( 'permalink_structure', '/%postname%/' );
		$wp_rewrite->init();
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		Calendar_Setup::get_instance()->register_endpoints();
		Rewrite::get_instance()->add_rewrite_rules();
		$wp_rewrite->flush_rules();
	}

	/**
	 * The anchor every recurring fixture in this file is built from.
	 *
	 * Deliberately **behind** now, with later occurrences ahead of it. That is
	 * what makes the series assertions non-vacuous: `Rewrite::parse_request()`
	 * injects the *next upcoming* occurrence into a bare series URL's query
	 * vars (PRD D-4), so an anchor that is itself the next upcoming occurrence
	 * makes "DTSTART is the anchor" and "DTSTART is whatever the request
	 * resolved to" the same string, and the test passes with the series feed
	 * silently narrowed to one date (preamble rule 3a #8).
	 *
	 * Relative to now rather than pinned for the same reason: that resolution
	 * compares occurrence rows against the clock, so a pinned anchor would stop
	 * being behind it, or its successors would stop being ahead of it, and the
	 * distinction above would decay back into a coincidence (preamble rule 3a
	 * #7).
	 *
	 * @since 0.36.0
	 *
	 * @return DateTimeImmutable The anchor start, in the fixture timezone.
	 */
	protected function anchor(): DateTimeImmutable {
		$timezone = new DateTimeZone( self::TIMEZONE );
		$date     = ( new DateTimeImmutable( 'now', $timezone ) )
			->modify( $this->anchor_offset )
			->format( 'Y-m-d' );

		return new DateTimeImmutable( $date . ' ' . self::WALL_START, $timezone );
	}

	/**
	 * The recurrence identifier of the nth occurrence of the weekly fixture.
	 *
	 * Derived from the anchor by weekly arithmetic rather than read back out of
	 * the occurrence table, so the expectation states what the rule means
	 * instead of repeating what the projector wrote.
	 *
	 * @since 0.36.0
	 *
	 * @param int $index Zero-based occurrence index.
	 *
	 * @return string The `Ymd\THis` identifier.
	 */
	protected function occurrence_id( int $index ): string {
		return $this->anchor()->modify( sprintf( '+%d days', 7 * $index ) )->format( 'Ymd' )
			. 'T'
			. str_replace( ':', '', self::WALL_START );
	}

	/**
	 * The RFC 5545 two-letter weekday code of the anchor's own weekday.
	 *
	 * @since 0.36.0
	 *
	 * @return string One of `SU` through `SA`.
	 */
	protected function byday(): string {
		return strtoupper( substr( $this->anchor()->format( 'D' ), 0, 2 ) );
	}

	/**
	 * Create a projected weekly series repeating on the anchor's own weekday.
	 *
	 * @since 0.36.0
	 *
	 * @param array $overrides Rule values to merge over the weekly default.
	 *
	 * @return int The series post ID.
	 */
	protected function create_weekly_series( array $overrides = array() ): int {
		$anchor = $this->anchor();
		$rule   = array_merge(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( (int) $anchor->format( 'w' ) ),
				'end_type'  => 'count',
				'count'     => 5,
			),
			$overrides
		);

		return $this->create_relative_recurring_event(
			$rule,
			$anchor,
			$anchor->modify( '+2 hours' ),
			self::TIMEZONE
		);
	}

	/**
	 * Create a published, non-recurring event with a pinned datetime range.
	 *
	 * Pinned deliberately: this fixture feeds pure input-to-output assertions
	 * about the emitted VEVENT text and is never compared against the clock.
	 *
	 * @since 0.36.0
	 *
	 * @param string $timezone Named identifier or fixed offset for the event.
	 *
	 * @return int The event post ID.
	 */
	protected function create_plain_event( string $timezone = self::TIMEZONE ): int {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_title'  => 'Sample Event',
				'post_name'   => 'sample-event',
				'post_status' => 'publish',
			)
		);

		( new Event( $post_id ) )->save_datetimes(
			array(
				'datetime_start' => '2030-06-15 14:30:00',
				'datetime_end'   => '2030-06-15 16:30:00',
				'timezone'       => $timezone,
			)
		);

		return (int) $post_id;
	}

	/**
	 * Request a URL and return the iCal body the `.ics` template would send.
	 *
	 * @since 0.36.0
	 *
	 * @param string $url URL to request.
	 *
	 * @return string The rendered iCal payload.
	 */
	protected function body_for( string $url ): string {
		$this->go_to( $url );

		return Calendar_Setup::get_instance()->get_ics_body();
	}

	/**
	 * The iCal download URL of one event's series.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Event post ID.
	 *
	 * @return string The `/ical/` endpoint URL.
	 */
	protected function series_ical_url( int $post_id ): string {
		return trailingslashit( (string) Context::get_instance()->series_permalink( $post_id ) )
			. Calendar_Setup::ICAL_SLUG . '/';
	}

	/**
	 * The iCal download URL of one occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier.
	 *
	 * @return string The occurrence-scoped `/ical/` endpoint URL.
	 */
	protected function occurrence_ical_url( int $post_id, string $recurrence_id ): string {
		return add_query_arg(
			array( Context::QUERY_VAR => $recurrence_id ),
			$this->series_ical_url( $post_id )
		);
	}

	/**
	 * Extract the value of one property from a VEVENT body.
	 *
	 * @since 0.36.0
	 *
	 * @param string $body     The iCal payload.
	 * @param string $property Property name, without parameters.
	 *
	 * @return string[] Every line for that property, whole.
	 */
	protected function lines_for( string $body, string $property ): array {
		return array_values(
			array_filter(
				explode( "\r\n", $body ),
				static function ( string $line ) use ( $property ): bool {
					return str_starts_with( $line, $property . ':' )
						|| str_starts_with( $line, $property . ';' );
				}
			)
		);
	}

	/**
	 * A recurring series' own feed emits one component carrying a
	 * timezone-qualified start and the recurrence rule, and does not enumerate.
	 *
	 * @covers ::get_ical_event_string
	 * @covers ::recurrence_lines
	 * @covers ::series_timezone
	 * @covers ::datetime_lines
	 *
	 * @return void
	 */
	public function test_series_feed_emits_one_component_with_a_tzid_start_and_an_rrule(): void {
		$post_id = $this->create_weekly_series();

		$this->enable_pretty_permalinks();

		$body = $this->body_for( $this->series_ical_url( $post_id ) );

		$this->assertSame(
			1,
			substr_count( $body, 'BEGIN:VEVENT' ),
			'A series feed must emit one component, not one per occurrence.'
		);
		$this->assertSame(
			array( sprintf( 'DTSTART;TZID=%s:%s', self::TIMEZONE, $this->occurrence_id( 0 ) ) ),
			$this->lines_for( $body, 'DTSTART' ),
			'DTSTART must be the series anchor, qualified by the named timezone.'
		);
		$this->assertSame(
			array( 'RRULE:FREQ=WEEKLY;BYDAY=' . $this->byday() ),
			array_map(
				static function ( string $line ): string {
					return preg_replace( '/;COUNT=\d+$/', '', $line );
				},
				$this->lines_for( $body, 'RRULE' )
			),
			'The feed must carry the recurrence rule.'
		);
		$this->assertSame(
			array( 'RRULE:FREQ=WEEKLY;BYDAY=' . $this->byday() . ';COUNT=5' ),
			$this->lines_for( $body, 'RRULE' ),
			'The rule must carry the authored COUNT.'
		);
		$this->assertSame(
			array( sprintf( 'UID:gatherpress_%d', $post_id ) ),
			$this->lines_for( $body, 'UID' ),
			'A series component keeps the series UID.'
		);
		$this->assertSame(
			array(),
			$this->lines_for( $body, 'RECURRENCE-ID' ),
			'A series component carries no RECURRENCE-ID.'
		);
	}

	/**
	 * Cancelled occurrences appear as `EXDATE`, derived from the rows rather
	 * than written into the rule.
	 *
	 * @covers ::get_ical_event_string
	 * @covers ::recurrence_lines
	 * @covers ::exdate_line
	 *
	 * @return void
	 */
	public function test_cancelled_occurrences_are_emitted_as_exdate(): void {
		$post_id = $this->create_weekly_series();

		Occurrences::get_instance()->set_status( $post_id, $this->occurrence_id( 1 ), Occurrences::STATUS_CANCELLED );
		Occurrences::get_instance()->set_status( $post_id, $this->occurrence_id( 3 ), Occurrences::STATUS_CANCELLED );

		$this->enable_pretty_permalinks();

		$body = $this->body_for( $this->series_ical_url( $post_id ) );

		$this->assertSame(
			array(
				sprintf(
					'EXDATE;TZID=%s:%s,%s',
					self::TIMEZONE,
					$this->occurrence_id( 1 ),
					$this->occurrence_id( 3 )
				),
			),
			$this->lines_for( $body, 'EXDATE' ),
			'Cancelled occurrences must appear as exclusions on the series component.'
		);
		$this->assertSame(
			array( 'RRULE:FREQ=WEEKLY;BYDAY=' . $this->byday() . ';COUNT=5' ),
			$this->lines_for( $body, 'RRULE' ),
			'Cancellation is occurrence state: the stored rule must be untouched by it (PRD C-5).'
		);
	}

	/**
	 * A series with no cancelled occurrences emits no `EXDATE` line at all.
	 *
	 * @covers ::exdate_line
	 *
	 * @return void
	 */
	public function test_a_series_with_no_cancellations_emits_no_exdate(): void {
		$post_id = $this->create_weekly_series();

		$this->enable_pretty_permalinks();

		$this->assertSame(
			array(),
			$this->lines_for( $this->body_for( $this->series_ical_url( $post_id ) ), 'EXDATE' ),
			'An empty exclusion set must emit no EXDATE property rather than an empty one.'
		);
	}

	/**
	 * An open-ended series emits a rule with no end bound and still does not
	 * enumerate its occurrences.
	 *
	 * @covers ::get_ical_event_string
	 * @covers ::recurrence_lines
	 *
	 * @return void
	 */
	public function test_open_ended_series_emits_no_end_bound_and_does_not_enumerate(): void {
		$post_id = $this->create_weekly_series(
			array(
				'end_type' => 'never',
				'count'    => 0,
			)
		);

		$this->enable_pretty_permalinks();

		$body = $this->body_for( $this->series_ical_url( $post_id ) );

		$this->assertSame(
			array( 'RRULE:FREQ=WEEKLY;BYDAY=' . $this->byday() ),
			$this->lines_for( $body, 'RRULE' ),
			'An open-ended rule must carry neither UNTIL nor COUNT.'
		);
		$this->assertSame(
			1,
			substr_count( $body, 'BEGIN:VEVENT' ),
			'An open-ended series cannot be enumerated, so the feed must stay at one component.'
		);
	}

	/**
	 * Two occurrences downloaded individually carry different unique
	 * identifiers, their own starts, and a recurrence reference.
	 *
	 * @covers ::get_ical_event_string
	 * @covers ::recurrence_lines
	 * @covers ::uid
	 *
	 * @return void
	 */
	public function test_two_occurrences_downloaded_individually_have_different_uids(): void {
		$post_id = $this->create_weekly_series();

		$this->enable_pretty_permalinks();

		$first  = $this->body_for( $this->occurrence_ical_url( $post_id, $this->occurrence_id( 1 ) ) );
		$second = $this->body_for( $this->occurrence_ical_url( $post_id, $this->occurrence_id( 2 ) ) );

		$this->assertSame(
			array( sprintf( 'UID:gatherpress_%d_%s', $post_id, $this->occurrence_id( 1 ) ) ),
			$this->lines_for( $first, 'UID' ),
			'A single-occurrence download must carry a distinguishing UID.'
		);
		$this->assertSame(
			array( sprintf( 'UID:gatherpress_%d_%s', $post_id, $this->occurrence_id( 2 ) ) ),
			$this->lines_for( $second, 'UID' ),
			'The second occurrence must carry its own UID.'
		);
		$this->assertSame(
			array( sprintf( 'RECURRENCE-ID;TZID=%s:%s', self::TIMEZONE, $this->occurrence_id( 1 ) ) ),
			$this->lines_for( $first, 'RECURRENCE-ID' ),
			'A single-occurrence download must carry a recurrence reference.'
		);
		$this->assertSame(
			array( sprintf( 'DTSTART;TZID=%s:%s', self::TIMEZONE, $this->occurrence_id( 2 ) ) ),
			$this->lines_for( $second, 'DTSTART' ),
			'The second occurrence must start on its own date, not on the anchor.'
		);
		$this->assertSame(
			array(),
			$this->lines_for( $second, 'RRULE' ),
			'A single-occurrence component describes one date and carries no rule.'
		);
	}

	/**
	 * The series download and a single-occurrence download of the same event do
	 * not collide in the response cache.
	 *
	 * `Setup::get_ics_cache_key()` is built from the resolved query, and the
	 * occurrence is not part of the query in the sense that key was originally
	 * written for -- without the occurrence in the key, whichever of the two
	 * was requested first would be served for the other.
	 *
	 * @covers \GatherPress\Core\Calendar\Setup::get_ics_cache_key
	 *
	 * @return void
	 */
	public function test_a_series_download_and_an_occurrence_download_do_not_share_a_cache_entry(): void {
		$post_id = $this->create_weekly_series();

		$this->enable_pretty_permalinks();

		$series     = $this->body_for( $this->series_ical_url( $post_id ) );
		$occurrence = $this->body_for( $this->occurrence_ical_url( $post_id, $this->occurrence_id( 2 ) ) );

		$this->assertNotSame(
			$series,
			$occurrence,
			'The occurrence download must not be served the cached series component.'
		);
		$this->assertSame(
			array( sprintf( 'UID:gatherpress_%d_%s', $post_id, $this->occurrence_id( 2 ) ) ),
			$this->lines_for( $occurrence, 'UID' ),
			'The occurrence download must carry its own UID even when the series was requested first.'
		);
	}

	/**
	 * A non-recurring event's component is unchanged from today's, apart from
	 * the timezone-qualified start.
	 *
	 * Asserts the whole property list and its order, then the two datetime
	 * lines, then the properties whose values are stable, so a silently added
	 * or reordered property fails here.
	 *
	 * @covers ::get_ical_event_string
	 * @covers ::datetime_lines
	 *
	 * @return void
	 */
	public function test_non_recurring_event_output_is_unchanged_apart_from_the_start(): void {
		$post_id = $this->create_plain_event();

		$this->enable_pretty_permalinks();

		$body  = $this->body_for( $this->series_ical_url( $post_id ) );
		$lines = explode( "\r\n", $body );

		$this->assertSame(
			array(
				'BEGIN',
				'VERSION',
				'PRODID',
				'BEGIN',
				'URL',
				'DTSTART',
				'DTEND',
				'DTSTAMP',
				'LAST-MODIFIED',
				'SEQUENCE',
				'SUMMARY',
				'DESCRIPTION',
				'LOCATION',
				'UID',
				'END',
				'END',
			),
			array_map(
				static function ( string $line ): string {
					return preg_split( '/[;:]/', $line )[0];
				},
				$lines
			),
			'A non-recurring event must emit exactly the property list it emits today, in the same order.'
		);
		$this->assertSame(
			array( 'DTSTART;TZID=America/New_York:20300615T143000' ),
			$this->lines_for( $body, 'DTSTART' ),
			'The start is the one thing that changes: local wall clock, qualified by the named timezone.'
		);
		$this->assertSame(
			array( 'DTEND;TZID=America/New_York:20300615T163000' ),
			$this->lines_for( $body, 'DTEND' ),
			'The end follows the start.'
		);
		$this->assertSame(
			array( sprintf( 'UID:gatherpress_%d', $post_id ) ),
			$this->lines_for( $body, 'UID' ),
			'A non-recurring event keeps its post-ID-derived UID.'
		);
		$this->assertSame(
			array( 'SUMMARY:Sample Event' ),
			$this->lines_for( $body, 'SUMMARY' ),
			'Nothing else about the component moves.'
		);
	}

	/**
	 * An event whose timezone is a fixed offset keeps today's bare UTC start
	 * and is never given a recurrence rule.
	 *
	 * A `TZID` must name a tz-database identifier, and RFC 5545 forbids
	 * attaching an `RRULE` to a start that carries no timezone reference for
	 * anything but a fixed-offset series. REQ-3 keeps a recurring event on a
	 * named timezone, so this is the defensive arm rather than an authored one.
	 *
	 * @covers ::get_ical_event_string
	 * @covers ::series_timezone
	 * @covers ::datetime_lines
	 * @covers ::recurrence_lines
	 *
	 * @return void
	 */
	public function test_a_fixed_offset_timezone_keeps_the_bare_utc_start_and_no_rule(): void {
		$post_id = $this->create_weekly_series();

		update_post_meta( $post_id, 'gatherpress_timezone', 'UTC+5' );

		$this->enable_pretty_permalinks();

		$body = $this->body_for( $this->series_ical_url( $post_id ) );

		$this->assertSame(
			array( 'DTSTART:' . $this->anchor()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' ) ),
			$this->lines_for( $body, 'DTSTART' ),
			'A fixed-offset timezone cannot be named in a TZID, so the start stays in the bare UTC form.'
		);
		$this->assertSame(
			array(),
			$this->lines_for( $body, 'RRULE' ),
			'An RRULE must never be attached to a start carrying no timezone reference.'
		);
	}

	/**
	 * Every aggregate feed carries the recurrence rule for a series.
	 *
	 * This is the point REQ-14 names as where GatherPress should exceed the
	 * WordCamp.org implementation, whose aggregate feeds omit recurring series
	 * entirely.
	 *
	 * @covers ::get_ical_event_string
	 * @covers \GatherPress\Core\Calendar\Setup::get_ical_list
	 *
	 * @return void
	 */
	public function test_aggregate_feeds_carry_the_recurrence_rule(): void {
		$this->anchor_offset = '+10 days';

		$post_id  = $this->create_weekly_series();
		$venue_id = $this->factory->post->create(
			array(
				'post_type'   => Venue::POST_TYPE,
				'post_title'  => 'Brooklyn Office',
				'post_name'   => 'brooklyn-office',
				'post_status' => 'publish',
			)
		);

		$topic_id = $this->factory->term->create(
			array(
				'taxonomy' => Topic::TAXONOMY,
				'name'     => 'Weekly Meetups',
				'slug'     => 'weekly-meetups',
			)
		);

		wp_set_post_terms( $post_id, '_brooklyn-office', Venue::TAXONOMY );
		wp_set_object_terms( $post_id, array( (int) $topic_id ), Topic::TAXONOMY );

		$this->enable_pretty_permalinks();

		$expected = 'RRULE:FREQ=WEEKLY;BYDAY=' . $this->byday() . ';COUNT=5';
		$feeds    = array(
			'site-wide'         => home_url( '/feed/' . Calendar_Setup::ICAL_SLUG . '/' ),
			'post type archive' => trailingslashit( (string) get_post_type_archive_link( Event::POST_TYPE ) )
				. 'feed/' . Calendar_Setup::ICAL_SLUG . '/',
			'venue'             => trailingslashit( (string) get_permalink( $venue_id ) )
				. 'feed/' . Calendar_Setup::ICAL_SLUG . '/',
			'taxonomy term'     => trailingslashit( (string) get_term_link( (int) $topic_id, Topic::TAXONOMY ) )
				. 'feed/' . Calendar_Setup::ICAL_SLUG . '/',
		);

		foreach ( $feeds as $name => $url ) {
			$body = $this->body_for( $url );

			$this->assertSame(
				array( $expected ),
				$this->lines_for( $body, 'RRULE' ),
				sprintf( 'The %s feed must carry the recurrence rule for the series.', $name )
			);
			$this->assertSame(
				1,
				substr_count( $body, 'BEGIN:VEVENT' ),
				sprintf( 'The %s feed must describe the series with one component, not enumerate it.', $name )
			);
		}
	}

	/**
	 * A non-recurring event on a site that *does* have recurring events still
	 * gets no rule.
	 *
	 * The site-wide flag is a cheap gate, not the answer: whether a given event
	 * repeats is a property of that event.
	 *
	 * @covers ::recurrence_lines
	 *
	 * @return void
	 */
	public function test_a_plain_event_on_a_recurring_site_gets_no_rule(): void {
		$this->create_weekly_series();

		$plain_id = $this->create_plain_event();

		$this->enable_pretty_permalinks();

		$this->assertTrue(
			Recurrence_Query::site_has_recurring_events(),
			'The fixture must leave the site flagged as having recurring events for this to test anything.'
		);
		$this->assertSame(
			array(),
			$this->lines_for( $this->body_for( $this->series_ical_url( $plain_id ) ), 'RRULE' ),
			'An event with no rule of its own carries no RRULE, however many other events do.'
		);
	}

	/**
	 * Direct coverage for `current_occurrence()`'s three return paths.
	 *
	 * Xdebug does not reliably trace a private helper reached through a short
	 * same-class delegation, so each arm gets its own reflection invoke.
	 *
	 * @covers ::current_occurrence
	 *
	 * @return void
	 */
	public function test_current_occurrence_direct_invoke_covers_every_path(): void {
		$post_id = $this->create_weekly_series();
		$other   = $this->create_plain_event();

		Context::get_instance()->clear();

		$this->assertNull(
			Utility::invoke_hidden_method( new Calendar( $post_id ), 'current_occurrence' ),
			'Outside occurrence context a component describes the series.'
		);

		Context::get_instance()->set( $post_id, $this->occurrence_id( 1 ) );

		$this->assertNull(
			Utility::invoke_hidden_method( new Calendar( $other ), 'current_occurrence' ),
			'An occurrence belongs to its own series post and may not be claimed by another.'
		);
		$this->assertSame(
			$this->occurrence_id( 1 ),
			Utility::invoke_hidden_method( new Calendar( $post_id ), 'current_occurrence' )['recurrence_id'],
			'The series post the occurrence belongs to describes that occurrence.'
		);
	}

	/**
	 * REQ-16: every calendar entry point this change touches issues no query
	 * against the occurrence table, and writes no option, on a site whose
	 * `gatherpress_has_recurring_events` flag is `'0'`.
	 *
	 * Driven through the real feed URLs rather than through the serializer, so
	 * the guard is measured where a request actually reaches it.
	 *
	 * @covers ::get_ical_event_string
	 * @covers ::recurrence_lines
	 *
	 * @return void
	 */
	public function test_calendar_entry_points_cost_nothing_when_the_site_has_no_recurring_events(): void {
		global $wpdb;

		$post_id  = $this->create_plain_event();
		$venue_id = $this->factory->post->create(
			array(
				'post_type'   => Venue::POST_TYPE,
				'post_title'  => 'Brooklyn Office',
				'post_name'   => 'brooklyn-office',
				'post_status' => 'publish',
			)
		);

		$topic_id = $this->factory->term->create(
			array(
				'taxonomy' => Topic::TAXONOMY,
				'name'     => 'Weekly Meetups',
				'slug'     => 'weekly-meetups',
			)
		);

		wp_set_post_terms( $post_id, '_brooklyn-office', Venue::TAXONOMY );
		wp_set_object_terms( $post_id, array( (int) $topic_id ), Topic::TAXONOMY );

		$this->enable_pretty_permalinks();

		update_option( Recurrence_Query::HAS_RECURRING_OPTION, '0', true );

		$urls = array(
			$this->series_ical_url( $post_id ),
			home_url( '/feed/' . Calendar_Setup::ICAL_SLUG . '/' ),
			trailingslashit( (string) get_post_type_archive_link( Event::POST_TYPE ) )
				. 'feed/' . Calendar_Setup::ICAL_SLUG . '/',
			trailingslashit( (string) get_permalink( $venue_id ) ) . 'feed/' . Calendar_Setup::ICAL_SLUG . '/',
			trailingslashit( (string) get_term_link( (int) $topic_id, Topic::TAXONOMY ) )
				. 'feed/' . Calendar_Setup::ICAL_SLUG . '/',
		);

		wp_cache_delete( 'alloptions', 'options' );
		$options_before = wp_load_alloptions();
		$before         = count( $wpdb->queries );

		foreach ( $urls as $url ) {
			$this->body_for( $url );
		}

		$captured = array_column( array_slice( $wpdb->queries, $before ), 0 );

		wp_cache_delete( 'alloptions', 'options' );
		$options_after = wp_load_alloptions();

		$this->assertNotEmpty(
			$captured,
			'Failed to capture any queries; SAVEQUERIES must be on for this assertion to mean anything.'
		);
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$captured,
					static function ( string $sql ) use ( $wpdb ): bool {
						return str_contains( $sql, $wpdb->prefix . 'gatherpress_event_occurrences' );
					}
				)
			),
			'No calendar entry point may reach the occurrence table on a site with no recurring events.'
		);
		$this->assertSame(
			$options_before,
			$options_after,
			'No calendar entry point may write an autoloaded option on a site with no recurring events.'
		);
	}
}
