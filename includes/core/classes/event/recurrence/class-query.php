<?php
/**
 * Occurrence integration with `WP_Query`.
 *
 * Short-circuits entirely when the site has no recurring events: an existing
 * site that never authors a recurring event must produce byte-identical SQL and
 * the same query count as before.
 *
 * The join is a `LEFT JOIN`, never an `INNER JOIN`. A non-recurring event has
 * no occurrence row, so an inner join would delete it from every list. The
 * `status = 'scheduled'` predicate lives in the join condition rather than the
 * `WHERE`, and ordering and range predicates use
 * `COALESCE( o.datetime_start_gmt, {events}.datetime_start_gmt )`, which is not
 * sargable and will filesort. That is the accepted trade.
 *
 * Two scope limits are deliberate and worth knowing before extending this:
 *
 * - A `'fields' => 'ids'` query is never expanded. `WP_Query` returns before
 *   `the_posts` for that shape, so identity cannot ride along, and the one
 *   production consumer — `Calendar\Setup::get_ical_list()` via
 *   `Event\Query::get_events_list()` — emits one VEVENT per entry from the
 *   anchor datetime. Expanding it produced duplicate VEVENTs sharing a UID.
 *   Occurrence-aware reads go through `Occurrences::select_upcoming()`.
 * - Only queries carrying an upcoming/past bucket are expanded, because only
 *   those have the events-table join `COALESCE()` falls back to. A plain Query
 *   Loop over an event post type therefore still renders one entry per series,
 *   at its anchor date. That is an MVP limitation of this task.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Event;
use GatherPress\Core\Traits\Singleton;
use WP_Post;
use WP_Query;

/**
 * Class Query.
 *
 * Singleton owning the occurrence-aware clause and result filters.
 *
 * @since 0.36.0
 */
final class Query {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Autoloaded option recording whether the site has any recurring events.
	 *
	 * Recomputed authoritatively from storage on every lifecycle event. Never a
	 * query on the read path, and never an incrementing counter.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const HAS_RECURRING_OPTION = 'gatherpress_has_recurring_events';

	/**
	 * Read-only derived mirror meta key holding a rule's frequency.
	 *
	 * `refresh_has_recurring_events()` reads this key rather than the canonical
	 * `Meta::META_KEY` blob, because this mirror is written by the rule-meta
	 * derivation in `Meta::set_recurrence()` (via `Meta::write_mirrors()` in
	 * `class-meta.php`), which runs only after the canonical blob has landed
	 * and decoded into a valid, expandable rule. The lifecycle hooks below
	 * watch both keys for exactly this reason: a write to the canonical key
	 * alone can fire before this mirror exists.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const FREQUENCY_META_KEY = 'gatherpress_recurrence_frequency';

	/**
	 * Post statuses that never count as a live recurring event.
	 *
	 * WordPress retains post meta on trash, so a status-blind recompute keeps
	 * counting a trashed series and the sweep never learns the last recurrence
	 * is gone. Trash and auto-draft are excluded because neither is a live
	 * authoring state. Every other status, drafts and custom statuses
	 * included, deliberately stays active: their projections are still
	 * maintained by the sweep, and the read path applies its own `publish`
	 * filter separately.
	 *
	 * Shared with `Occurrences::select_series_needing_top_up()`, so the flag
	 * recompute and the top-up candidate selector always agree on what counts
	 * as live.
	 *
	 * @since 0.36.0
	 * @var string[]
	 */
	const INACTIVE_POST_STATUSES = array( 'trash', 'auto-draft' );

