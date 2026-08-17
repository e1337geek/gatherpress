<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Rewrite.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Recurrence\Context;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Rewrite;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Setup;
use GatherPress\Core\Topic;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use WP;

/**
 * Class Test_Rewrite.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Rewrite
 */
class Test_Rewrite extends Base {

	use Occurrence_Fixtures;

	/**
	 * Set up test environment: create the occurrence table, and put the
	 * event post type behind a pretty permalink structure with the
	 * occurrence rewrite rule registered and flushed, so real requests
	 * routed through `$this->go_to()` actually exercise the rewrite rule
	 * rather than the plain `?p=` query-string form.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		// The event post type's own permastruct was built at bootstrap, while
		// permalinks were still plain -- WP only builds a post type's pretty
		// permalink structure when `permalink_structure` is non-empty at
		// registration time, so it must be re-registered now that pretty
		// permalinks are active, or get_permalink() keeps returning the
		// `?p=`-style plain URL for the rest of this test.
		unregister_post_type( Event::POST_TYPE );
		Event_Setup::get_instance()->register_post_type();
		unregister_taxonomy( Topic::TAXONOMY );
		Topic::get_instance()->register_taxonomy();

		Rewrite::get_instance()->add_rewrite_rules();
		$wp_rewrite->flush_rules();
	}

	/**
	 * Restore a plain permalink structure so later test classes in the same
	 * process are not affected by this class's rewrite rules.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();

		parent::tearDown();
	}

	/**
	 * Create and project a recurring event whose occurrences are anchored
	 * relative to "now" rather than to a fixed calendar date, so the test
	 * suite does not become a date bomb as real time passes.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $day_offset Days from "now" for the first occurrence.
	 * @param int    $interval   Days between occurrences.
	 * @param int    $count      Number of occurrences.
	 * @param string $timezone   Named tz-database identifier for the series.
	 *
	 * @return array{0: int, 1: DateTimeImmutable} The post ID and its anchor start.
	 */
	protected function create_relative_daily_series(
		int $day_offset,
		int $interval,
		int $count,
		string $timezone = 'America/New_York'
	): array {
		$tz    = new DateTimeZone( $timezone );
		$start = ( new DateTimeImmutable( 'now', $tz ) )->modify( sprintf( '%+d days', $day_offset ) );
		$end   = $start->add( new DateInterval( 'PT2H' ) );

		$post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );

