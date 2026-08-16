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
use GatherPress\Core\Event\Recurrence\Rule;
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
	 * Create and project a recurring event anchored relative to "now", rather
	 * than to `Occurrence_Fixtures`' fixed 2026-09-03 anchor.
	 *
	 * `select_upcoming()`/`select_past()` compare against `current_time()`, so
	 * a test asserting "upcoming" or "past" placement against a fixed
	 * calendar date is a date bomb -- it silently starts failing once real
	 * time passes the fixture's anchor. This builds the event directly rather
	 * than through `create_recurring_event()`, so the anchor is always
	 * relative to whenever the suite actually runs.
	 *
	 * @since 0.36.0
	 *
	 * @param array             $rule     Recurrence rule values.
	 * @param DateTimeImmutable $start    Anchor start, in `$timezone`.
	 * @param DateTimeImmutable $end      Anchor end, in `$timezone`.
	 * @param string            $timezone Named tz-database identifier for the series.
	 *
	 * @return int The projected post ID.
	 */
	protected function create_relative_recurring_event(
		array $rule,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		string $timezone = 'America/New_York'
	): int {
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
		add_post_meta( $post_id, Meta::META_KEY, wp_json_encode( $rule ) );
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
				'callback' => array( $instance, 'maybe_delete_for_post' ),
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
	 * Coverage for BLOCKING 2: a long-running `never`-ending series must
	 * project occurrences that are actually upcoming, not stop at a horizon
	 * measured from a years-old anchor. Anchored 2019-01-03 (matching the
	 * reviewer's measured probe), an anchor-relative 12-month horizon would
	 * project entirely into the past with zero upcoming entries; the horizon
	 * must instead roll forward from `now`.
	 *
	 * @covers ::project
	 * @covers ::expand_or_clear
	 * @covers ::resolve_horizon
	 *
	 * @return void
	 */
	public function test_project_rolls_horizon_forward_for_a_long_running_never_ending_series(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$anchor   = new DateTimeImmutable( '2019-01-03 18:00:00', $timezone );

		$post_id = $this->create_relative_recurring_event(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( (int) $anchor->format( 'w' ) ),
				'end_type'  => 'never',
			),
			$anchor,
			$anchor->modify( '+2 hours' ),
			'America/New_York'
		);

		$rows = Occurrences::get_instance()->select_for_series( array( $post_id ) );

		$this->assertNotEmpty( $rows, 'Failed to assert that the long-running series wrote any rows at all.' );

		$upcoming     = Occurrences::get_instance()->select_upcoming( 50 );
		$upcoming_ids = wp_list_pluck( $upcoming, 'post_id' );

		$this->assertContains(
			$post_id,
			$upcoming_ids,
			'Failed to assert that a 2019-anchored never-ending series has upcoming occurrences.'
		);
	}

	/**
	 * Direct coverage for `resolve_projectable()`'s anchor-null branch:
	 * extracted-helper coverage guards against the known xdebug tracing gap
	 * for same-class helpers called via short delegation (`project()` ->
	 * `resolve_projectable()` -> `delete_for_post()`).
	 *
	 * @covers ::resolve_projectable
	 *
	 * @return void
	 */
	public function test_resolve_projectable_clears_rows_when_anchor_cannot_be_resolved(): void {
		$post_id = $this->create_and_project();

		delete_post_meta( $post_id, 'gatherpress_datetime_start' );
		delete_post_meta( $post_id, 'gatherpress_datetime_end' );

		$result = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'resolve_projectable',
			array( $post_id )
		);

		$this->assertNull(
			$result,
			'Failed to assert that resolve_projectable returns null for an unresolvable anchor.'
		);
		$this->assertCount(
			0,
			Occurrences::get_instance()->select_for_series( array( $post_id ) ),
			'Failed to assert that resolve_projectable cleared the existing rows.'
		);
	}

	/**
	 * Direct coverage for `resolve_horizon()`'s ternary: a future anchor is
	 * used as the horizon base directly.
	 *
	 * @covers ::resolve_horizon
	 *
	 * @return void
	 */
	public function test_resolve_horizon_uses_anchor_when_anchor_is_in_the_future(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$anchor   = ( new DateTimeImmutable( 'now', $timezone ) )->modify( '+5 years' );

		$horizon = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'resolve_horizon',
			array( $anchor, $timezone )
		);

		$this->assertSame(
			$anchor->modify( '+' . Occurrences::PROJECTION_HORIZON_MONTHS . ' months' )->format( 'Y-m-d H:i:s' ),
			$horizon->format( 'Y-m-d H:i:s' ),
			'Failed to assert that a future anchor is used directly as the horizon base.'
		);
	}

	/**
	 * Direct coverage for `resolve_horizon()`'s ternary: a past anchor rolls
	 * the horizon forward from "now" instead (BLOCKING 2).
	 *
	 * @covers ::resolve_horizon
	 *
	 * @return void
	 */
	public function test_resolve_horizon_uses_now_when_anchor_is_in_the_past(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$anchor   = new DateTimeImmutable( '2019-01-03 18:00:00', $timezone );
		$now      = new DateTimeImmutable( 'now', $timezone );

		$horizon = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'resolve_horizon',
			array( $anchor, $timezone )
		);

		$this->assertGreaterThan(
			$now,
			$horizon,
			'Failed to assert that a past anchor still produces a horizon in the future.'
		);
	}

	/**
	 * Coverage for the `gatherpress_recurrence_horizon_months` filter.
	 *
	 * @covers ::resolve_horizon
	 *
	 * @return void
	 */
	public function test_resolve_horizon_is_filterable(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$anchor   = ( new DateTimeImmutable( 'now', $timezone ) )->modify( '+1 year' );

		$filter = static fn() => 1;
		add_filter( 'gatherpress_recurrence_horizon_months', $filter );

		$horizon = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'resolve_horizon',
			array( $anchor, $timezone )
		);

		remove_filter( 'gatherpress_recurrence_horizon_months', $filter );

		$this->assertSame(
			$anchor->modify( '+1 months' )->format( 'Y-m-d H:i:s' ),
			$horizon->format( 'Y-m-d H:i:s' ),
			'Failed to assert that the gatherpress_recurrence_horizon_months filter overrides the default.'
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
	 * Coverage for BLOCKING 1: a series whose rule is removed must have its
	 * existing occurrence rows cleared, not left orphaned. `Rule::from_post()`
	 * returning null looks identical whether a post never had a rule or just
	 * lost one, so `project()` cannot tell "nothing to do" from "clean up"
	 * without deleting unconditionally in the no-rule branch.
	 *
	 * @covers ::project
	 * @covers ::resolve_projectable
	 *
	 * @return void
	 */
	public function test_project_deletes_existing_rows_when_rule_is_removed(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		$this->assertCount( 5, $instance->select_for_series( array( $post_id ) ) );

		delete_post_meta( $post_id, Meta::META_KEY );
		Meta::get_instance()->set_recurrence( $post_id );
		// The blob is now empty, so Meta defers the decision to shutdown
		// rather than clearing synchronously -- simulate shutdown firing.
		Meta::get_instance()->resolve_pending_recurrence();

		$this->assertNull( Rule::from_post( $post_id ), 'Failed to assert the mirrors were cleared by Meta.' );

		$written = $instance->project( $post_id );

		$this->assertSame( 0, $written, 'Failed to assert that project() returns 0 once the rule is gone.' );
		$this->assertCount(
			0,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that removing the rule orphaned no rows -- they were deleted.'
		);
	}

	/**
	 * Coverage for BLOCKING 1, replayed through the real lifecycle wiring
	 * rather than calling project() directly: `maybe_project()` at
	 * `wp_after_insert_post` priority 20, `Meta::set_recurrence()` at
	 * priority 10 on the same hook, and `resolve_pending_projection()` on
	 * `shutdown`. Removing the recurrence blob and replaying that exact
	 * sequence must still clear the series' rows.
	 *
	 * @covers ::maybe_project
	 * @covers ::resolve_pending_projection
	 * @covers ::project
	 *
	 * @return void
	 */
	public function test_full_lifecycle_replay_deletes_rows_when_recurrence_blob_is_removed(): void {
		$post_id     = $this->create_and_project();
		$occurrences = Occurrences::get_instance();
		$meta        = Meta::get_instance();

		$this->assertCount( 5, $occurrences->select_for_series( array( $post_id ) ) );

		delete_post_meta( $post_id, Meta::META_KEY );

		// wp_after_insert_post priority 10, then priority 20, in that order --
		// matches production hook ordering exactly.
		$meta->set_recurrence( $post_id );
		$occurrences->maybe_project( $post_id );

		// Neither class had a blob to react to synchronously, so both defer.
		// Simulates shutdown firing, Meta's priority-10 resolution before
		// Occurrences' priority-20 one.
		$meta->resolve_pending_recurrence();
		$occurrences->resolve_pending_projection();

		$this->assertNull( Rule::from_post( $post_id ) );
		$this->assertCount(
			0,
			$occurrences->select_for_series( array( $post_id ) ),
			'Failed to assert that the full lifecycle replay cleared the series\' rows.'
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
	 * Coverage for the real production path when a series' timezone becomes a
	 * fixed UTC offset: `Meta::set_recurrence()` re-runs with the new,
	 * genuinely-invalid raw timezone, its own `Timezone_Guard::assert_named()`
	 * check rejects it, and `clear_mirrors()` removes the rule mirrors --
	 * `Rule::from_post()` then returns null and `project()` clears the rows
	 * through the no-rule branch (BLOCKING 1's fix), never reaching
	 * `expand_or_clear()`'s own catch at all. An earlier version of this test
	 * changed only the datetime blob without re-running `Meta::set_recurrence()`,
	 * which left the mirrors (and therefore `Rule::from_post()`) stale and
	 * made `expand_or_clear()`'s catch arm look reachable for a case it is not
	 * reachable for in production.
	 *
	 * @covers ::project
	 * @covers ::resolve_projectable
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
		Meta::get_instance()->set_recurrence( $post_id );

		$this->assertNull(
			Rule::from_post( $post_id ),
			'Failed to assert that Meta cleared the mirrors for the now-invalid raw timezone.'
		);

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
	 * Coverage for `expand_or_clear()`'s own catch, kept genuinely reachable
	 * by a scenario `Meta` cannot intercept: `Meta::read_timezone()` reads the
	 * raw `gatherpress_datetime` blob directly, so it passes a validly-named
	 * timezone. `Event::get_datetime()`, which `resolve_anchor()` reads
	 * through, applies the `gatherpress_timezone` filter *after* that
	 * validation -- a misbehaving filter can still hand `expand_or_clear()` a
	 * fixed offset even though the mirrors (and `Rule::from_post()`) remain
	 * valid. This is the live scenario `resolve_anchor()`'s own unguarded
	 * `new DateTimeZone()` construction and `expand_or_clear()`'s try/catch
	 * both exist to survive without fataling the save.
	 *
	 * @covers ::project
	 * @covers ::expand_or_clear
	 *
	 * @return void
	 */
	public function test_project_clears_existing_rows_when_timezone_filter_injects_a_fixed_offset(): void {
		$post_id = $this->create_and_project();

		$this->assertInstanceOf(
			Rule::class,
			Rule::from_post( $post_id ),
			'Failed to assert that the rule mirrors are still valid before the filter runs.'
		);

		$filter = static fn() => '+05:30';
		add_filter( 'gatherpress_timezone', $filter );

		$instance = Occurrences::get_instance();
		$written  = $instance->project( $post_id );

		remove_filter( 'gatherpress_timezone', $filter );

		$this->assertSame(
			0,
			$written,
			'Failed to assert that project() returns 0 when the timezone filter injects a fixed offset.'
		);
		$this->assertCount(
			0,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that expand_or_clear() cleared the previously projected rows.'
		);
	}

	/**
	 * Coverage for `resolve_anchor()`'s own guard around `new DateTimeZone()`:
	 * a `gatherpress_timezone` filter can hand back a string that is not a
	 * fixed offset either, but something `DateTimeZone` rejects outright
	 * (`Not/AZone`). Without a try/catch there, this fatals the post save
	 * one line before `expand_or_clear()`'s own try/catch would ever run.
	 *
	 * @covers ::project
	 * @covers ::resolve_anchor
	 *
	 * @return void
	 */
	public function test_project_returns_zero_when_timezone_filter_injects_an_invalid_string(): void {
		$post_id = $this->create_and_project();

		$filter = static fn() => 'Not/AZone';
		add_filter( 'gatherpress_timezone', $filter );

		$instance = Occurrences::get_instance();
		$written  = $instance->project( $post_id );

		remove_filter( 'gatherpress_timezone', $filter );

		$this->assertSame(
			0,
			$written,
			'Failed to assert that project() returns 0 rather than fataling on an unconstructable timezone string.'
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
		$span     = $start->diff( $start->modify( '+2 hours' ) );

		$row = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'build_occurrence_row',
			array( $start, $span, $timezone )
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
	 * Coverage for BLOCKING 4: a nominal 2-hour span applied to an occurrence
	 * landing on the fall-back DST transition (2026-11-01, America/New_York)
	 * must stay a nominal 2-hour span on the wall clock (01:00 -> 03:00),
	 * even though 10,800 real seconds elapse across the repeated hour. An
	 * absolute-seconds delta taken once from a non-transition anchor and
	 * reapplied via raw `modify( '+N seconds' )` is fragile precisely because
	 * it happens to agree with the calendar-decomposed `DateInterval` used
	 * here for whole-hour spans -- this test pins the values a reviewer
	 * measured directly, not a value re-derived from reasoning about it.
	 *
	 * @covers ::build_occurrence_row
	 *
	 * @return void
	 */
	public function test_build_occurrence_row_preserves_nominal_span_across_fall_back(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$anchor   = new DateTimeImmutable( '2026-09-03 18:00:00', $timezone );
		$span     = $anchor->diff( $anchor->modify( '+2 hours' ) );

		$start = new DateTimeImmutable( '2026-11-01 01:00:00', $timezone );

		$row = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'build_occurrence_row',
			array( $start, $span, $timezone )
		);

		$this->assertSame(
			'2026-11-01 03:00:00',
			$row['datetime_end'],
			'Failed to assert that the nominal wall-clock end stayed 03:00:00, not the absolute-time 02:00:00.'
		);
		$this->assertSame(
			'2026-11-01 08:00:00',
			$row['datetime_end_gmt'],
			'Failed to assert that the GMT end matches the nominal wall-clock end.'
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
	 * Coverage for `delete_for_post()` removing all rows for a series.
	 *
	 * @covers ::delete_for_post
	 *
	 * @return void
	 */
	public function test_delete_for_post_removes_all_rows(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		$deleted = $instance->delete_for_post( $post_id );

		$this->assertSame( 5, $deleted, 'Failed to assert that delete_for_post reports five deleted rows.' );
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
	 * Coverage for `maybe_delete_for_post()` skipping an unsupported post type.
	 *
	 * @covers ::maybe_delete_for_post
	 *
	 * @return void
	 */
	public function test_maybe_delete_for_post_skips_unsupported_post_type(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		// Simulate the post type no longer supporting gatherpress-event-date
		// by the time delete_post fires -- the guard, not an empty table, is
		// what is under test.
		set_post_type( $post_id, 'post' );

		$instance->maybe_delete_for_post( $post_id );

		$this->assertCount(
			5,
			$instance->select_for_series( array( $post_id ) ),
			'Failed to assert that an unsupported post type left the series untouched.'
		);
	}

	/**
	 * Coverage for `maybe_delete_for_post()` deleting rows for a supported post type.
	 *
	 * @covers ::maybe_delete_for_post
	 *
	 * @return void
	 */
	public function test_maybe_delete_for_post_deletes_rows_for_supported_post_type(): void {
		$post_id  = $this->create_and_project();
		$instance = Occurrences::get_instance();

		$instance->maybe_delete_for_post( $post_id );

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
		$timezone        = new DateTimeZone( 'America/New_York' );
		$now             = new DateTimeImmutable( 'now', $timezone );
		$recurring_start = $now->modify( '+10 days' )->setTime( 18, 0, 0 );

		$recurring_post_id = $this->create_relative_recurring_event(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( (int) $recurring_start->format( 'w' ) ),
				'end_type'  => 'count',
				'count'     => 3,
			),
			$recurring_start,
			$recurring_start->modify( '+2 hours' ),
			'America/New_York'
		);

		$plain_start   = $now->modify( '+15 days' )->setTime( 12, 0, 0 );
		$plain_post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );
		add_post_meta(
			$plain_post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => $plain_start->format( 'Y-m-d H:i:s' ),
					'dateTimeEnd'   => $plain_start->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
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
	 * Coverage for BLOCKING 3: a fully cancelled series must not reappear in
	 * `select_upcoming()` as if it were a non-recurring event at its original
	 * anchor date. Without the `NOT EXISTS` guard, every occurrence row
	 * failing the `status = 'scheduled'` join predicate falls through the
	 * same `NULL` branch a genuinely non-recurring event uses, and the
	 * `COALESCE` fallback resurrects the series at its anchor.
	 *
	 * @covers ::select_by_horizon
	 *
	 * @return void
	 */
	public function test_select_upcoming_omits_a_fully_cancelled_series(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$now      = new DateTimeImmutable( 'now', $timezone );
		$start    = $now->modify( '+10 days' )->setTime( 18, 0, 0 );

		$post_id = $this->create_relative_recurring_event(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( (int) $start->format( 'w' ) ),
				'end_type'  => 'count',
				'count'     => 3,
			),
			$start,
			$start->modify( '+2 hours' ),
			'America/New_York'
		);

		$instance = Occurrences::get_instance();

		foreach ( $instance->select_for_series( array( $post_id ) ) as $row ) {
			$instance->set_status( $post_id, $row['recurrence_id'], Occurrences::STATUS_CANCELLED );
		}

		$refs     = $instance->select_upcoming( 50 );
		$post_ids = wp_list_pluck( $refs, 'post_id' );

		$this->assertNotContains(
			$post_id,
			$post_ids,
			'Failed to assert that a fully cancelled series is absent from select_upcoming().'
		);
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