	/**
	 * SQL alias the occurrence table is joined under.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const OCCURRENCE_ALIAS = 'gatherpress_occurrence';

	/**
	 * Prefix every occurrence column is aliased under in an expanded SELECT.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const SELECT_ALIAS_PREFIX = 'gatherpress_occurrence_';

	/**
	 * SQL alias carrying `recurrence_id` back on a full result set's rows.
	 *
	 * A raw column artifact rather than the published API: `attach_occurrences()`
	 * is what turns it into `RESULT_PROPERTY`, so nothing downstream depends on
	 * the shape the database happened to hand back.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const SELECT_ALIAS = self::SELECT_ALIAS_PREFIX . 'recurrence_id';

	/**
	 * Property carrying occurrence identity on each result object.
	 *
	 * Identity travels on the object, never by list position (PRD C-1). A null
	 * value means the entry is a non-recurring event.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const RESULT_PROPERTY = 'gatherpress_recurrence_id';

	/**
	 * Property carrying an occurrence's own datetime columns on each result object.
	 *
	 * The companion to `RESULT_PROPERTY`, and what makes the render path
	 * readable without a second query: identity alone would force every
	 * consumer to fetch the row it came from, once per loop iteration. The
	 * values ride along on the same object the identity does, so
	 * `Context::loop_occurrence()` is pure property access.
	 *
	 * PRD C-3 is why the *columns* travel rather than just the date: an
	 * occurrence's time of day is read from the occurrence record, never
	 * recomposed from the anchor's time.
	 *
	 * A null value means the entry is a non-recurring event.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const RESULT_DATETIME_PROPERTY = 'gatherpress_occurrence_datetime';

	/**
	 * Class constructor.
	 *
	 * This method initializes the object and sets up necessary hooks.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Set up hooks for various purposes.
	 *
	 * Every lifecycle path that can change whether any post carries a
	 * recurrence rule recomputes the `HAS_RECURRING_OPTION` flag from storage.
	 * `transition_post_status` and `deleted_post` are scoped to posts whose
	 * post type declares `gatherpress-event-date` support, so a WXR import or
	 * an editor save does not pay a `wp_postmeta` query for every attachment,
	 * revision, and unrelated post type it touches. `import_end` already
	 * sweeps once per import for the bulk case. The three meta hooks watch
	 * both `Meta::META_KEY` and `FREQUENCY_META_KEY`, because the two are
	 * written by separate statements: the save request stores the canonical
	 * blob, and `Meta::set_recurrence()` then derives the mirror from it.
	 * Either write can be the one that completes a not-yet-recurring or
	 * no-longer-recurring transition.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_action(
			'transition_post_status',
			array( $this, 'maybe_refresh_has_recurring_events_for_transition' ),
			10,
			3
		);
		add_action( 'deleted_post', array( $this, 'maybe_refresh_has_recurring_events_for_deleted_post' ), 10, 2 );
		add_action( 'added_post_meta', array( $this, 'maybe_refresh_has_recurring_events_for_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'maybe_refresh_has_recurring_events_for_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'maybe_refresh_has_recurring_events_for_meta' ), 10, 3 );
		add_action( 'import_end', array( $this, 'refresh_has_recurring_events' ) );
		// Priority 11, strictly after Event\Query's own priority-10 clause
		// filters, so the events table is already joined and its ordering and
		// range predicates are there to be rewritten.
		add_filter( 'posts_clauses', array( $this, 'expand_event_clauses' ), 11, 2 );
		add_filter( 'the_posts', array( $this, 'attach_occurrences' ), 10, 2 );
	}

	/**
	 * Refresh the has-recurring-events flag when a supported post's status changes.
	 *
	 * Covers publish, trash, untrash and draft transitions in one hook. Scoped
	 * to post types declaring `gatherpress-event-date` support, so attachments,
	 * revisions, and unrelated post types never trigger the recompute query.
	 *
	 * `$new_status` and `$old_status` are required by WordPress'
	 * `transition_post_status` signature and are deliberately unread: the flag
	 * is recomputed from the database, so which way the status moved does not
	 * change the answer.
	 *
	 * @since 0.36.0
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post whose status changed.
	 *
	 * @return void
	 */
	public function maybe_refresh_has_recurring_events_for_transition( $new_status, $old_status, WP_Post $post ): void {
		if ( post_type_supports( $post->post_type, 'gatherpress-event-date' ) ) {
			self::refresh_has_recurring_events();
		}
	}

