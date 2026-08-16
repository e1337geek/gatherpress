<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Occurrences.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrence_Ref;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;

/**
 * Class Test_Occurrences.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Occurrences
 */
class Test_Occurrences extends Base {

	use Occurrence_Fixtures;

	/**
	 * The reference weekly rule shared by the projection tests, matching
	 * `Occurrence_Fixtures::expected_weekly_set()`.
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
	 * Ensure the occurrence table exists before every test, independent of
	 * execution order relative to Test_Schema.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );
	}

	/**
	 * Create a recurring event and project its rule, returning the post ID.
	 *
	 * @since 0.36.0
	 *
	 * @param array $rule Recurrence rule values.
	 *
	 * @return int The projected post ID.
	 */
	protected function create_and_project( array $rule = self::WEEKLY_RULE ): int {
		$post_id = $this->create_recurring_event( $rule );

		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );

		return $post_id;
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
		$instance = Occurrences::get_instance();
		$hooks    = array(
			array(
				'type'     => 'action',
				'name'     => 'wp_after_insert_post',
				'priority' => 20,
				'callback' => array( $instance, 'maybe_project' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'delete_post',
				'priority' => 10,
				'callback' => array( $instance, 'maybe_delete_for_series' ),
			),
		);

		$this->assert_hooks( $hooks, $instance );
	}

	/**
	 * Coverage for C-1: the occurrence identifier is the local start in `Ymd\THis` form.
	 *
	 * @covers ::recurrence_id
	 *
	 * @return void
	 */
	public function test_recurrence_id_format_is_ymdthis(): void {
		$start = new DateTimeImmutable( '2026-09-03 18:00:00', new DateTimeZone( 'America/New_York' ) );

		$this->assertSame(
			'20260903T180000',
			Occurrences::recurrence_id( $start ),
			'Failed to assert that recurrence_id formats the local start as Ymd\THis.'
		);
	}

	/**
	 * Coverage for `project()` writing every row of the reference weekly rule.
	 *
	 * @covers ::project
	 * @covers ::resolve_anchor
	 * @covers ::build_occurrence_row
	 * @covers ::upsert_occurrences
	 * @covers ::insert_or_update_rows
	 * @covers ::delete_stale_rows
	 *
	 * @return void
	 */
	public function test_project_writes_rows_for_weekly_rule(): void {
		$post_id = $this->create_recurring_event( self::WEEKLY_RULE );
		Meta::get_instance()->set_recurrence( $post_id );

		$written = Occurrences::get_instance()->project( $post_id );

		$this->assertSame( 5, $written, 'Failed to assert that project() wrote all five occurrences.' );

		$rows = Occurrences::get_instance()->select_for_series( array( $post_id ) );

		$this->assertCount( 5, $rows, 'Failed to assert that five rows exist for the series.' );

		foreach ( $this->expected_weekly_set() as $index => $expected ) {
			$this->assertSame( $expected['recurrence_id'], $rows[ $index ]['recurrence_id'] );
			$this->assertSame( $expected['datetime_start'], $rows[ $index ]['datetime_start'] );
			$this->assertSame( $expected['datetime_start_gmt'], $rows[ $index ]['datetime_start_gmt'] );
			$this->assertSame( $expected['datetime_end'], $rows[ $index ]['datetime_end'] );
			$this->assertSame( $expected['datetime_end_gmt'], $rows[ $index ]['datetime_end_gmt'] );
			$this->assertSame( Occurrences::STATUS_SCHEDULED, $rows[ $index ]['status'] );
		}
	}

	/**
	 * Coverage for REQ-4: projecting twice with no rule change is idempotent.
	 *
	 * @covers ::project
	 *
	 * @return void
	 */
	public function test_project_twice_is_idempotent(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		$second = $instance->project( $post_id );

		$this->assertSame( 5, $second, 'Failed to assert that the second projection also wrote five rows.' );

		$rows = $instance->select_for_series( array( $post_id ) );

		$this->assertCount( 5, $rows, 'Failed to assert that no duplicate rows exist after a second projection.' );
	}

