<?php
/**
 * Class handles multisite unit tests for the occurrence table's lazy,
 * per-blog creation hazard.
 *
 * THE CONTRACT (stated explicitly, so it is a decision rather than an
 * accident): `Setup::create_tables()` runs per-blog on network
 * activation (`activate_gatherpress_plugin()`) and on a new site's creation
 * while the plugin is network-active (`on_site_create()`), and otherwise only
 * via `check_plugin_version()`, which is hooked on `admin_init` for the
 * CURRENT SITE ONLY. Table creation is therefore lazy per blog: on an
 * existing network upgrading in place, a subsite does not get the occurrence
 * table until someone visits its wp-admin (or a network activation /
 * new-site event runs). A blog without the table must therefore show exactly
 * what it would show with no recurrence code present at all -- not fatal, and
 * equally not an empty list. "Does not fatal" is the weaker property and is
 * not sufficient on its own; see
 * `test_occurrence_query_degrades_gracefully_when_table_is_absent()` below.
 *
 * Per AGENTS.md: never remove `@group multisite` from this class, and never
 * add `@codeCoverageIgnore` to a multisite-only branch -- CI runs this suite
 * and merges its coverage into the gate. `phpunit.xml.dist` excludes the
 * `multisite` group, so this file is silent in `npm run test:unit:php`; run
 * it explicitly with `npm run test:unit:php:multisite`, which invokes
 * `phpunit -c phpunit-multisite.xml.dist`.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use GatherPress\Core\Event;
use GatherPress\Core\Event\Query as Event_Query;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Query;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use Throwable;
use WP_Query;

/**
 * Class Test_Backcompat_Multisite.
 *
 * @group multisite
 *
 * @since 0.36.0
 */
