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
use GatherPress\Core\Event\Recurrence\Query;
use GatherPress\Core\Event\Recurrence\Rewrite;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Setup;
use GatherPress\Core\Settings;
use GatherPress\Core\Topic;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use ReflectionClass;
use WP;

/**
 * Class Test_Rewrite.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Rewrite
 */
class Test_Rewrite extends Base {

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

		// A public constructor on a singleton is not a style point: every
		// `new Rewrite()` adds another `wp_loaded`, `query_vars` and
		// `parse_request` callback, so the rewrite rules are re-registered and
		// the occurrence table re-probed once per instance, on every request.
		$this->assertTrue(
			( new ReflectionClass( Rewrite::class ) )->getConstructor()->isProtected(),
			'Failed to assert the singleton constructor is protected, leaving get_instance() the only'
				. ' construction path.'
		);
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
	 * rewrite slug rather than a hardcoded `/event/`. This only exercises
	 * URL composition (`get_occurrence_url()` -> `get_permalink()`) -- it
	 * does NOT exercise routing, since `add_rewrite_rule_for_post_type()`'s
	 * own runtime slug read is a different code path entirely. See
	 * `test_occurrence_url_routes_under_a_non_default_rewrite_slug()` below
	 * for the routing-side guarantee.
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
	 * Coverage for the actual correctness claim of REQ-8: an occurrence URL
	 * under a *non-default* `events_url` slug actually ROUTES -- drives a
	 * real request through `add_rewrite_rule_for_post_type()`'s runtime
	 * slug read at `class-rewrite.php:138`, the line that matters, rather
	 * than through `get_occurrence_url()`'s independent composition path.
	 * Substituting a hardcoded `'event'` literal for `$slug` at that line
	 * turns this test (and only this test) red.
	 *
	 * @covers ::add_rewrite_rule_for_post_type
	 * @covers ::parse_request
	 *
	 * @return void
	 */
	public function test_occurrence_url_routes_under_a_non_default_rewrite_slug(): void {
		global $wp_rewrite;

		update_option( 'gatherpress_settings', array( 'events_url' => 'meetups' ) );
		unregister_post_type( Event::POST_TYPE );
		Event_Setup::get_instance()->register_post_type();
		Rewrite::get_instance()->add_rewrite_rules();
		$wp_rewrite->flush_rules();

		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( 5, 7, 3 );
		$recurrence_id                  = Occurrences::recurrence_id( $anchor_start );
		$url                            = Rewrite::get_occurrence_url( $post_id, $recurrence_id );

		$this->assertStringContainsString(
			'/meetups/',
			$url,
			'Fixture setup: the occurrence URL should be composed under the meetups slug.'
		);

		$this->go_to( $url );

		$this->assertFalse( is_404(), 'An occurrence URL under a non-default slug must not 404.' );
		$this->assertTrue(
			is_singular( Event::POST_TYPE ),
			'An occurrence URL under a non-default slug should render the event single template.'
		);
		$this->assertSame(
			$recurrence_id,
			get_query_var( Context::QUERY_VAR ),
			'The occurrence query var should resolve under a non-default slug.'
		);

		// Restore the default slug for every later test in this class/process.
		delete_option( 'gatherpress_settings' );
		unregister_post_type( Event::POST_TYPE );
		Event_Setup::get_instance()->register_post_type();
		Rewrite::get_instance()->add_rewrite_rules();
		$wp_rewrite->flush_rules();
	}

