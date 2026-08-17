<?php
/**
 * Occurrence persistence.
 *
 * Owns every read and write of `{prefix}gatherpress_event_occurrences`, whose
 * primary key is the composite `(series_post_id, recurrence_id)`. There is no
 * autoincrement column, which is what makes PRD C-1 structural rather than a
 * convention — no insertion-order identifier exists to leak into a URL or an
 * RSVP link.
 *
 * PRD C-2 — every read takes an array of post IDs resolved through
 * `Series::resolve_post_ids()` and emits `series_post_id IN (…)`. A query
 * written as `series_post_id = %d` forecloses REQ-18. Mutations (`project()`,
 * `set_status()`, `delete_for_post()`, `get()`) operate on exactly one post's
 * own rows, so `series_post_id = %d` there is correct rather than a violation
 * — C-2 governs series-wide reads, not single-post writes. `delete_for_post()`
 * is deliberately per-post, not per-series: one rule per event post (PRD D-5)
 * means every call site (`delete_post`, an expand-failure clear) only ever
 * needs to clear the post it was handed. A genuine series-wide delete is a
 * different, not-yet-needed method (`delete_for_series( array $post_ids )`),
 * added when REQ-18's forward split actually requires one.
 *
 * PRD C-5 — cancellation is the `status` column on an occurrence row. The rule
 * is never mutated to express it.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use GatherPress\Core\Event;
use GatherPress\Core\Traits\Singleton;
use GatherPress\Core\Utility;
use InvalidArgumentException;

/**
 * Class Occurrences.
 *
 * Singleton repository, matching the shape of `Rsvp\Query`.
 *
 * @since 0.36.0
 */
final class Occurrences {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Occurrence table name format, taking the table prefix.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const TABLE_FORMAT = '%sgatherpress_event_occurrences';

	/**
	 * Status of an occurrence that is going ahead.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const STATUS_SCHEDULED = 'scheduled';

	/**
	 * Status of an occurrence that has been cancelled.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * How many months ahead of "now" an open-ended rule is projected.
	 *
	 * A `never`-ending rule has no natural horizon, so one is imposed here
	 * rather than expanding toward `Expander::MAX_ITERATIONS`. The horizon is
	 * measured from `max( $anchor_start, now )`, not from the anchor alone --
	 * an anchor-relative horizon computes the same fixed window every time
	 * `project()` runs, so a long-running series eventually projects entirely
	 * into the past and a top-up re-run (REQ-6) is a guaranteed no-op. `until`
	 * is not bounded by this constant either: `Expander::expand()` treats a
	 * non-count rule's horizon as its stopping point, so an `UNTIL` far beyond
	 * this window is truncated at the horizon, same as `never`.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const PROJECTION_HORIZON_MONTHS = 12;

	/**
	 * How many days of margin before the horizon a series is topped up.
	 *
	 * A series is treated as needing a top-up once its latest projected
	 * occurrence is within this many days of `resolve_horizon()`'s own
	 * horizon, rather than waiting until the horizon has already been
	 * reached. Filterable via `gatherpress_recurrence_top_up_margin_days`.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const TOP_UP_MARGIN_DAYS = 30;

	/**
	 * Maximum number of series re-projected by one sweep.
	 *
	 * `top_up()` calls `project()` once per candidate, and each `project()`
	 * call can upsert a full horizon's worth of rows for one series (e.g. a
	 * weekly rule over a 12-month horizon is ~52 rows). A batch this size
	 * bounds one sweep's worst case to roughly the same order of magnitude
	 * as `Venue\Map\Prewarm::CONTENT_SCAN_BATCH_SIZE`'s heavier-per-row scan,
	 * scaled down for `project()`'s multi-row-per-candidate cost. Filterable
	 * via `gatherpress_recurrence_top_up_batch_size`.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const TOP_UP_BATCH_SIZE = 50;

	/**
	 * How long a lazy repair attempt is suppressed for one series.
	 *
	 * Bounds `maybe_repair_stale_series()` to at most one attempt per series
	 * per window, regardless of how many reads hit that series while the
	 * scheduled sweep has not yet caught up to it.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const LAZY_REPAIR_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Transient key format for the lazy-repair debounce, taking a post ID.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const LAZY_REPAIR_TRANSIENT_FORMAT = 'gatherpress_projected_%d';

	/**
	 * Maximum number of distinct series one read attempts to lazily repair.
	 *
	 * `maybe_lazy_repair()` runs synchronously inside a front-end read, so a
	 * listing page surfacing many distinct stale series must not turn into
	 * many synchronous `project()` calls inside one request -- the scheduled
	 * sweep is the primary top-up path; this is a same-request safety net,
	 * not expected to carry the bulk of the work. Filterable via
	 * `gatherpress_recurrence_lazy_repair_batch_size`.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const LAZY_REPAIR_READ_BATCH_SIZE = 1;

	/**
	 * Posts whose recurrence blob had not landed yet when a save tried to project it.
	 *
	 * `wp_after_insert_post` can fire before the request's meta writes have
	 * all landed — REST, duplication, and import all write the blob with a
	 * separate `add_post_meta()` call after the insert completes, the same
	 * race `Recurrence\Meta::$pending_recurrence` guards. Rather than guess,
	 * the post is noted here and decided again on `shutdown`, once every
	 * write this request is going to make has already happened.
	 *
	 * Each value is whether the post already had valid recurrence mirrors at
	 * the moment it was deferred -- captured before `Meta`'s own deferred
	 * resolution has a chance to touch them, since both classes read the same
	 * meta key at the same point in the same `wp_after_insert_post` firing.
	 * REQ-16's "a site with no recurring events pays nothing" guarantee turns
	 * on this: an ordinary event that was never recurring, and still is not
	 * at shutdown, must resolve without ever querying the occurrence table.
	 * Only a post that *was* recurring justifies the cleanup query when its
	 * rule turns out to be gone.
	 *
	 * @since 0.36.0
	 * @var array<int, bool>
	 */
	protected array $pending_projection = array();

