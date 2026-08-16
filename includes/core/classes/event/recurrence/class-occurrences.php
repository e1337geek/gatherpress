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
 * `set_status()`, `delete_for_series()`, `get()`) operate on exactly one
 * post's own rows, so `series_post_id = %d` there is correct rather than a
 * violation — C-2 governs series-wide reads, not single-post writes.
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

use DateTimeImmutable;
use DateTimeZone;
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
	 * How many years ahead of its anchor an open-ended rule is projected.
	 *
	 * A `never`-ending rule has no natural horizon, so one is imposed here
	 * rather than expanding toward `Expander::MAX_ITERATIONS`. Two years
	 * keeps a single projection pass cheap while comfortably covering any
	 * reasonable "upcoming events" window; re-saving the post re-projects
	 * and slides the horizon forward. A `count`-bounded rule ignores this
	 * value entirely (`Expander::expand()` bounds those by count, not by
	 * horizon), and an `until`-bounded rule's own end date is always nearer
	 * than this horizon since `Rule::MAX_COUNT` and `Rule::MAX_INTERVAL`
	 * cap how far a `count` rule can reach, not how far an `until` date may
	 * be set.
	 *
	 * @since 0.36.0
	 * @var int
	 */
	const PROJECTION_HORIZON_YEARS = 2;

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
	 * @since 0.36.0
	 * @var array<int, bool>
	 */
	protected array $pending_projection = array();

	/**
	 * Class constructor.
	 *
	 * @since 0.36.0
	 */
	public function __construct() {
		$this->setup_hooks();
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
		add_action( 'delete_post', array( $this, 'maybe_delete_for_series' ) );
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

		$this->pending_projection[ $post_id ] = true;

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

		foreach ( array_keys( $pending ) as $post_id ) {
			// The post can be gone by shutdown -- a duplicate that failed, or
			// an insert rolled back after this hook ran.
			if ( ! post_type_supports( (string) get_post_type( $post_id ), 'gatherpress-event-date' ) ) {
				continue;
			}

			$this->project( $post_id );
		}
	}

	/**
	 * Delete a series' occurrence rows when its post is hard-deleted, if supported.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID being deleted.
	 *
	 * @return void
	 */
	public function maybe_delete_for_series( int $post_id ): void {
		if ( ! post_type_supports( (string) get_post_type( $post_id ), 'gatherpress-event-date' ) ) {
			return;
		}

		$this->delete_for_series( $post_id );
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
	 * occurrence row) still produces one result via
	 * `COALESCE( o.datetime_start_gmt, events.datetime_start_gmt )`, while a
	 * recurring series produces one result per occurrence row. The `status`
	 * predicate lives in the join condition, never in `WHERE`, so a fully
	 * cancelled series does not fall back through the `NULL` branch and
	 * reappear under its original anchor date.
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

		$sql = 'SELECT %i.ID AS post_id, %i.recurrence_id AS recurrence_id,'
			. ' COALESCE( %i.datetime_start_gmt, %i.datetime_start_gmt ) AS effective_start_gmt'
			. ' FROM %i'
			. ' LEFT JOIN %i ON %i.ID = %i.post_id'
			. ' LEFT JOIN %i ON %i.ID = %i.series_post_id AND %i.status = %s'
			. " WHERE %i.post_type IN ( {$type_placeholders} ) AND %i.post_status = %s"
			. " HAVING effective_start_gmt {$comparison} %s"
			. " ORDER BY effective_start_gmt {$order}"
			. ' LIMIT %d';

		$values = array_merge(
			array(
				$wpdb->posts,
				$occurrences_table,
				$occurrences_table,
				$events_table,
				$wpdb->posts,
				$events_table,
				$wpdb->posts,
				$events_table,
				$occurrences_table,
				$wpdb->posts,
				$occurrences_table,
				$occurrences_table,
				$status,
				$wpdb->posts,
			),
			$post_types,
			array(
				$wpdb->posts,
				'publish',
				current_time( 'mysql', true ),
				$limit,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from %i/%s/%d placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'row_to_ref' ), null === $rows ? array() : $rows );
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
	 * `status` of an existing row, and deletes rows the rule no longer produces.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID.
	 *
	 * @return int Rows written, or 0 when the post is not recurring.
	 */
	public function project( int $post_id ): int {
		$rule = Rule::from_post( $post_id );

		if ( ! $rule instanceof Rule ) {
			return 0;
		}

		$anchor = $this->resolve_anchor( $post_id );

		if ( null === $anchor ) {
			return 0;
		}

		[ $anchor_start, $anchor_end, $timezone ] = $anchor;

		$occurrences = $this->expand_or_clear( $rule, $anchor_start, $timezone, $post_id );

		if ( null === $occurrences ) {
			return 0;
		}

		$duration = $anchor_end->getTimestamp() - $anchor_start->getTimestamp();
		$rows     = array_map(
			fn( DateTimeImmutable $start ) => $this->build_occurrence_row( $start, $duration, $timezone ),
			$occurrences
		);

		return $this->upsert_occurrences( $post_id, $rows );
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
	 *              stored datetime cannot be parsed. The timezone string itself
	 *              is not validated here -- `expand_or_clear()` is the single
	 *              source of truth for that, via `Expander::expand()`'s own
	 *              `Timezone_Guard::assert_named()` call.
	 */
	protected function resolve_anchor( int $post_id ): ?array {
		$event    = new Event( $post_id );
		$datetime = $event->get_datetime();

		$timezone_name = Utility::normalize_timezone_string( (string) $datetime['timezone'] );
		$timezone      = new DateTimeZone( $timezone_name );

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
	 * timezones through `Utility::maybe_convert_utc_offset()`, so a fixed UTC
	 * offset (`+05:30`) reaching here is a live, reachable configuration, not
	 * a hypothetical one. A rule that can no longer be expanded must not leave
	 * stale occurrences behind, matching `Recurrence\Meta::write_recurrence()`'s
	 * own clear-rather-than-fatal handling of the identical guard.
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
		$through = $anchor_start->modify( sprintf( '+%d years', self::PROJECTION_HORIZON_YEARS ) );

		try {
			return ( new Expander() )->expand( $rule, $anchor_start, $timezone, $through );
		} catch ( InvalidArgumentException $e ) {
			$this->delete_for_series( $post_id );

			return null;
		}
	}

	/**
	 * Build one occurrence's row values from its expanded start.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $start    Occurrence start in the series timezone.
	 * @param int               $duration Event duration in seconds, from the series anchor.
	 * @param DateTimeZone      $timezone Series timezone.
	 *
	 * @return array<string, string> Row values keyed as the occurrence table's columns are.
	 */
	protected function build_occurrence_row( DateTimeImmutable $start, int $duration, DateTimeZone $timezone ): array {
		$end = $start->modify( sprintf( '%+d seconds', $duration ) );
		$utc = new DateTimeZone( 'UTC' );

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
	 * Delete every occurrence row belonging to a series.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_for_series( int $post_id ): int {
		global $wpdb;

		$table = sprintf( self::TABLE_FORMAT, $wpdb->prefix );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE series_post_id = %d', $table, $post_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