class Test_Backcompat_Multisite extends Base {

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
	 * Create a published, non-recurring event with a datetime range on the
	 * currently active blog.
	 *
	 * @since 0.36.0
	 *
	 * @return int The created post ID.
	 */
	protected function create_event(): int {
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
					'dateTimeStart' => '2026-09-03 18:00:00',
					'dateTimeEnd'   => '2026-09-03 20:00:00',
					'timezone'      => 'UTC',
				)
			)
		);

		Event_Setup::get_instance()->set_datetimes( $post_id );

		return (int) $post_id;
	}

	/**
	 * Create a recurring series on the currently active blog and project its
	 * occurrence rows.
	 *
	 * @since 0.36.0
	 *
	 * @return int The created series post ID.
	 */
	protected function create_series(): int {
		$post_id = $this->create_event();

		add_post_meta( $post_id, Meta::META_KEY, wp_json_encode( self::DAILY_RULE ) );
		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );

		return $post_id;
	}

	/**
	 * (a) Coverage for a subsite created AFTER network activation getting the
	 * occurrence table via the `wp_initialize_site` -> `on_site_create()`
	 * path, the same network-wide table creation hook that already covers
	 * `wp_gatherpress_events` (see `Test_Setup::test_on_site_create_multisite()`).
	 *
	 * @group multisite
	 * @covers \GatherPress\Core\Setup::on_site_create
	 * @covers \GatherPress\Core\Setup::create_tables
	 *
	 * @return void
	 */
	public function test_subsite_created_after_activation_gets_the_occurrence_table(): void {
		global $wpdb;

		// Simulate the plugin being network-activated, matching
		// Test_Setup::test_on_site_create_multisite()'s own setup.
		$active_sitewide_plugins                                = get_site_option( 'active_sitewide_plugins', array() );
		$active_sitewide_plugins['gatherpress/gatherpress.php'] = time();
		update_site_option( 'active_sitewide_plugins', $active_sitewide_plugins );

		$new_site_id = $this->factory()->blog->create();

		switch_to_blog( $new_site_id );

		$table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- asserting table creation.
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		restore_current_blog();

		unset( $active_sitewide_plugins['gatherpress/gatherpress.php'] );
		update_site_option( 'active_sitewide_plugins', $active_sitewide_plugins );

		$this->assertSame(
			$table,
			$table_exists,
			'Failed to assert the occurrence table was created on a subsite created while the plugin is network-active.'
		);
	}

	/**
	 * (b) A blog whose occurrence table is absent must show exactly what it
	 * would show with no recurrence code present at all.
	 *
	 * The stated contract is graceful degradation, and "does not fatal" is not
	 * the same thing. `$wpdb` swallows the missing-table error and never
	 * throws, so nothing is reported anywhere -- but the missing table is named
	 * in both the outer `LEFT JOIN` and the `NOT EXISTS` subquery, so without
	 * the guard the whole statement fails and the upcoming-events list comes
	 * back EMPTY. An ordinary, non-recurring, published event then vanishes
	 * from the site with no error surfaced. That is worse than a crash, because
	 * a crash gets reported.
	 *
	 * Note also that `get_results()` returns `array()` rather than `null` on a
	 * failed query, so `select_for_series()`'s `null === $rows` arm is
	 * unreachable for this failure mode -- it must not be left in place as a
	 * branch claiming to handle something it cannot.
	 *
	 * @group multisite
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::select_for_series
	 * @covers \GatherPress\Core\Event\Recurrence\Query::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_occurrence_query_degrades_gracefully_when_table_is_absent(): void {
		global $wpdb;

		$new_site_id = $this->factory()->blog->create();

		switch_to_blog( $new_site_id );

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );

		$table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- simulating the lazy-creation hazard.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		$post_id = $this->create_event();

		// The hazard is specific to a blog whose HAS_RECURRING_OPTION is
		// true but whose own table is missing -- e.g. carried over from a
		// site-meta sync, or a recurring post saved before the table
		// existed. On an ordinary subsite the no-recurring-events guard means
		// this whole code path is never reached at all, which is the safe case.
		update_option( Query::HAS_RECURRING_OPTION, '1', true );

		$exception = null;

		try {
			$rows = Occurrences::get_instance()->select_for_series( array( $post_id ) );
		} catch ( Throwable $e ) {
			$exception = $e;
			$rows      = null;
		}

		$this->assertNull(
			$exception,
			'Failed to assert select_for_series() does not throw/fatal when the occurrence table is absent.'
		);
		$this->assertSame(
			array(),
			$rows,
			'Failed to assert select_for_series() degrades to an empty array when the occurrence table is absent.'
		);

		$args = array(
			'post_type'                    => Event::POST_TYPE,
			Event_Query::EVENT_QUERY_PARAM => 'upcoming',
			'posts_per_page'               => 20,
		);

		$exception = null;
		$query     = null;
		$captured  = array();
		$capture   = static function ( array $pieces ) use ( &$captured ): array {
			$captured[] = $pieces;

			return $pieces;
		};

		// Priority 12 runs after Recurrence\Query's own priority-11 filter, so
		// what is captured is the clause set the query actually executes.
		add_filter( 'posts_clauses', $capture, 12 );

		try {
			$query = new WP_Query( $args );
		} catch ( Throwable $e ) {
			$exception = $e;
		}

		remove_filter( 'posts_clauses', $capture, 12 );

		$with_recurrence = $captured;
		$captured        = array();

		remove_filter( 'posts_clauses', array( Query::get_instance(), 'expand_event_clauses' ), 11 );
		add_filter( 'posts_clauses', $capture, 12 );

		$baseline = new WP_Query( $args );

		remove_filter( 'posts_clauses', $capture, 12 );
		add_filter( 'posts_clauses', array( Query::get_instance(), 'expand_event_clauses' ), 11, 2 );

		$without_recurrence = $captured;
		$baseline_posts     = wp_list_pluck( $baseline->posts, 'ID' );
		$query_posts        = wp_list_pluck( $query->posts, 'ID' );

		restore_current_blog();

		$this->assertNull(
			$exception,
			'Failed to assert the upcoming-events WP_Query does not throw/fatal when the occurrence table is absent.'
		);
		$this->assertSame(
			array( $post_id ),
			$baseline_posts,
			'Failed to assert the fixture event is what the list shows with no recurrence code present at all.'
		);
		$this->assertSame(
			$baseline_posts,
			$query_posts,
			'Failed to assert a subsite missing the occurrence table shows exactly what it would show with no'
				. ' recurrence code present -- an empty list here is the regression: an ordinary published event'
				. ' silently vanishing with no error surfaced anywhere.'
		);
		$this->assertSame(
			$without_recurrence,
			$with_recurrence,
			'Failed to assert the clauses are byte-identical when the occurrence table is absent.'
		);
	}

	/**
	 * (b2) Coverage for the table-existence memo across a blog that gains the
	 * table mid-request.
	 *
	 * The memo is what keeps that guard from costing a schema probe per
	 * query, and `Setup::create_tables()` is the one path that can turn its
	 * answer from false to true inside one request. A memo that outlived that
	 * would leave a blog permanently un-expanded after its table appeared.
	 *
	 * @group multisite
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::table_exists
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::forget_table_exists
	 * @covers \GatherPress\Core\Setup::create_tables
	 *
	 * @return void
	 */
	public function test_table_exists_is_rechecked_after_the_table_is_created(): void {
		global $wpdb;

		$new_site_id = $this->factory()->blog->create();

		switch_to_blog( $new_site_id );

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );

		$table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- simulating the lazy-creation hazard.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		Occurrences::get_instance()->forget_table_exists();

		$missing  = Occurrences::get_instance()->table_exists();
		$memoized = Occurrences::get_instance()->table_exists();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );

		$restored = Occurrences::get_instance()->table_exists();

		restore_current_blog();

		$this->assertFalse(
			$missing,
			'Failed to assert table_exists() reports a missing occurrence table.'
		);
		$this->assertFalse(
			$memoized,
			'Failed to assert the memoized answer matches the probe.'
		);
		$this->assertTrue(
			$restored,
			'Failed to assert create_tables() discards the memo so the table is seen once it exists.'
		);
	}

	/**
	 * (c) Coverage for `switch_to_blog()` resolving the correct per-blog table
	 * prefix in the `Occurrences` class: two blogs each carry their own
	 * series under the same post ID space, and a read on one blog never sees
	 * the other's rows.
	 *
	 * @group multisite
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::select_for_series
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::project
	 *
	 * @return void
	 */
	public function test_switch_to_blog_resolves_the_correct_per_blog_table(): void {
		$site_a = get_current_blog_id();
		$site_b = $this->factory()->blog->create();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );

		$series_a = $this->create_series();

		switch_to_blog( $site_b );

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );

		$series_b = $this->create_series();

		restore_current_blog();

		$this->assertSame( $site_a, get_current_blog_id(), 'Failed to assert the current blog was restored.' );

		$rows_a = Occurrences::get_instance()->select_for_series( array( $series_a ) );

		$this->assertCount( 5, $rows_a, 'Failed to assert site A\'s series projected its own five occurrences.' );
		$this->assertSame(
			array(),
			Occurrences::get_instance()->select_for_series( array( $series_b ) ),
			'Failed to assert site A cannot see site B\'s series by its post ID -- proves the query hit site A\'s'
				. ' own table, not a shared or stale one.'
		);

		switch_to_blog( $site_b );

		$rows_b = Occurrences::get_instance()->select_for_series( array( $series_b ) );

		$this->assertCount( 5, $rows_b, 'Failed to assert site B\'s series projected its own five occurrences.' );
		$this->assertSame(
			array(),
			Occurrences::get_instance()->select_for_series( array( $series_a ) ),
			'Failed to assert site B cannot see site A\'s series by its post ID.'
		);

		restore_current_blog();
	}
}