	/**
	 * Coverage for a rule edit removing stale occurrence rows and adding new ones.
	 *
	 * @covers ::project
	 *
	 * @return void
	 */
	public function test_project_after_rule_edit_removes_stale_and_adds_new(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		update_post_meta(
			$post_id,
			Meta::META_KEY,
			wp_json_encode(
				array(
					'frequency' => 'daily',
					'interval'  => 1,
					'end_type'  => 'count',
					'count'     => 3,
				)
			)
		);
		Meta::get_instance()->set_recurrence( $post_id );
		$instance->project( $post_id );

		$rows           = $instance->select_for_series( array( $post_id ) );
		$recurrence_ids = wp_list_pluck( $rows, 'recurrence_id' );

		$this->assertCount( 3, $rows, 'Failed to assert that only the new daily rule\'s three rows remain.' );
		$this->assertNotContains(
			'20260915T180000',
			$recurrence_ids,
			'Failed to assert that a stale weekly occurrence was removed.'
		);
		$this->assertContains(
			'20260904T180000',
			$recurrence_ids,
			'Failed to assert that a new daily occurrence was added.'
		);
	}

	/**
	 * Coverage for C-5: cancellation survives a rule regeneration untouched.
	 *
	 * @covers ::project
	 * @covers ::insert_or_update_rows
	 *
	 * @return void
	 */
	public function test_project_preserves_cancelled_status_across_regeneration(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		$this->assertTrue(
			$instance->set_status( $post_id, '20260903T180000', Occurrences::STATUS_CANCELLED ),
			'Failed to assert that set_status cancelled the first occurrence.'
		);

		$instance->project( $post_id );

		$row = $instance->get( $post_id, '20260903T180000' );

		$this->assertSame(
			Occurrences::STATUS_CANCELLED,
			$row['status'],
			'Failed to assert that the cancelled status survived regeneration.'
		);
	}

	/**
	 * Coverage for a rule whose expansion produces zero occurrences deleting
	 * every existing row for the series.
	 *
	 * @covers ::project
	 * @covers ::delete_stale_rows
	 *
	 * @return void
	 */
	public function test_project_rule_yielding_zero_occurrences_deletes_all_existing_rows(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		update_post_meta(
			$post_id,
			Meta::META_KEY,
			wp_json_encode(
				array(
					'frequency' => 'daily',
					'interval'  => 1,
					'end_type'  => 'until',
					'until'     => '2026-09-01',
				)
			)
		);
		Meta::get_instance()->set_recurrence( $post_id );

		$written = $instance->project( $post_id );

		$this->assertSame( 0, $written, 'Failed to assert that a rule expanding to nothing writes zero rows.' );
		$this->assertCount(
			0,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that the previously projected rows were all deleted.'
		);
	}

	/**
	 * Coverage for REQ-16: a non-recurring event returns 0 without writing anything.
	 *
	 * @covers ::project
	 *
	 * @return void
	 */
	public function test_project_returns_zero_for_non_recurring_event(): void {
		$post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );

		$this->assertSame(
			0,
			Occurrences::get_instance()->project( $post_id ),
			'Failed to assert that project() returns 0 for a post with no recurrence rule.'
		);
	}

	/**
	 * Coverage for `resolve_anchor()` returning null when the stored datetime
	 * cannot be parsed -- a rule's mirrors can exist on a post whose own
	 * datetime meta never landed.
	 *
	 * @covers ::project
	 * @covers ::resolve_anchor
	 *
	 * @return void
	 */
	public function test_project_returns_zero_when_anchor_datetime_cannot_be_parsed(): void {
		$post_id = $this->create_recurring_event( self::WEEKLY_RULE );
		Meta::get_instance()->set_recurrence( $post_id );

		delete_post_meta( $post_id, 'gatherpress_datetime_start' );
		delete_post_meta( $post_id, 'gatherpress_datetime_end' );

		$this->assertSame(
			0,
			Occurrences::get_instance()->project( $post_id ),
			'Failed to assert that project() returns 0 when the anchor datetime cannot be parsed.'
		);
	}

	/**
	 * Coverage for `expand_or_clear()` clearing existing rows, without fataling,
	 * when the series timezone is a fixed UTC offset rather than a named
	 * tz-database identifier. `Expander::expand()` asserts this on its first
	 * line and does not catch what it throws -- GatherPress normalizes
	 * site/event timezones through `Utility::maybe_convert_utc_offset()`, so a
	 * fixed offset (`+05:30`) reaching here is a live, reachable
	 * configuration, not a hypothetical one.
	 *
	 * @covers ::project
	 * @covers ::expand_or_clear
	 *
	 * @return void
	 */
	public function test_project_clears_existing_rows_and_returns_zero_for_fixed_offset_timezone(): void {
		$post_id = $this->create_and_project();

		update_post_meta(
			$post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => $this->reference_anchor_start,
					'dateTimeEnd'   => $this->reference_anchor_end,
					'timezone'      => '+05:30',
				)
			)
		);
		Event_Setup::get_instance()->set_datetimes( $post_id );

		$instance = Occurrences::get_instance();
		$written  = $instance->project( $post_id );

		$this->assertSame(
			0,
			$written,
			'Failed to assert that project() returns 0 for a fixed-offset timezone rather than fataling.'
		);
		$this->assertCount(
			0,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that the previously projected rows were cleared.'
		);
	}

	/**
	 * Direct coverage for `build_occurrence_row()`'s duration arithmetic.
	 *
	 * Extracted-helper coverage: called from inside project()'s array_map,
	 * so a direct invoke guards against the known xdebug tracing gap for
	 * same-class helpers called from a tight loop.
	 *
	 * @covers ::build_occurrence_row
	 *
	 * @return void
	 */
	public function test_build_occurrence_row_applies_duration_from_anchor(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$start    = new DateTimeImmutable( '2026-09-15 18:00:00', $timezone );

		$row = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'build_occurrence_row',
			array( $start, 7200, $timezone )
		);

		$this->assertSame(
			array(
				'recurrence_id'      => '20260915T180000',
				'datetime_start'     => '2026-09-15 18:00:00',
				'datetime_start_gmt' => '2026-09-15 22:00:00',
				'datetime_end'       => '2026-09-15 20:00:00',
				'datetime_end_gmt'   => '2026-09-16 00:00:00',
				'timezone'           => 'America/New_York',
			),
			$row,
			'Failed to assert that build_occurrence_row applies the anchor duration correctly.'
		);
	}

	/**
	 * Coverage for `get()` returning null for a composite key that matches nothing.
	 *
	 * @covers ::get
	 *
	 * @return void
	 */
	public function test_get_returns_null_for_missing_composite_key(): void {
		$post_id = $this->factory->post->create();

		$this->assertNull(
			Occurrences::get_instance()->get( $post_id, '20260903T180000' ),
			'Failed to assert that get() returns null for a composite key that matches nothing.'
		);
	}

	/**
	 * Coverage for `get()` returning a row for an existing composite key.
	 *
	 * @covers ::get
	 *
	 * @return void
	 */
	public function test_get_returns_row_for_existing_composite_key(): void {
		$post_id = $this->create_and_project();

		$row = Occurrences::get_instance()->get( $post_id, '20260903T180000' );

		$this->assertSame( '2026-09-03 18:00:00', $row['datetime_start'] );
		$this->assertSame( Occurrences::STATUS_SCHEDULED, $row['status'] );
	}

	/**
	 * Coverage for `select_for_series()` returning an empty array for an empty post ID list.
	 *
	 * @covers ::select_for_series
	 *
	 * @return void
	 */
	public function test_select_for_series_returns_empty_array_for_empty_post_ids(): void {
		$this->assertSame(
			array(),
			Occurrences::get_instance()->select_for_series( array() ),
			'Failed to assert that select_for_series returns an empty array for an empty post ID list.'
		);
	}

	/**
	 * Coverage for C-2: `select_for_series()` accepts multiple post IDs and
	 * emits `series_post_id IN (…)`, never `= %d`.
	 *
	 * @covers ::select_for_series
	 *
	 * @return void
	 */
	public function test_select_for_series_accepts_multiple_post_ids(): void {
		global $wpdb;

		$post_id_a = $this->create_and_project();
		$post_id_b = $this->create_and_project();
		$instance  = Occurrences::get_instance();

		$rows = $instance->select_for_series( array( $post_id_a, $post_id_b ) );

		$this->assertCount( 10, $rows, 'Failed to assert that rows from both series were returned.' );
		$this->assertStringContainsString(
			'series_post_id IN (',
			$wpdb->last_query,
			'Failed to assert that the query emits series_post_id IN (…), not = %d.'
		);
		$this->assertStringNotContainsString(
			'series_post_id = ',
			$wpdb->last_query,
			'Failed to assert that the query never emits series_post_id = %d.'
		);
	}

	/**
	 * Coverage for `select_for_series()`'s `status` argument.
	 *
	 * @covers ::select_for_series
	 *
	 * @return void
	 */
	public function test_select_for_series_filters_by_status(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		$instance->set_status( $post_id, '20260903T180000', Occurrences::STATUS_CANCELLED );

		$cancelled = $instance->select_for_series(
			array( $post_id ),
			array( 'status' => Occurrences::STATUS_CANCELLED )
		);

		$this->assertCount( 1, $cancelled, 'Failed to assert that the status filter narrowed the result to one row.' );
		$this->assertSame( '20260903T180000', $cancelled[0]['recurrence_id'] );
	}

	/**
	 * Coverage for `set_status()` updating a matching row and returning true.
	 *
	 * @covers ::set_status
	 *
	 * @return void
	 */
	public function test_set_status_updates_matching_row_and_returns_true(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		$this->assertTrue( $instance->set_status( $post_id, '20260903T180000', Occurrences::STATUS_CANCELLED ) );
		$this->assertSame(
			Occurrences::STATUS_CANCELLED,
			$instance->get( $post_id, '20260903T180000' )['status']
		);
	}

	/**
	 * Coverage for `set_status()` returning false when the composite key matches nothing.
	 *
	 * @covers ::set_status
	 *
	 * @return void
	 */
	public function test_set_status_returns_false_when_no_row_matches(): void {
		$post_id = $this->factory->post->create();

		$this->assertFalse(
			Occurrences::get_instance()->set_status( $post_id, '20260903T180000', Occurrences::STATUS_CANCELLED ),
			'Failed to assert that set_status returns false when no row matches.'
		);
	}

	/**
	 * Coverage for `set_status()` scoping by both `series_post_id` and
	 * `recurrence_id` -- a recurrence_id that belongs to a different series
	 * must not be mutated through this post's ID, and vice versa.
	 *
	 * @covers ::set_status
	 *
	 * @return void
	 */
	public function test_set_status_scopes_by_both_columns(): void {
		$post_id_a = $this->create_and_project();
		$post_id_b = $this->create_and_project();
		$instance  = Occurrences::get_instance();

		$this->assertTrue(
			$instance->set_status( $post_id_a, '20260903T180000', Occurrences::STATUS_CANCELLED ),
			'Failed to assert that setting status on the first series\' own row succeeded.'
		);

		$this->assertSame(
			Occurrences::STATUS_SCHEDULED,
			$instance->get( $post_id_b, '20260903T180000' )['status'],
			'Failed to assert that a second series\' row with the same recurrence_id was left untouched.'
		);
	}

	/**
	 * Coverage for `delete_for_series()` removing all rows for a series.
	 *
	 * @covers ::delete_for_series
	 *
	 * @return void
	 */
	public function test_delete_for_series_removes_all_rows(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		$deleted = $instance->delete_for_series( $post_id );

		$this->assertSame( 5, $deleted, 'Failed to assert that delete_for_series reports five deleted rows.' );
		$this->assertCount(
			0,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that no rows remain for the series.'
		);
	}

	/**
	 * Coverage for `maybe_project()` skipping an unsupported post type.
	 *
	 * @covers ::maybe_project
	 *
	 * @return void
	 */
	public function test_maybe_project_skips_unsupported_post_type(): void {
		$post_id  = $this->factory->post->create( array( 'post_type' => 'post' ) );
		$instance = Occurrences::get_instance();

		$instance->maybe_project( $post_id );

		$this->assertFalse(
			has_action( 'shutdown', array( $instance, 'resolve_pending_projection' ) ),
			'Failed to assert that no shutdown resolution was scheduled for an unsupported post type.'
		);
	}

	/**
	 * Coverage for `maybe_project()` projecting immediately when the blob is present.
	 *
	 * @covers ::maybe_project
	 *
	 * @return void
	 */
	public function test_maybe_project_projects_immediately_when_blob_present(): void {
		$post_id = $this->create_recurring_event( self::WEEKLY_RULE );
		Meta::get_instance()->set_recurrence( $post_id );

		Occurrences::get_instance()->maybe_project( $post_id );

		$this->assertCount(
			5,
			Occurrences::get_instance()->select_for_series( array( $post_id ) ),
			'Failed to assert that maybe_project() projected immediately when the blob was present.'
		);
	}

	/**
	 * Coverage for `maybe_project()` deferring to shutdown when no blob has landed yet.
	 *
	 * @covers ::maybe_project
	 *
	 * @return void
	 */
	public function test_maybe_project_defers_when_no_blob_yet(): void {
		$post_id  = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );
		$instance = Occurrences::get_instance();

		$instance->maybe_project( $post_id );

		$this->assertCount(
			0,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that nothing was projected before the blob landed.'
		);
		$this->assertNotFalse(
			has_action( 'shutdown', array( $instance, 'resolve_pending_projection' ) ),
			'Failed to assert that a shutdown resolution was scheduled.'
		);

		// Drain the pending state so it does not leak into another test.
		$instance->resolve_pending_projection();
	}

	/**
	 * Coverage for `resolve_pending_projection()` projecting a post whose blob
	 * arrived after `maybe_project()` ran but before shutdown.
	 *
	 * @covers ::maybe_project
	 * @covers ::resolve_pending_projection
	 *
	 * @return void
	 */
	public function test_resolve_pending_projection_projects_pending_posts(): void {
		$post_id  = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );
		$instance = Occurrences::get_instance();

		// wp_after_insert_post fires before the blob-writing caller runs.
		$instance->maybe_project( $post_id );

		add_post_meta(
			$post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => $this->reference_anchor_start,
					'dateTimeEnd'   => $this->reference_anchor_end,
					'timezone'      => 'America/New_York',
				)
			)
		);
		Event_Setup::get_instance()->set_datetimes( $post_id );
		add_post_meta( $post_id, Meta::META_KEY, wp_json_encode( self::WEEKLY_RULE ) );
		Meta::get_instance()->set_recurrence( $post_id );

		$instance->resolve_pending_projection();

		$this->assertCount(
			5,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that resolve_pending_projection projected the late-arriving rule.'
		);
	}

	/**
	 * Coverage for `resolve_pending_projection()` skipping a post whose type no
	 * longer supports `gatherpress-event-date` by the time shutdown runs.
	 *
	 * @covers ::resolve_pending_projection
	 *
	 * @return void
	 */
	public function test_resolve_pending_projection_skips_unsupported_post_type(): void {
		$post_id  = $this->factory->post->create( array( 'post_type' => 'post' ) );
		$instance = Occurrences::get_instance();

		Utility::set_and_get_hidden_property( $instance, 'pending_projection', array( $post_id => true ) );

		$instance->resolve_pending_projection();

		$this->assertCount(
			0,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that an unsupported post type was skipped rather than projected.'
		);
	}

	/**
	 * Coverage for `maybe_delete_for_series()` skipping an unsupported post type.
	 *
	 * @covers ::maybe_delete_for_series
	 *
	 * @return void
	 */
	public function test_maybe_delete_for_series_skips_unsupported_post_type(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		// Simulate the post type no longer supporting gatherpress-event-date
		// by the time delete_post fires -- the guard, not an empty table, is
		// what is under test.
		set_post_type( $post_id, 'post' );

		$instance->maybe_delete_for_series( $post_id );

		$this->assertCount(
			5,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that an unsupported post type left the series untouched.'
		);
	}

	/**
	 * Coverage for `maybe_delete_for_series()` deleting rows for a supported post type.
	 *
	 * @covers ::maybe_delete_for_series
	 *
	 * @return void
	 */
	public function test_maybe_delete_for_series_deletes_rows_for_supported_post_type(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		$instance->maybe_delete_for_series( $post_id );

		$this->assertCount(
			0,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that a supported post type\'s series rows were deleted.'
		);
	}

	/**
	 * Coverage for `select_upcoming()` interleaving recurring and non-recurring
	 * events in one ascending list, each entry carrying its own identity.
	 *
	 * @covers ::select_upcoming
	 * @covers ::select_by_horizon
	 * @covers ::row_to_ref
	 *
	 * @return void
	 */
	public function test_select_upcoming_returns_occurrence_refs_ordered_ascending(): void {
		$recurring_post_id = $this->create_and_project();

		$plain_post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );
		add_post_meta(
			$plain_post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => '2026-09-10 12:00:00',
					'dateTimeEnd'   => '2026-09-10 13:00:00',
					'timezone'      => 'America/New_York',
				)
			)
		);
		Event_Setup::get_instance()->set_datetimes( $plain_post_id );

		$refs = Occurrences::get_instance()->select_upcoming( 20 );

		$this->assertNotEmpty( $refs, 'Failed to assert that upcoming occurrences were returned.' );

		foreach ( $refs as $ref ) {
			$this->assertInstanceOf( Occurrence_Ref::class, $ref );
		}

		$post_ids = wp_list_pluck( $refs, 'post_id' );

		$this->assertContains( $recurring_post_id, $post_ids );
		$this->assertContains( $plain_post_id, $post_ids );

		$plain_ref = current(
			array_filter( $refs, fn( Occurrence_Ref $ref ) => $plain_post_id === $ref->post_id )
		);

		$this->assertNull(
			$plain_ref->recurrence_id,
			'Failed to assert that a non-recurring event carries a null recurrence_id.'
		);

		$starts = wp_list_pluck( $refs, 'datetime_start_gmt' );
		$sorted = $starts;
		sort( $sorted );

		$this->assertSame( $sorted, $starts, 'Failed to assert that upcoming occurrences are ordered ascending.' );
	}

	/**
	 * Coverage for `select_upcoming()` returning an empty array when no post
	 * type declares `gatherpress-event-date` support.
	 *
	 * @covers ::select_by_horizon
	 *
	 * @return void
	 */
	public function test_select_upcoming_returns_empty_array_when_no_supported_post_types(): void {
		$supported = get_post_types_by_support( 'gatherpress-event-date' );

		foreach ( $supported as $post_type ) {
			remove_post_type_support( $post_type, 'gatherpress-event-date' );
		}

		try {
			$this->assertSame(
				array(),
				Occurrences::get_instance()->select_upcoming( 10 ),
				'Failed to assert that select_upcoming returns an empty array with no supported post types.'
			);
		} finally {
			foreach ( $supported as $post_type ) {
				add_post_type_support( $post_type, 'gatherpress-event-date' );
			}
		}
	}

	/**
	 * Coverage for `select_past()` ordering descending and respecting the `status` argument.
	 *
	 * @covers ::select_past
	 * @covers ::select_by_horizon
	 *
	 * @return void
	 */
	public function test_select_past_returns_occurrence_refs_ordered_descending(): void {
		$post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );
		add_post_meta(
			$post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => '2020-01-01 12:00:00',
					'dateTimeEnd'   => '2020-01-01 13:00:00',
					'timezone'      => 'America/New_York',
				)
			)
		);
		Event_Setup::get_instance()->set_datetimes( $post_id );

		$refs = Occurrences::get_instance()->select_past( 10, array( 'status' => Occurrences::STATUS_SCHEDULED ) );

		$this->assertNotEmpty( $refs, 'Failed to assert that past occurrences were returned.' );

		$starts = wp_list_pluck( $refs, 'datetime_start_gmt' );
		$sorted = $starts;
		rsort( $sorted );

		$this->assertSame( $sorted, $starts, 'Failed to assert that past occurrences are ordered descending.' );
	}
}
