<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Query.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Calendar\Setup as Calendar_Setup;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Query as Event_Query;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Query;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Setup;
use GatherPress\Core\Topic;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use WP_Post;
use WP_Query;

/**
 * Class Test_Query.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Query
 */
class Test_Query extends Base {

	/**
	 * The reference daily rule: five consecutive daily occurrences.
	 *
	 * @since 0.36.0
	 * @var array
	 */
	const DAILY_RULE = array(
		'frequency' => 'daily',
		'interval'  => 1,
		'end_type'  => 'count',
		'count'     => 5,
	);

	/**
	 * Start every test from an empty occurrence table, independent of
	 * execution order relative to Test_Schema.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		gatherpress_reset_custom_tables();

		update_option( Query::HAS_RECURRING_OPTION, '0', true );
	}

	/**
	 * Build "now" in UTC.
	 *
	 * Every fixture in this file is relative to this value rather than to a
	 * literal calendar date, so no test in the file is a date bomb, and the
	 * series timezone is UTC so a recurrence identifier (a *local* start) and
	 * the GMT columns read identically.
	 *
	 * @since 0.36.0
	 *
	 * @return DateTimeImmutable Current time in UTC.
	 */
	protected function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Create a published, non-recurring event with a datetime range.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $start Event start in UTC.
	 * @param DateTimeImmutable $end   Event end in UTC.
	 *
	 * @return int The created post ID.
	 */
	protected function create_event_at( DateTimeImmutable $start, DateTimeImmutable $end ): int {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		add_post_meta(
			$post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => $start->format( 'Y-m-d H:i:s' ),
					'dateTimeEnd'   => $end->format( 'Y-m-d H:i:s' ),
					'timezone'      => 'UTC',
				)
			)
		);

		Event_Setup::get_instance()->set_datetimes( $post_id );

		return (int) $post_id;
	}

	/**
	 * Create a recurring series and project its occurrence rows.
	 *
	 * Fixtures are arranged in production order: the datetime blob and its
	 * derived row land first, then the recurrence blob, then the mirrors, then
	 * the projection. That is exactly the sequence a real save produces.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $start Anchor start in UTC.
	 * @param DateTimeImmutable $end   Anchor end in UTC.
	 * @param array             $rule  Recurrence rule values.
	 *
	 * @return int The created post ID.
	 */
	protected function create_series_at(
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		array $rule = self::DAILY_RULE
	): int {
		$post_id = $this->create_event_at( $start, $end );

		add_post_meta( $post_id, Meta::META_KEY, wp_json_encode( $rule ) );
		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );

		return $post_id;
	}

	/**
	 * Build the shared fixture set used by most tests in this file.
	 *
	 * The series anchor ends thirty minutes ago, so its first occurrence is the
	 * only past one and the remaining four are upcoming. Two standalone events
	 * bracket the series in time so an ordering assertion can prove that
	 * occurrences and plain events interleave rather than clustering.
	 *
	 * @since 0.36.0
	 *
	 * @return array<string, mixed> Post IDs and the anchor, keyed by role.
	 */
	protected function build_scenario(): array {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		return array(
			'anchor'  => $anchor,
			'series'  => $this->create_series_at( $anchor, $now->modify( '-30 minutes' ) ),
			'early'   => $this->create_event_at( $now->modify( '+12 hours' ), $now->modify( '+13 hours' ) ),
			'mid'     => $this->create_event_at( $now->modify( '+60 hours' ), $now->modify( '+61 hours' ) ),
			'past'    => $this->create_event_at( $now->modify( '-48 hours' ), $now->modify( '-47 hours' ) ),
			'undated' => $this->factory->post->create(
				array(
					'post_type'   => Event::POST_TYPE,
					'post_status' => 'publish',
				)
			),
		);
	}

	/**
	 * Build the recurrence identifier of the nth occurrence of a daily series.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $anchor Anchor start in UTC.
	 * @param int               $index  Zero-based occurrence index.
	 *
	 * @return string The recurrence identifier in `Ymd\THis` form.
	 */
	protected function occurrence_id( DateTimeImmutable $anchor, int $index ): string {
		return $anchor->modify( sprintf( '+%d days', $index ) )->format( 'Ymd\THis' );
	}

	/**
	 * Run an occurrence-aware event query and return it.
	 *
	 * Drives a real `WP_Query` through the production `pre_get_posts` and
	 * `posts_clauses` wiring rather than calling the filter callback directly.
	 *
	 * @since 0.36.0
	 *
	 * @param string $bucket Either `upcoming` or `past`.
	 * @param array  $args   Additional query arguments.
	 *
	 * @return WP_Query The executed query.
	 */
	protected function run_event_query( string $bucket, array $args = array() ): WP_Query {
		return new WP_Query( $this->event_query_args( $bucket, $args ) );
	}

	/**
	 * Build the arguments of a bucketed event query.
	 *
	 * @since 0.36.0
	 *
	 * @param string $bucket Either `upcoming` or `past`.
	 * @param array  $args   Additional query arguments.
	 *
	 * @return array The query arguments.
	 */
	protected function event_query_args( string $bucket, array $args = array() ): array {
		return array_merge(
			array(
				'post_type'                    => Event::POST_TYPE,
				Event_Query::EVENT_QUERY_PARAM => $bucket,
				'posts_per_page'               => 20,
				'orderby'                      => 'datetime',
				'order'                        => 'upcoming' === $bucket ? 'ASC' : 'DESC',
			),
			$args
		);
	}

	/**
	 * Reduce a query's results to `post_id|recurrence_id` strings.
	 *
	 * Identity is read off each result object, never off its list position.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_Query $query Executed query.
	 *
	 * @return string[] One entry per result row.
	 */
	protected function entries( WP_Query $query ): array {
		return array_map(
			static function ( WP_Post $post ): string {
				return $post->ID . '|' . (string) $post->gatherpress_recurrence_id;
			},
			$query->posts
		);
	}

	/**
	 * A site with no recurring events runs byte-identical SQL.
	 *
	 * Captures the clause array a real event query produces with the filter
	 * registered and with it removed, and asserts the two are identical. The
	 * early return in `expand_event_clauses()` is the only thing that can make
	 * this pass, and deleting it makes every clause grow a join.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_generated_sql_is_byte_identical_without_recurring_events(): void {
		$now = $this->now();

		$this->create_event_at( $now->modify( '+2 hours' ), $now->modify( '+3 hours' ) );

		$this->assertFalse(
			Query::site_has_recurring_events(),
			'Failed to assert the fixture site has no recurring events.'
		);

		[ $with, $without ] = $this->capture_clauses_both_ways( $this->event_query_args( 'upcoming' ) );

		$this->assertSame(
			$without,
			$with,
			'Failed to assert that a site without recurring events produces byte-identical SQL clauses.'
		);
	}

	/**
	 * Coverage for the scope guard: a non-event query is never touched.
	 *
	 * The clause filter runs on every `posts_clauses`, so on a site that does
	 * have recurring events the only thing keeping the occurrence join off
	 * posts, pages, search and REST collections is the events-table check.
	 * Same shape as the byte-identical assertion above, on the other guard arm.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_plain_post_query_clauses_are_unchanged_on_a_recurring_site(): void {
		$this->build_scenario();

		$this->assertTrue(
			Query::site_has_recurring_events(),
			'Failed to assert the fixture site has recurring events.'
		);

		$this->factory->post->create( array( 'post_status' => 'publish' ) );

		[ $with, $without ] = $this->capture_clauses_both_ways(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 5,
			)
		);

		$this->assertSame(
			$without,
			$with,
			'Failed to assert a plain post query is untouched on a site with recurring events.'
		);
		$this->assertStringNotContainsString(
			Query::OCCURRENCE_ALIAS,
			(string) $with['join'],
			'Failed to assert the occurrence table is not joined into a non-event query.'
		);
	}

	/**
	 * Coverage at the callback boundary: the clause array is returned unchanged.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_expand_event_clauses_returns_pieces_unchanged_without_recurring_events(): void {
		global $wpdb;

		$pieces = array(
			'where'    => " AND {$wpdb->posts}.post_type = 'gatherpress_event'",
			'groupby'  => '',
			'join'     => ' LEFT JOIN ' . $wpdb->prefix . 'gatherpress_events ON ' . $wpdb->posts . '.ID='
						. $wpdb->prefix . 'gatherpress_events.post_id',
			'orderby'  => $wpdb->prefix . 'gatherpress_events.datetime_start_gmt ASC',
			'distinct' => '',
			'fields'   => "{$wpdb->posts}.*",
			'limits'   => '',
		);

		$this->assertSame(
			$pieces,
			Query::get_instance()->expand_event_clauses( $pieces, new WP_Query() ),
			'Failed to assert that the clause filter is a no-op without recurring events.'
		);
	}

	/**
	 * Capture the `posts_clauses` array a bucketed event query ends up with.
	 *
	 * @since 0.36.0
	 *
	 * @param string $bucket Either `upcoming` or `past`.
	 *
	 * @return array The captured clause array.
	 */
	protected function capture_clauses( string $bucket ): array {
		return $this->capture_clauses_for( $this->event_query_args( $bucket ) );
	}

	/**
	 * Capture the `posts_clauses` array any query ends up with.
	 *
	 * @since 0.36.0
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array The captured clause array.
	 */
	protected function capture_clauses_for( array $args ): array {
		$captured = array();
		$capture  = static function ( array $pieces ) use ( &$captured ): array {
			$captured = $pieces;

			return $pieces;
		};

		add_filter( 'posts_clauses', $capture, 12 );
		new WP_Query( $args );
		remove_filter( 'posts_clauses', $capture, 12 );

		return $captured;
	}

	/**
	 * Capture the clauses of one query with and without the clause filter.
	 *
	 * @since 0.36.0
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array{0: array, 1: array} The clauses with, then without, the filter.
	 */
	protected function capture_clauses_both_ways( array $args ): array {
		$with = $this->capture_clauses_for( $args );

		remove_filter( 'posts_clauses', array( Query::get_instance(), 'expand_event_clauses' ), 11 );

		$without = $this->capture_clauses_for( $args );

		add_filter( 'posts_clauses', array( Query::get_instance(), 'expand_event_clauses' ), 11, 2 );

		return array( $with, $without );
	}

	/**
	 * Capture the final SQL a bucketed event query sends to the database.
	 *
	 * @since 0.36.0
	 *
	 * @param string $bucket Either `upcoming` or `past`.
	 * @param array  $args   Additional query arguments.
	 *
	 * @return string The SQL statement.
	 */
	protected function capture_request( string $bucket, array $args = array() ): string {
		$captured = '';
		$capture  = static function ( string $request ) use ( &$captured ): string {
			$captured = $request;

			return $request;
		};

		add_filter( 'posts_request', $capture, 10 );
		$this->run_event_query( $bucket, $args );
		remove_filter( 'posts_request', $capture, 10 );

		return $captured;
	}

	/**
	 * Coverage for the regression an earlier revision shipped: an inner join
	 * deletes every non-recurring event from every list.
	 *
	 * @covers ::expand_event_clauses
	 * @covers ::attach_occurrences
	 *
	 * @return void
	 */
	public function test_non_recurring_events_still_appear_when_recurring_events_exist(): void {
		$scenario = $this->build_scenario();

		$this->assertTrue(
			Query::site_has_recurring_events(),
			'Failed to assert the fixture site has recurring events.'
		);

		$upcoming = $this->entries( $this->run_event_query( 'upcoming' ) );

		$this->assertContains(
			$scenario['early'] . '|',
			$upcoming,
			'Failed to assert the non-recurring upcoming event survives the occurrence join.'
		);
		$this->assertContains(
			$scenario['mid'] . '|',
			$upcoming,
			'Failed to assert the second non-recurring upcoming event survives the occurrence join.'
		);

		$past = $this->entries( $this->run_event_query( 'past' ) );

		$this->assertContains(
			$scenario['past'] . '|',
			$past,
			'Failed to assert the non-recurring past event survives the occurrence join.'
		);
	}

	/**
	 * Coverage for a series contributing one list entry per scheduled occurrence.
	 *
	 * @covers ::expand_event_clauses
	 * @covers ::attach_occurrences
	 * @covers ::stamp_occurrence
	 *
	 * @return void
	 */
	public function test_upcoming_list_shows_four_occurrences_and_past_shows_one(): void {
		$scenario = $this->build_scenario();
		$series   = $scenario['series'];
		$anchor   = $scenario['anchor'];

		$upcoming = $this->entries( $this->run_event_query( 'upcoming' ) );

		$this->assertSame(
			array(
				$series . '|' . $this->occurrence_id( $anchor, 1 ),
				$series . '|' . $this->occurrence_id( $anchor, 2 ),
				$series . '|' . $this->occurrence_id( $anchor, 3 ),
				$series . '|' . $this->occurrence_id( $anchor, 4 ),
			),
			array_values(
				array_filter(
					$upcoming,
					static function ( string $entry ) use ( $series ): bool {
						return str_starts_with( $entry, $series . '|' );
					}
				)
			),
			'Failed to assert the series contributes its four upcoming occurrences.'
		);

		$past = $this->entries( $this->run_event_query( 'past' ) );

		$this->assertSame(
			array( $series . '|' . $this->occurrence_id( $anchor, 0 ) ),
			array_values(
				array_filter(
					$past,
					static function ( string $entry ) use ( $series ): bool {
						return str_starts_with( $entry, $series . '|' );
					}
				)
			),
			'Failed to assert the series contributes exactly its one past occurrence.'
		);
	}

	/**
	 * Coverage for the `COALESCE` ordering: occurrences and plain events interleave.
	 *
	 * @covers ::expand_event_clauses
	 * @covers ::coalesce_event_columns
	 *
	 * @return void
	 */
	public function test_recurring_and_non_recurring_interleave_by_date(): void {
		$scenario = $this->build_scenario();
		$series   = $scenario['series'];
		$anchor   = $scenario['anchor'];

		$this->assertSame(
			array(
				$scenario['early'] . '|',
				$series . '|' . $this->occurrence_id( $anchor, 1 ),
				$series . '|' . $this->occurrence_id( $anchor, 2 ),
				$scenario['mid'] . '|',
				$series . '|' . $this->occurrence_id( $anchor, 3 ),
				$series . '|' . $this->occurrence_id( $anchor, 4 ),
			),
			$this->entries( $this->run_event_query( 'upcoming' ) ),
			'Failed to assert occurrences and plain events order as one interleaved list.'
		);
	}

	/**
	 * Coverage for a canceled occurrence dropping out without the anchor row returning.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_cancelled_occurrence_does_not_resurrect_the_series_anchor_row(): void {
		$scenario = $this->build_scenario();
		$series   = $scenario['series'];
		$anchor   = $scenario['anchor'];

		Occurrences::get_instance()->set_status(
			$series,
			$this->occurrence_id( $anchor, 2 ),
			Occurrences::STATUS_CANCELLED
		);

		$upcoming = $this->entries( $this->run_event_query( 'upcoming' ) );

		$this->assertNotContains(
			$series . '|' . $this->occurrence_id( $anchor, 2 ),
			$upcoming,
			'Failed to assert the canceled occurrence is absent from the list.'
		);
		$this->assertSame(
			array(
				$series . '|' . $this->occurrence_id( $anchor, 1 ),
				$series . '|' . $this->occurrence_id( $anchor, 3 ),
				$series . '|' . $this->occurrence_id( $anchor, 4 ),
			),
			array_values(
				array_filter(
					$upcoming,
					static function ( string $entry ) use ( $series ): bool {
						return str_starts_with( $entry, $series . '|' );
					}
				)
			),
			'Failed to assert the series still contributes its three remaining occurrences.'
		);

		$this->assertNotContains(
			$series . '|',
			array_merge( $upcoming, $this->entries( $this->run_event_query( 'past' ) ) ),
			'Failed to assert the series anchor row did not resurrect as a plain event.'
		);
	}

	/**
	 * Coverage for the canceled-series guard: a fully canceled series is absent.
	 *
	 * Without the guard the `LEFT JOIN` matches nothing, the row falls through
	 * with `NULL` occurrence columns, `COALESCE` reaches the anchor date, and
	 * the canceled series reappears as an ordinary event.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_fully_cancelled_series_is_absent_from_the_list(): void {
		$scenario = $this->build_scenario();
		$series   = $scenario['series'];
		$anchor   = $scenario['anchor'];

		for ( $index = 0; $index < 5; $index++ ) {
			Occurrences::get_instance()->set_status(
				$series,
				$this->occurrence_id( $anchor, $index ),
				Occurrences::STATUS_CANCELLED
			);
		}

		$combined = array_merge(
			$this->entries( $this->run_event_query( 'upcoming' ) ),
			$this->entries( $this->run_event_query( 'past' ) )
		);

		$this->assertNotContains(
			$series . '|',
			$combined,
			'Failed to assert a fully canceled series does not reappear at its anchor date.'
		);
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$combined,
					static function ( string $entry ) use ( $series ): bool {
						return str_starts_with( $entry, $series . '|' );
					}
				)
			),
			'Failed to assert a fully canceled series contributes no list entries at all.'
		);
	}

	/**
	 * Coverage for the requirement that `'fields' => 'ids'` is never expanded.
	 *
	 * `WP_Query` returns before `the_posts` for an ids result set, so occurrence
	 * identity cannot travel with the rows. Expanding it would hand every
	 * caller of `Event\Query::get_events_list()` a repeated bare post ID it has
	 * no way to disambiguate, which is what produced duplicate iCal VEVENTs.
	 * The requirement is therefore one entry per event post, unexpanded, with
	 * no occurrence join in the SQL at all.
	 *
	 * `get_events_list()` also sets `no_found_rows`, so this pins the
	 * no-pagination path: `found_posts` stays zero.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_get_events_list_is_never_expanded_over_occurrences(): void {
		$scenario = $this->build_scenario();

		$request = '';
		$capture = static function ( string $sql ) use ( &$request ): string {
			$request = $sql;

			return $sql;
		};

		add_filter( 'posts_request', $capture );
		$query = Event_Query::get_instance()->get_events_list( 'upcoming', 20 );
		remove_filter( 'posts_request', $capture );

		$this->assertStringNotContainsString(
			Query::OCCURRENCE_ALIAS,
			$request,
			'Failed to assert an ids query joins no occurrence table.'
		);
		$this->assertSame(
			array_fill( 0, count( $query->posts ), 'integer' ),
			array_map( 'gettype', $query->posts ),
			'Failed to assert the ids result set is still a list of integers.'
		);
		$this->assertSame(
			array( (int) $scenario['early'], (int) $scenario['mid'] ),
			array_values( array_unique( $query->posts ) ),
			'Failed to assert the ids result set holds the upcoming event posts.'
		);
		$this->assertSame(
			$query->posts,
			array_values( array_unique( $query->posts ) ),
			'Failed to assert no post ID repeats in an ids result set.'
		);
		$this->assertSame(
			0,
			$query->found_posts,
			'Failed to assert found_posts stays zero when no_found_rows is set.'
		);
	}

	/**
	 * Coverage for the other compact field shape, `'fields' => 'id=>parent'`.
	 *
	 * `WP_Query` returns before `the_posts` for both compact shapes, so
	 * occurrence identity cannot travel with either. An expanded id=>parent
	 * result set is therefore a repeated, identity-less series ID that burns
	 * `posts_per_page`, and it diverges from what the same query returns as
	 * ids. The requirement is that both compact shapes return the same
	 * unexpanded post list, with no occurrence join in the SQL.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_id_parent_fields_query_is_never_expanded_over_occurrences(): void {
		$this->build_scenario();

		$ids = array_map( 'intval', $this->run_event_query( 'upcoming', array( 'fields' => 'ids' ) )->posts );

		$request = '';
		$capture = static function ( string $sql ) use ( &$request ): string {
			$request = $sql;

			return $sql;
		};

		add_filter( 'posts_request', $capture );
		$pairs_query = $this->run_event_query( 'upcoming', array( 'fields' => 'id=>parent' ) );
		remove_filter( 'posts_request', $capture );

		$pairs = array_map(
			static function ( $row ): int {
				return (int) $row->ID;
			},
			$pairs_query->posts
		);

		$this->assertStringNotContainsString(
			Query::OCCURRENCE_ALIAS,
			$request,
			'Failed to assert an id=>parent query joins no occurrence table.'
		);
		$this->assertSame(
			$ids,
			$pairs,
			'Failed to assert both compact field shapes return the same unexpanded post list.'
		);
		$this->assertSame(
			$pairs,
			array_values( array_unique( $pairs ) ),
			'Failed to assert no post ID repeats in an id=>parent result set.'
		);
	}

	/**
	 * Coverage for the iCal feed emitting one VEVENT per event, with unique UIDs.
	 *
	 * `Calendar\Setup::get_ical_list()` is the one production consumer of
	 * `get_events_list()`. It loops `have_posts()` and builds each VEVENT from
	 * `new Calendar( get_the_ID() )`, which reads the post's *anchor* datetime,
	 * so an occurrence-expanded ids list emits the same VEVENT repeatedly under
	 * one UID. RFC 5545 requires UID uniqueness within a VCALENDAR.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_ical_feed_emits_one_vevent_per_event_with_distinct_uids(): void {
		$now = $this->now();

		$series = $this->create_series_at( $now->modify( '+2 hours' ), $now->modify( '+3 hours' ) );
		$single = $this->create_event_at( $now->modify( '+30 hours' ), $now->modify( '+31 hours' ) );

		$this->assertCount(
			5,
			Occurrences::get_instance()->select_for_series( array( $series ) ),
			'Failed to assert the series projected five occurrence rows.'
		);

		$ical = Calendar_Setup::get_instance()->get_ical_list();

		$this->assertSame(
			2,
			substr_count( $ical, 'BEGIN:VEVENT' ),
			'Failed to assert the feed emits one VEVENT per event rather than one per occurrence.'
		);

		preg_match_all( '/^UID:(.*)$/m', $ical, $matches );

		$uids = array_map( 'trim', $matches[1] );

		sort( $uids );

		$expected = array( 'gatherpress_' . $series, 'gatherpress_' . $single );

		sort( $expected );

		$this->assertSame(
			$expected,
			$uids,
			'Failed to assert every VEVENT carries a distinct UID, as RFC 5545 requires.'
		);
	}

	/**
	 * Coverage for the admin post list staying one row per post.
	 *
	 * `edit.php` rows carry Edit/Trash/View actions and bulk-action checkboxes
	 * keyed by post ID, so an occurrence-expanded admin list would show one
	 * indistinguishable row per occurrence and make a bulk action on "a row"
	 * act on the whole series.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_admin_event_list_shows_one_row_per_post(): void {
		$scenario = $this->build_scenario();

		set_current_screen( 'edit-' . Event::POST_TYPE );

		$this->assertTrue( is_admin(), 'Failed to assert the admin screen context was set.' );

		// The admin "All" view, which is what `edit.php` runs by default:
		// `Event\Query::adjust_admin_event_sorting()` joins the events table at
		// priority 9 with no date predicate, so every dated event is listed.
		$query = new WP_Query(
			array(
				'post_type'      => Event::POST_TYPE,
				'posts_per_page' => 20,
				'orderby'        => 'datetime',
				'order'          => 'ASC',
			)
		);

		$ids = wp_list_pluck( $query->posts, 'ID' );

		set_current_screen( 'front' );

		$this->assertSame(
			$ids,
			array_values( array_unique( $ids ) ),
			'Failed to assert the admin event list shows each post exactly once.'
		);
		$this->assertContains(
			(int) $scenario['series'],
			$ids,
			'Failed to assert the series is still present in the admin event list.'
		);
	}

	/**
	 * Coverage for an admin-ajax front-end read staying expanded.
	 *
	 * `admin-ajax.php` serves front-end requests, including logged-out ones,
	 * and `is_admin()` is true for every one of them. A theme lazy-loading an
	 * upcoming list over admin-ajax must receive the same expanded list a
	 * page load renders, not one entry per series at its anchor date. The
	 * admin exemption exists for `edit.php` rows, whose actions and bulk
	 * checkboxes an ajax read does not have.
	 *
	 * The fixture satisfies every other guard arm, so the admin arm is the
	 * only one that can act: the site has recurring events, the fields are
	 * the full shape, the bucketed query joins the events table, and the
	 * occurrence table exists.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_admin_ajax_event_query_is_still_expanded(): void {
		$scenario = $this->build_scenario();

		set_current_screen( 'edit-' . Event::POST_TYPE );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertTrue( is_admin(), 'Fixture is inert: the admin screen context was not set.' );
		$this->assertTrue( wp_doing_ajax(), 'Fixture is inert: the ajax context was not set.' );

		$entries = $this->entries( $this->run_event_query( 'upcoming' ) );

		remove_filter( 'wp_doing_ajax', '__return_true' );
		set_current_screen( 'front' );

		foreach ( array( 1, 2, 3, 4 ) as $index ) {
			$this->assertContains(
				$scenario['series'] . '|' . $this->occurrence_id( $scenario['anchor'], $index ),
				$entries,
				'Failed to assert an admin-ajax upcoming query is expanded over occurrences.'
			);
		}
	}

	/**
	 * Coverage for a recurring event whose rule has produced no occurrence rows.
	 *
	 * A rule exists before its rows do, and a rule can legitimately produce
	 * none. Keying the `NULL`-fallback guard on the rule mirror rather than on
	 * the occurrence rows makes such a post match no join row *and* be denied
	 * the fallback, so a published event with a valid title and date vanishes
	 * from every list. Showing it at its anchor date is the required behavior.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_recurring_event_with_no_projected_rows_still_appears(): void {
		$now    = $this->now();
		$anchor = $now->modify( '+6 hours' );

		$series = $this->create_series_at( $anchor, $now->modify( '+7 hours' ) );

		// Clear the projected rows while the rule and its mirrors stay in
		// place. That is the state between a rule being saved and its
		// occurrences being projected, and the state a rule that yields nothing
		// leaves behind permanently.
		Occurrences::get_instance()->delete_for_post( $series );

		$this->assertSame(
			array(),
			Occurrences::get_instance()->select_for_series( array( $series ) ),
			'Failed to assert the series has no occurrence rows.'
		);
		$this->assertSame(
			'daily',
			get_post_meta( $series, Query::FREQUENCY_META_KEY, true ),
			'Failed to assert the rule mirror is still present.'
		);
		$this->assertTrue(
			Query::site_has_recurring_events(),
			'Failed to assert the site still reports recurring events.'
		);

		$this->assertSame(
			array( $series . '|' ),
			$this->entries( $this->run_event_query( 'upcoming' ) ),
			'Failed to assert a recurring event with no occurrence rows still appears at its anchor date.'
		);
	}

	/**
	 * Coverage for the `tax_query` duplicate-row problem.
	 *
	 * An event matching two selected terms yields two joined rows, so WordPress
	 * groups on the post ID. Collapsing on the post ID alone would collapse
	 * every occurrence of a series into one entry, so the group has to widen to
	 * the `(post_id, recurrence_id)` tuple: the series must keep all four
	 * occurrences while the doubly-matched plain event still appears once.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_tax_query_duplicates_collapse_on_the_occurrence_tuple(): void {
		$scenario = $this->build_scenario();
		$series   = $scenario['series'];
		$anchor   = $scenario['anchor'];

		wp_set_object_terms( $series, array( 'alpha', 'beta' ), Topic::TAXONOMY );
		wp_set_object_terms( (int) $scenario['early'], array( 'alpha', 'beta' ), Topic::TAXONOMY );

		$query = $this->run_event_query(
			'upcoming',
			array(
				'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => Topic::TAXONOMY,
						'field'    => 'slug',
						'terms'    => array( 'alpha', 'beta' ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				$scenario['early'] . '|',
				$series . '|' . $this->occurrence_id( $anchor, 1 ),
				$series . '|' . $this->occurrence_id( $anchor, 2 ),
				$series . '|' . $this->occurrence_id( $anchor, 3 ),
				$series . '|' . $this->occurrence_id( $anchor, 4 ),
			),
			$this->entries( $query ),
			'Failed to assert term duplicates collapse on the occurrence tuple, not on the post ID.'
		);
		$this->assertSame(
			5,
			$query->found_posts,
			'Failed to assert found_posts counts the de-duplicated joined rows.'
		);
	}

	/**
	 * Coverage for pagination over an occurrence-expanded list.
	 *
	 * @covers ::expand_event_clauses
	 * @covers ::attach_occurrences
	 *
	 * @return void
	 */
	public function test_limit_and_pagination_do_not_repeat_the_series(): void {
		$scenario = $this->build_scenario();

		$first  = $this->run_event_query( 'upcoming', array( 'posts_per_page' => 2 ) );
		$second = $this->run_event_query(
			'upcoming',
			array(
				'posts_per_page' => 2,
				'paged'          => 2,
			)
		);
		$third  = $this->run_event_query(
			'upcoming',
			array(
				'posts_per_page' => 2,
				'paged'          => 3,
			)
		);

		$pages = array( $this->entries( $first ), $this->entries( $second ), $this->entries( $third ) );
		$all   = array_merge( ...$pages );

		$this->assertSame(
			$all,
			array_values( array_unique( $all ) ),
			'Failed to assert no occurrence repeats across pages.'
		);
		$this->assertCount(
			6,
			$all,
			'Failed to assert three pages of two cover every list entry exactly once.'
		);
		$this->assertSame(
			6,
			$first->found_posts,
			'Failed to assert found_posts counts joined rows rather than distinct posts.'
		);
		$this->assertSame(
			3,
			$first->max_num_pages,
			'Failed to assert pagination is computed from the joined-row count.'
		);
	}

	/**
	 * Coverage for the deterministic tiebreaker an expanded ordering needs.
	 *
	 * Two occurrences of different series routinely share a start datetime, and
	 * the ordering column alone cannot separate them. MySQL's sort is not
	 * stable, so a tied pair can be ordered one way for `LIMIT 0, 10` and the
	 * other for `LIMIT 10, 10`, which puts one entry on two pages and the other
	 * on none. The canonical `(post_id, recurrence_id)` list key is what makes
	 * the ordering total.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_expanded_ordering_is_total(): void {
		global $wpdb;

		$this->build_scenario();

		$request  = $this->capture_request( 'upcoming' );
		$order_by = trim( substr( $request, (int) strrpos( $request, 'ORDER BY' ) ) );

		$this->assertStringStartsWith(
			sprintf(
				'ORDER BY COALESCE( %s.datetime_start_gmt,',
				Query::OCCURRENCE_ALIAS
			),
			$order_by,
			'Failed to assert the expanded ordering leads with the effective occurrence start.'
		);
		$this->assertStringContainsString(
			sprintf(
				') ASC, `%s`.ID ASC, `%s`.recurrence_id ASC',
				$wpdb->posts,
				Query::OCCURRENCE_ALIAS
			),
			$order_by,
			'Failed to assert the occurrence list key follows the effective start as the tiebreaker.'
		);
	}

	/**
	 * Coverage for the other arm: an unordered query stays unordered.
	 *
	 * `'orderby' => 'none'` asks for no sort at all, and appending a tiebreaker
	 * to an empty clause would hand it an `ORDER BY` it never had, and a
	 * filesort with it.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_expansion_does_not_order_a_query_that_asked_for_no_order(): void {
		$this->build_scenario();

		$request = $this->capture_request( 'upcoming', array( 'orderby' => 'none' ) );

		$this->assertStringContainsString(
			Query::OCCURRENCE_ALIAS,
			$request,
			'Failed to assert the unordered query was still occurrence-expanded.'
		);
		$this->assertStringNotContainsString(
			'ORDER BY',
			$request,
			'Failed to assert an unordered query acquires no ORDER BY from expansion.'
		);
	}

	/**
	 * Coverage for the documented exclusion of dateless events from both buckets.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_event_with_no_date_is_excluded_from_both_buckets(): void {
		$scenario = $this->build_scenario();

		$combined = array_merge(
			$this->entries( $this->run_event_query( 'upcoming' ) ),
			$this->entries( $this->run_event_query( 'past' ) )
		);

		$this->assertNotContains(
			$scenario['undated'] . '|',
			$combined,
			'Failed to assert an event with no datetime row stays out of both buckets.'
		);
	}

	/**
	 * Coverage for the measured query plan.
	 *
	 * The `COALESCE` ordering is not sargable, so a filesort is expected and
	 * accepted. `Using temporary` is deliberately not asserted against, since a
	 * `tax_query` produces one and collapsing that away would collapse
	 * occurrences too. The discriminating assertion is the one below it: the
	 * occurrence join must stay index-served, which a row-count budget does not
	 * detect at fixture scale.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_explain_plan_filesorts_and_keeps_the_occurrence_join_indexed(): void {
		global $wpdb;

		$this->build_scenario();

		$request = $this->capture_request( 'upcoming' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- EXPLAIN of a query WordPress already prepared.
		$plan = $wpdb->get_results( 'EXPLAIN ' . $request, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$extra = implode( ' ', array_column( $plan, 'Extra' ) );

		$this->assertStringContainsString(
			'Using filesort',
			$extra,
			'Failed to assert the accepted filesort is present in the query plan.'
		);

		// The filesort is the price of COALESCE ordering; the occurrence join
		// itself must still be index-served, which is what keeps rows examined
		// proportional to the occurrences a series actually has.
		$occurrence_plan = array_values(
			array_filter(
				$plan,
				static function ( array $row ): bool {
					return Query::OCCURRENCE_ALIAS === $row['table'];
				}
			)
		);

		$this->assertCount( 1, $occurrence_plan, 'Failed to assert the occurrence table is in the query plan.' );
		$this->assertNotNull(
			$occurrence_plan[0]['key'],
			'Failed to assert the occurrence join is served by an index rather than a table scan.'
		);
		$this->assertNotSame(
			'ALL',
			$occurrence_plan[0]['type'],
			'Failed to assert the occurrence join avoids a full table scan.'
		);
	}

	/**
	 * Coverage for `attach_occurrences` leaving a non-event query untouched.
	 *
	 * @covers ::attach_occurrences
	 * @covers ::is_event_query
	 *
	 * @return void
	 */
	public function test_attach_occurrences_leaves_non_event_queries_untouched(): void {
		$this->build_scenario();

		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$query   = new WP_Query( array( 'post_type' => 'post' ) );

		$this->assertSame(
			array( $post_id ),
			wp_list_pluck( $query->posts, 'ID' ),
			'Failed to assert the plain post query returned its post.'
		);
		$this->assertFalse(
			property_exists( $query->posts[0], 'gatherpress_recurrence_id' ),
			'Failed to assert a non-event query result carries no occurrence identity.'
		);
	}

	/**
	 * Coverage for `attach_occurrences` short-circuiting on a site with no recurring events.
	 *
	 * @covers ::attach_occurrences
	 *
	 * @return void
	 */
	public function test_attach_occurrences_leaves_results_untouched_without_recurring_events(): void {
		$now = $this->now();

		$this->create_event_at( $now->modify( '+2 hours' ), $now->modify( '+3 hours' ) );

		$query = $this->run_event_query( 'upcoming' );

		$this->assertCount( 1, $query->posts, 'Failed to assert the single event was returned.' );
		$this->assertFalse(
			property_exists( $query->posts[0], 'gatherpress_recurrence_id' ),
			'Failed to assert no occurrence identity is stamped without recurring events.'
		);
	}

	/**
	 * Coverage for a non-recurring event carrying a null occurrence identity.
	 *
	 * @covers ::attach_occurrences
	 * @covers ::stamp_occurrence
	 *
	 * @return void
	 */
	public function test_non_recurring_event_is_stamped_with_a_null_recurrence_id(): void {
		$scenario = $this->build_scenario();

		$query = $this->run_event_query( 'upcoming' );
		$posts = array_values(
			array_filter(
				$query->posts,
				static function ( WP_Post $post ) use ( $scenario ): bool {
					return (int) $post->ID === (int) $scenario['early'];
				}
			)
		);

		$this->assertCount( 1, $posts, 'Failed to assert the non-recurring event is in the result set.' );
		$this->assertNull(
			$posts[0]->gatherpress_recurrence_id,
			'Failed to assert a non-recurring event carries a null recurrence identifier.'
		);
	}

	/**
	 * Coverage for identity travelling on clones rather than on the objects WordPress built.
	 *
	 * Mutating the objects core created would leave `$unfiltered_posts` holding
	 * recurrence-stamped posts, which is what the "do not poison the post
	 * cache" rule guards against.
	 *
	 * @covers ::attach_occurrences
	 *
	 * @return void
	 */
	public function test_attach_occurrences_returns_clones_rather_than_mutating_core_objects(): void {
		$this->build_scenario();

		$originals = array();
		$capture   = static function ( array $posts ) use ( &$originals ): array {
			$originals = $posts;

			return $posts;
		};

		add_filter( 'the_posts', $capture, 9 );
		$query = $this->run_event_query( 'upcoming' );
		remove_filter( 'the_posts', $capture, 9 );

		$this->assertNotEmpty( $originals, 'Failed to assert the pre-filter results were captured.' );

		foreach ( $originals as $original ) {
			$this->assertFalse(
				property_exists( $original, 'gatherpress_recurrence_id' ),
				'Failed to assert the objects WordPress built were not mutated in place.'
			);
		}
	}

	/**
	 * Coverage for `stamp_occurrence` publishing a recurrence identifier.
	 *
	 * @covers ::stamp_occurrence
	 *
	 * @return void
	 */
	public function test_stamp_occurrence_publishes_the_select_alias(): void {
		$post = new WP_Post( (object) array( 'ID' => 1 ) );

		$post->gatherpress_occurrence_recurrence_id = '20260903T180000';

		$stamped = Utility::invoke_hidden_method( Query::get_instance(), 'stamp_occurrence', array( $post ) );

		$this->assertSame(
			'20260903T180000',
			$stamped->gatherpress_recurrence_id,
			'Failed to assert the select alias is published as the recurrence identifier.'
		);
		$this->assertFalse(
			property_exists( $stamped, 'gatherpress_occurrence_recurrence_id' ),
			'Failed to assert the raw select alias is removed from the published object.'
		);
	}

	/**
	 * Coverage for `stamp_occurrence` on a row with no occurrence.
	 *
	 * @covers ::stamp_occurrence
	 *
	 * @return void
	 */
	public function test_stamp_occurrence_publishes_null_when_the_alias_is_absent(): void {
		$post = new WP_Post( (object) array( 'ID' => 1 ) );

		$stamped = Utility::invoke_hidden_method( Query::get_instance(), 'stamp_occurrence', array( $post ) );

		$this->assertNull(
			$stamped->gatherpress_recurrence_id,
			'Failed to assert an absent select alias publishes as null.'
		);
	}

	/**
	 * Coverage for `is_event_query` recognizing a supported post type.
	 *
	 * @covers ::is_event_query
	 *
	 * @return void
	 */
	public function test_is_event_query_accepts_a_supported_post_type(): void {
		$this->assertTrue(
			Utility::invoke_hidden_method(
				Query::get_instance(),
				'is_event_query',
				array( new WP_Query( array( 'post_type' => Event::POST_TYPE ) ) )
			),
			'Failed to assert an event query is recognized.'
		);
	}

	/**
	 * Coverage for `is_event_query` rejecting an unsupported post type.
	 *
	 * @covers ::is_event_query
	 *
	 * @return void
	 */
	public function test_is_event_query_rejects_an_unsupported_post_type(): void {
		$this->assertFalse(
			Utility::invoke_hidden_method(
				Query::get_instance(),
				'is_event_query',
				array( new WP_Query( array( 'post_type' => 'post' ) ) )
			),
			'Failed to assert a plain post query is not an event query.'
		);
	}

	/**
	 * Coverage for `coalesce_event_columns` rewriting both renderings of the anchor columns.
	 *
	 * `Event\Query` writes the ORDER BY column unquoted and the WHERE column
	 * back-quoted through `$wpdb->prepare()`'s `%i` placeholder, so both forms
	 * have to be rewritten.
	 *
	 * @covers ::coalesce_event_columns
	 *
	 * @return void
	 */
	public function test_coalesce_event_columns_rewrites_both_renderings(): void {
		$rewritten = Utility::invoke_hidden_method(
			Query::get_instance(),
			'coalesce_event_columns',
			array(
				'wp_gatherpress_events.datetime_start_gmt ASC'
				. " AND `wp_gatherpress_events`.`datetime_end_gmt` >= '2026-01-01 00:00:00'",
				'wp_gatherpress_events',
			)
		);

		$this->assertSame(
			'COALESCE( gatherpress_occurrence.datetime_start_gmt, wp_gatherpress_events.datetime_start_gmt ) ASC'
			. ' AND COALESCE( gatherpress_occurrence.datetime_end_gmt, wp_gatherpress_events.datetime_end_gmt )'
			. " >= '2026-01-01 00:00:00'",
			$rewritten,
			'Failed to assert both the unquoted and back-quoted column renderings are wrapped in COALESCE.'
		);
	}

	/**
	 * Coverage for `coalesce_event_columns` leaving an unrelated clause alone.
	 *
	 * @covers ::coalesce_event_columns
	 *
	 * @return void
	 */
	public function test_coalesce_event_columns_leaves_unrelated_clauses_alone(): void {
		$this->assertSame(
			'wp_posts.post_title ASC',
			Utility::invoke_hidden_method(
				Query::get_instance(),
				'coalesce_event_columns',
				array( 'wp_posts.post_title ASC', 'wp_gatherpress_events' )
			),
			'Failed to assert a clause with no anchor columns is returned unchanged.'
		);
	}
	/**
	 * The results filter stamps nothing yet, and it has to leave both result
	 * shapes alone: the plugin's own read API asks for IDs, while a template
	 * loop gets `WP_Post` objects.
	 *
	 * @covers ::attach_occurrences
	 *
	 * @return void
	 */
	public function test_attach_occurrences_returns_both_result_shapes_unchanged(): void {
		$post_ids = array(
			$this->factory->post->create(),
			$this->factory->post->create(),
		);
		$posts    = array_map( 'get_post', $post_ids );
		$instance = Query::get_instance();

		$this->assertSame(
			$post_ids,
			$instance->attach_occurrences( $post_ids, new WP_Query() ),
			'Failed to assert that attach_occurrences returns an ID result set unchanged.'
		);
		$this->assertSame(
			$posts,
			$instance->attach_occurrences( $posts, new WP_Query() ),
			'Failed to assert that attach_occurrences returns a WP_Post result set unchanged.'
		);
		$this->assertSame(
			array(),
			$instance->attach_occurrences( array(), new WP_Query() ),
			'Failed to assert that attach_occurrences returns an empty result set unchanged.'
		);
	}

	/**
	 * An empty clause set survives the pass-through too, which is the shape a
	 * query with no filters of its own arrives in.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_expand_event_clauses_passes_an_empty_clause_set_through(): void {
		$this->assertSame(
			array(),
			Query::get_instance()->expand_event_clauses( array(), new WP_Query() ),
			'Failed to assert that expand_event_clauses passes an empty clause set through.'
		);
	}

	/**
	 * The clause filter hands back exactly what it was given, key order and
	 * all, so a query that ran through it is byte-identical to one that did
	 * not.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_expand_event_clauses_returns_the_clauses_unchanged(): void {
		$pieces = array(
			'where'    => ' AND post_type = \'gatherpress_event\'',
			'groupby'  => '',
			'join'     => '',
			'orderby'  => 'wp_posts.post_date DESC',
			'distinct' => '',
			'fields'   => 'wp_posts.ID',
			'limits'   => 'LIMIT 0, 10',
		);

		$this->assertSame(
			$pieces,
			Query::get_instance()->expand_event_clauses( $pieces, new WP_Query() ),
			'Failed to assert that expand_event_clauses returns the clauses unchanged.'
		);
	}

	/**
	 * Neither filter is registered yet. Registering either one early would put
	 * an unfinished join on every event query on the site, so absence from the
	 * hook table is part of the contract, not an oversight.
	 *
	 * @return void
	 */
	public function test_neither_occurrence_filter_is_registered(): void {
		$instance = Query::get_instance();

		$this->assertFalse(
			has_filter( 'posts_clauses', array( $instance, 'expand_event_clauses' ) ),
			'Failed to assert that expand_event_clauses is not hooked to posts_clauses.'
		);
		$this->assertFalse(
			has_filter( 'the_posts', array( $instance, 'attach_occurrences' ) ),
			'Failed to assert that attach_occurrences is not hooked to the_posts.'
		);
	}
}
