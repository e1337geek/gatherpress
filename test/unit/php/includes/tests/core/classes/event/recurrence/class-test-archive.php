<?php
/**
 * Class handles unit tests for the occurrence-aware event post type archive.
 *
 * Every test in this file drives a real front-end request through `go_to()`
 * followed by the `template_redirect` action that `wp-includes/template-loader.php`
 * fires, because the defect this file exists for lives precisely in the gap
 * between the query WordPress parses from the URL and the query GatherPress
 * substitutes for it. A test that builds a `WP_Query` by hand, or that calls
 * `Event\Setup::handle_event_archive_redirect()` directly, travels neither side
 * of that gap and proves nothing about it.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Query as Event_Query;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Query;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use WP_Post;
use WP_Query;

/**
 * Class Test_Archive.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Setup
 */
class Test_Archive extends Base {

	/**
	 * Number of occurrences each fixture series projects.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const SERIES_COUNT = 8;

	/**
	 * Permalink structure in force before this test replaced it.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	protected string $permalink_structure = '';

	/**
	 * Ensure the occurrence table exists before every test.
	 *
	 * The archive requests below travel the plain-permalink form of the post
	 * type archive URL — `?post_type=gatherpress_event&paged=2`. That is a
	 * deliberate choice, not a shortcut: the rewrite layer is not implicated
	 * in this defect, which lives entirely in what `WP::handle_404()` decides
	 * before `template_redirect` runs, and re-registering the post type under
	 * a pretty permastruct once per test accumulates duplicate archive rules
	 * in `$wp_rewrite->extra_rules_top`, which reorders them and makes the
	 * suite depend on execution order. Both URL forms parse to the same
	 * `is_post_type_archive` + `paged` query, which is the input this file is
	 * about.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );

		global $wp_rewrite;

		$this->permalink_structure = (string) $wp_rewrite->permalink_structure;

		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();
	}

	/**
	 * Restore whatever permalink structure was in force before the test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( $this->permalink_structure );
		$wp_rewrite->flush_rules();

		parent::tearDown();
	}

	/**
	 * Build "now" in UTC.
	 *
	 * Every fixture below is relative to this value rather than to a literal
	 * calendar date, because the archive compares its rows against the wall
	 * clock through `Event\Query::get_datetime_comparison_column()`.
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
	 * Create a daily recurring series and project its occurrence rows.
	 *
	 * Fixtures are arranged in production order: the datetime blob and its
	 * derived events-table row first, then the recurrence blob, then the
	 * derived mirrors, then the projection.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $start Anchor start in UTC.
	 * @param DateTimeImmutable $end   Anchor end in UTC.
	 *
	 * @return int The created post ID.
	 */
	protected function create_series_at( DateTimeImmutable $start, DateTimeImmutable $end ): int {
		$post_id = $this->create_event_at( $start, $end );

		add_post_meta(
			$post_id,
			Meta::META_KEY,
			wp_json_encode(
				array(
					'frequency' => 'daily',
					'interval'  => 1,
					'end_type'  => 'count',
					'count'     => self::SERIES_COUNT,
				)
			)
		);
		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );

		return $post_id;
	}

	/**
	 * Build the archive fixture: three series and two standalone events.
	 *
	 * Three series of eight daily occurrences plus two plain events is
	 * twenty-six list entries over three pages of ten, which is what makes a
	 * broken page two distinguishable from an empty one: the union across
	 * pages has to account for every entry, and page two is neither the first
	 * page nor the last.
	 *
	 * The anchors are staggered by one hour and the earlier standalone event
	 * sits *between* the first two series anchors, so the required answer —
	 * ascending by occurrence datetime — interleaves all three series with a
	 * plain event on the very first page. The answer the archive produced
	 * before this fixture existed, `wp_posts.post_date DESC`, orders strictly
	 * by creation: it groups each series together and puts the *last*-created
	 * post first. The two orderings share no prefix, which is the point
	 * (rule 3a #8).
	 *
	 * @since 0.36.0
	 *
	 * @return array<string, mixed> Post IDs and their occurrence starts, keyed by role.
	 */
	protected function build_archive_fixture(): array {
		$now = $this->now();

		$anchors = array(
			'series_a' => $now->modify( '+1 hour' ),
			'series_b' => $now->modify( '+2 hours' ),
			'series_c' => $now->modify( '+3 hours' ),
		);

		$fixture = array( 'anchors' => $anchors );

		foreach ( $anchors as $role => $anchor ) {
			$fixture[ $role ] = $this->create_series_at( $anchor, $anchor->modify( '+30 minutes' ) );
		}

		$fixture['plain_starts'] = array(
			'plain_early' => $now->modify( '+90 minutes' ),
			'plain_late'  => $now->modify( '+200 hours' ),
		);

		foreach ( $fixture['plain_starts'] as $role => $start ) {
			$fixture[ $role ] = $this->create_event_at( $start, $start->modify( '+30 minutes' ) );
		}

		return $fixture;
	}

	/**
	 * State the entries the archive must list, ordered ascending by occurrence start.
	 *
	 * Built from the fixture's own arithmetic rather than from a run of the
	 * code under test, so the expectation is the requirement and not a
	 * transcript of current behavior (rule 3a #5).
	 *
	 * @since 0.36.0
	 *
	 * @param array $fixture Fixture returned by `build_archive_fixture()`.
	 *
	 * @return string[] `post_id|recurrence_id` strings in required order.
	 */
	protected function expected_entries( array $fixture ): array {
		$rows = array();

		foreach ( $fixture['plain_starts'] as $role => $start ) {
			$rows[] = array( $fixture[ $role ], '', $start );
		}

		foreach ( $fixture['anchors'] as $role => $anchor ) {
			for ( $index = 0; $index < self::SERIES_COUNT; $index++ ) {
				$start  = $anchor->modify( sprintf( '+%d days', $index ) );
				$rows[] = array( $fixture[ $role ], $start->format( 'Ymd\THis' ), $start );
			}
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return $left[2] <=> $right[2];
			}
		);

		return array_map(
			static function ( array $row ): string {
				return $row[0] . '|' . $row[1];
			},
			$rows
		);
	}

	/**
	 * Drive one real archive request and return the resulting global query.
	 *
	 * `go_to()` runs `WP::main()`, which parses the request, runs the main
	 * query and calls `handle_404()`. `template_redirect` is what
	 * `wp-includes/template-loader.php` fires immediately afterwards, and it
	 * is the hook `Event\Setup::handle_event_archive_redirect()` registers on,
	 * so firing it here is the production sequence rather than a shortcut.
	 *
	 * @since 0.36.0
	 *
	 * @param int $page Page number to request.
	 *
	 * @return WP_Query The global query after the archive request completes.
	 */
	protected function request_archive_page( int $page ): WP_Query {
		$url = (string) get_post_type_archive_link( Event::POST_TYPE );

		if ( 1 < $page ) {
			$url = add_query_arg( 'paged', $page, $url );
		}

		$this->go_to( $url );

		// Firing a core hook, not declaring a plugin one: this is the action
		// `wp-includes/template-loader.php` fires after `WP::main()` returns.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'template_redirect' );

		global $wp_query;

		return $wp_query;
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
	 * Coverage for the paged archive request that used to 404.
	 *
	 * `found_posts` counts occurrences, so WordPress advertises more pages
	 * than there are event posts. Every one of those advertised pages has to
	 * be reachable and has to carry occurrence-expanded rows.
	 *
	 * @covers ::handle_event_archive_redirect
	 * @covers ::fall_back_to_archive_mode
	 *
	 * @return void
	 */
	public function test_every_advertised_archive_page_is_reachable(): void {
		$fixture  = $this->build_archive_fixture();
		$expected = $this->expected_entries( $fixture );

		$this->assertCount(
			26,
			$expected,
			'Failed to assert the fixture states twenty-six archive entries.'
		);

		$first = $this->request_archive_page( 1 );

		$this->assertFalse(
			$first->is_404(),
			'Failed to assert the unpaged archive request is not a 404.'
		);
		$this->assertSame(
			count( $expected ),
			$first->found_posts,
			'Failed to assert found_posts counts occurrences rather than event posts.'
		);
		$this->assertSame(
			3,
			$first->max_num_pages,
			'Failed to assert the archive advertises three pages of ten.'
		);

		$pages = array( $this->entries( $first ) );

		foreach ( array( 2, 3 ) as $page ) {
			$query = $this->request_archive_page( $page );

			$this->assertFalse(
				$query->is_404(),
				sprintf( 'Failed to assert archive page %d is not a 404.', $page )
			);
			$this->assertSame(
				count( $expected ),
				$query->found_posts,
				sprintf( 'Failed to assert page %d counts the same rows page one advertised.', $page )
			);

			$pages[] = $this->entries( $query );
		}

		$this->assertSame(
			array( 10, 10, 6 ),
			array_map( 'count', $pages ),
			'Failed to assert the three pages carry ten, ten and six entries.'
		);
		$this->assertSame(
			$expected,
			array_merge( ...$pages ),
			'Failed to assert the union across pages is every occurrence, in occurrence-datetime order.'
		);
	}

	/**
	 * Coverage for the ordering change: the archive interleaves by occurrence datetime.
	 *
	 * The assertion names the exact first page rather than a weaker property
	 * such as "more than one series appears", because the requirement is an
	 * ordering and only the required ordering can produce that exact list.
	 *
	 * @covers ::fall_back_to_archive_mode
	 *
	 * @return void
	 */
	public function test_archive_orders_by_occurrence_datetime_ascending(): void {
		$fixture  = $this->build_archive_fixture();
		$expected = $this->expected_entries( $fixture );

		$first = $this->request_archive_page( 1 );

		$this->assertSame(
			array_slice( $expected, 0, 10 ),
			$this->entries( $first ),
			'Failed to assert the first archive page is the ten earliest occurrences.'
		);

		$posts_on_first_page = array_unique(
			array_map(
				static function ( WP_Post $post ): int {
					return $post->ID;
				},
				$first->posts
			)
		);

		$this->assertCount(
			4,
			$posts_on_first_page,
			'Failed to assert all three series and the early standalone event share the first page.'
		);
	}

	/**
	 * Coverage for the boundary: a page past the end still 404s.
	 *
	 * The 404 deferral must not become a blanket "never 404 an event
	 * archive". A request past `max_num_pages` has nothing to render and has
	 * to keep saying so.
	 *
	 * @covers ::handle_event_archive_redirect
	 *
	 * @return void
	 */
	public function test_archive_page_past_the_end_is_still_a_404(): void {
		$this->build_archive_fixture();

		$query = $this->request_archive_page( 4 );

		$this->assertTrue(
			$query->is_404(),
			'Failed to assert a request past the last archive page is a 404.'
		);
		$this->assertSame(
			array(),
			$query->posts,
			'Failed to assert a request past the last archive page renders nothing.'
		);
	}

	/**
	 * Coverage for the other side of the boundary: an empty archive is not a 404.
	 *
	 * Core does not 404 an unpaged post type archive with no rows — it renders
	 * an empty archive at `200`, because `get_queried_object()` resolves to the
	 * post type. Deferring core's decision means reproducing that rule, not
	 * inventing a stricter one: an events site whose events have all happened
	 * still has an archive.
	 *
	 * @covers ::handle_event_archive_redirect
	 * @covers ::defer_event_archive_404
	 *
	 * @return void
	 */
	public function test_archive_with_no_matching_events_is_not_a_404(): void {
		$now = $this->now();

		// Past-only, so the default `upcoming` archive matches nothing while
		// the site still has published events.
		$this->create_event_at( $now->modify( '-4 hours' ), $now->modify( '-3 hours' ) );

		$query = $this->request_archive_page( 1 );

		$this->assertFalse(
			$query->is_404(),
			'Failed to assert an empty first page of the archive is not a 404.'
		);
		$this->assertTrue(
			$query->is_post_type_archive(),
			'Failed to assert an empty archive is still an archive.'
		);
		$this->assertSame(
			array(),
			$query->posts,
			'Failed to assert the empty archive lists nothing.'
		);
	}

	/**
	 * Coverage for the past archive: it reads most-recent-first.
	 *
	 * The other direction of `substitute_archive_query()`'s order ternary, and
	 * the requirement it encodes — a past archive whose newest entry is not
	 * first is a list nobody reads top-down.
	 *
	 * @covers ::handle_event_archive_redirect
	 * @covers ::fall_back_to_archive_mode
	 *
	 * @return void
	 */
	public function test_past_archive_orders_by_occurrence_datetime_descending(): void {
		add_filter( 'gatherpress_event_archive_mode', array( $this, 'force_past_archive_mode' ) );

		$now      = $this->now();
		$expected = array();

		for ( $offset = 2; $offset <= 5; $offset++ ) {
			$start = $now->modify( sprintf( '-%d hours', $offset ) );

			// Created newest-first, so the required reading order is the
			// creation order and the `wp_posts.post_date DESC` fallback is its
			// exact reverse (rule 3a #8).
			$expected[] = $this->create_event_at( $start, $start->modify( '+30 minutes' ) ) . '|';
		}

		$query = $this->request_archive_page( 1 );

		remove_filter( 'gatherpress_event_archive_mode', array( $this, 'force_past_archive_mode' ) );

		$this->assertSame(
			$expected,
			$this->entries( $query ),
			'Failed to assert the past archive reads most-recent-first.'
		);
	}

	/**
	 * Pin the archive mode to `past`.
	 *
	 * @since 0.36.0
	 *
	 * @return string Always `past`.
	 */
	public function force_past_archive_mode(): string {
		return 'past';
	}

	/**
	 * Direct coverage for the substitution helper's return paths.
	 *
	 * The request-driven tests above are what prove the wiring, but xdebug does
	 * not reliably trace a `protected` helper reached through a short
	 * same-class delegation — it reports the body as `count=0` even though the
	 * mutations that break it turn those tests red. Per the project's
	 * "Extracted same-class helpers and xdebug coverage tracing" rule, each
	 * return path also gets a direct invoke.
	 *
	 * @covers ::substitute_archive_query
	 *
	 * @return void
	 */
	public function test_substitute_archive_query_return_paths(): void {
		global $wp_query;

		$now = $this->now();

		for ( $offset = 1; $offset <= 3; $offset++ ) {
			$start = $now->modify( sprintf( '+%d hours', $offset ) );

			$this->create_event_at( $start, $start->modify( '+30 minutes' ) );
		}

		$instance = Event_Setup::get_instance();

		$this->go_to( (string) get_post_type_archive_link( Event::POST_TYPE ) );
		Utility::invoke_hidden_method(
			$instance,
			'substitute_archive_query',
			array( $wp_query, Event::POST_TYPE, 'upcoming' )
		);

		$this->assertCount(
			3,
			$wp_query->posts,
			'Failed to assert the substituted query lists the upcoming events.'
		);
		$this->assertSame(
			'ASC',
			$wp_query->query['order'],
			'Failed to assert an upcoming archive is substituted in ascending order.'
		);
		$this->assertTrue(
			$wp_query->is_post_type_archive,
			'Failed to assert the substituted query is flagged as a post type archive.'
		);
		$this->assertFalse(
			$wp_query->is_404(),
			'Failed to assert a substituted query with rows is not a 404.'
		);

		$this->go_to( add_query_arg( 'paged', 2, (string) get_post_type_archive_link( Event::POST_TYPE ) ) );
		Utility::invoke_hidden_method(
			$instance,
			'substitute_archive_query',
			array( $wp_query, Event::POST_TYPE, 'past' )
		);

		$this->assertSame(
			'DESC',
			$wp_query->query['order'],
			'Failed to assert a past archive is substituted in descending order.'
		);
		$this->assertTrue(
			$wp_query->is_404(),
			'Failed to assert a paged substituted query with no rows falls back to a 404.'
		);
	}

	/**
	 * Coverage for every guard arm of the 404 deferral.
	 *
	 * Each arm is a reason GatherPress will *not* substitute a query, and
	 * therefore a reason core must be left to make its own 404 decision. The
	 * positive case is proven by the request-driven tests above; these pin the
	 * arms that turn it off.
	 *
	 * @covers ::defer_event_archive_404
	 *
	 * @dataProvider data_defer_event_archive_404
	 *
	 * @param bool   $preempt   Incoming preempt value.
	 * @param array  $flags     Conditional flags to set on the query.
	 * @param array  $vars      Query vars to set on the query.
	 * @param bool   $expected  Expected return value.
	 * @param string $assertion Assertion message.
	 *
	 * @return void
	 */
	public function test_defer_event_archive_404_guards(
		bool $preempt,
		array $flags,
		array $vars,
		bool $expected,
		string $assertion
	): void {
		$query = new WP_Query();

		foreach ( $flags as $flag => $value ) {
			$query->$flag = $value;
		}

		foreach ( $vars as $key => $value ) {
			$query->set( $key, $value );
		}

		$this->assertSame(
			$expected,
			Event_Setup::get_instance()->defer_event_archive_404( $preempt, $query ),
			$assertion
		);
	}

	/**
	 * Data provider for the 404 deferral guards.
	 *
	 * @since 0.36.0
	 *
	 * @return array<string, array<int, mixed>> One case per guard arm.
	 */
	public function data_defer_event_archive_404(): array {
		$archive = array(
			'is_post_type_archive' => true,
			'is_feed'              => false,
		);
		$event   = array( 'post_type' => Event::POST_TYPE );

		return array(
			'defers on an event post type archive' => array(
				false,
				$archive,
				$event,
				true,
				'Failed to assert the 404 decision is deferred on an event archive request.',
			),
			'yields to an existing preempt'        => array(
				true,
				$archive,
				$event,
				true,
				'Failed to assert an existing preempt is returned unchanged.',
			),
			'ignores a non-archive request'        => array(
				false,
				array(
					'is_post_type_archive' => false,
					'is_feed'              => false,
				),
				$event,
				false,
				'Failed to assert a non-archive request is left to core.',
			),
			'ignores a feed request'               => array(
				false,
				array(
					'is_post_type_archive' => true,
					'is_feed'              => true,
				),
				$event,
				false,
				'Failed to assert an event feed request is left to core.',
			),
			'ignores an already-claimed archive'   => array(
				false,
				$archive,
				array(
					'post_type'                    => Event::POST_TYPE,
					Event_Query::EVENT_QUERY_PARAM => 'upcoming',
				),
				false,
				'Failed to assert an archive another handler already claimed is left to core.',
			),
			'ignores a non-event post type'        => array(
				false,
				$archive,
				array( 'post_type' => 'post' ),
				false,
				'Failed to assert a non-event post type archive is left to core.',
			),
		);
	}

	/**
	 * Coverage for a site with no recurring events: pagination still works.
	 *
	 * The 404 deferral is not gated on the recurrence flag, and it must not
	 * be: a plain event archive with more posts than fit on a page paginates
	 * through the same code path.
	 *
	 * The twelve events are created earliest-first, so the required ordering —
	 * ascending by event datetime — is the exact reverse of the
	 * `wp_posts.post_date DESC` the archive produced before, and no page of
	 * the required answer can coincide with a page of the old one (rule 3a #8).
	 *
	 * @covers ::handle_event_archive_redirect
	 *
	 * @return void
	 */
	public function test_non_recurring_archive_paginates_in_datetime_order(): void {
		$now      = $this->now();
		$expected = array();

		for ( $offset = 12; $offset <= 23; $offset++ ) {
			$start = $now->modify( sprintf( '+%d hours', $offset ) );

			$expected[] = $this->create_event_at( $start, $start->modify( '+30 minutes' ) ) . '|';
		}

		$this->assertFalse(
			Query::site_has_recurring_events(),
			'Failed to assert the fixture site has no recurring events.'
		);

		$first  = $this->request_archive_page( 1 );
		$second = $this->request_archive_page( 2 );

		$this->assertSame(
			12,
			$first->found_posts,
			'Failed to assert the plain archive counts all twelve events.'
		);
		$this->assertSame(
			$expected,
			array_merge( $this->entries( $first ), $this->entries( $second ) ),
			'Failed to assert a non-recurring archive paginates in ascending datetime order.'
		);
	}

	/**
	 * Coverage for REQ-16 through the real archive entry point.
	 *
	 * Captures every statement a full page-one and page-two archive request
	 * runs on a site whose `gatherpress_has_recurring_events` option is `'0'`,
	 * once with the recurrence clause and result filters registered and once
	 * with them removed, and asserts the two captures are byte-identical. A
	 * `posts_clauses` callback capture cannot prove this — it only observes
	 * the queries that reach that one filter — so the capture is taken from
	 * `$wpdb->queries` across the whole request.
	 *
	 * @covers \GatherPress\Core\Event\Recurrence\Query::expand_event_clauses
	 * @covers \GatherPress\Core\Event\Recurrence\Query::attach_occurrences
	 *
	 * @return void
	 */
	public function test_archive_request_runs_identical_sql_without_recurring_events(): void {
		$now = $this->now();

		for ( $index = 1; $index <= 12; $index++ ) {
			$start = $now->modify( sprintf( '+%d hours', $index ) );

			$this->create_event_at( $start, $start->modify( '+30 minutes' ) );
		}

		$this->assertFalse(
			Query::site_has_recurring_events(),
			'Failed to assert the fixture site has no recurring events.'
		);

		$recurrence = Query::get_instance();
		$with       = $this->capture_archive_queries();

		remove_filter( 'posts_clauses', array( $recurrence, 'expand_event_clauses' ), 11 );
		remove_filter( 'the_posts', array( $recurrence, 'attach_occurrences' ), 10 );

		$without = $this->capture_archive_queries();

		add_filter( 'posts_clauses', array( $recurrence, 'expand_event_clauses' ), 11, 2 );
		add_filter( 'the_posts', array( $recurrence, 'attach_occurrences' ), 10, 2 );

		$this->assertNotEmpty(
			$with,
			'Failed to assert the capture actually observed the archive request.'
		);
		$this->assertSame(
			$without,
			$with,
			'Failed to assert a flag-off site runs identical SQL with and without the recurrence filters.'
		);
		$this->assertSame(
			'0',
			get_option( Query::HAS_RECURRING_OPTION ),
			'Failed to assert the archive request performed no option write.'
		);
	}

	/**
	 * Capture every SQL statement two real archive requests run.
	 *
	 * Two details make the capture comparable rather than merely repeatable.
	 * The pair of requests is run once and thrown away before the capture
	 * starts, because the first run of any request primes the object cache and
	 * the second therefore issues fewer statements — comparing a cold capture
	 * against a warm one measures the cache, not the filters. And the current
	 * GMT timestamp `Event\Query::adjust_event_sql()` interpolates is
	 * normalized away, because two captures taken microseconds apart can still
	 * straddle a second boundary. Nothing else is touched, so a structural
	 * difference — an added join, an added column, an extra statement — still
	 * fails the comparison.
	 *
	 * @since 0.36.0
	 *
	 * @return string[] The statements, in execution order.
	 */
	protected function capture_archive_queries(): array {
		global $wpdb;

		$this->request_archive_page( 1 );
		$this->request_archive_page( 2 );

		$previous_queries   = $wpdb->queries;
		$previous_save      = $wpdb->save_queries;
		$wpdb->queries      = array();
		$wpdb->save_queries = true;

		$this->request_archive_page( 1 );
		$this->request_archive_page( 2 );

		$captured = array_map(
			static function ( array $entry ): string {
				return (string) preg_replace(
					'/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
					'{datetime}',
					(string) $entry[0]
				);
			},
			$wpdb->queries
		);

		$wpdb->queries      = $previous_queries;
		$wpdb->save_queries = $previous_save;

		return $captured;
	}
}