	/**
	 * Refresh the has-recurring-events flag when a supported post is hard-deleted.
	 *
	 * Scoped the same way as `maybe_refresh_has_recurring_events_for_transition()`.
	 *
	 * `$post_id` is required by WordPress' `deleted_post` signature and is
	 * deliberately unread: the row is already gone by the time this runs, so
	 * the flag is recomputed rather than adjusted for one post.
	 *
	 * @since 0.36.0
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param int     $post_id Post ID that was deleted.
	 * @param WP_Post $post    The deleted post.
	 *
	 * @return void
	 */
	public function maybe_refresh_has_recurring_events_for_deleted_post( $post_id, WP_Post $post ): void {
		if ( post_type_supports( $post->post_type, 'gatherpress-event-date' ) ) {
			self::refresh_has_recurring_events();
		}
	}

	/**
	 * Refresh the has-recurring-events flag when the recurrence rule meta changes.
	 *
	 * Filters `added_post_meta`, `updated_post_meta` and `deleted_post_meta` down
	 * to the canonical `Meta::META_KEY` blob and the `FREQUENCY_META_KEY` mirror,
	 * so writes to unrelated meta never trigger the recompute query, and a write
	 * to either half of the pair still catches a transition the other half's
	 * write alone could miss.
	 *
	 * `$meta_id` and `$post_id` are required by WordPress' `added_post_meta`,
	 * `updated_post_meta` and `deleted_post_meta` signatures and are
	 * deliberately unread: the flag is a site-wide recompute, so the key that
	 * changed decides whether to run and the post it belongs to does not.
	 *
	 * @since 0.36.0
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param int|int[] $meta_id  Meta ID, or an array of meta IDs for `deleted_post_meta`.
	 * @param int       $post_id  Post ID the meta belongs to.
	 * @param string    $meta_key Meta key that changed.
	 *
	 * @return void
	 */
	public function maybe_refresh_has_recurring_events_for_meta( $meta_id, $post_id, $meta_key = '' ): void {
		if ( in_array( $meta_key, array( Meta::META_KEY, self::FREQUENCY_META_KEY ), true ) ) {
			self::refresh_has_recurring_events();
		}
	}

