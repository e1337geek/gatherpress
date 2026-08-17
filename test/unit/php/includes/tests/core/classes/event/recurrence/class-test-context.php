<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Context.
 *
 * REQ-9's claim is that an occurrence's datetime reaches every existing block
 * without a single block file changing. A test that calls the filter callback
 * directly cannot prove that, so the block tests here go through `go_to()` and
 * `do_blocks()` -- the real request, the real block, the real wiring.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use GatherPress\Core\Calendar\Calendar;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Recurrence\Context;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use WP_Query;

/**
 * Class Test_Context.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Context
 */
class Test_Context extends Base {

	use Occurrence_Fixtures;

	/**
	 * The reference weekly rule, matching `Occurrence_Fixtures::expected_weekly_set()`.
	 *
	 * @since 0.36.0
	 * @var array
	 */
	const WEEKLY_RULE = array(
		'frequency' => 'weekly',
		'interval'  => 2,
		'weekdays'  => array( 2, 4 ),
		'end_type'  => 'count',
		'count'     => 5,
	);

	/**
	 * Recurrence identifier of the reference set's second occurrence.
	 *
	 * Deliberately not the first: an implementation that returns the series
	 * anchor rather than the occurrence record passes against the first entry
	 * by coincidence, because the anchor *is* the first occurrence.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const SECOND_ID = '20260915T180000';

	/**
	 * Local start of the reference set's second occurrence.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const SECOND_START = '2026-09-15 18:00:00';

	/**
	 * GMT start of the reference set's second occurrence.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const SECOND_START_GMT = '2026-09-15 22:00:00';

	/**
	 * GMT start of the series anchor, which is also the first occurrence.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const ANCHOR_START_GMT = '2026-09-03 22:00:00';

	/**
	 * Ensure the occurrence table exists and no context leaks in from another test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );
		Context::get_instance()->clear();
	}

	/**
	 * Leave no occurrence context behind for the next test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		Context::get_instance()->clear();

		parent::tearDown();
	}

	/**
	 * Create the reference recurring event and project its occurrence rows.
	 *
	 * @since 0.36.0
	 *
	 * @return int The projected post ID.
	 */
	protected function create_and_project(): int {
		$post_id = $this->create_recurring_event( self::WEEKLY_RULE );

		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );

