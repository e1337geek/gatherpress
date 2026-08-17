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
use GatherPress\Core\Event\Recurrence\Projection_Cron;
use GatherPress\Core\Event\Recurrence\Query;
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
	 * Direct coverage for `resolve_projectable()`'s no-rule branch (returns
	 * `array( $post_id )` — one direct invoke per return path per AGENTS.md,
	 * rather than reaching it only transitively through `project()`.
	 *
	 * @covers ::resolve_projectable
	 *
	 * @return void
	 */
	public function test_resolve_projectable_clears_rows_when_no_rule_exists(): void {
		$post_id = $this->create_and_project();

		delete_post_meta( $post_id, Meta::META_KEY );
		Meta::get_instance()->set_recurrence( $post_id );
		Meta::get_instance()->resolve_pending_recurrence();

		$result = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'resolve_projectable',
			array( $post_id, true )
		);

		$this->assertNull( $result, 'Failed to assert that resolve_projectable returns null when no rule exists.' );
		$this->assertCount(
			0,
			Occurrences::get_instance()->select_for_series( array( $post_id ) ),
			'Failed to assert that resolve_projectable cleared the existing rows.'
		);
	}

	/**
	 * Direct coverage for `resolve_projectable()`'s no-rule branch with
	 * `$cleanup` false (CF-1): existing rows are left untouched.
	 *
	 * @covers ::resolve_projectable
	 *
	 * @return void
	 */
	public function test_resolve_projectable_skips_cleanup_when_cleanup_is_false(): void {
		$post_id = $this->create_and_project();

		delete_post_meta( $post_id, Meta::META_KEY );
		Meta::get_instance()->set_recurrence( $post_id );
		Meta::get_instance()->resolve_pending_recurrence();

		$result = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'resolve_projectable',
			array( $post_id, false )
		);

		$this->assertNull( $result, 'Failed to assert that resolve_projectable returns null when no rule exists.' );
		$this->assertCount(
			5,
			Occurrences::get_instance()->select_for_series( array( $post_id ) ),
			'Failed to assert that resolve_projectable left the existing rows untouched when cleanup is false.'
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
			array( $post_id, true )
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
	 * Direct coverage for `resolve_projectable()`'s success path: a post with
	 * a valid rule and a resolvable anchor returns the rule, anchor start,
	 * anchor end, and timezone together.
	 *
	 * @covers ::resolve_projectable
	 *
	 * @return void
	 */
	public function test_resolve_projectable_returns_rule_and_anchor_for_a_recurring_post(): void {
		$post_id = $this->create_recurring_event( self::WEEKLY_RULE );
		Meta::get_instance()->set_recurrence( $post_id );

		$result = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'resolve_projectable',
			array( $post_id, true )
		);

		$this->assertIsArray( $result );
		$this->assertInstanceOf( Rule::class, $result[0] );
		$this->assertInstanceOf( DateTimeImmutable::class, $result[1] );
		$this->assertInstanceOf( DateTimeImmutable::class, $result[2] );
		$this->assertInstanceOf( DateTimeZone::class, $result[3] );
		$this->assertSame(
			$this->reference_anchor_start,
			$result[1]->format( 'Y-m-d H:i:s' ),
			'Failed to assert that the returned anchor start matches the stored datetime.'
		);
	}

	/**
	 * Direct coverage for `run_projection()`'s success path (a resolved rule
	 * writing rows): called only via short delegation from `project()` and
	 * from a loop in `resolve_pending_projection()`, which is the same
	 * xdebug same-class tracing gap AGENTS.md documents for extracted
	 * helpers.
	 *
	 * @covers ::run_projection
	 *
	 * @return void
	 */
	public function test_run_projection_writes_rows_for_a_resolved_rule(): void {
		$post_id = $this->create_recurring_event( self::WEEKLY_RULE );
		Meta::get_instance()->set_recurrence( $post_id );

		$written = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'run_projection',
			array( $post_id, true )
		);

		$this->assertSame( 5, $written, 'Failed to assert that run_projection wrote all five occurrences.' );
	}

	/**
	 * Direct coverage for `run_projection()`'s `null === $occurrences` branch
	 * (`expand_or_clear()` rejected the timezone): same xdebug same-class
	 * short-delegation gap as the success-path test above.
	 *
	 * @covers ::run_projection
	 *
	 * @return void
	 */
	public function test_run_projection_returns_zero_when_expand_rejects_the_timezone(): void {
		$post_id = $this->create_and_project();

		$filter = static fn() => '+05:30';
		add_filter( 'gatherpress_timezone', $filter );

		$written = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'run_projection',
			array( $post_id, true )
		);

		remove_filter( 'gatherpress_timezone', $filter );

		$this->assertSame(
			0,
			$written,
			'Failed to assert that run_projection returns 0 when expand_or_clear rejects the timezone.'
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
	 * Coverage for CF-1: saving an ordinary, never-recurring event through the
	 * real save-path hooks must issue zero queries against the occurrence
	 * table -- REQ-16's "a site with no recurring events pays nothing"
	 * guarantee. Checking only project()'s return value (the test above) is
	 * not enough to guard this: the BLOCKING-1 fix for orphaned rows made the
	 * deferred no-blob path (maybe_project() -> resolve_pending_projection())
	 * clean up unconditionally, which silently added a DELETE query to this
	 * exact, most-common save path. That regression passed a return-value-only
	 * assertion; it only shows up in the query log, which is why this test
	 * drives the real `wp_after_insert_post` / `shutdown` hooks rather than
	 * calling project() directly.
	 *
	 * @covers ::maybe_project
	 * @covers ::resolve_pending_projection
	 * @covers ::run_projection
	 * @covers ::resolve_projectable
	 *
	 * @return void
	 */
	public function test_ordinary_event_save_never_queries_the_occurrence_table(): void {
		global $wpdb;

		$post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );

		$query_count_before = count( $wpdb->queries );

		// Production order, driven by the real hooks rather than hand-called:
		// wp_after_insert_post (Meta::set_recurrence() at priority 10,
		// Occurrences::maybe_project() at priority 20), then shutdown, for a
		// post with no gatherpress_recurrence blob at all.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing WordPress core hook.
		do_action( 'wp_after_insert_post', $post_id, get_post( $post_id ), true, null );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing WordPress core hook.
		do_action( 'shutdown' );

		$occurrences_table   = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$queries_since       = array_slice( $wpdb->queries, $query_count_before );
		$touched_occurrences = array_values(
			array_filter(
				$queries_since,
				static function ( $query ) use ( $occurrences_table ) {
					return str_contains( $query[0], $occurrences_table );
				}
			)
		);

		$this->assertSame(
			array(),
			$touched_occurrences,
			'Failed to assert that saving a never-recurring event issued no query against the occurrence table.'
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
	 * Coverage for BLOCKING 1, replayed through the real lifecycle wiring --
	 * the actual `wp_after_insert_post` and `shutdown` hooks are fired, not a
	 * hand-called sequence of the methods those hooks invoke. CF-4: a
	 * hand-called sequence cannot fail even when the wiring it claims to test
	 * is broken -- inverting `maybe_project()`'s `wp_after_insert_post`
	 * priority and its dynamic `shutdown` priority so `Occurrences` runs
	 * *before* `Meta` left the previous, hand-called version of this test
	 * green. Firing the hooks for real is what makes the ordering the
	 * deferred design depends on part of what is under test.
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

		$this->assertCount( 5, $occurrences->select_for_series( array( $post_id ) ) );

		delete_post_meta( $post_id, Meta::META_KEY );

		// Fires the real hooks, in production order: wp_after_insert_post
		// (Meta::set_recurrence() at priority 10, Occurrences::maybe_project()
		// at priority 20), then shutdown (Meta's priority-10 resolution, then
		// Occurrences' priority-20 one) -- the hooks themselves decide the
		// order, nothing here hand-sequences it.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing WordPress core hook.
		do_action( 'wp_after_insert_post', $post_id, get_post( $post_id ), true, null );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing WordPress core hook.
		do_action( 'shutdown' );

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
	 * Coverage for CF-3, end to end through `project()`: an anchor whose own
	 * span crosses the fall-back transition (2026-10-31 22:00 -> 2026-11-01
	 * 02:00, a nominal 4 hours) must project every later occurrence -- even
	 * ones that do not themselves touch a transition -- with that same
	 * nominal 4-hour span. `$anchor_start->diff( $anchor_end )` on the two
	 * *zoned* anchor datetimes reports their real elapsed time (5 hours, since
	 * the anchor itself spans the repeated hour) rather than their wall-clock
	 * difference, and reapplying that inflated span to every occurrence is
	 * exactly the corruption this method exists to prevent -- the anchor's
	 * own stored row would then contradict the anchor it was derived from.
	 * `test_build_occurrence_row_applies_duration_from_anchor()` and
	 * `..._preserves_nominal_span_across_fall_back()` pass a hand-built
	 * `DateInterval` directly and so cannot catch this: this test is the only
	 * one that exercises `resolve_nominal_span()` itself.
	 *
	 * @covers ::project
	 * @covers ::resolve_nominal_span
	 *
	 * @return void
	 */
	public function test_project_preserves_nominal_span_for_an_anchor_spanning_fall_back(): void {
		$timezone     = new DateTimeZone( 'America/New_York' );
		$anchor_start = new DateTimeImmutable( '2026-10-31 22:00:00', $timezone );
		$anchor_end   = new DateTimeImmutable( '2026-11-01 02:00:00', $timezone );

		$post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );
		add_post_meta(
			$post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => $anchor_start->format( 'Y-m-d H:i:s' ),
					'dateTimeEnd'   => $anchor_end->format( 'Y-m-d H:i:s' ),
					'timezone'      => 'America/New_York',
				)
			)
		);
		Event_Setup::get_instance()->set_datetimes( $post_id );
		add_post_meta(
			$post_id,
			Meta::META_KEY,
			wp_json_encode(
				array(
					'frequency' => 'weekly',
					'interval'  => 1,
					'weekdays'  => array( (int) $anchor_start->format( 'w' ) ),
					'end_type'  => 'count',
					'count'     => 3,
				)
			)
		);
		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );

		// The anchor's own row: its stored end must match the anchor it was derived from.
		$anchor_row = Occurrences::get_instance()->get( $post_id, Occurrences::recurrence_id( $anchor_start ) );

		$this->assertSame(
			'2026-11-01 02:00:00',
			$anchor_row['datetime_end'],
			'Failed to assert that the anchor\'s own row keeps the anchor\'s own stored end time.'
		);

		// A later occurrence that does not itself span a transition.
		$later_start = new DateTimeImmutable( '2026-11-07 22:00:00', $timezone );
		$later_row   = Occurrences::get_instance()->get( $post_id, Occurrences::recurrence_id( $later_start ) );

		$this->assertSame(
			'2026-11-08 02:00:00',
			$later_row['datetime_end'],
			'Failed to assert that a later occurrence kept the nominal 4-hour span, not the anchor\'s inflated 5 hours.'
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
	 * Coverage for CF-4: the priority gap on `shutdown` -- not registration
	 * order -- is what guarantees `Meta::resolve_pending_recurrence()` runs
	 * before `Occurrences::resolve_pending_projection()`. `has_action()`
	 * returning a truthy value accepts any priority, including one that would
	 * put `Occurrences` first and break the ordering the whole deferred
	 * design depends on, so the priorities themselves have to be asserted.
	 *
	 * @covers ::maybe_project
	 *
	 * @return void
	 */
	public function test_shutdown_priority_gap_runs_meta_before_occurrences(): void {
		$post_id  = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );
		$instance = Occurrences::get_instance();
		$meta     = Meta::get_instance();

		$meta->set_recurrence( $post_id );
		$instance->maybe_project( $post_id );

		$this->assertSame(
			20,
			has_action( 'shutdown', array( $instance, 'resolve_pending_projection' ) ),
			'Failed to assert that resolve_pending_projection is registered at priority 20.'
		);
		$this->assertLessThan(
			has_action( 'shutdown', array( $instance, 'resolve_pending_projection' ) ),
			has_action( 'shutdown', array( $meta, 'resolve_pending_recurrence' ) ),
			'Failed to assert that Meta\'s shutdown resolution runs at a lower priority than Occurrences\'.'
		);

		// Drain the pending state so it does not leak into another test.
		$meta->resolve_pending_recurrence();
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
	 * CF-2: asserting only that the recurring post ID is present, and only
	 * checking null-ness for the *non*-recurring entry, does not exercise
	 * C-1 at all -- `row_to_ref()` could hardcode `recurrence_id = null` for
	 * every row and this test would still pass, while every real caller of
	 * `select_upcoming()` would silently lose occurrence identity for every
	 * recurring series. Asserting the recurring entry's exact, expected
	 * `Ymd\THis` recurrence ID is what makes that change fail here.
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

		$recurring_ref = current(
			array_filter( $refs, fn( Occurrence_Ref $ref ) => $recurring_post_id === $ref->post_id )
		);

		$this->assertSame(
			Occurrences::recurrence_id( $recurring_start ),
			$recurring_ref->recurrence_id,
			'Failed to assert that a recurring event carries its expected, non-null recurrence_id.'
		);

		$starts = wp_list_pluck( $refs, 'datetime_start_gmt' );
		$sorted = $starts;
		sort( $sorted );

		$this->assertSame( $sorted, $starts, 'Failed to assert that upcoming occurrences are ordered ascending.' );
	}

	/**
	 * Direct coverage for `row_to_ref()`'s non-null branch: a row whose
	 * `recurrence_id` column is a string produces an `Occurrence_Ref` that
	 * carries the same string, not null.
	 *
	 * @covers ::row_to_ref
	 *
	 * @return void
	 */
	public function test_row_to_ref_carries_a_non_null_recurrence_id(): void {
		$ref = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'row_to_ref',
			array(
				array(
					'post_id'             => 42,
					'recurrence_id'       => '20260903T180000',
					'effective_start_gmt' => '2026-09-03 22:00:00',
				),
			)
		);

		$this->assertSame( 42, $ref->post_id );
		$this->assertSame( '20260903T180000', $ref->recurrence_id );
		$this->assertSame( '2026-09-03 22:00:00', $ref->datetime_start_gmt );
	}

	/**
	 * Direct coverage for `row_to_ref()`'s null branch: a row whose
	 * `recurrence_id` column is `null` (a non-recurring event, no occurrence
	 * row) produces an `Occurrence_Ref` with a null `recurrence_id`.
	 *
	 * @covers ::row_to_ref
	 *
	 * @return void
	 */
	public function test_row_to_ref_carries_a_null_recurrence_id(): void {
		$ref = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'row_to_ref',
			array(
				array(
					'post_id'             => 42,
					'recurrence_id'       => null,
					'effective_start_gmt' => '2026-09-03 22:00:00',
				),
			)
		);

		$this->assertSame( 42, $ref->post_id );
		$this->assertNull( $ref->recurrence_id );
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

	/**
	 * Create a "never"-ending weekly series anchored a few weeks in the past,
	 * projected with a short horizon so it starts out needing a top-up once
	 * the horizon filter is restored to its default.
	 *
	 * @since 0.36.0
	 *
	 * @return array{0: int, 1: DateTimeImmutable} The post ID and its anchor.
	 */
	protected function create_short_horizon_never_ending_series(): array {
		$timezone = new DateTimeZone( 'America/New_York' );
		$anchor   = ( new DateTimeImmutable( 'now', $timezone ) )->modify( '-3 weeks' );

		$short_horizon = static fn() => 1;
		add_filter( 'gatherpress_recurrence_horizon_months', $short_horizon );

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

		remove_filter( 'gatherpress_recurrence_horizon_months', $short_horizon );

		return array( $post_id, $anchor );
	}

	/**
	 * Coverage for REQ-6: a scheduled sweep re-runs `project()` for a
	 * long-running series whose projected horizon is running short,
	 * extending it, while every occurrence already in the past survives --
	 * attendees' RSVPs hang off past occurrences.
	 *
	 * @covers ::top_up
	 * @covers ::select_series_needing_top_up
	 * @covers ::resolve_top_up_cutoff
	 *
	 * @return void
	 */
	public function test_scheduled_top_up_extends_the_horizon_and_retains_past_occurrences(): void {
		list( $post_id ) = $this->create_short_horizon_never_ending_series();

		Query::refresh_has_recurring_events();

		$before   = Occurrences::get_instance()->select_for_series( array( $post_id ) );
		$now_gmt  = current_time( 'mysql', true );
		$past_ids = wp_list_pluck(
			array_filter( $before, static fn( $row ) => $row['datetime_start_gmt'] < $now_gmt ),
			'recurrence_id'
		);

		$this->assertNotEmpty(
			$past_ids,
			'Failed to assert that the fixture produced a past occurrence to protect.'
		);

		$far_future = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->modify( '+6 months' )
			->format( 'Y-m-d H:i:s' );

		$this->assertEmpty(
			array_filter( $before, static fn( $row ) => $row['datetime_start_gmt'] > $far_future ),
			'Failed to assert that the short-horizon fixture had nothing projected six months out yet.'
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SWEEP_ACTION is a gatherpress_-prefixed class constant.
		do_action( Projection_Cron::SWEEP_ACTION );

		$after     = Occurrences::get_instance()->select_for_series( array( $post_id ) );
		$after_ids = wp_list_pluck( $after, 'recurrence_id' );

		foreach ( $past_ids as $recurrence_id ) {
			$this->assertContains(
				$recurrence_id,
				$after_ids,
				'Failed to assert that a past occurrence survived the scheduled top-up.'
			);
		}

		$this->assertNotEmpty(
			array_filter( $after, static fn( $row ) => $row['datetime_start_gmt'] > $far_future ),
			'Failed to assert that the scheduled top-up extended the horizon six months out.'
		);
	}

	/**
	 * Coverage for REQ-6: the scheduled sweep must issue no query against the
	 * occurrence table at all on a site with no recurring events -- checked
	 * via a `$wpdb->queries` capture, not the sweep's return value, matching
	 * the CF-1 zero-query test above for the save path.
	 *
	 * @covers \GatherPress\Core\Event\Recurrence\Projection_Cron::run_sweep
	 *
	 * @return void
	 */
	public function test_scheduled_job_performs_no_writes_on_a_site_with_no_recurring_events(): void {
		global $wpdb;

		Query::refresh_has_recurring_events();
		$this->assertFalse(
			Query::site_has_recurring_events(),
			'Failed to assert that the fixture site has no recurring events.'
		);

		$occurrences_table  = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$query_count_before = count( $wpdb->queries );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SWEEP_ACTION is a gatherpress_-prefixed class constant.
		do_action( Projection_Cron::SWEEP_ACTION );

		$queries_since       = array_slice( $wpdb->queries, $query_count_before );
		$touched_occurrences = array_values(
			array_filter(
				$queries_since,
				static function ( $query ) use ( $occurrences_table ) {
					return str_contains( $query[0], $occurrences_table );
				}
			)
		);

		$this->assertSame(
			array(),
			$touched_occurrences,
			'Failed to assert that the scheduled sweep issued no query against the occurrence table.'
		);
	}

	/**
	 * Coverage for REQ-6: a `COUNT`-bounded rule is already complete and must
	 * never be re-projected by the sweep, however far its (fixed, final)
	 * latest occurrence sits in the past.
	 *
	 * @covers ::select_series_needing_top_up
	 * @covers ::top_up
	 *
	 * @return void
	 */
	public function test_count_bounded_rule_is_not_re_projected_by_the_sweep(): void {
		global $wpdb;

		$timezone = new DateTimeZone( 'America/New_York' );
		$far_past = ( new DateTimeImmutable( 'now', $timezone ) )->modify( '-3 years' );

		$post_id = $this->create_relative_recurring_event(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( (int) $far_past->format( 'w' ) ),
				'end_type'  => 'count',
				'count'     => 3,
			),
			$far_past,
			$far_past->modify( '+2 hours' ),
			'America/New_York'
		);

		$before = Occurrences::get_instance()->select_for_series( array( $post_id ) );
		$this->assertCount( 3, $before, 'Failed to assert the count-bounded fixture wrote exactly 3 rows.' );

		$this->assertNotContains(
			$post_id,
			Occurrences::get_instance()->select_series_needing_top_up( 100 ),
			'Failed to assert that select_series_needing_top_up excludes a count-bounded series.'
		);

		Query::refresh_has_recurring_events();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SWEEP_ACTION is a gatherpress_-prefixed class constant.
		do_action( Projection_Cron::SWEEP_ACTION );

		$after = Occurrences::get_instance()->select_for_series( array( $post_id ) );

		$this->assertSame(
			$before,
			$after,
			'Failed to assert that the count-bounded series rows were untouched by the sweep.'
		);
	}

	/**
	 * Direct coverage for `select_series_needing_top_up()`'s `LIMIT`: only up
	 * to the requested number of stale series are returned.
	 *
	 * @covers ::select_series_needing_top_up
	 *
	 * @return void
	 */
	public function test_select_series_needing_top_up_respects_the_limit(): void {
		$this->create_short_horizon_never_ending_series();
		$this->create_short_horizon_never_ending_series();

		$candidates = Occurrences::get_instance()->select_series_needing_top_up( 1 );

		$this->assertCount( 1, $candidates, 'Failed to assert that select_series_needing_top_up respects its limit.' );
	}

	/**
	 * Coverage for the `gatherpress_recurrence_top_up_margin_days` filter.
	 *
	 * @covers ::resolve_top_up_cutoff
	 *
	 * @return void
	 */
	public function test_resolve_top_up_cutoff_is_filterable_by_margin_days(): void {
		$filter = static fn() => 0;
		add_filter( 'gatherpress_recurrence_top_up_margin_days', $filter );

		$cutoff = Utility::invoke_hidden_method( Occurrences::get_instance(), 'resolve_top_up_cutoff' );

		remove_filter( 'gatherpress_recurrence_top_up_margin_days', $filter );

		$expected = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->modify( '+' . Occurrences::PROJECTION_HORIZON_MONTHS . ' months' );

		$this->assertSame(
			$expected->format( 'Y-m-d H:i' ),
			$cutoff->format( 'Y-m-d H:i' ),
			'Failed to assert that a zero margin leaves the cutoff at the horizon itself.'
		);
	}

	/**
	 * Coverage for the `gatherpress_recurrence_top_up_batch_size` filter.
	 *
	 * @covers ::top_up
	 *
	 * @return void
	 */
	public function test_top_up_batch_size_is_filterable(): void {
		$this->create_short_horizon_never_ending_series();
		$this->create_short_horizon_never_ending_series();

		$filter = static fn() => 1;
		add_filter( 'gatherpress_recurrence_top_up_batch_size', $filter );

		$written = Occurrences::get_instance()->top_up();

		remove_filter( 'gatherpress_recurrence_top_up_batch_size', $filter );

		$this->assertSame( 1, $written, 'Failed to assert that top_up honors the batch-size filter default.' );
	}

	/**
	 * Direct coverage for `top_up()`'s explicit-limit branch, bypassing the filter.
	 *
	 * @covers ::top_up
	 *
	 * @return void
	 */
	public function test_top_up_uses_an_explicit_limit_when_given_one(): void {
		$this->create_short_horizon_never_ending_series();
		$this->create_short_horizon_never_ending_series();

		$written = Occurrences::get_instance()->top_up( 1 );

		$this->assertSame( 1, $written, 'Failed to assert that top_up respects an explicit limit argument.' );
	}

	/**
	 * Direct coverage for `is_series_stale()`'s count-bounded branch.
	 *
	 * @covers ::is_series_stale
	 *
	 * @return void
	 */
	public function test_is_series_stale_returns_false_for_a_count_bounded_series(): void {
		$post_id = $this->create_and_project();

		$stale = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'is_series_stale',
			array( $post_id )
		);

		$this->assertFalse( $stale, 'Failed to assert that a count-bounded series is never reported stale.' );
	}

	/**
	 * Direct coverage for `is_series_stale()`'s non-recurring branch.
	 *
	 * @covers ::is_series_stale
	 *
	 * @return void
	 */
	public function test_is_series_stale_returns_false_for_a_non_recurring_post(): void {
		$post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );

		$stale = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'is_series_stale',
			array( $post_id )
		);

		$this->assertFalse( $stale, 'Failed to assert that a non-recurring post is never reported stale.' );
	}

	/**
	 * Direct coverage for `is_series_stale()`'s fresh-never-ending branch.
	 *
	 * @covers ::is_series_stale
	 *
	 * @return void
	 */
	public function test_is_series_stale_returns_false_for_a_freshly_projected_series(): void {
		$post_id = $this->create_relative_recurring_event(
			array(
				'frequency' => 'weekly',
				'interval'  => 1,
				'weekdays'  => array( 2, 4 ),
				'end_type'  => 'never',
			),
			new DateTimeImmutable( 'now', new DateTimeZone( 'America/New_York' ) ),
			( new DateTimeImmutable( 'now', new DateTimeZone( 'America/New_York' ) ) )->modify( '+2 hours' ),
			'America/New_York'
		);

		$stale = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'is_series_stale',
			array( $post_id )
		);

		$this->assertFalse( $stale, 'Failed to assert that a freshly projected series is not stale.' );
	}

	/**
	 * Direct coverage for `is_series_stale()`'s stale branch.
	 *
	 * @covers ::is_series_stale
	 *
	 * @return void
	 */
	public function test_is_series_stale_returns_true_for_a_short_horizon_series(): void {
		list( $post_id ) = $this->create_short_horizon_never_ending_series();

		$stale = Utility::invoke_hidden_method(
			Occurrences::get_instance(),
			'is_series_stale',
			array( $post_id )
		);

		$this->assertTrue( $stale, 'Failed to assert that a series projected with a short horizon is reported stale.' );
	}

	/**
	 * Coverage for REQ-6: reading a stale series through `select_upcoming()`
	 * -- the real production read path -- triggers exactly one repair, and a
	 * second read within the debounce window is suppressed.
	 *
	 * @covers ::select_by_horizon
	 * @covers ::maybe_lazy_repair
	 * @covers ::maybe_repair_stale_series
	 *
	 * @return void
	 */
	public function test_lazy_repair_triggers_once_then_is_suppressed_for_the_debounce_window(): void {
		list( $post_id ) = $this->create_short_horizon_never_ending_series();

		Query::refresh_has_recurring_events();
		delete_transient( sprintf( 'gatherpress_projected_%d', $post_id ) );

		// The margin-days filter fires exactly once per maybe_repair_stale_series()
		// attempt (inside is_series_stale() -> resolve_top_up_cutoff()), unlike
		// the horizon-months filter, which also fires from project()'s own
		// resolve_horizon() call whenever a repair actually re-projects -- so
		// this is the precise "one attempt" signal the debounce governs.
		$calls  = 0;
		$filter = static function ( $days ) use ( &$calls ) {
			++$calls;

			return $days;
		};
		add_filter( 'gatherpress_recurrence_top_up_margin_days', $filter );

		Occurrences::get_instance()->select_upcoming( 50 );

		$this->assertSame(
			1,
			$calls,
			'Failed to assert that the first stale read triggered exactly one repair attempt.'
		);
		$this->assertNotFalse(
			get_transient( sprintf( 'gatherpress_projected_%d', $post_id ) ),
			'Failed to assert that the debounce transient was set after the repair.'
		);

		Occurrences::get_instance()->select_upcoming( 50 );

		remove_filter( 'gatherpress_recurrence_top_up_margin_days', $filter );

		$this->assertSame(
			1,
			$calls,
			'Failed to assert that a second read within the debounce window did not trigger another repair attempt.'
		);

		$far_future = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->modify( '+6 months' )
			->format( 'Y-m-d H:i:s' );
		$rows       = Occurrences::get_instance()->select_for_series( array( $post_id ) );

		$this->assertNotEmpty(
			array_filter( $rows, static fn( $row ) => $row['datetime_start_gmt'] > $far_future ),
			'Failed to assert that the triggered repair actually extended the horizon.'
		);
	}

	/**
	 * Coverage for `maybe_lazy_repair()`'s site-wide short-circuit: no
	 * repair is attempted when the site has no recurring events.
	 *
	 * @covers ::maybe_lazy_repair
	 *
	 * @return void
	 */
	public function test_select_upcoming_does_not_repair_when_site_has_no_recurring_events(): void {
		update_option( Query::HAS_RECURRING_OPTION, '0' );

		$calls  = 0;
		$filter = static function ( $months ) use ( &$calls ) {
			++$calls;

			return $months;
		};
		add_filter( 'gatherpress_recurrence_horizon_months', $filter );

		Occurrences::get_instance()->select_upcoming( 50 );

		remove_filter( 'gatherpress_recurrence_horizon_months', $filter );

		$this->assertSame(
			0,
			$calls,
			'Failed to assert that select_upcoming never attempts a repair when the site has no recurring events.'
		);
	}

	/**
	 * Coverage for `select_by_horizon()`'s `$upcoming` gate: `select_past()`
	 * never triggers the lazy repair, only `select_upcoming()` does.
	 *
	 * @covers ::select_by_horizon
	 *
	 * @return void
	 */
	public function test_select_past_does_not_trigger_lazy_repair(): void {
		list( $post_id ) = $this->create_short_horizon_never_ending_series();

		Query::refresh_has_recurring_events();
		delete_transient( sprintf( 'gatherpress_projected_%d', $post_id ) );

		Occurrences::get_instance()->select_past( 50 );

		$this->assertFalse(
			get_transient( sprintf( 'gatherpress_projected_%d', $post_id ) ),
			'Failed to assert that select_past never sets the lazy-repair debounce transient.'
		);
	}
}