	/**
	 * Join the occurrence table into an event query's clauses.
	 *
	 * Filters `posts_clauses` at priority 11, after `Event\Query`'s own
	 * priority-10 filters.
	 *
	 * The join is a `LEFT JOIN` with `status` in the join condition, never an
	 * `INNER JOIN` and never `status` in the `WHERE`: either shape deletes
	 * every non-recurring event from every list. That alone is not sufficient
	 * though -- a series whose occurrences are all cancelled matches no join
	 * row, falls through with `NULL` occurrence columns, and would reappear at
	 * its anchor date as an ordinary event. The `NOT EXISTS` guard scopes that
	 * `NULL` fallback to posts with **no occurrence rows at all**, so a series
	 * with rows contributes only its scheduled ones while a non-recurring post
	 * contributes its single anchor row.
	 *
	 * The guard reads the occurrence table, matching
	 * `Occurrences::select_by_horizon()`, and deliberately not the rule
	 * mirror. Keying on the mirror instead asks "does this post have a rule",
	 * which is `true` for a post whose rule has not projected any rows yet, or
	 * whose rule legitimately produces none -- an `until` that precedes the
	 * anchor, reachable by editing either end of that pair. Such a post would
	 * match no join row *and* be denied the `NULL` fallback, disappearing from
	 * every list, the archive, and the admin screen while still being a
	 * published event. Hiding a real event is strictly worse than showing it
	 * at its anchor date, so the fallback keys on rows. It is a `NOT EXISTS`
	 * semi-join rather than a `LEFT JOIN` because a join can multiply rows
	 * where a semi-join cannot.
	 *
	 * Ordering and range predicates are rewritten to
	 * `COALESCE( occurrence, anchor )`, which is not sargable and will
	 * filesort. That is measured and accepted.
	 *
	 * @since 0.36.0
	 *
	 * @param array    $pieces Query clauses keyed as `WP_Query` supplies them.
	 * @param WP_Query $query  Query being filtered.
	 *
	 * @return array The clauses, unmodified by this stub.
	 */
	public function expand_event_clauses( array $pieces, WP_Query $query ): array {
		global $wpdb;

		$events_table = sprintf( Event::TABLE_FORMAT, $wpdb->prefix );

		// Arm 1 is REQ-16: a site that never authors a recurring event runs
		// byte-identical SQL and pays nothing.
		//
		// Arm 2 exempts an `'ids'` result set from expansion entirely, not
		// merely from the SELECT alias. `WP_Query` returns before `the_posts`
		// for that field shape, so occurrence identity cannot travel with the
		// rows, and an expanded ids list is a repeated bare post ID its caller
		// cannot disambiguate. `Calendar\Setup::get_ical_list()` is the live
		// consumer of `Event\Query::get_events_list()`: it emits one VEVENT per
		// entry, read from the post's *anchor* datetime, so expanding that
		// query yields duplicate VEVENTs sharing one UID -- which RFC 5545
		// forbids -- and burns the `posts_per_page` budget on them. The
		// occurrence-aware read API for GatherPress's own lists is the
		// additive `Occurrences::select_upcoming()`, which returns
		// `Occurrence_Ref[]` and carries identity on the object.
		//
		// Arm 3 exempts admin queries for the same reason as arm 2, one layer
		// up: `edit.php` is a post-management screen. Its rows carry
		// Edit/Trash/View actions and bulk-action checkboxes keyed by post ID,
		// and `Event\Admin_List` renders its columns from the post. Expanding
		// it would emit one indistinguishable row per occurrence -- roughly
		// fifty for a weekly series projected to the twelve-month horizon --
		// pushing every other event off the screen and making a bulk action on
		// "a row" act on the whole series. Per-occurrence management belongs to
		// a dedicated series screen, not to the generic post list.
		//
		// Arms 2 and 3 are one rule: expand only where occurrence identity can
		// travel with the row.
		//
		// Arm 4 scopes the filter to queries `Event\Query` has already joined
		// the events table onto -- the only case with an anchor column for
		// `COALESCE()` to fall back to. A plain Query Loop over an event post
		// type sets no upcoming/past bucket, so it gets no events join and is
		// not expanded: it shows one entry per series, at the series anchor
		// date. That is a known MVP limitation of this task, not an oversight.
		//
		// Arm 5 is the multisite contract, and it has to be a positive check
		// rather than error handling downstream. `$wpdb` swallows a
		// missing-table error and `get_results()` returns `array()`, never
		// `null`, so there is no failure value for a caller to branch on. The
		// missing table is named in both the `LEFT JOIN` and the `NOT EXISTS`
		// subquery, so the whole statement fails and an ordinary,
		// non-recurring published event silently disappears from the list with
		// nothing reported anywhere -- worse than a crash, because a crash gets
		// reported. Not expanding at all is what makes the stated contract
		// true: a blog without the table shows exactly what it would show with
		// no recurrence code present. It is deliberately the last arm, so a
		// site with no recurring events never pays for the check and REQ-16's
		// byte-identical SQL is unaffected.
		if (
			! self::site_has_recurring_events()
			|| 'ids' === $query->get( 'fields' )
			|| is_admin()
			|| ! str_contains( (string) $pieces['join'], $events_table )
			|| ! Occurrences::get_instance()->table_exists()
		) {
			return $pieces;
		}

		$alias             = self::OCCURRENCE_ALIAS;
		$rows_alias        = $alias . '_rows';
		$occurrences_table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );

		$pieces['join'] .= $wpdb->prepare(
			' LEFT JOIN %i AS %i ON %i.ID = %i.series_post_id AND %i.status = %s',
			$occurrences_table,
			$alias,
			$wpdb->posts,
			$alias,
			$alias,
			Occurrences::STATUS_SCHEDULED
		);

		$pieces['orderby'] = $this->coalesce_event_columns( (string) $pieces['orderby'], $events_table );
		$pieces['where']   = $this->coalesce_event_columns( (string) $pieces['where'], $events_table )
			. $wpdb->prepare(
				' AND ( %i.series_post_id IS NOT NULL OR NOT EXISTS ('
				. ' SELECT 1 FROM %i AS %i WHERE %i.series_post_id = %i.ID ) )',
				$alias,
				$occurrences_table,
				$rows_alias,
				$rows_alias,
				$wpdb->posts
			);