		add_post_meta(
			$post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => $start->format( 'Y-m-d H:i:s' ),
					'dateTimeEnd'   => $end->format( 'Y-m-d H:i:s' ),
					'timezone'      => $timezone,
				)
			)
		);
		Event_Setup::get_instance()->set_datetimes( $post_id );

		add_post_meta(
			$post_id,
			Meta::META_KEY,
			wp_json_encode(
				array(
					'frequency' => 'daily',
					'interval'  => $interval,
					'end_type'  => 'count',
					'count'     => $count,
				)
			)
		);
		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );

		return array( $post_id, $start );
	}

	/**
	 * Coverage for `__construct` and `setup_hooks`.
	 *
	 * @covers ::__construct
	 * @covers ::setup_hooks
	 *
	 * @return void
	 */
	public function test_setup_hooks(): void {
		$instance = Rewrite::get_instance();
		$hooks    = array(
			array(
				'type'     => 'action',
				'name'     => 'wp_loaded',
				'priority' => 10,
				'callback' => array( $instance, 'add_rewrite_rules' ),
			),
			array(
				'type'     => 'filter',
				'name'     => 'query_vars',
				'priority' => 10,
				'callback' => array( $instance, 'add_query_vars' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'parse_request',
				'priority' => 10,
				'callback' => array( $instance, 'parse_request' ),
			),
		);

		$this->assert_hooks( $hooks, $instance );
	}

	/**
	 * Coverage for `add_query_vars`.
	 *
	 * @covers ::add_query_vars
	 *
	 * @return void
	 */
	public function test_add_query_vars(): void {
		$this->assertSame(
			array( 'apples', Context::QUERY_VAR ),
			Rewrite::get_instance()->add_query_vars( array( 'apples' ) ),
			'Failed to assert that the occurrence query var is appended.'
		);
	}

	/**
	 * Coverage for `get_occurrence_url`: it composes onto the *configured*
	 * rewrite slug rather than a hardcoded `/event/`. This is the test that
	 * catches a hardcoded `/event/` regression -- the default slug alone
	 * would pass even with `/event/` baked in.
	 *
	 * @covers ::get_occurrence_url
	 *
	 * @return void
	 */
	public function test_occurrence_url_composes_onto_configured_rewrite_slug(): void {
		update_option( 'gatherpress_settings', array( 'events_url' => 'meetups' ) );
		unregister_post_type( Event::POST_TYPE );
		Event_Setup::get_instance()->register_post_type();
		Rewrite::get_instance()->add_rewrite_rules();

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_name'   => 'custom-slug-meetup',
				'post_status' => 'publish',
			)
		);

		$this->assertSame(
			home_url( '/meetups/custom-slug-meetup/20260901T180000/' ),
			Rewrite::get_occurrence_url( $post_id, '20260901T180000' ),
			'Occurrence URL should compose onto the configured events_url slug, not a hardcoded /event/.'
		);

		// Restore the default slug for every later test in this class/process.
		delete_option( 'gatherpress_settings' );
		unregister_post_type( Event::POST_TYPE );
		Event_Setup::get_instance()->register_post_type();
	}

	/**
	 * Coverage for `get_occurrence_url` when the post has no permalink.
	 *
	 * @covers ::get_occurrence_url
	 *
	 * @return void
	 */
	public function test_get_occurrence_url_returns_empty_string_without_permalink(): void {
		$this->assertSame(
			'',
			Rewrite::get_occurrence_url( 0, '20260901T180000' ),
			'get_occurrence_url() should return an empty string when the post has no permalink.'
		);
	}

	/**
	 * Coverage for `get_occurrence_url`'s `gatherpress_recurrence_id_format` filter.
	 *
	 * @covers ::get_occurrence_url
	 *
	 * @return void
	 */
	public function test_occurrence_url_applies_recurrence_id_format_filter(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_name'   => 'filtered-meetup',
				'post_status' => 'publish',
			)
		);

		add_filter(
			'gatherpress_recurrence_id_format',
			static function () {
				return 'custom-segment';
			}
		);

		$this->assertSame(
			home_url( '/event/filtered-meetup/custom-segment/' ),
			Rewrite::get_occurrence_url( $post_id, '20260901T180000' ),
			'gatherpress_recurrence_id_format should be able to override the URL segment.'
		);

		remove_all_filters( 'gatherpress_recurrence_id_format' );
	}

	/**
	 * Coverage for the occurrence URL resolving 200 and rendering the event
	 * template, driven through a real request rather than an internal call.
	 *
	 * @covers ::parse_request
	 * @covers ::resolve_post_id_from_query_vars
	 * @covers ::add_rewrite_rule_for_post_type
	 * @covers ::add_rewrite_rules
	 *
	 * @return void
	 */
	public function test_occurrence_url_returns_200_and_renders_event_template(): void {
		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( 5, 7, 3 );
		$recurrence_id                  = Occurrences::recurrence_id( $anchor_start );

		$this->go_to( Rewrite::get_occurrence_url( $post_id, $recurrence_id ) );

		$this->assertFalse( is_404(), 'A real occurrence URL must not 404.' );
		$this->assertTrue(
			is_singular( Event::POST_TYPE ),
			'A real occurrence URL should render the event single template.'
		);
		$this->assertSame(
			$post_id,
			get_queried_object_id(),
			'The queried object should be the series post.'
		);
		$this->assertSame(
			$recurrence_id,
			get_query_var( Context::QUERY_VAR ),
			'The occurrence query var should carry the resolved recurrence ID.'
		);
	}

	/**
	 * Coverage for REQ-8: a well-formed `Ymd\THis` segment that is not an
	 * actual occurrence of that series 404s rather than silently rendering
	 * the series at its anchor date.
	 *
	 * @covers ::parse_request
	 *
	 * @return void
	 */
	public function test_non_occurrence_datetime_returns_404(): void {
		list( $post_id, ) = $this->create_relative_daily_series( 5, 7, 3 );

		// Well-formed Ymd\THis, but never produced by this series' rule.
		$this->go_to( Rewrite::get_occurrence_url( $post_id, '19991231T235959' ) );

		$this->assertTrue(
			is_404(),
			'A well-formed but non-occurrence datetime segment should 404.'
		);
	}

	/**
	 * Coverage for D-4: visiting a recurring series at its bare permalink
	 * (no occurrence segment) resolves to the next upcoming occurrence.
	 *
	 * @covers ::parse_request
	 * @covers ::maybe_resolve_bare_series
	 * @covers ::next_upcoming_recurrence_id
	 *
	 * @return void
	 */
	public function test_bare_series_url_resolves_next_upcoming_occurrence(): void {
		// Occurrences at -15, -8, -1, +6, +13 days -- the first three are
		// already past by the time the request is made, so "+6 days" is the
		// next upcoming one.
		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( -15, 7, 5 );
		// Occurrences land at anchor_start + 0/7/14/21/28 days, i.e. -15/-8/-1/+6/+13
		// days relative to "now" -- the "+21" entry is the first upcoming one.
		$expected_recurrence_id = Occurrences::recurrence_id( $anchor_start->modify( '+21 days' ) );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertFalse( is_404(), 'The bare series URL must not 404.' );
		$this->assertSame(
			$expected_recurrence_id,
			get_query_var( Context::QUERY_VAR ),
			'The bare series URL should resolve the occurrence query var to the next upcoming occurrence.'
		);
	}

	/**
	 * Coverage for `maybe_resolve_bare_series` when the series has no
	 * upcoming occurrence left -- the query var must stay unset so the post
	 * renders exactly as a non-recurring event would.
	 *
	 * @covers ::maybe_resolve_bare_series
	 * @covers ::next_upcoming_recurrence_id
	 *
	 * @return void
	 */
	public function test_bare_series_url_leaves_query_var_unset_when_series_has_lapsed(): void {
		list( $post_id, ) = $this->create_relative_daily_series( -30, 7, 5 );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertFalse( is_404(), 'A lapsed series bare URL must not 404.' );
		$this->assertSame(
			'',
			(string) get_query_var( Context::QUERY_VAR ),
			'A lapsed series has no upcoming occurrence, so the query var must stay unset.'
		);
	}

	/**
	 * Coverage for `maybe_resolve_bare_series` on a non-recurring event: no
	 * occurrence rows exist at all, so `select_for_series()` returns an
	 * empty array and the query var stays unset.
	 *
	 * @covers ::maybe_resolve_bare_series
	 * @covers ::next_upcoming_recurrence_id
	 *
	 * @return void
	 */
	public function test_bare_series_url_leaves_query_var_unset_for_non_recurring_event(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$this->assertFalse( is_404(), 'A non-recurring event must not 404.' );
		$this->assertSame(
			'',
			(string) get_query_var( Context::QUERY_VAR ),
			'A non-recurring event has no occurrence rows, so the query var must stay unset.'
		);
	}

	/**
	 * Coverage for REQ-12: a cancelled occurrence's URL resolves rather
	 * than 404s, so an attendee holding the link is told it was cancelled
	 * instead of hitting a dead end.
	 *
	 * @covers ::parse_request
	 *
	 * @return void
	 */
	public function test_cancelled_occurrence_url_resolves_rather_than_404(): void {
		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( 5, 7, 3 );
		$recurrence_id                  = Occurrences::recurrence_id( $anchor_start );

		$this->assertTrue(
			Occurrences::get_instance()->set_status( $post_id, $recurrence_id, Occurrences::STATUS_CANCELLED ),
			'Fixture setup: set_status() should find the freshly projected row.'
		);

		$this->go_to( Rewrite::get_occurrence_url( $post_id, $recurrence_id ) );

		$this->assertFalse( is_404(), 'A cancelled occurrence URL must resolve, not 404.' );
		$this->assertSame(
			$recurrence_id,
			get_query_var( Context::QUERY_VAR ),
			'The occurrence query var should still carry the cancelled occurrence\'s recurrence ID.'
		);
	}

	/**
	 * Feed-routing regression coverage: the singular event feed, the
	 * sitewide feed, and a taxonomy feed must all keep resolving after the
	 * occurrence rewrite rule is registered. This is what the rewrite-rule
	 * spike exists to protect.
	 *
	 * @covers ::add_rewrite_rule_for_post_type
	 *
	 * @return void
	 */
	public function test_feed_routing_is_unaffected_by_occurrence_rewrite_rule(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_name'   => 'feed-regression-event',
				'post_status' => 'publish',
			)
		);

		$this->go_to( trailingslashit( get_permalink( $post_id ) ) . 'feed/' );
		$this->assertTrue( is_feed(), 'The singular event feed URL should still resolve as a feed.' );
		$this->assertFalse( is_404(), 'The singular event feed URL must not 404.' );

		$this->go_to( home_url( '/feed/' ) );
		$this->assertTrue( is_feed(), 'The sitewide feed URL should still resolve as a feed.' );
		$this->assertFalse( is_404(), 'The sitewide feed URL must not 404.' );

		// GatherPress registers a custom `feed/(ical)` endpoint per taxonomy
		// term rather than relying on WP's generic taxonomy feed rule -- this
		// is the actual feed surface `Calendar\Setup` wires up for
		// `gatherpress_topic`, so it is what this regression test protects.
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'gatherpress_topic' ) );
		$this->go_to( trailingslashit( get_term_link( $term ) ) . 'feed/ical/' );
		$this->assertTrue( is_feed(), 'The taxonomy ical feed URL should still resolve as a feed.' );
		$this->assertFalse( is_404(), 'The taxonomy ical feed URL must not 404.' );
	}

	/**
	 * Coverage for `add_rewrite_rule_for_post_type` bailing when
	 * `get_post_type_object()` cannot resolve an object for a post type
	 * that declared the support without ever calling `register_post_type()`.
	 *
	 * @covers ::add_rewrite_rule_for_post_type
	 *
	 * @return void
	 */
	public function test_add_rewrite_rule_for_post_type_bails_on_unregistered_post_type(): void {
		global $wp_rewrite;
		$before = $wp_rewrite->extra_rules_top;

		Utility::invoke_hidden_method(
			Rewrite::get_instance(),
			'add_rewrite_rule_for_post_type',
			array( 'gp_never_registered' )
		);

		$this->assertSame(
			$before,
			$wp_rewrite->extra_rules_top,
			'No rewrite rule should be added for a post type that was never registered.'
		);
	}

	/**
	 * Coverage for `add_rewrite_rule_for_post_type` bailing on a post type
	 * with rewrites disabled.
	 *
	 * @covers ::add_rewrite_rule_for_post_type
	 *
	 * @return void
	 */
	public function test_add_rewrite_rule_for_post_type_bails_when_rewrite_disabled(): void {
		register_post_type(
			'gp_test_no_rewrite',
			array(
				'public'  => true,
				'rewrite' => false,
			)
		);

		global $wp_rewrite;
		$before = $wp_rewrite->extra_rules_top;

		Utility::invoke_hidden_method(
			Rewrite::get_instance(),
			'add_rewrite_rule_for_post_type',
			array( 'gp_test_no_rewrite' )
		);

		$this->assertSame(
			$before,
			$wp_rewrite->extra_rules_top,
			'No rewrite rule should be added for a post type with rewrites disabled.'
		);

		unregister_post_type( 'gp_test_no_rewrite' );
	}

	/**
	 * Coverage for `add_rewrite_rule_for_post_type` bailing on a post type
	 * with its query var disabled.
	 *
	 * @covers ::add_rewrite_rule_for_post_type
	 *
	 * @return void
	 */
	public function test_add_rewrite_rule_for_post_type_bails_when_query_var_disabled(): void {
		register_post_type(
			'gp_test_no_qv',
			array(
				'public'    => true,
				'rewrite'   => array( 'slug' => 'gp-test-no-qv' ),
				'query_var' => false,
			)
		);

		global $wp_rewrite;
		$before = $wp_rewrite->extra_rules_top;

		Utility::invoke_hidden_method(
			Rewrite::get_instance(),
			'add_rewrite_rule_for_post_type',
			array( 'gp_test_no_qv' )
		);

		$this->assertSame(
			$before,
			$wp_rewrite->extra_rules_top,
			'No rewrite rule should be added for a post type with its query var disabled.'
		);

		unregister_post_type( 'gp_test_no_qv' );
	}

	/**
	 * Coverage for `resolve_post_id_from_query_vars` when a post type
	 * declares `gatherpress-event-date` support without ever being
	 * registered -- `get_post_type_object()` returns null and the loop must
	 * skip past it rather than fatal.
	 *
	 * @covers ::resolve_post_id_from_query_vars
	 *
	 * @return void
	 */
	public function test_resolve_post_id_from_query_vars_skips_unregistered_supporting_post_type(): void {
		add_post_type_support( 'gp_ghost_event', 'gatherpress-event-date' );

		$result = Utility::invoke_hidden_method(
			Rewrite::get_instance(),
			'resolve_post_id_from_query_vars',
			array( array() )
		);

		remove_post_type_support( 'gp_ghost_event', 'gatherpress-event-date' );

		$this->assertNull(
			$result,
			'An unregistered post type declaring the support must be skipped, not fatal.'
		);
	}

	/**
	 * Coverage for `resolve_post_id_from_query_vars` when the query vars
	 * carry a value for the post type's query var, but no post exists at
	 * that path.
	 *
	 * @covers ::resolve_post_id_from_query_vars
	 *
	 * @return void
	 */
	public function test_resolve_post_id_from_query_vars_returns_null_when_no_post_matches(): void {
		$result = Utility::invoke_hidden_method(
			Rewrite::get_instance(),
			'resolve_post_id_from_query_vars',
			array( array( Event::POST_TYPE => 'no-such-event-slug' ) )
		);

		$this->assertNull(
			$result,
			'resolve_post_id_from_query_vars() should return null when no post matches the path.'
		);
	}

	/**
	 * Coverage for `parse_request` when the occurrence query var is present
	 * but set to an empty string -- the falsy-but-isset short circuit of
	 * the `||` guard. Not reachable through the registered rewrite rule
	 * (whose capture group cannot match an empty string), so it is driven
	 * directly against a real `WP` object per the class's own contract.
	 *
	 * @covers ::parse_request
	 *
	 * @return void
	 */
	public function test_parse_request_treats_empty_string_occurrence_var_as_bare_series(): void {
		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( 5, 7, 3 );
		$expected_recurrence_id         = Occurrences::recurrence_id( $anchor_start );

		$post           = get_post( $post_id );
		$wp             = new WP();
		$wp->query_vars = array(
			Event::POST_TYPE   => $post->post_name,
			Context::QUERY_VAR => '',
		);

		Rewrite::get_instance()->parse_request( $wp );

		$this->assertSame(
			$expected_recurrence_id,
			$wp->query_vars[ Context::QUERY_VAR ],
			'An empty-string occurrence query var should be treated as a bare series URL.'
		);
	}

	/**
	 * Coverage for `parse_request` when the occurrence segment is present
	 * but nothing in the query vars identifies a series post at all.
	 *
	 * @covers ::parse_request
	 *
	 * @return void
	 */
	public function test_parse_request_bails_when_no_post_resolves(): void {
		$wp             = new WP();
		$wp->query_vars = array( Context::QUERY_VAR => '20260901T180000' );

		Rewrite::get_instance()->parse_request( $wp );

		$this->assertArrayNotHasKey(
			'error',
			$wp->query_vars,
			'parse_request() should not set a 404 error when no post resolves at all.'
		);
	}

	/**
	 * Coverage for `maybe_resolve_bare_series` when the request is not for
	 * a series post at all (no occurrence segment, and nothing in the query
	 * vars identifies an event-supporting post type). This is the ordinary
	 * shape of every non-event request on the site -- driven through a real
	 * request to the home page.
	 *
	 * @covers ::parse_request
	 * @covers ::maybe_resolve_bare_series
	 *
	 * @return void
	 */
	public function test_bare_series_resolution_is_a_no_op_for_non_event_requests(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( is_404(), 'The home page must not 404.' );
		$this->assertSame(
			'',
			(string) get_query_var( Context::QUERY_VAR ),
			'A request unrelated to any event post type must not set the occurrence query var.'
		);
	}
}