	/**
	 * Coverage for a *localized* `events_url` slug -- `Settings::get('events_url')`
	 * falls back to `Event\Setup::get_localized_post_type_slug()` when the
	 * option is unset, and that is a live, reachable configuration on any
	 * non-English site that has not explicitly overridden the events slug.
	 *
	 * @covers ::add_rewrite_rule_for_post_type
	 * @covers ::parse_request
	 *
	 * @return void
	 */
	public function test_occurrence_url_routes_under_a_localized_rewrite_slug(): void {
		global $wp_rewrite;

		$filter_localized_label = static function ( $labels ) {
			$labels->singular_name = 'Veranstaltung';

			return $labels;
		};
		add_filter( 'post_type_labels_gatherpress_event', $filter_localized_label );

		// Settings::get_defaults_map() caches the resolved default for the
		// remainder of the request the first time anything reads a setting
		// -- almost certainly already true by this point in the suite --
		// so the localized-slug default must be forced to recompute or the
		// filter above has no effect on events_url's default.
		$settings = Settings::get_instance();
		Utility::set_and_get_hidden_property( $settings, 'defaults_cache', null );

		delete_option( 'gatherpress_settings' );
		unregister_post_type( Event::POST_TYPE );
		Event_Setup::get_instance()->register_post_type();
		Rewrite::get_instance()->add_rewrite_rules();
		$wp_rewrite->flush_rules();

		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( 5, 7, 3 );
		$recurrence_id                  = Occurrences::recurrence_id( $anchor_start );
		$url                            = Rewrite::get_occurrence_url( $post_id, $recurrence_id );

		$this->assertStringContainsString(
			'/veranstaltung/',
			$url,
			'Fixture setup: the occurrence URL should be composed under the localized slug.'
		);

		$this->go_to( $url );

		$this->assertFalse( is_404(), 'An occurrence URL under a localized slug must not 404.' );
		$this->assertSame(
			$recurrence_id,
			get_query_var( Context::QUERY_VAR ),
			'The occurrence query var should resolve under a localized slug.'
		);

		remove_filter( 'post_type_labels_gatherpress_event', $filter_localized_label );
		Utility::set_and_get_hidden_property( $settings, 'defaults_cache', null );
		unregister_post_type( Event::POST_TYPE );
		Event_Setup::get_instance()->register_post_type();
		Rewrite::get_instance()->add_rewrite_rules();
		$wp_rewrite->flush_rules();
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
	 * Coverage for the occurrence segment being unfilterable, and for the URL
	 * it emits round-tripping through a real request.
	 *
	 * `gatherpress_recurrence_id_format` used to let an integration replace the
	 * segment. It was one-way: `add_rewrite_rule_for_post_type()` registers a
	 * single fixed `RECURRENCE_ID_REGEX` and `parse_request()` matches the raw
	 * segment against the canonical `recurrence_id` column, so every URL the
	 * filter customized 404'd at the address it advertised. The filter is gone;
	 * this pins that it stays gone, and that the URL actually generated routes.
	 *
	 * The round-trip is a real `go_to()` rather than a string comparison,
	 * because a string comparison is exactly what let the broken filter ship.
	 *
	 * @covers ::get_occurrence_url
	 * @covers ::parse_request
	 *
	 * @return void
	 */
	public function test_occurrence_url_ignores_a_segment_filter_and_round_trips(): void {
		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( 5, 7, 3 );
		$recurrence_id                  = Occurrences::recurrence_id( $anchor_start );

		add_filter(
			'gatherpress_recurrence_id_format',
			static function () {
				return 'custom-segment';
			}
		);

		$url = Rewrite::get_occurrence_url( $post_id, $recurrence_id );

		remove_all_filters( 'gatherpress_recurrence_id_format' );

		$this->assertStringEndsWith(
			'/' . $recurrence_id . '/',
			$url,
			'Failed to assert the occurrence URL still ends in the canonical recurrence ID.'
		);
		$this->assertStringNotContainsString(
			'custom-segment',
			$url,
			'Failed to assert no filter can rewrite the occurrence segment into something unroutable.'
		);

		$this->go_to( $url );

		$this->assertFalse( is_404(), 'Failed to assert the generated occurrence URL routes.' );
		$this->assertSame(
			$recurrence_id,
			get_query_var( Context::QUERY_VAR ),
			'Failed to assert the generated occurrence URL round-trips to its own recurrence ID.'
		);
		$this->assertSame(
			$post_id,
			get_queried_object_id(),
			'Failed to assert the generated occurrence URL round-trips to its own series post.'
		);
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
	 * Coverage for the upgrade path: `WP_Rewrite::wp_rewrite_rules()` reads
	 * the persisted `rewrite_rules` option verbatim on every request when it
	 * is non-empty -- `add_rewrite_rule()` alone only ever mutates
	 * `$wp_rewrite->extra_rules_top` in memory, so on a site that already
	 * has a populated `rewrite_rules` option (every existing GatherPress
	 * site, the moment this code deploys), the occurrence rule would never
	 * reach the persisted option -- and therefore never match a real
	 * request -- without `maybe_flush_rewrite_rules()` correcting it.
	 *
	 * @covers ::add_rewrite_rule_for_post_type
	 * @covers ::maybe_flush_rewrite_rules
	 *
	 * @return void
	 */
	public function test_add_rewrite_rules_self_heals_a_stale_persisted_option(): void {
		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( 5, 7, 3 );
		$recurrence_id                  = Occurrences::recurrence_id( $anchor_start );
		$url                            = Rewrite::get_occurrence_url( $post_id, $recurrence_id );

		// Simulate an existing site upgrading: strip the occurrence pattern
		// back out of the persisted option, as if this plugin version had
		// never registered it and the site's rules were never otherwise
		// regenerated since. `extra_rules_top` in memory is untouched --
		// exactly what happens between one request finishing and the next
		// one's `wp_loaded` firing.
		$reg_ex = sprintf( '%s/([^/]+)/(%s)/?$', 'event', Rewrite::RECURRENCE_ID_REGEX );
		$stale  = get_option( 'rewrite_rules' );
		$this->assertArrayHasKey(
			$reg_ex,
			$stale,
			'Fixture setup: the occurrence rule should already be persisted before it is stripped back out.'
		);
		unset( $stale[ $reg_ex ] );
		update_option( 'rewrite_rules', $stale );

		// Before: WP_Rewrite::wp_rewrite_rules() reads the stale option
		// verbatim -- add_rewrite_rules() has not run again yet, matching a
		// request that lands between deploy and the next full request
		// bootstrap.
		$this->go_to( $url );
		$this->assertTrue(
			is_404(),
			'BEFORE: a stale persisted rewrite_rules option missing the occurrence rule must 404 the occurrence URL.'
		);

		// This is exactly what fires on `wp_loaded` for every real request.
		Rewrite::get_instance()->add_rewrite_rules();

		// After: the mismatch triggered delete_option(), so the next read
		// via wp_rewrite_rules() regenerates the option from the rules
		// still held in extra_rules_top -- including this one.
		$this->go_to( $url );
		$this->assertFalse(
			is_404(),
			'AFTER: add_rewrite_rules() must self-heal a stale persisted option so the occurrence URL resolves.'
		);
		$this->assertSame(
			$recurrence_id,
			get_query_var( Context::QUERY_VAR ),
			'AFTER: the occurrence query var should resolve once the option is healed.'
		);
	}

	/**
	 * Coverage for `maybe_flush_rewrite_rules` when the persisted option
	 * already matches -- the comparison must be a no-op, or this would
	 * flush on every single request.
	 *
	 * @covers ::maybe_flush_rewrite_rules
	 *
	 * @return void
	 */
	public function test_maybe_flush_rewrite_rules_is_a_no_op_when_already_correct(): void {
		// setUp() already ran add_rewrite_rules() + flush_rules(), so the
		// option is already correct for the default slug.
		$before = get_option( 'rewrite_rules' );

		Rewrite::get_instance()->add_rewrite_rules();

		$this->assertSame(
			$before,
			get_option( 'rewrite_rules' ),
			'add_rewrite_rules() must not touch an already-correct rewrite_rules option.'
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
		$url = Rewrite::get_occurrence_url( $post_id, '19991231T235959' );
		$this->go_to( $url );

		$this->assertTrue(
			is_404(),
			'A well-formed but non-occurrence datetime segment should 404.'
		);

		// parse_request() must have neutralized redirect_canonical() on this
		// 404, or WP would 301 the miss back to the bare series URL instead
		// of letting the 404 stand -- go_to() itself never fires
		// template_redirect, so this has to be asserted directly.
		$this->assertNull(
			redirect_canonical( $url, false ),
			'redirect_canonical() must be neutralized so a non-occurrence 404 is not silently redirected.'
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
	 * Coverage for `next_upcoming_recurrence_id`'s `series_post_id === $post_id`
	 * check: `select_for_series()` can legitimately return rows from more
	 * than one post (PRD C-2 / REQ-18's forward split, reachable today via
	 * the `gatherpress_series_post_ids` filter), interleaved by start time
	 * across posts. A sibling post's row that sorts earlier than this
	 * post's own next occurrence must not be mistaken for it.
	 *
	 * @covers ::next_upcoming_recurrence_id
	 *
	 * @return void
	 */
	public function test_bare_series_url_skips_an_earlier_sibling_series_row(): void {
		// The sibling's own occurrence is closer to "now" than this post's,
		// so it sorts first in the combined, interleaved result set.
		list( $sibling_post_id, )       = $this->create_relative_daily_series( 3, 7, 1 );
		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( 10, 7, 1 );
		$expected_recurrence_id         = Occurrences::recurrence_id( $anchor_start );

		$make_siblings = static function (
			array $post_ids,
			int $resolved_post_id
		) use (
			$post_id,
			$sibling_post_id
): array {
			if ( $resolved_post_id === $post_id ) {
				return array( $post_id, $sibling_post_id );
			}

			return $post_ids;
		};
		add_filter( 'gatherpress_series_post_ids', $make_siblings, 10, 2 );

		$this->go_to( get_permalink( $post_id ) );

		remove_filter( 'gatherpress_series_post_ids', $make_siblings, 10 );

		$this->assertFalse( is_404(), 'The bare series URL must not 404.' );
		$this->assertSame(
			$expected_recurrence_id,
			get_query_var( Context::QUERY_VAR ),
			'The sibling series row must not be mistaken for this post\'s own next occurrence.'
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
	 * Coverage for REQ-16: a plain event permalink request on a site with no
	 * recurring events at all must never query the occurrence table.
	 * `Query::site_has_recurring_events()` is the authoritative, cheap
	 * (single autoloaded option read) guard `maybe_resolve_bare_series()`
	 * checks *before* `resolve_post_id_from_query_vars()` -- which itself
	 * costs a `get_page_by_path()` lookup -- runs at all, on every request
	 * that has no occurrence segment (i.e. every ordinary event permalink on
	 * the entire site).
	 *
	 * The guard lives on `parse_request()` itself rather than on this branch,
	 * so its sibling test below covers the occurrence-segment branch.
	 *
	 * @covers ::parse_request
	 * @covers ::maybe_resolve_bare_series
	 *
	 * @return void
	 */
	public function test_bare_series_resolution_skips_occurrence_query_without_recurring_events(): void {
		update_option( Query::HAS_RECURRING_OPTION, '0' );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		global $wpdb;
		$occurrences_table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$query_count       = 0;
		$count_queries     = static function ( string $query ) use ( $occurrences_table, &$query_count ): string {
			if ( str_contains( $query, $occurrences_table ) ) {
				++$query_count;
			}

			return $query;
		};
		add_filter( 'query', $count_queries );

		$this->go_to( get_permalink( $post_id ) );

		remove_filter( 'query', $count_queries );

		$this->assertFalse( is_404(), 'A plain event permalink must not 404.' );
		$this->assertSame(
			0,
			$query_count,
			'A plain event permalink request must not query the occurrence table when the site has no recurring events.'
		);
	}

	/**
	 * Coverage for REQ-16 on the occurrence-segment branch of `parse_request()`.
	 *
	 * The sibling of the bare-series test above. That one probes the branch
	 * taken when a URL carries no occurrence segment; this one drives a real
	 * occurrence URL, which takes the other branch and reaches
	 * `Occurrences::get()` -- a raw, uncached `$wpdb->get_row()`. The two
	 * branches shipped with different guarding precisely because only the
	 * first was ever probed, so both are pinned here.
	 *
	 * @covers ::parse_request
	 *
	 * @return void
	 */
	public function test_occurrence_url_skips_occurrence_query_without_recurring_events(): void {
		global $wpdb;

		list( $post_id, $anchor_start ) = $this->create_relative_daily_series( 5, 7, 3 );

		$url = Rewrite::get_occurrence_url( $post_id, Occurrences::recurrence_id( $anchor_start ) );

		// Flipped only after projection, so a real occurrence row exists and the
		// URL is genuinely well-formed: what is asserted is the guard, not a
		// missing fixture.
		update_option( Query::HAS_RECURRING_OPTION, '0' );

		$occurrences_table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$query_count       = 0;
		$count_queries     = static function ( string $query ) use ( $occurrences_table, &$query_count ): string {
			if ( str_contains( $query, $occurrences_table ) ) {
				++$query_count;
			}

			return $query;
		};

		add_filter( 'query', $count_queries );

		$this->go_to( $url );

		remove_filter( 'query', $count_queries );

		$this->assertSame(
			0,
			$query_count,
			'An occurrence URL must not query the occurrence table when the site has no recurring events.'
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
		// The site must genuinely have a recurring event, or REQ-16's guard on
		// `parse_request()` returns before the post-resolution branch this test
		// exists to reach -- the assertion below would then hold for a reason
		// that has nothing to do with post resolution.
		$this->create_relative_daily_series( 5, 7, 3 );

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
		// The site DOES have a recurring event elsewhere, so
		// maybe_resolve_bare_series() clears Query::site_has_recurring_events()'s
		// guard and reaches resolve_post_id_from_query_vars() -- which must
		// still find nothing to resolve for a request that identifies no
		// post at all.
		$this->create_relative_daily_series( 5, 7, 3 );

		$this->go_to( home_url( '/' ) );

		$this->assertFalse( is_404(), 'The home page must not 404.' );
		$this->assertSame(
			'',
			(string) get_query_var( Context::QUERY_VAR ),
			'A request unrelated to any event post type must not set the occurrence query var.'
		);
	}
}