		// WordPress groups on the post ID whenever a tax_query or meta_query
		// can duplicate rows. Collapsing on the post ID alone would collapse
		// every occurrence of a series into one entry, so the group widens to
		// the canonical list key -- the `(post_id, recurrence_id)` tuple --
		// which de-duplicates in SQL, before LIMIT and before FOUND_ROWS.
		if ( '' !== (string) $pieces['groupby'] ) {
			$pieces['groupby'] .= $wpdb->prepare( ', %i.recurrence_id', $alias );
		}

		$pieces['fields'] .= $wpdb->prepare( ', %i.recurrence_id AS %i', $alias, self::SELECT_ALIAS );

		// The occurrence's own datetime columns travel with the row so the
		// render path can read them off the result object rather than
		// re-querying once per loop iteration. Selecting them costs nothing
		// beyond the bytes -- the row is already joined and already read.
		foreach ( Context::META_KEY_COLUMNS as $column ) {
			$pieces['fields'] .= $wpdb->prepare(
				', %i.%i AS %i',
				$alias,
				$column,
				self::SELECT_ALIAS_PREFIX . $column
			);
		}

		return $pieces;
	}

	/**
	 * Rewrite the anchor datetime columns of one clause as `COALESCE()` fallbacks.
	 *
	 * `Event\Query` writes the ORDER BY column unquoted and the WHERE column
	 * back-quoted, because the latter goes through `$wpdb->prepare()`'s `%i`
	 * placeholder, so both renderings have to be rewritten. Comparing on
	 * `COALESCE( occurrence, anchor )` is what keeps
	 * `Event\Query::get_datetime_comparison_column()`'s "is a running event
	 * still upcoming" semantics intact for occurrences.
	 *
	 * @since 0.36.0
	 *
	 * @param string $clause       One SQL clause, either `orderby` or `where`.
	 * @param string $events_table Unprefixed-format events table name.
	 *
	 * @return string The clause with both anchor columns wrapped.
	 */
	private function coalesce_event_columns( string $clause, string $events_table ): string {
		$alias   = self::OCCURRENCE_ALIAS;
		$search  = array();
		$replace = array();

		foreach ( array( 'datetime_start_gmt', 'datetime_end_gmt' ) as $column ) {
			$coalesce  = sprintf( 'COALESCE( %s.%s, %s.%s )', $alias, $column, $events_table, $column );
			$search[]  = sprintf( '%s.%s', $events_table, $column );
			$replace[] = $coalesce;
			$search[]  = sprintf( '`%s`.`%s`', $events_table, $column );
			$replace[] = $coalesce;
		}

		return str_replace( $search, $replace, $clause );
	}

	/**
	 * Stamp occurrence identity onto a query's results.
	 *
	 * Filters `the_posts` at priority 10. Every result set reaching this filter
	 * is a list of `WP_Post` objects -- `WP_Query` returns before `the_posts`
	 * for both the `'ids'` and the `'id=>parent'` field shapes -- so the
	 * plugin's own `Event\Query::get_events_list()` contract is untouched, and
	 * `Occurrences::select_upcoming()` remains the occurrence-aware read API
	 * for GatherPress's own lists.
	 *
	 * @since 0.36.0
	 *
	 * @param array    $posts Results as `WP_Post` objects.
	 * @param WP_Query $query Query being filtered.
	 *
	 * @return array The results, unmodified by this stub.
	 */
	public function attach_occurrences( array $posts, WP_Query $query ): array {
		if ( ! self::site_has_recurring_events() || ! $this->is_event_query( $query ) ) {
			return $posts;
		}

		return array_map( array( $this, 'stamp_occurrence' ), $posts );
	}

	/**
	 * Report whether a query asks for a post type that supports event dates.
	 *
	 * Scoped through `get_post_types_by_support()` rather than against a
	 * hardcoded post type slug, because recurrence belongs to the
	 * `gatherpress-event-date` support.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_Query $query Query being filtered.
	 *
	 * @return bool True when the query targets at least one event post type.
	 */
	private function is_event_query( WP_Query $query ): bool {
		$requested = array_filter( array_map( 'strval', (array) $query->get( 'post_type' ) ) );

		return array() !== array_intersect( $requested, get_post_types_by_support( 'gatherpress-event-date' ) );
	}

	/**
	 * Publish one result row's occurrence identity onto a clone of it.
	 *
	 * The clone is the point, and it is load-bearing on the path that is easy
	 * to overlook. `WP_Query` calls `update_post_caches()` only when
	 * `$is_unfiltered_query` holds *and* `$unfiltered_posts === $this->posts`.
	 * On an expanded query the appended `fields` column already falsifies the
	 * first half -- but `attach_occurrences()` also runs on event queries where
	 * `expand_event_clauses()` bailed (a plain Query Loop, which gets no events
	 * join), and there `fields` is untouched and `$is_unfiltered_query` is
	 * `true`. Stamping in place would keep the identity comparison true and
	 * send occurrence-stamped `WP_Post` objects into the shared post cache for
	 * every later reader of those IDs. Returning fresh objects breaks the
	 * comparison, so core takes `_prime_post_caches()` instead.
	 *
	 * Do not "simplify" this to an in-place assignment.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_Post $post One result row.
	 *
	 * @return WP_Post A clone carrying `RESULT_PROPERTY` and `RESULT_DATETIME_PROPERTY`.
	 */
	private function stamp_occurrence( WP_Post $post ): WP_Post {
		$values     = get_object_vars( $post );
		$identifier = $values[ self::SELECT_ALIAS ] ?? null;
		$datetime   = array();

		unset( $values[ self::SELECT_ALIAS ] );

		// The raw aliases are consumed here and never published, matching how
		// `SELECT_ALIAS` is consumed: nothing downstream depends on the shape
		// the database happened to hand back.
		foreach ( Context::META_KEY_COLUMNS as $column ) {
			$column_alias = self::SELECT_ALIAS_PREFIX . $column;

			$datetime[ $column ] = $values[ $column_alias ] ?? null;

			unset( $values[ $column_alias ] );
		}

		$values[ self::RESULT_PROPERTY ]          = ( null === $identifier ) ? null : (string) $identifier;
		$values[ self::RESULT_DATETIME_PROPERTY ] = ( null === $identifier ) ? null : $datetime;

		return new WP_Post( (object) $values );
	}

	/**
	 * Report whether the site has any recurring events.
	 *
	 * @since 0.36.0
	 *
	 * @return bool True when at least one event carries a recurrence rule.
	 */
	public static function site_has_recurring_events(): bool {
		return '1' === get_option( self::HAS_RECURRING_OPTION, '0' );
	}

	/**
	 * Recompute the has-recurring-events option from storage.
	 *
	 * Authoritative rather than incremental: the option is derived from what is
	 * stored, so a lost or duplicated lifecycle event cannot desynchronize it.
	 * Reads the rule meta rather than the occurrence table, because the meta is
	 * written the moment a rule is saved while the occurrence table is
	 * populated by a separate projection step. Reading the table here could
	 * observe a rule before its occurrences are projected and write a false
	 * `'0'`, which would hide every recurring event from every query on the
	 * site.
	 *
	 * The meta count is joined to `wp_posts` and scoped away from
	 * `INACTIVE_POST_STATUSES`, because WordPress keeps post meta on trash:
	 * without the join, trashing the site's last recurring event leaves the
	 * frequency mirror in `wp_postmeta` and the flag stuck at `'1'` forever.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public static function refresh_has_recurring_events(): void {
		global $wpdb;

		$status_placeholders = implode( ', ', array_fill( 0, count( self::INACTIVE_POST_STATUSES ), '%s' ) );

		$sql = 'SELECT 1 FROM %i frequency_meta'
			. ' INNER JOIN %i live_post ON live_post.ID = frequency_meta.post_id'
			. " WHERE frequency_meta.meta_key = %s AND frequency_meta.meta_value != ''"
			. " AND live_post.post_status NOT IN ( {$status_placeholders} )"
			. ' LIMIT 1';

		// A lifecycle-triggered recompute, not a read path query; caching it
		// would only cache the flag it is itself in the process of producing.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from %i/%s placeholders only.
		$has = (bool) $wpdb->get_var(
			$wpdb->prepare(
				$sql,
				array_merge(
					array( $wpdb->postmeta, $wpdb->posts, self::FREQUENCY_META_KEY ),
					self::INACTIVE_POST_STATUSES
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		update_option( self::HAS_RECURRING_OPTION, $has ? '1' : '0', true );
	}
}