		return $post_id;
	}

	/**
	 * Register the occurrence query var so `go_to()` keeps it.
	 *
	 * The rewrite lane owns real registration; this stands in for it so the
	 * `wp` wiring can be driven through a genuine request here.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function register_query_var(): void {
		add_filter(
			'query_vars',
			static function ( array $query_vars ): array {
				$query_vars[] = Context::QUERY_VAR;

				return $query_vars;
			}
		);
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
		$instance = Context::get_instance();
		$hooks    = array(
			array(
				'type'     => 'filter',
				'name'     => 'get_post_metadata',
				'priority' => 10,
				'callback' => array( $instance, 'metadata' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'wp',
				'priority' => 10,
				'callback' => array( $instance, 'sync' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'the_post',
				'priority' => 10,
				'callback' => array( $instance, 'sync' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'wp_reset_postdata',
				'priority' => 10,
				'callback' => array( $instance, 'sync' ),
			),
		);

		$this->assert_hooks( $hooks, $instance );
	}

	/**
	 * Coverage for `current()` outside occurrence context.
	 *
	 * @covers ::current
	 * @covers ::clear
	 *
	 * @return void
	 */
	public function test_current_returns_null_outside_context(): void {
		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert that current() is null outside occurrence context.'
		);
	}

	/**
	 * Coverage for `set()` entering the context of a real occurrence row.
	 *
	 * @covers ::set
	 * @covers ::current
	 *
	 * @return void
	 */
	public function test_set_enters_the_context_of_an_existing_occurrence(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$current = Context::get_instance()->current();

		$this->assertIsArray( $current, 'Failed to assert that current() returns the occurrence row.' );
		$this->assertSame(
			self::SECOND_ID,
			$current['recurrence_id'],
			'Failed to assert that the context carries the requested recurrence_id.'
		);
		$this->assertSame(
			$post_id,
			(int) $current['series_post_id'],
			'Failed to assert that the context carries the series post ID.'
		);
	}

	/**
	 * Coverage for `set()` when the composite key matches no row.
	 *
	 * @covers ::set
	 *
	 * @return void
	 */
	public function test_set_leaves_context_unset_when_the_composite_key_matches_nothing(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, '20991231T235900' );

		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert that an unmatched composite key leaves the context unset.'
		);
	}

	/**
	 * Coverage for the metadata filter serving the occurrence's datetime.
	 *
	 * @covers ::metadata
	 * @covers ::occurrence_value
	 *
	 * @return void
	 */
	public function test_metadata_returns_occurrence_datetime_in_context(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			self::SECOND_START,
			get_post_meta( $post_id, 'gatherpress_datetime_start', true ),
			'Failed to assert that the occurrence local start is served in context.'
		);
		$this->assertSame(
			self::SECOND_START_GMT,
			get_post_meta( $post_id, 'gatherpress_datetime_start_gmt', true ),
			'Failed to assert that the occurrence GMT start is served in context.'
		);
		$this->assertSame(
			'2026-09-15 20:00:00',
			get_post_meta( $post_id, 'gatherpress_datetime_end', true ),
			'Failed to assert that the occurrence local end is served in context.'
		);
		$this->assertSame(
			'2026-09-16 00:00:00',
			get_post_meta( $post_id, 'gatherpress_datetime_end_gmt', true ),
			'Failed to assert that the occurrence GMT end is served in context.'
		);
		$this->assertSame(
			'America/New_York',
			get_post_meta( $post_id, 'gatherpress_timezone', true ),
			'Failed to assert that the occurrence timezone is served in context.'
		);
	}

	/**
	 * Coverage for the metadata filter standing aside outside occurrence context.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_metadata_returns_series_datetime_outside_context(): void {
		$post_id = $this->create_and_project();

		$this->assertSame(
			$this->reference_anchor_start,
			get_post_meta( $post_id, 'gatherpress_datetime_start', true ),
			'Failed to assert that the series datetime is served outside occurrence context.'
		);
	}

	/**
	 * Coverage for the metadata filter scoping its substitution to the series post.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_metadata_returns_series_datetime_for_a_different_post(): void {
		$post_id = $this->create_and_project();
		$other   = $this->create_recurring_event( self::WEEKLY_RULE );

		update_post_meta( $other, 'gatherpress_datetime_start', '2030-01-01 08:00:00' );

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			'2030-01-01 08:00:00',
			get_post_meta( $other, 'gatherpress_datetime_start', true ),
			'Failed to assert that another post keeps its own datetime while context is set.'
		);
	}

	/**
	 * Coverage for the metadata filter ignoring meta keys it does not own.
	 *
	 * Asserted against a multi-value key rather than a single-value one: a
	 * filter missing its key allowlist still answers a single-value read with
	 * the right string by way of the series fallback, so a single-value
	 * assertion here cannot fail. Collapsing a multi-value read to one entry is
	 * the damage an unscoped filter actually does.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_metadata_leaves_unrelated_meta_keys_untouched(): void {
		$post_id = $this->create_and_project();

		add_post_meta( $post_id, 'gatherpress_unit_test_key', 'first' );
		add_post_meta( $post_id, 'gatherpress_unit_test_key', 'second' );

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			'first',
			get_post_meta( $post_id, 'gatherpress_unit_test_key', true ),
			'Failed to assert that an unrelated meta key is left untouched in context.'
		);
		$this->assertSame(
			array( 'first', 'second' ),
			get_post_meta( $post_id, 'gatherpress_unit_test_key', false ),
			'Failed to assert that an unrelated multi-value meta key keeps every one of its values.'
		);
	}

	/**
	 * Coverage for the metadata filter's non-single return shape.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_metadata_returns_an_array_when_single_is_false(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			array( self::SECOND_START ),
			get_post_meta( $post_id, 'gatherpress_datetime_start', false ),
			'Failed to assert that a non-single meta read returns the occurrence value in an array.'
		);
	}

	/**
	 * Coverage for the re-entrancy guard.
	 *
	 * An occurrence row's `timezone` column is nullable, and the filter falls
	 * back to the series' own `gatherpress_timezone` meta when it is empty.
	 * That fallback is a `get_post_meta()` call on the very key the filter is
	 * answering, so without the guard it re-enters itself without end. This
	 * test completes only because the guard stops the second entry.
	 *
	 * @covers ::metadata
	 * @covers ::occurrence_value
	 * @covers ::read_series_meta
	 *
	 * @return void
	 */
	public function test_metadata_does_not_recurse_when_the_occurrence_timezone_is_empty(): void {
		global $wpdb;

		$post_id = $this->create_and_project();
		$table   = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET timezone = NULL WHERE series_post_id = %d AND recurrence_id = %s',
				$table,
				$post_id,
				self::SECOND_ID
			)
		);

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			'America/New_York',
			get_post_meta( $post_id, 'gatherpress_timezone', true ),
			'Failed to assert that an empty occurrence timezone falls back to the series meta without recursing.'
		);
	}

	/**
	 * Coverage for `read_series_meta()` invoked directly.
	 *
	 * @covers ::read_series_meta
	 *
	 * @return void
	 */
	public function test_read_series_meta_returns_the_series_value(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			$this->reference_anchor_start,
			Utility::invoke_hidden_method(
				Context::get_instance(),
				'read_series_meta',
				array( $post_id, 'gatherpress_datetime_start' )
			),
			'Failed to assert that read_series_meta bypasses the occurrence substitution.'
		);
		$this->assertSame(
			self::SECOND_START,
			get_post_meta( $post_id, 'gatherpress_datetime_start', true ),
			'Failed to assert that read_series_meta restores the substitution when it returns.'
		);
	}

	/**
	 * Coverage for `occurrence_value()` reading the occurrence row's column.
	 *
	 * @covers ::occurrence_value
	 *
	 * @return void
	 */
	public function test_occurrence_value_returns_the_row_column(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			self::SECOND_START_GMT,
			Utility::invoke_hidden_method(
				Context::get_instance(),
				'occurrence_value',
				array( 'gatherpress_datetime_start_gmt' )
			),
			'Failed to assert that occurrence_value returns the occurrence row column.'
		);
	}

	/**
	 * Coverage for `occurrence_value()` falling back to the series meta.
	 *
	 * @covers ::occurrence_value
	 *
	 * @return void
	 */
	public function test_occurrence_value_falls_back_to_series_meta_when_the_column_is_empty(): void {
		global $wpdb;

		$post_id = $this->create_and_project();
		$table   = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET timezone = %s WHERE series_post_id = %d AND recurrence_id = %s',
				$table,
				'',
				$post_id,
				self::SECOND_ID
			)
		);

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			'America/New_York',
			Utility::invoke_hidden_method(
				Context::get_instance(),
				'occurrence_value',
				array( 'gatherpress_timezone' )
			),
			'Failed to assert that occurrence_value falls back to the series meta for an empty column.'
		);
	}

	/**
	 * Coverage for `get_datetime()` in occurrence context.
	 *
	 * @covers ::get_datetime
	 *
	 * @return void
	 */
	public function test_get_datetime_returns_the_occurrence_shape_in_context(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			array(
				'datetime_start'     => self::SECOND_START,
				'datetime_start_gmt' => self::SECOND_START_GMT,
				'datetime_end'       => '2026-09-15 20:00:00',
				'datetime_end_gmt'   => '2026-09-16 00:00:00',
				'timezone'           => 'America/New_York',
			),
			Context::get_instance()->get_datetime( $post_id ),
			'Failed to assert that get_datetime returns the occurrence in the Event::get_datetime shape.'
		);
	}

	/**
	 * Coverage for `get_datetime()` reading the record rather than the filter.
	 *
	 * `get_datetime()` is the explicit accessor the recurrence subsystem calls;
	 * the `get_post_metadata` filter is the compatibility path for unmodified
	 * blocks. With the filter unhooked, delegating to `Event::get_datetime()`
	 * would return the series anchor — so this is what separates the two.
	 *
	 * @covers ::get_datetime
	 * @covers ::occurrence_value
	 *
	 * @return void
	 */
	public function test_get_datetime_reads_the_record_without_the_metadata_filter(): void {
		$post_id  = $this->create_and_project();
		$instance = Context::get_instance();

		$instance->set( $post_id, self::SECOND_ID );

		remove_filter( 'get_post_metadata', array( $instance, 'metadata' ), 10 );

		$datetime = $instance->get_datetime( $post_id );

		add_filter( 'get_post_metadata', array( $instance, 'metadata' ), 10, 4 );

		$this->assertSame(
			self::SECOND_START,
			$datetime['datetime_start'],
			'Failed to assert that get_datetime reads the occurrence record without the metadata filter.'
		);
	}

	/**
	 * Coverage for `get_datetime()` outside occurrence context.
	 *
	 * @covers ::get_datetime
	 *
	 * @return void
	 */
	public function test_get_datetime_returns_the_series_outside_context(): void {
		$post_id = $this->create_and_project();

		$this->assertSame(
			$this->reference_anchor_start,
			Context::get_instance()->get_datetime( $post_id )['datetime_start'],
			'Failed to assert that get_datetime returns the series datetime outside occurrence context.'
		);
	}

	/**
	 * Coverage for `get_datetime()` asked about a post other than the context's.
	 *
	 * @covers ::get_datetime
	 *
	 * @return void
	 */
	public function test_get_datetime_returns_the_series_for_another_post_in_context(): void {
		$post_id = $this->create_and_project();
		$other   = $this->create_recurring_event( self::WEEKLY_RULE );

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			$this->reference_anchor_start,
			Context::get_instance()->get_datetime( $other )['datetime_start'],
			'Failed to assert that get_datetime returns another post\'s own series datetime.'
		);
	}

	/**
	 * Coverage for `occurrence_url()` carrying the recurrence identifier.
	 *
	 * @covers ::occurrence_url
	 *
	 * @return void
	 */
	public function test_occurrence_url_carries_the_recurrence_id(): void {
		$post_id = $this->create_and_project();
		$url     = Context::occurrence_url( $post_id, self::SECOND_ID );

		$this->assertStringContainsString(
			sprintf( '%s=%s', Context::QUERY_VAR, self::SECOND_ID ),
			$url,
			'Failed to assert that the occurrence URL carries the recurrence identifier.'
		);
		$this->assertStringContainsString(
			(string) wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH ),
			$url,
			'Failed to assert that the occurrence URL is built on the series permalink.'
		);
	}

	/**
	 * Coverage for `occurrence_url()` without a recurrence identifier.
	 *
	 * @covers ::occurrence_url
	 *
	 * @return void
	 */
	public function test_occurrence_url_returns_the_plain_permalink_without_a_recurrence_id(): void {
		$post_id = $this->create_and_project();

		$this->assertSame(
			get_permalink( $post_id ),
			Context::occurrence_url( $post_id, '' ),
			'Failed to assert that an empty recurrence identifier yields the plain permalink.'
		);
	}

	/**
	 * Coverage for `occurrence_url()` when the post has no permalink.
	 *
	 * @covers ::occurrence_url
	 *
	 * @return void
	 */
	public function test_occurrence_url_returns_empty_string_for_a_missing_post(): void {
		$this->assertSame(
			'',
			Context::occurrence_url( 0, self::SECOND_ID ),
			'Failed to assert that a post with no permalink yields an empty occurrence URL.'
		);
	}

	/**
	 * Coverage for context being established on `wp`, from a real request.
	 *
	 * @covers ::sync
	 * @covers ::maybe_set_from_request
	 * @covers ::set
	 *
	 * @return void
	 */
	public function test_context_is_established_on_wp_for_an_occurrence_request(): void {
		$post_id = $this->create_and_project();

		$this->register_query_var();
		$this->go_to( Context::occurrence_url( $post_id, self::SECOND_ID ) );

		$current = Context::get_instance()->current();

		$this->assertIsArray( $current, 'Failed to assert that the wp action establishes occurrence context.' );
		$this->assertSame(
			self::SECOND_ID,
			$current['recurrence_id'],
			'Failed to assert that the established context matches the requested occurrence.'
		);
	}

	/**
	 * Coverage for a request without the occurrence query var.
	 *
	 * @covers ::sync
	 * @covers ::maybe_set_from_request
	 *
	 * @return void
	 */
	public function test_context_stays_unset_on_wp_without_the_query_var(): void {
		$post_id = $this->create_and_project();

		$this->register_query_var();
		$this->go_to( (string) get_permalink( $post_id ) );

		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert that a plain series request leaves the context unset.'
		);
	}

	/**
	 * Coverage for an occurrence query var on a request that resolves to no post.
	 *
	 * @covers ::sync
	 * @covers ::maybe_set_from_request
	 *
	 * @return void
	 */
	public function test_context_stays_unset_when_the_request_resolves_to_no_post(): void {
		$this->create_and_project();

		$this->register_query_var();
		$this->go_to( add_query_arg( Context::QUERY_VAR, self::SECOND_ID, home_url( '/' ) ) );

		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert that an occurrence query var on a non-singular request is ignored.'
		);
	}

	/**
	 * Coverage for REQ-16: a request naming no occurrence queries no occurrence.
	 *
	 * Both guards in `maybe_set_from_request()` exist for this. Neither changes
	 * the resulting context — `Occurrences::get()` would return null for an
	 * empty recurrence identifier or a post ID of zero anyway — so the only
	 * thing that can tell a missing guard apart is the query it did not make.
	 *
	 * @covers ::sync
	 * @covers ::maybe_set_from_request
	 *
	 * @return void
	 */
	public function test_a_request_naming_no_occurrence_makes_no_occurrence_query(): void {
		global $wpdb;

		$post_id = $this->create_and_project();
		$table   = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$seen    = 0;

		$this->register_query_var();

		add_filter(
			'query',
			static function ( string $query ) use ( &$seen, $table ): string {
				if ( str_contains( $query, $table ) ) {
					++$seen;
				}

				return $query;
			}
		);

		$this->go_to( (string) get_permalink( $post_id ) );

		$this->assertSame(
			0,
			$seen,
			'Failed to assert that a plain series request never queries the occurrence table.'
		);

		$this->go_to( add_query_arg( Context::QUERY_VAR, self::SECOND_ID, home_url( '/' ) ) );

		$this->assertSame(
			0,
			$seen,
			'Failed to assert that a request resolving to no post never queries the occurrence table.'
		);

		$this->go_to( Context::occurrence_url( $post_id, self::SECOND_ID ) );

		$this->assertGreaterThan(
			0,
			$seen,
			'Failed to assert that a genuine occurrence request does query the occurrence table.'
		);
	}

	/**
	 * Coverage for `maybe_set_from_request()` invoked directly, on both return paths.
	 *
	 * @covers ::maybe_set_from_request
	 *
	 * @return void
	 */
	public function test_maybe_set_from_request_returns_early_without_a_resolvable_request(): void {
		$post_id  = $this->create_and_project();
		$instance = Context::get_instance();

		Utility::invoke_hidden_method( $instance, 'maybe_set_from_request', array( $post_id ) );

		$this->assertNull(
			$instance->current(),
			'Failed to assert that a request carrying no occurrence query var leaves the context unset.'
		);

		set_query_var( Context::QUERY_VAR, self::SECOND_ID );

		Utility::invoke_hidden_method( $instance, 'maybe_set_from_request', array( 0 ) );

		$this->assertNull(
			$instance->current(),
			'Failed to assert that a post ID of zero leaves the context unset.'
		);

		Utility::invoke_hidden_method( $instance, 'maybe_set_from_request', array( $post_id ) );

		$this->assertIsArray(
			$instance->current(),
			'Failed to assert that a resolvable request enters occurrence context.'
		);
	}

	/**
	 * Coverage for an inner loop over an unrelated post, and the reset afterwards.
	 *
	 * Driven through a real secondary `WP_Query` rather than a bare
	 * `do_action()`, because the whole point is that the loop's own wiring
	 * behaves — a hand-fired action proves nothing about `WP_Query::the_post()`.
	 *
	 * @covers ::sync
	 * @covers ::maybe_set_from_request
	 * @covers ::clear
	 *
	 * @return void
	 */
	public function test_an_inner_loop_over_an_unrelated_post_does_not_inherit_context(): void {
		$post_id = $this->create_and_project();
		$other   = $this->create_recurring_event( self::WEEKLY_RULE );

		$this->register_query_var();
		$this->go_to( Context::occurrence_url( $post_id, self::SECOND_ID ) );

		$this->assertIsArray(
			Context::get_instance()->current(),
			'Failed to assert that the occurrence request established context.'
		);

		$loop = new WP_Query(
			array(
				'p'         => $other,
				'post_type' => Event::POST_TYPE,
			)
		);

		$loop->the_post();

		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert that iterating an unrelated post clears occurrence context.'
		);
		$this->assertSame(
			$this->reference_anchor_start,
			get_post_meta( $other, 'gatherpress_datetime_start', true ),
			'Failed to assert that the unrelated post in the inner loop reads its own datetime.'
		);

		wp_reset_postdata();

		$this->assertIsArray(
			Context::get_instance()->current(),
			'Failed to assert that resetting post data restores the request\'s occurrence context.'
		);
	}

	/**
	 * Coverage for REQ-9: the unmodified event-date block renders the occurrence's date.
	 *
	 * No block file is modified anywhere in this task. The block is rendered
	 * through `do_blocks()` on a real occurrence request, so what this asserts
	 * is the production read path end to end.
	 *
	 * @covers ::metadata
	 * @covers ::sync
	 *
	 * @return void
	 */
	public function test_event_date_block_renders_occurrence_date_unmodified(): void {
		$post_id = $this->create_and_project();

		$this->register_query_var();
		$this->go_to( Context::occurrence_url( $post_id, self::SECOND_ID ) );

		$output = do_blocks(
			'<!-- wp:gatherpress/event-date {"displayType":"start","startDateFormat":"Y-m-d H:i"} /-->'
		);

		$this->assertStringContainsString(
			'2026-09-15 18:00',
			$output,
			'Failed to assert that the unmodified event-date block renders the occurrence date.'
		);
		$this->assertStringNotContainsString(
			'2026-09-03 18:00',
			$output,
			'Failed to assert that the event-date block stopped rendering the series anchor date.'
		);
	}

	/**
	 * Coverage for REQ-9 on the add-to-calendar block.
	 *
	 * The block's hrefs are on-site endpoint URLs; the datetime lives in what
	 * those endpoints serve. So the block is rendered for real, and the two
	 * payloads its links resolve to are asserted to carry the occurrence's
	 * datetime rather than the series anchor's.
	 *
	 * @covers ::metadata
	 * @covers ::sync
	 *
	 * @return void
	 */
	public function test_add_to_calendar_links_carry_occurrence_datetime(): void {
		$post_id = $this->create_and_project();

		$this->register_query_var();
		$this->go_to( Context::occurrence_url( $post_id, self::SECOND_ID ) );

		$output = do_blocks(
			'<!-- wp:gatherpress/add-to-calendar -->'
			. '<div class="wp-block-gatherpress-add-to-calendar">'
			. '<a href="#gatherpress-google-calendar">Google Calendar</a>'
			. '<a href="#gatherpress-ical-calendar">iCal</a>'
			. '</div>'
			. '<!-- /wp:gatherpress/add-to-calendar -->'
		);

		$this->assertStringNotContainsString(
			'#gatherpress-google-calendar',
			$output,
			'Failed to assert that the add-to-calendar block resolved its placeholder hrefs.'
		);

		$calendar = new Calendar( $post_id );

		$this->assertStringContainsString(
			'20260915T220000Z',
			$calendar->get_google_destination_url(),
			'Failed to assert that the Google Calendar payload carries the occurrence datetime.'
		);
		$this->assertStringContainsString(
			'DTSTART:20260915T220000Z',
			$calendar->get_ical_event_string(),
			'Failed to assert that the iCal payload carries the occurrence datetime.'
		);
		$this->assertStringNotContainsString(
			'DTSTART:20260903T220000Z',
			$calendar->get_ical_event_string(),
			'Failed to assert that the iCal payload stopped carrying the series anchor datetime.'
		);
	}

	/**
	 * Coverage for teardown leaving no stale occurrence value behind.
	 *
	 * @covers ::sync
	 * @covers ::clear
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_no_stale_occurrence_value_leaks_after_teardown(): void {
		$post_id = $this->create_and_project();

		$this->register_query_var();
		$this->go_to( Context::occurrence_url( $post_id, self::SECOND_ID ) );

		$this->assertSame(
			self::SECOND_START,
			get_post_meta( $post_id, 'gatherpress_datetime_start', true ),
			'Failed to assert that the occurrence request served the occurrence datetime.'
		);

		$this->go_to( (string) get_permalink( $post_id ) );

		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert that occurrence context is gone after the request ends.'
		);
		$this->assertSame(
			$this->reference_anchor_start,
			get_post_meta( $post_id, 'gatherpress_datetime_start', true ),
			'Failed to assert that no stale occurrence value leaks into a later request.'
		);

		$output = do_blocks(
			'<!-- wp:gatherpress/event-date {"displayType":"start","startDateFormat":"Y-m-d H:i"} /-->'
		);

		$this->assertStringContainsString(
			'2026-09-03 18:00',
			$output,
			'Failed to assert that the event-date block returns to the series date after teardown.'
		);
	}

	/**
	 * Coverage for PRD C-3: an occurrence's time of day comes from its record.
	 *
	 * The row's time of day is moved away from the anchor's, which the current
	 * rule set cannot produce. The test exists so the read path can never
	 * hard-code "apply the anchor's time to the occurrence's date" -- doing so
	 * would foreclose multiple-times-per-day rules later.
	 *
	 * @covers ::metadata
	 * @covers ::get_datetime
	 * @covers ::occurrence_value
	 *
	 * @return void
	 */
	public function test_occurrence_time_of_day_comes_from_the_record_not_the_anchor(): void {
		global $wpdb;

		$post_id = $this->create_and_project();
		$table   = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET datetime_start = %s, datetime_start_gmt = %s,'
					. ' datetime_end = %s, datetime_end_gmt = %s'
					. ' WHERE series_post_id = %d AND recurrence_id = %s',
				$table,
				'2026-09-15 09:30:00',
				'2026-09-15 13:30:00',
				'2026-09-15 11:30:00',
				'2026-09-15 15:30:00',
				$post_id,
				self::SECOND_ID
			)
		);

		$this->register_query_var();
		$this->go_to( Context::occurrence_url( $post_id, self::SECOND_ID ) );

		$this->assertSame(
			'2026-09-15 09:30:00',
			Context::get_instance()->get_datetime( $post_id )['datetime_start'],
			'Failed to assert that the occurrence record\'s time of day wins over the anchor\'s.'
		);

		$output = do_blocks(
			'<!-- wp:gatherpress/event-date {"displayType":"start","startDateFormat":"Y-m-d H:i"} /-->'
		);

		$this->assertStringContainsString(
			'2026-09-15 09:30',
			$output,
			'Failed to assert that the block rendered the record\'s time of day.'
		);
		$this->assertStringNotContainsString(
			'2026-09-15 18:00',
			$output,
			'Failed to assert that the anchor\'s time of day was not applied to the occurrence date.'
		);
	}

	/**
	 * Coverage for the pre-warmed `Event` instance trap.
	 *
	 * Nothing stops a plugin or theme from constructing an `Event` and reading
	 * its datetime before `wp` fires. With a single-slot cache that instance
	 * would return the series datetime for the rest of its life. The cache is
	 * keyed by occurrence, so the pre-warmed instance resolves correctly.
	 *
	 * @covers ::metadata
	 * @covers ::set
	 *
	 * @return void
	 */
	public function test_event_instance_constructed_before_context_still_reports_occurrence_datetime(): void {
		$post_id = $this->create_and_project();
		$event   = new Event( $post_id );

		$this->assertSame(
			self::ANCHOR_START_GMT,
			$event->get_datetime()['datetime_start_gmt'],
			'Failed to assert that the pre-warmed instance reports the series datetime before context.'
		);

		Context::get_instance()->set( $post_id, self::SECOND_ID );

		$this->assertSame(
			self::SECOND_START_GMT,
			$event->get_datetime()['datetime_start_gmt'],
			'Failed to assert that a pre-warmed Event instance reports the occurrence datetime in context.'
		);

		Context::get_instance()->clear();

		$this->assertSame(
			self::ANCHOR_START_GMT,
			$event->get_datetime()['datetime_start_gmt'],
			'Failed to assert that the pre-warmed instance returns to the series datetime after clear.'
		);
	}
	/**
	 * Outside occurrence context there is no occurrence to report, and the
	 * frozen stub is always outside it.
	 *
	 * @covers ::current
	 *
	 * @return void
	 */
	public function test_current_returns_null_outside_occurrence_context(): void {
		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert that current returns null when no occurrence is set.'
		);
	}

	/**
	 * Returning null from `get_post_metadata` is that filter's
	 * do-not-short-circuit convention, so the stub returning null for every
	 * read is what keeps unmodified blocks reading core's own meta. A non-null
	 * `$value` from an earlier filter must get the same answer: the stub has no
	 * occurrence to serve, so it declines rather than passing anything along.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_metadata_declines_to_short_circuit_every_read(): void {
		$post_id  = $this->factory->post->create();
		$instance = Context::get_instance();

		$this->assertNull(
			$instance->metadata( null, $post_id, 'gatherpress_datetime_start', true ),
			'Failed to assert that metadata returns null for an unfiltered single read.'
		);
		$this->assertNull(
			$instance->metadata( array( '2026-01-01 09:00:00' ), $post_id, 'gatherpress_datetime_start', false ),
			'Failed to assert that metadata returns null for a non-single read another filter already answered.'
		);
		$this->assertNull(
			$instance->metadata( null, $post_id, 'unrelated_meta_key', true ),
			'Failed to assert that metadata returns null for a meta key recurrence does not derive.'
		);
	}

	/**
	 * The permalink builder is a frozen static, so it answers with the empty
	 * string for every series and every occurrence identifier alike.
	 *
	 * @covers ::occurrence_url
	 *
	 * @return void
	 */
	public function test_occurrence_url_returns_an_empty_string(): void {
		$post_id = $this->factory->post->create();

		$this->assertSame(
			'',
			Context::occurrence_url( $post_id, '20260101T090000' ),
			'Failed to assert that occurrence_url returns an empty string.'
		);
		$this->assertSame(
			'',
			Context::occurrence_url( $post_id, '20260214T173000' ),
			'Failed to assert that occurrence_url returns an empty string for a second occurrence of the same series.'
		);
	}

	/**
	 * The query var is the permalink's occurrence segment, so its value is a
	 * public contract shared with rewrite rules and with block render paths.
	 *
	 * @return void
	 */
	public function test_query_var_is_the_prefixed_occurrence_name(): void {
		$this->assertSame(
			'gatherpress_occurrence',
			Context::QUERY_VAR,
			'Failed to assert that the occurrence query var is gatherpress_occurrence.'
		);
	}

	/**
	 * `set()` and `clear()` are the entry and exit of occurrence context. The
	 * stub stores nothing, so entering context must not make `current()` start
	 * answering, and leaving it must be safe to call whether or not context was
	 * ever entered.
	 *
	 * @covers ::set
	 * @covers ::clear
	 * @covers ::current
	 *
	 * @return void
	 */
	public function test_set_and_clear_leave_current_unanswered(): void {
		$post_id  = $this->factory->post->create();
		$instance = Context::get_instance();

		$instance->set( $post_id, '20260101T090000' );

		$this->assertNull(
			$instance->current(),
			'Failed to assert that current still returns null after set.'
		);

		$instance->clear();

		$this->assertNull(
			$instance->current(),
			'Failed to assert that current returns null after clear.'
		);

		// Clearing a context that was never entered is a no-op, not an error.
		$instance->clear();

		$this->assertNull(
			$instance->current(),
			'Failed to assert that a second clear leaves current returning null.'
		);
	}
}