	/**
	 * Class constructor.
	 *
	 * Bootstraps `Projection_Cron` here rather than from `Recurrence\Setup`:
	 * the sweep/lazy-repair scheduler it owns exists solely to keep this
	 * class's own `project()` re-running over time (REQ-6), so wiring it up
	 * alongside the class it serves keeps that relationship in one file
	 * instead of splitting it across this class and the subsystem's shared
	 * `Setup::instantiate_classes()`.
	 *
	 * @since 0.36.0
	 */
	public function __construct() {
		$this->setup_hooks();

		Projection_Cron::get_instance();
	}

	/**
	 * Set up hooks for occurrence projection and cleanup.
	 *
	 * `maybe_project()` is registered at priority 20 on `wp_after_insert_post`,
	 * strictly after `Recurrence\Meta::set_recurrence()`'s default-priority-10
	 * handling of the same event, so the mirrors `Rule::from_post()` reads are
	 * already whatever this save produced by the time projection runs.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_action( 'wp_after_insert_post', array( $this, 'maybe_project' ), 20 );
		add_action( 'delete_post', array( $this, 'maybe_delete_for_post' ) );
	}

	/**
	 * Project a post's recurrence rule after a save, deferring when the blob is not there yet.
	 *
	 * Mirrors `Recurrence\Meta::set_recurrence()`'s own deferred-to-`shutdown`
	 * handling of the identical race (the datetime write's #2116 shape, once
	 * more here): reading the raw blob rather than the mirrors is what makes
	 * the two classes' decisions agree on whether this pass is safe to
	 * project from immediately.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID that was just saved.
	 *
	 * @return void
	 */
	public function maybe_project( int $post_id ): void {
		if ( ! post_type_supports( (string) get_post_type( $post_id ), 'gatherpress-event-date' ) ) {
			return;
		}

		$data = get_post_meta( $post_id, Meta::META_KEY, true );

		if ( ! empty( $data ) ) {
			$this->project( $post_id );

			return;
		}

		// Captured now, before Meta's own deferred resolution can touch the
		// mirrors this request -- see the $pending_projection property
		// docblock for why this is what keeps an ordinary, never-recurring
		// save from ever querying the occurrence table (REQ-16).
		$this->pending_projection[ $post_id ] = Rule::from_post( $post_id ) instanceof Rule;

		add_action( 'shutdown', array( $this, 'resolve_pending_projection' ), 20 );
	}

	/**
	 * Project every post that finished its save without a recurrence blob landed yet.
	 *
	 * Runs on `shutdown` at priority 20, strictly after
	 * `Recurrence\Meta::resolve_pending_recurrence()`'s own default-priority-10
	 * `shutdown` resolution — registered dynamically per post, so the priority
	 * gap is what guarantees the ordering rather than registration order.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function resolve_pending_projection(): void {
		$pending                  = $this->pending_projection;
		$this->pending_projection = array();

		foreach ( $pending as $post_id => $was_recurring ) {
			// The post can be gone by shutdown -- a duplicate that failed, or
			// an insert rolled back after this hook ran.
			if ( ! post_type_supports( (string) get_post_type( $post_id ), 'gatherpress-event-date' ) ) {
				continue;
			}

			$this->run_projection( $post_id, $was_recurring );
		}
	}

	/**
	 * Delete a post's occurrence rows when it is hard-deleted, if supported.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID being deleted.
	 *
	 * @return void
	 */
	public function maybe_delete_for_post( int $post_id ): void {
		if ( ! post_type_supports( (string) get_post_type( $post_id ), 'gatherpress-event-date' ) ) {
			return;
		}

		$this->delete_for_post( $post_id );
	}

	/**
	 * Derive an occurrence identifier from its local start.
	 *
	 * The RFC 5545 `RECURRENCE-ID` form, always the occurrence's local start in
	 * `Ymd\THis`. Never an all-day `Ymd` form, never a `Z`-suffixed UTC form.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $start Occurrence start in the series timezone.
	 *
	 * @return string The occurrence identifier.
	 */
	public static function recurrence_id( DateTimeImmutable $start ): string {
		return $start->format( 'Ymd\THis' );
	}

	/**
	 * Select upcoming occurrences and non-recurring events as one ordered list.
	 *
	 * Returns value objects rather than bare IDs, so identity travels on the
	 * object and no index-correspondence contract exists between caller and
	 * callee.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $limit Maximum entries to return.
	 * @param array $args  Optional query arguments, including `status`.
	 *
	 * @return Occurrence_Ref[] Ordered ascending by start.
	 */
	public function select_upcoming( int $limit, array $args = array() ): array {
		return $this->select_by_horizon( $limit, $args, true );
	}

	/**
	 * Select past occurrences and non-recurring events as one ordered list.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $limit Maximum entries to return.
	 * @param array $args  Optional query arguments, including `status`.
	 *
	 * @return Occurrence_Ref[] Ordered descending by start.
	 */
	public function select_past( int $limit, array $args = array() ): array {
		return $this->select_by_horizon( $limit, $args, false );
	}

