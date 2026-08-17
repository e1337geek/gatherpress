<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Projection_Cron.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Projection_Cron;
use GatherPress\Core\Event\Recurrence\Query;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;

/**
 * Class Test_Projection_Cron.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Projection_Cron
 */
class Test_Projection_Cron extends Base {

	use Occurrence_Fixtures;

	/**
	 * Ensure the occurrence table exists, and start every test with the
	 * sweep unscheduled, independent of execution order.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );

		wp_clear_scheduled_hook( Projection_Cron::SWEEP_ACTION );
	}

	/**
	 * Leave no scheduled sweep behind for the next test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		wp_clear_scheduled_hook( Projection_Cron::SWEEP_ACTION );

		parent::tearDown();
	}

	/**
	 * Create and project a recurring event anchored relative to "now".
	 *
	 * Duplicated from `Test_Occurrences` rather than added to
	 * `Occurrence_Fixtures` -- this lane's file scope is the `Occurrences`
	 * class, its test, and this new test file, not the shared trait.
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
		$instance = Projection_Cron::get_instance();
		$hooks    = array(
			array(
				'type'     => 'action',
				'name'     => 'init',
				'priority' => 10,
				'callback' => array( $instance, 'maybe_schedule_sweep' ),
			),
			array(
				'type'     => 'action',
				'name'     => Projection_Cron::SWEEP_ACTION,
				'priority' => 10,
				'callback' => array( $instance, 'run_sweep' ),
			),
		);

		$this->assert_hooks( $hooks, $instance );
	}

	/**
	 * Coverage for `maybe_schedule_sweep()` scheduling the recurring sweep
	 * when nothing is scheduled yet.
	 *
	 * @covers ::maybe_schedule_sweep
	 *
	 * @return void
	 */
	public function test_maybe_schedule_sweep_schedules_the_sweep(): void {
		Projection_Cron::get_instance()->maybe_schedule_sweep();

		$this->assertNotFalse(
			wp_next_scheduled( Projection_Cron::SWEEP_ACTION ),
			'Failed to assert that maybe_schedule_sweep schedules the recurring sweep.'
		);
	}

	/**
	 * Coverage for `maybe_schedule_sweep()`'s dedup branch: an already
	 * scheduled sweep is never duplicated.
	 *
	 * @covers ::maybe_schedule_sweep
	 *
	 * @return void
	 */
	public function test_maybe_schedule_sweep_does_not_duplicate_an_existing_schedule(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, Projection_Cron::SWEEP_RECURRENCE, Projection_Cron::SWEEP_ACTION );
		$existing = wp_next_scheduled( Projection_Cron::SWEEP_ACTION );

		Projection_Cron::get_instance()->maybe_schedule_sweep();

		$this->assertSame(
			$existing,
			wp_next_scheduled( Projection_Cron::SWEEP_ACTION ),
			'Failed to assert that maybe_schedule_sweep left an existing schedule untouched.'
		);
	}

	/**
	 * Coverage for `maybe_schedule_sweep()`'s filter short-circuit -- the
	 * seam a companion plugin uses to route the sweep through Action
	 * Scheduler instead of WP-Cron, mirroring
	 * `gatherpress_async_geocode_pre_enqueue_job`.
	 *
	 * @covers ::maybe_schedule_sweep
	 *
	 * @return void
	 */
	public function test_maybe_schedule_sweep_is_short_circuited_by_the_pre_schedule_filter(): void {
		$filter = static fn() => 'action-scheduler-job-id';
		add_filter( 'gatherpress_recurrence_top_up_pre_schedule_sweep', $filter );

		Projection_Cron::get_instance()->maybe_schedule_sweep();

		remove_filter( 'gatherpress_recurrence_top_up_pre_schedule_sweep', $filter );

		$this->assertFalse(
			wp_next_scheduled( Projection_Cron::SWEEP_ACTION ),
			'Failed to assert that a non-null filter return suppresses the default WP-Cron scheduling.'
		);
	}

	// Coverage for run_sweep()'s early-return branch lives in
	// Test_Occurrences::test_scheduled_job_performs_no_writes_on_a_site_with_no_recurring_events(),
	// which fires this same real cron hook and asserts against a $wpdb->queries
	// capture. A prior version of this test asserted only that
	// site_has_recurring_events() was still false after the sweep -- a
	// tautology, since nothing in run_sweep() can write that option regardless
	// of whether the guard runs; removing the guard left that assertion green.

	/**
	 * Coverage for `run_sweep()`'s top-up branch, driven through the real
	 * cron hook.
	 *
	 * @covers ::run_sweep
	 *
	 * @return void
	 */
	public function test_run_sweep_tops_up_when_site_has_recurring_events(): void {
		$timezone = new DateTimeZone( 'America/New_York' );
		$anchor   = ( new DateTimeImmutable( 'now', $timezone ) )->modify( '-1 week' );

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

		Query::refresh_has_recurring_events();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SWEEP_ACTION is a gatherpress_-prefixed class constant.
		do_action( Projection_Cron::SWEEP_ACTION );

		$far_future = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->modify( '+6 months' )
			->format( 'Y-m-d H:i:s' );
		$rows       = Occurrences::get_instance()->select_for_series( array( $post_id ) );

		$this->assertNotEmpty(
			array_filter( $rows, static fn( $row ) => $row['datetime_start_gmt'] > $far_future ),
			'Failed to assert that run_sweep topped up a stale series via the real cron hook.'
		);
	}

	/**
	 * Coverage for REQ-6: deactivation unschedules the sweep so a cron event
	 * never survives plugin deactivation.
	 *
	 * @covers ::deactivate
	 *
	 * @return void
	 */
	public function test_deactivation_unschedules_the_sweep(): void {
		$instance = Projection_Cron::get_instance();
		$instance->maybe_schedule_sweep();

		$this->assertNotFalse(
			wp_next_scheduled( Projection_Cron::SWEEP_ACTION ),
			'Failed to assert that the sweep was scheduled before deactivation.'
		);

		$instance->deactivate();

		$this->assertFalse(
			wp_next_scheduled( Projection_Cron::SWEEP_ACTION ),
			'Failed to assert that deactivate() unschedules the sweep.'
		);
	}

	/**
	 * Coverage for `deactivate()`'s no-op branch: deactivating twice, or
	 * deactivating when nothing was ever scheduled, does not error.
	 *
	 * @covers ::deactivate
	 *
	 * @return void
	 */
	public function test_deactivation_is_a_no_op_when_nothing_is_scheduled(): void {
		$instance = Projection_Cron::get_instance();

		$instance->deactivate();

		$this->assertFalse(
			wp_next_scheduled( Projection_Cron::SWEEP_ACTION ),
			'Failed to assert that deactivate() is a harmless no-op when nothing was scheduled.'
		);
	}

	/**
	 * Coverage for `setup_hooks()`'s `register_deactivation_hook()` call.
	 * Both deactivation tests above call `deactivate()` directly, so neither
	 * would fail if the `register_deactivation_hook()` line were removed --
	 * this asserts the registration itself, the same shape as the
	 * cron-wiring gap a prior review round caught for `run_sweep()`.
	 *
	 * @covers ::setup_hooks
	 *
	 * @return void
	 */
	public function test_deactivation_hook_is_registered(): void {
		$instance = Projection_Cron::get_instance();

		$this->assertSame(
			10,
			has_action(
				'deactivate_' . plugin_basename( GATHERPRESS_CORE_FILE ),
				array( $instance, 'deactivate' )
			),
			'Failed to assert that deactivate() is registered on the real WordPress deactivation hook.'
		);
	}
}