	/**
	 * Shared query behind `select_upcoming()` and `select_past()`.
	 *
	 * `LEFT JOIN`s the occurrence table onto every post of a
	 * `gatherpress-event-date`-supporting type, so a non-recurring event (no
	 * occurrence row at all) still produces one result via
	 * `COALESCE( scheduled_occurrence.datetime_start_gmt, events.datetime_start_gmt )`,
	 * while a recurring series produces one result per matching occurrence
	 * row. The `status` predicate lives in the join condition, never in
	 * `WHERE` -- but that alone is not sufficient: without the `NOT EXISTS`
	 * guard below, a post whose occurrence rows all fail the status filter
	 * (a fully cancelled series) would *also* fall through the `NULL`
	 * `scheduled_occurrence` branch and reappear as if it were a
	 * non-recurring event at its original anchor date. The guard scopes the
	 * `NULL` fallback to posts with **no occurrence rows at all**, so a
	 * cancelled series is correctly absent rather than misrepresented.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $limit    Maximum entries to return.
	 * @param array $args     Optional query arguments, including `status`.
	 * @param bool  $upcoming True for ascending/future, false for descending/past.
	 *
	 * @return Occurrence_Ref[] Ordered by effective start.
	 */
	protected function select_by_horizon( int $limit, array $args, bool $upcoming ): array {
		global $wpdb;

		$post_types = get_post_types_by_support( 'gatherpress-event-date' );

		if ( array() === $post_types ) {
			return array();
		}

		$status = (string) ( $args['status'] ?? self::STATUS_SCHEDULED );

		$events_table      = sprintf( Event::TABLE_FORMAT, $wpdb->prefix );
		$occurrences_table = sprintf( self::TABLE_FORMAT, $wpdb->prefix );

		$type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$comparison        = $upcoming ? '>=' : '<';
		$order             = $upcoming ? 'ASC' : 'DESC';

		$sql = 'SELECT %i.ID AS post_id, scheduled_occurrence.recurrence_id AS recurrence_id,'
			. ' COALESCE( scheduled_occurrence.datetime_start_gmt, %i.datetime_start_gmt ) AS effective_start_gmt'
			. ' FROM %i'
			. ' LEFT JOIN %i ON %i.ID = %i.post_id'
			. ' LEFT JOIN %i AS scheduled_occurrence ON %i.ID = scheduled_occurrence.series_post_id'
			. ' AND scheduled_occurrence.status = %s'
			. " WHERE %i.post_type IN ( {$type_placeholders} ) AND %i.post_status = %s"
			. ' AND ( scheduled_occurrence.recurrence_id IS NOT NULL'
			. ' OR NOT EXISTS ( SELECT 1 FROM %i WHERE series_post_id = %i.ID ) )'
			. " HAVING effective_start_gmt {$comparison} %s"
			. " ORDER BY effective_start_gmt {$order}"
			. ' LIMIT %d';

		$values = array_merge(
			array(
				$wpdb->posts,
				$events_table,
				$wpdb->posts,
				$events_table,
				$wpdb->posts,
				$events_table,
				$occurrences_table,
				$wpdb->posts,
				$status,
				$wpdb->posts,
			),
			$post_types,
			array(
				$wpdb->posts,
				'publish',
				$occurrences_table,
				$wpdb->posts,
				current_time( 'mysql', true ),
				$limit,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from %i/%s/%d placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$refs = array_map( array( $this, 'row_to_ref' ), null === $rows ? array() : $rows );

		// Lazy repair (REQ-6) only runs off an upcoming-events read -- a
		// past-only read has nothing forward-looking to repair, and gating
		// it here keeps select_past() genuinely read-only.
		if ( $upcoming ) {
			$this->maybe_lazy_repair( $refs );
		}

		return $refs;
	}

	/**
	 * Debounced lazy repair (REQ-6): attempt one repair per stale series
	 * encountered by an upcoming-events read, at most once per
	 * `LAZY_REPAIR_TTL` window.
	 *
	 * Short-circuits on `Query::site_has_recurring_events()` first, so a
	 * site with no recurring events never pays even the transient lookup.
	 * Only refs carrying a non-null `recurrence_id` name an actual series --
	 * a non-recurring event's ref has nothing to repair.
	 *
	 * The per-read cap is applied *after* filtering out series already
	 * suppressed by their debounce transient, not before: refs arrive in
	 * `datetime_start_gmt` order, not staleness order, so slicing the raw
	 * post ID list first lets a series that is currently suppressed (or was
	 * already repaired earlier in this same read) occupy a batch slot and
	 * starve every series behind it out of every read for the rest of the
	 * transient's `LAZY_REPAIR_TTL` window -- a fresh series sorting first
	 * would otherwise permanently block a genuinely stale one sorting after
	 * it. `maybe_repair_stale_series()` still re-checks the transient itself
	 * (a second read within the same request could otherwise double-attempt
	 * a series this method already cleared), so this filter is an
	 * optimization that keeps the cap meaningful, not the sole guard.
	 *
	 * @since 0.36.0
	 *
	 * @param Occurrence_Ref[] $refs Refs produced by this read.
	 *
	 * @return void
	 */
	protected function maybe_lazy_repair( array $refs ): void {
		if ( ! Query::site_has_recurring_events() ) {
			return;
		}

		$post_ids = array();

		foreach ( $refs as $ref ) {
			if ( null !== $ref->recurrence_id ) {
				$post_ids[ $ref->post_id ] = true;
			}
		}

		$unsuppressed = array_values(
			array_filter(
				array_keys( $post_ids ),
				static function ( $post_id ) {
					return false === get_transient( sprintf( self::LAZY_REPAIR_TRANSIENT_FORMAT, $post_id ) );
				}
			)
		);

		/**
		 * Filters how many distinct stale series one read attempts to lazily repair.
		 *
		 * @since 0.36.0
		 *
		 * @param int $batch_size Batch size, default `Occurrences::LAZY_REPAIR_READ_BATCH_SIZE`.
		 *
		 * @return int Batch size.
		 */
		$limit = max(
			0,
			(int) apply_filters( 'gatherpress_recurrence_lazy_repair_batch_size', self::LAZY_REPAIR_READ_BATCH_SIZE )
		);

		foreach ( array_slice( $unsuppressed, 0, $limit ) as $post_id ) {
			$this->maybe_repair_stale_series( $post_id );
		}
	}

	/**
	 * Attempt one repair for a series, then suppress further attempts for
	 * `LAZY_REPAIR_TTL`.
	 *
	 * The transient governs attempt frequency only -- it is never read as an
	 * oracle for whether the series is actually stale. When it is missing,
	 * this checks real storage via `is_series_stale()` rather than assuming
	 * either state, so a fresh series costs one cheap `SELECT` and no write,
	 * and a genuinely stale one gets exactly one `project()` call per window.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID encountered by a read.
	 *
	 * @return void
	 */
	protected function maybe_repair_stale_series( int $post_id ): void {
		$transient_key = sprintf( self::LAZY_REPAIR_TRANSIENT_FORMAT, $post_id );

		if ( false !== get_transient( $transient_key ) ) {
			return;
		}

		set_transient( $transient_key, time(), self::LAZY_REPAIR_TTL );

		if ( $this->is_series_stale( $post_id ) ) {
			$this->project( $post_id );
		}
	}

	/**
	 * Report whether a series' projected horizon has run short.
	 *
	 * Two end types are already complete by design and are never reported
	 * stale: `COUNT`, always -- and `UNTIL`, once its latest projected
	 * occurrence has reached the rule's own `until` bound, which
	 * `has_reached_until()` checks. Re-projecting either would be a no-op at
	 * best and a rewrite of the same rows forever at worst. An empty end type
	 * means the post carries no recurrence rule at all. A series with a rule
	 * but zero projected rows (a failed projection, a partial restore) is
	 * always reported stale, since it would otherwise be invisible to both
	 * the sweep and the lazy repair forever.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID to check.
	 *
	 * @return bool True when the series has no projected rows at all, or its
	 *              latest projected occurrence is within `TOP_UP_MARGIN_DAYS`
	 *              of the horizon, or beyond it.
	 */
	protected function is_series_stale( int $post_id ): bool {
		global $wpdb;

		$end_type = (string) get_post_meta( $post_id, 'gatherpress_recurrence_end_type', true );

		if ( '' === $end_type || Rule::END_TYPE_COUNT === $end_type ) {
			return false;
		}

		$table = sprintf( self::TABLE_FORMAT, $wpdb->prefix );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT MAX(datetime_start_gmt) AS latest_gmt, MAX(datetime_start) AS latest_local'
					. ' FROM %i WHERE series_post_id = %d',
				$table,
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$latest_gmt = null === $row ? null : $row['latest_gmt'];

		if ( null === $latest_gmt ) {
			return true;
		}

		if ( $this->has_reached_until( $post_id, $end_type, (string) $row['latest_local'] ) ) {
			return false;
		}

		return $latest_gmt < $this->resolve_top_up_cutoff()->format( Event::DATETIME_FORMAT );
	}

	/**
	 * Report whether an `UNTIL`-bounded series has already reached its `until` date.
	 *
	 * Compares only the date portion, since `gatherpress_recurrence_until` is
	 * stored as a bare `Y-m-d` (RFC 5545's `UNTIL` is date-only in this
	 * plugin's authoring model) while `$latest_local` carries a time -- once
	 * the latest projected occurrence's local date is on or after `until`,
	 * `Expander::expand()`'s own `past_until()` guard means nothing further
	 * will ever be produced, so the series is complete rather than stale.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id      Series post ID.
	 * @param string $end_type     One of the `Rule::END_TYPE_*` constants.
	 * @param string $latest_local Latest projected occurrence's local start, `Y-m-d H:i:s`.
	 *
	 * @return bool True when the series is `UNTIL`-bounded and has reached its `until` date.
	 */
	protected function has_reached_until( int $post_id, string $end_type, string $latest_local ): bool {
		if ( Rule::END_TYPE_UNTIL !== $end_type ) {
			return false;
		}

		$until = (string) get_post_meta( $post_id, 'gatherpress_recurrence_until', true );

		if ( '' === $until ) {
			return false;
		}

		return substr( $latest_local, 0, 10 ) >= $until;
	}

	/**
	 * Resolve the cutoff below which a series' latest occurrence counts as stale.
	 *
	 * `TOP_UP_MARGIN_DAYS` before `resolve_horizon()`'s own horizon (measured
	 * from "now" in UTC, since this is not anchored to any one series'
	 * timezone), so a top-up happens with margin to spare rather than only
	 * once the horizon has already been reached.
	 *
	 * @since 0.36.0
	 *
	 * @return DateTimeImmutable The cutoff datetime, in UTC.
	 */
	protected function resolve_top_up_cutoff(): DateTimeImmutable {
		/** This filter is documented in resolve_horizon(). */
		$months = (int) apply_filters( 'gatherpress_recurrence_horizon_months', self::PROJECTION_HORIZON_MONTHS );

		/**
		 * Filters how many days of margin before the horizon a series is topped up.
		 *
		 * @since 0.36.0
		 *
		 * @param int $days Number of days, default `Occurrences::TOP_UP_MARGIN_DAYS`.
		 *
		 * @return int Number of days.
		 */
		$margin_days = (int) apply_filters( 'gatherpress_recurrence_top_up_margin_days', self::TOP_UP_MARGIN_DAYS );

		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		return $now->modify( sprintf( '+%d months', $months ) )->modify( sprintf( '-%d days', $margin_days ) );
	}

	/**
	 * Select series whose projected horizon is running short.
	 *
	 * A maintenance aggregate for the scheduled sweep, distinct from the
	 * event-listing joins in `select_by_horizon()`: this produces
	 * `series_post_id` candidates only, never rows for display, so
	 * `GROUP BY` / `HAVING MAX()` is the correct tool here rather than a
	 * violation of the "never aggregate a result set of event rows" rule.
	 *
	 * Excluded structurally, in SQL, so none of the following is ever a
	 * candidate: a post with no recurrence rule at all (empty `end_type`
	 * mirror -- kept in parity with `is_series_stale()`'s own empty-end-type
	 * branch, since the two predicates disagreeing would silently make an
	 * unrecognized/blank end type a permanent hourly candidate);
	 * `COUNT`-bounded rules, via the `end_type` mirror; and `UNTIL`-bounded
	 * rules whose latest projected occurrence has already reached their
	 * `until` mirror. The `COUNT` and reached-`UNTIL` cases are complete by
	 * design and would otherwise look permanently "stale" (their fixed,
	 * final occurrence only ever falls further behind
	 * `resolve_top_up_cutoff()` as real time passes) and get rewritten by
	 * every sweep forever.
	 *
	 * `ORDER BY MAX( o.datetime_start_gmt ) ASC` rotates the batch by
	 * staleness rather than by `series_post_id`. Without it, `LIMIT` alone
	 * deterministically returns the same subset (MySQL's default row order
	 * for an unordered `GROUP BY` tracks storage/insertion order, i.e. the
	 * lowest post IDs) on every sweep -- a site with more stale candidates
	 * than one batch can starve a newer, more-overdue series behind older,
	 * lower-ID ones indefinitely.
	 *
	 * @since 0.36.0
	 *
	 * @param int $limit Maximum number of series to return.
	 *
	 * @return int[] Series post IDs needing a top-up, most-overdue first.
	 */
	public function select_series_needing_top_up( int $limit ): array {
		global $wpdb;

		$table  = sprintf( self::TABLE_FORMAT, $wpdb->prefix );
		$cutoff = $this->resolve_top_up_cutoff()->format( Event::DATETIME_FORMAT );

		// The joined meta columns are aliased (et_value, until_value) rather
		// than referenced as bare `end_type_meta.meta_value` /
		// `until_meta.meta_value` in GROUP BY and HAVING. A bare reference to
		// a non-aggregate joined column used only in HAVING, once three
		// tables are joined, is rejected as "Unknown column" by both MariaDB
		// and MySQL 8 -- this is not engine-specific, and putting the raw
		// (unaliased) columns in the SELECT list only worked around it
		// because both were literally named `meta_value`, which itself
		// breaks under a site running with ONLY_FULL_GROUP_BY (WordPress
		// strips that mode on connect, but a site filtering
		// `incompatible_sql_modes` back in would silently get an empty
		// candidate list forever). Aliasing costs nothing and is correct
		// under every SQL mode.
		$sql = 'SELECT o.series_post_id,'
			. ' end_type_meta.meta_value AS et_value, until_meta.meta_value AS until_value'
			. ' FROM %i o'
			. ' INNER JOIN %i end_type_meta'
			. ' ON end_type_meta.post_id = o.series_post_id AND end_type_meta.meta_key = %s'
			. ' LEFT JOIN %i until_meta'
			. ' ON until_meta.post_id = o.series_post_id AND until_meta.meta_key = %s'
			. ' WHERE end_type_meta.meta_value != %s AND end_type_meta.meta_value != %s'
			. ' GROUP BY o.series_post_id, et_value, until_value'
			. ' HAVING MAX( o.datetime_start_gmt ) < %s'
			. ' AND ('
			. '     et_value != %s'
			. '     OR until_value IS NULL'
			. '     OR until_value = %s'
			. '     OR DATE( MAX( o.datetime_start ) ) < until_value'
			. ' )'
			. ' ORDER BY MAX( o.datetime_start_gmt ) ASC'
			. ' LIMIT %d';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from %i/%s/%d placeholders only.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				$sql,
				$table,
				$wpdb->postmeta,
				'gatherpress_recurrence_end_type',
				$wpdb->postmeta,
				'gatherpress_recurrence_until',
				Rule::END_TYPE_COUNT,
				'',
				$cutoff,
				Rule::END_TYPE_UNTIL,
				'',
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'intval', $rows );
	}

	/**
	 * Re-project every series whose horizon is running short, up to a batch limit.
	 *
	 * The entry point the scheduled sweep (`Projection_Cron::run_sweep()`)
	 * calls. `project()` is idempotent, so calling it again for a candidate
	 * genuinely extends its horizon without disturbing rows the rule still
	 * produces -- including every past occurrence, which `Expander::expand()`
	 * regenerates identically because it always walks forward from the
	 * series' own anchor, never from "now".
	 *
	 * @since 0.36.0
	 *
	 * @param int $limit Maximum series to top up, or 0 to use the
	 *                    `gatherpress_recurrence_top_up_batch_size` filter default.
	 *
	 * @return int Number of series topped up.
	 */
	public function top_up( int $limit = 0 ): int {
		if ( $limit <= 0 ) {
			/**
			 * Filters the maximum number of series topped up by one scheduled sweep.
			 *
			 * @since 0.36.0
			 *
			 * @param int $batch_size Batch size, default `Occurrences::TOP_UP_BATCH_SIZE`.
			 *
			 * @return int Batch size.
			 */
			$limit = max(
				1,
				(int) apply_filters( 'gatherpress_recurrence_top_up_batch_size', self::TOP_UP_BATCH_SIZE )
			);
		}

		$post_ids = $this->select_series_needing_top_up( $limit );

		foreach ( $post_ids as $post_id ) {
			$this->project( $post_id );
		}

		return count( $post_ids );
	}

	/**
	 * Convert one `select_by_horizon()` result row into an `Occurrence_Ref`.
	 *
	 * @since 0.36.0
	 *
	 * @param array $row Result row keyed `post_id`, `recurrence_id`, `effective_start_gmt`.
	 *
	 * @return Occurrence_Ref The value object carrying occurrence identity.
	 */
	protected function row_to_ref( array $row ): Occurrence_Ref {
		return new Occurrence_Ref(
			(int) $row['post_id'],
			null === $row['recurrence_id'] ? null : (string) $row['recurrence_id'],
			(string) $row['effective_start_gmt']
		);
	}

	/**
	 * Project a series' rule onto occurrence rows.
	 *
	 * Idempotent: upserts on the composite primary key without touching the
	 * `status` of an existing row, and deletes rows the rule no longer
	 * produces. A post that no longer has an expandable rule -- no rule at
	 * all, or an anchor that cannot be resolved -- has its existing rows
	 * cleared rather than left orphaned: `Recurrence\Meta` clears the rule
	 * mirrors the moment a recurrence is removed or its timezone is rejected,
	 * and this method is the only thing that ever deletes the occurrence rows
	 * mirrors used to imply.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID.
	 *
	 * @return int Rows written, or 0 when the post is not recurring.
	 */
	public function project( int $post_id ): int {
		return $this->run_projection( $post_id, true );
	}

	/**
	 * Shared implementation behind `project()` and the deferred-shutdown path.
	 *
	 * `$cleanup_when_not_recurring` exists only for `resolve_pending_projection()`:
	 * a direct `project()` call always cleans up when it finds no rule, matching
	 * this method's own frozen "deletes rows the rule no longer produces"
	 * contract. The deferred path instead passes whether the post *was*
	 * recurring at the moment it was deferred, so an ordinary, never-recurring
	 * event resolves without ever touching the occurrence table (REQ-16) --
	 * `Rule::from_post()` returning null looks identical whether a post never
	 * had a rule or just lost one, so that distinction has to be captured
	 * earlier, in `maybe_project()`, and threaded through.
	 *
	 * @since 0.36.0
	 *
	 * @param int  $post_id                    Series post ID.
	 * @param bool $cleanup_when_not_recurring Whether to delete existing rows when no rule is found.
	 *
	 * @return int Rows written, or 0 when the post is not recurring.
	 */
	protected function run_projection( int $post_id, bool $cleanup_when_not_recurring ): int {
		$resolved = $this->resolve_projectable( $post_id, $cleanup_when_not_recurring );

		if ( null === $resolved ) {
			return 0;
		}

		[ $rule, $anchor_start, $anchor_end, $timezone ] = $resolved;

		$occurrences = $this->expand_or_clear( $rule, $anchor_start, $timezone, $post_id );

		if ( null === $occurrences ) {
			return 0;
		}

		$span = $this->resolve_nominal_span( $anchor_start, $anchor_end );
		$rows = array_map(
			fn( DateTimeImmutable $start ) => $this->build_occurrence_row( $start, $span, $timezone ),
			$occurrences
		);

		return $this->upsert_occurrences( $post_id, $rows );
	}

	/**
	 * Resolve the anchor's nominal wall-clock span, immune to zone contamination.
	 *
	 * `DateTimeImmutable::diff()` on two *zoned* datetimes returns their
	 * elapsed real time decomposed into calendar units, not their wall-clock
	 * difference -- across a DST transition the two disagree, and the elapsed
	 * form silently inflates a nominal span the same way an absolute-seconds
	 * delta does. Stripping the zone before diffing (both sides reconstructed
	 * from their own `Y-m-d H:i:s` string in UTC) makes the result a pure
	 * wall-clock calendar difference: the anchor's own printed digits, never
	 * reinterpreted through a UTC offset.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $anchor_start Series anchor start, in the series timezone.
	 * @param DateTimeImmutable $anchor_end   Series anchor end, in the series timezone.
	 *
	 * @return DateInterval The nominal wall-clock span between the two.
	 */
	protected function resolve_nominal_span(
		DateTimeImmutable $anchor_start,
		DateTimeImmutable $anchor_end
	): DateInterval {
		$utc = new DateTimeZone( 'UTC' );

		$naive_start = new DateTimeImmutable( $anchor_start->format( Event::DATETIME_FORMAT ), $utc );
		$naive_end   = new DateTimeImmutable( $anchor_end->format( Event::DATETIME_FORMAT ), $utc );

		return $naive_start->diff( $naive_end );
	}

	/**
	 * Resolve a post's rule and anchor together, clearing its occurrence rows
	 * when either is missing and `$cleanup` allows it.
	 *
	 * Split out from `run_projection()` so the "nothing to project" bail is a
	 * single `return` there (`php:S1142`), and so the clear-on-removal
	 * behavior lives in one place for both failure modes: no rule at all, and
	 * a rule whose anchor datetime cannot be resolved.
	 *
	 * @since 0.36.0
	 *
	 * @param int  $post_id Post ID to resolve.
	 * @param bool $cleanup Whether to delete existing rows when no rule is found.
	 *
	 * @return array{0: Rule, 1: DateTimeImmutable, 2: DateTimeImmutable, 3: DateTimeZone}|null
	 *              The rule, anchor start, anchor end, and timezone, or null
	 *              when the post has no expandable rule.
	 */
	protected function resolve_projectable( int $post_id, bool $cleanup ): ?array {
		$rule = Rule::from_post( $post_id );

		if ( ! $rule instanceof Rule ) {
			if ( $cleanup ) {
				$this->delete_for_post( $post_id );
			}

			return null;
		}

		$anchor = $this->resolve_anchor( $post_id );

		if ( null === $anchor ) {
			if ( $cleanup ) {
				$this->delete_for_post( $post_id );
			}

			return null;
		}

		return array_merge( array( $rule ), $anchor );
	}

	/**
	 * Resolve a post's anchor start, anchor end, and series timezone for projection.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to resolve the anchor for.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: DateTimeZone}|null
	 *              The anchor start, anchor end, and timezone, or null when the
	 *              timezone string cannot construct a `DateTimeZone` at all, or
	 *              the stored datetime cannot be parsed. Whether the timezone is
	 *              a *named* tz-database identifier is not checked here --
	 *              `expand_or_clear()` is the single source of truth for that,
	 *              via `Expander::expand()`'s own `Timezone_Guard::assert_named()`
	 *              call.
	 */
	protected function resolve_anchor( int $post_id ): ?array {
		$event    = new Event( $post_id );
		$datetime = $event->get_datetime();

		$timezone_name = Utility::normalize_timezone_string( (string) $datetime['timezone'] );

		try {
			// The `gatherpress_timezone` filter runs inside get_datetime()
			// after GatherPress's own validation, so a misbehaving filter can
			// still hand back a string DateTimeZone rejects outright -- that
			// must not fatal the post save.
			$timezone = new DateTimeZone( $timezone_name );
		} catch ( Exception $e ) {
			return null;
		}

		$anchor_start = DateTimeImmutable::createFromFormat(
			Event::DATETIME_FORMAT,
			(string) $datetime['datetime_start'],
			$timezone
		);
		$anchor_end   = DateTimeImmutable::createFromFormat(
			Event::DATETIME_FORMAT,
			(string) $datetime['datetime_end'],
			$timezone
		);

		// A rule's mirrors can exist on a post whose own datetime meta never
		// landed, so the anchor may be unparsable even though Rule::from_post()
		// returned a valid rule.
		if ( false === $anchor_start || false === $anchor_end ) {
			return null;
		}

		return array( $anchor_start, $anchor_end, $timezone );
	}

	/**
	 * Expand a rule, clearing the series' occurrence rows when the timezone
	 * turns out not to be a named tz-database identifier.
	 *
	 * `Expander::expand()` asserts the timezone is named on its first line and
	 * does not catch what it throws -- GatherPress normalizes site/event
	 * timezones through `Utility::maybe_convert_utc_offset()`, and the
	 * `gatherpress_timezone` filter `resolve_anchor()` reads through can also
	 * hand back a fixed offset after GatherPress's own validation has already
	 * passed, so a fixed UTC offset (`+05:30`) reaching here is a live,
	 * reachable configuration, not a hypothetical one. A rule that can no
	 * longer be expanded must not leave stale occurrences behind, matching
	 * `Recurrence\Meta::write_recurrence()`'s own clear-rather-than-fatal
	 * handling of the identical guard.
	 *
	 * @since 0.36.0
	 *
	 * @param Rule              $rule         Rule being expanded.
	 * @param DateTimeImmutable $anchor_start Series anchor start.
	 * @param DateTimeZone      $timezone     Series timezone.
	 * @param int               $post_id      Series post ID, for the clear-on-catch path.
	 *
	 * @return DateTimeImmutable[]|null The expanded occurrences, or null when the timezone was rejected.
	 */
	protected function expand_or_clear(
		Rule $rule,
		DateTimeImmutable $anchor_start,
		DateTimeZone $timezone,
		int $post_id
	): ?array {
		$through = $this->resolve_horizon( $anchor_start, $timezone );

		try {
			return ( new Expander() )->expand( $rule, $anchor_start, $timezone, $through );
		} catch ( InvalidArgumentException $e ) {
			$this->delete_for_post( $post_id );

			return null;
		}
	}

	/**
	 * Resolve the horizon a `never`- or `until`-ending rule is expanded to.
	 *
	 * Measured from `max( $anchor_start, now )` rather than from the anchor
	 * alone, so a long-running series' horizon rolls forward on every
	 * projection instead of staying pinned to its original anchor date --
	 * an anchor-relative horizon would eventually leave a years-old series
	 * projected entirely into the past, with no upcoming occurrences and no
	 * way for a re-save to fix it, since `project()` is a pure function of
	 * rule and anchor. Filterable so a future top-up task (REQ-6) is not
	 * boxed in by a literal.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $anchor_start Series anchor start.
	 * @param DateTimeZone      $timezone     Series timezone, used to read "now".
	 *
	 * @return DateTimeImmutable The horizon datetime.
	 */
	protected function resolve_horizon( DateTimeImmutable $anchor_start, DateTimeZone $timezone ): DateTimeImmutable {
		/**
		 * Filters how many months ahead of "now" an open-ended recurrence rule is projected.
		 *
		 * @since 0.36.0
		 *
		 * @param int $months Number of months, default `Occurrences::PROJECTION_HORIZON_MONTHS`.
		 *
		 * @return int Number of months.
		 */
		$months = (int) apply_filters( 'gatherpress_recurrence_horizon_months', self::PROJECTION_HORIZON_MONTHS );
		$now    = new DateTimeImmutable( 'now', $timezone );
		$from   = $anchor_start > $now ? $anchor_start : $now;

		return $from->modify( sprintf( '+%d months', $months ) );
	}

	/**
	 * Build one occurrence's row values from its expanded start.
	 *
	 * The end time carries the anchor's *nominal* wall-clock span, produced by
	 * `resolve_nominal_span()` and applied here through `modify()` rather than
	 * `DateTimeImmutable::add()`. A nominal 2-hour span starting just before a
	 * fall-back transition must still read "2 hours later" on the clock, even
	 * though 10,800 real seconds elapse. Note that `$span` is *not*
	 * `$anchor_start->diff( $anchor_end )` on the zoned anchors directly --
	 * `diff()` between two zoned datetimes returns their *elapsed* real time,
	 * not their wall-clock difference, and reintroduces the exact inflation
	 * this method exists to avoid whenever the anchor itself spans a
	 * transition.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $start    Occurrence start in the series timezone.
	 * @param DateInterval      $span     Nominal wall-clock event span, from `resolve_nominal_span()`.
	 * @param DateTimeZone      $timezone Series timezone.
	 *
	 * @return array<string, string> Row values keyed as the occurrence table's columns are.
	 */
	protected function build_occurrence_row(
		DateTimeImmutable $start,
		DateInterval $span,
		DateTimeZone $timezone
	): array {
		$modifier = '%R%y years %R%m months %R%d days %R%h hours %R%i minutes %R%s seconds';
		$end      = $start->modify( $span->format( $modifier ) );
		$utc      = new DateTimeZone( 'UTC' );

		return array(
			'recurrence_id'      => self::recurrence_id( $start ),
			'datetime_start'     => $start->format( Event::DATETIME_FORMAT ),
			'datetime_start_gmt' => $start->setTimezone( $utc )->format( Event::DATETIME_FORMAT ),
			'datetime_end'       => $end->format( Event::DATETIME_FORMAT ),
			'datetime_end_gmt'   => $end->setTimezone( $utc )->format( Event::DATETIME_FORMAT ),
			'timezone'           => $timezone->getName(),
		);
	}

	/**
	 * Upsert a series' occurrence rows and delete the ones the rule no longer produces.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $post_id Series post ID.
	 * @param array $rows    Row values built by `build_occurrence_row()`.
	 *
	 * @return int Rows written.
	 */
	protected function upsert_occurrences( int $post_id, array $rows ): int {
		global $wpdb;

		$table = sprintf( self::TABLE_FORMAT, $wpdb->prefix );

		if ( array() !== $rows ) {
			$this->insert_or_update_rows( $table, $post_id, $rows );
		}

		$this->delete_stale_rows( $table, $post_id, wp_list_pluck( $rows, 'recurrence_id' ) );

		return count( $rows );
	}

	/**
	 * Upsert occurrence rows on the composite primary key, never touching `status`.
	 *
	 * `ON DUPLICATE KEY UPDATE` deliberately omits `status`: this is the entire
	 * reason cancellation is a column rather than an `EXDATE` in the rule, and
	 * an existing row's status must survive a rule regeneration untouched.
	 *
	 * @since 0.36.0
	 *
	 * @param string $table   Occurrence table name.
	 * @param int    $post_id Series post ID.
	 * @param array  $rows    Row values built by `build_occurrence_row()`.
	 *
	 * @return void
	 */
	protected function insert_or_update_rows( string $table, int $post_id, array $rows ): void {
		global $wpdb;

		$placeholders = array();
		$values       = array( $table );

		foreach ( $rows as $row ) {
			$placeholders[] = '(%d, %s, %s, %s, %s, %s, %s)';
			$values[]       = $post_id;
			$values[]       = $row['recurrence_id'];
			$values[]       = $row['datetime_start'];
			$values[]       = $row['datetime_start_gmt'];
			$values[]       = $row['datetime_end'];
			$values[]       = $row['datetime_end_gmt'];
			$values[]       = $row['timezone'];
		}

		$sql = 'INSERT INTO %i'
			. ' (series_post_id, recurrence_id, datetime_start, datetime_start_gmt,'
			. ' datetime_end, datetime_end_gmt, timezone)'
			. ' VALUES ' . implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE'
			. ' datetime_start = VALUES(datetime_start),'
			. ' datetime_start_gmt = VALUES(datetime_start_gmt),'
			. ' datetime_end = VALUES(datetime_end),'
			. ' datetime_end_gmt = VALUES(datetime_end_gmt),'
			. ' timezone = VALUES(timezone)';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from %i/%s/%d placeholders only.
		$wpdb->query( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Delete a series' occurrence rows that the current rule no longer produces.
	 *
	 * @since 0.36.0
	 *
	 * @param string   $table          Occurrence table name.
	 * @param int      $post_id        Series post ID.
	 * @param string[] $recurrence_ids Recurrence identifiers the rule currently produces.
	 *
	 * @return void
	 */
	protected function delete_stale_rows( string $table, int $post_id, array $recurrence_ids ): void {
		global $wpdb;

		if ( array() === $recurrence_ids ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE series_post_id = %d', $table, $post_id ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $recurrence_ids ), '%s' ) );
		$sql          = "DELETE FROM %i WHERE series_post_id = %d AND recurrence_id NOT IN ( {$placeholders} )";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		$wpdb->query( $wpdb->prepare( $sql, array_merge( array( $table, $post_id ), $recurrence_ids ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Read one occurrence row by its composite key.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return array|null The row, or null when the composite key matches nothing.
	 */
	public function get( int $post_id, string $recurrence_id ): ?array {
		global $wpdb;

		$table = sprintf( self::TABLE_FORMAT, $wpdb->prefix );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE series_post_id = %d AND recurrence_id = %s',
				$table,
				$post_id,
				$recurrence_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $row ? null : $row;
	}

	/**
	 * Read the occurrences of a series.
	 *
	 * Takes an array of post IDs, never a single ID, so the query emits
	 * `series_post_id IN (…)` and REQ-18 stays reachable.
	 *
	 * @since 0.36.0
	 *
	 * @param int[] $post_ids Post IDs from `Series::resolve_post_ids()`.
	 * @param array $args     Optional query arguments, including `status`.
	 *
	 * @return array The matching occurrence rows.
	 */
	public function select_for_series( array $post_ids, array $args = array() ): array {
		global $wpdb;

		if ( array() === $post_ids ) {
			return array();
		}

		$table        = sprintf( self::TABLE_FORMAT, $wpdb->prefix );
		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
		$sql          = "SELECT * FROM %i WHERE series_post_id IN ( {$placeholders} )";
		$values       = array_merge( array( $table ), $post_ids );

		if ( isset( $args['status'] ) ) {
			$sql     .= ' AND status = %s';
			$values[] = $args['status'];
		}

		$sql .= ' ORDER BY datetime_start_gmt ASC';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $rows ? array() : $rows;
	}

	/**
	 * Set the status of one occurrence.
	 *
	 * Scopes its update by both `series_post_id` and `recurrence_id`. Keying on
	 * `recurrence_id` alone is an authorization hole.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 * @param string $status        One of the `STATUS_*` constants.
	 *
	 * @return bool True when a row was updated, false when the composite key matched nothing.
	 */
	public function set_status( int $post_id, string $recurrence_id, string $status ): bool {
		global $wpdb;

		$table = sprintf( self::TABLE_FORMAT, $wpdb->prefix );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE series_post_id = %d AND recurrence_id = %s',
				$table,
				$post_id,
				$recurrence_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $exists ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s WHERE series_post_id = %d AND recurrence_id = %s',
				$table,
				$status,
				$post_id,
				$recurrence_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return true;
	}

	/**
	 * Delete every occurrence row belonging to one post.
	 *
	 * Deliberately per-post, not per-series -- one rule per event post
	 * (PRD D-5) means every call site (`delete_post`, an expand-failure
	 * clear) only ever needs to clear the post it was handed. A genuine
	 * series-wide delete is a different, not-yet-needed method
	 * (`delete_for_series( array $post_ids )`), added when REQ-18's forward
	 * split actually requires one.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID whose occurrence rows should be removed.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_for_post( int $post_id ): int {
		global $wpdb;

		$table = sprintf( self::TABLE_FORMAT, $wpdb->prefix );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE series_post_id = %d', $table, $post_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
