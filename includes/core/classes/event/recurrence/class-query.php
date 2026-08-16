<?php
/**
 * Occurrence integration with `WP_Query`.
 *
 * Short-circuits entirely when the site has no recurring events (S-6), which is
 * what REQ-16 rests on: an existing site that never authors a recurring event
 * must produce byte-identical SQL and the same query count as before.
 *
 * The join is a `LEFT JOIN`, never an `INNER JOIN` — a non-recurring event has
 * no occurrence row, and an inner join would delete it from every list. The
 * `status = 'scheduled'` predicate lives in the join condition rather than the
 * `WHERE`, and ordering and range predicates use
 * `COALESCE( o.datetime_start_gmt, {events}.datetime_start_gmt )`, which is not
 * sargable and will filesort. That is the accepted trade.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Traits\Singleton;
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
	 * `transition_post_status` alone covers publish, trash, untrash and draft
	 * transitions; the three meta hooks are filtered to the canonical
	 * `gatherpress_recurrence` key so unrelated meta writes do not trigger a
	 * query.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_action( 'transition_post_status', array( $this, 'refresh_has_recurring_events' ) );
		add_action( 'deleted_post', array( $this, 'refresh_has_recurring_events' ) );
		add_action( 'added_post_meta', array( $this, 'maybe_refresh_has_recurring_events' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'maybe_refresh_has_recurring_events' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'maybe_refresh_has_recurring_events' ), 10, 3 );
		add_action( 'import_end', array( $this, 'refresh_has_recurring_events' ) );
	}

	/**
	 * Refresh the has-recurring-events flag when the recurrence rule meta changes.
	 *
	 * Filters `added_post_meta`, `updated_post_meta` and `deleted_post_meta` down
	 * to the canonical `gatherpress_recurrence` key, so writes to unrelated meta
	 * never trigger the recompute query.
	 *
	 * @since 0.36.0
	 *
	 * @param int|int[] $meta_id  Meta ID, or an array of meta IDs for `deleted_post_meta`.
	 * @param int       $post_id  Post ID the meta belongs to.
	 * @param string    $meta_key Meta key that changed.
	 *
	 * @return void
	 */
	public function maybe_refresh_has_recurring_events( $meta_id, $post_id, $meta_key = '' ): void {
		if ( Meta::META_KEY === $meta_key ) {
			self::refresh_has_recurring_events();
		}
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Join the occurrence table into an event query's clauses.
	 *
	 * Filters `posts_clauses` at priority 11, after `Event\Query`'s own
	 * priority-10 filters. Returns the clauses untouched when the site has no
	 * recurring events.
	 *
	 * @since 0.36.0
	 *
	 * @param array    $pieces Query clauses keyed as `WP_Query` supplies them.
	 * @param WP_Query $query  Query being filtered.
	 *
	 * @return array The clauses, modified only for occurrence-aware event queries.
	 */
	public function expand_event_clauses( array $pieces, WP_Query $query ): array {
		return $pieces;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Stamp occurrence identity onto a query's results.
	 *
	 * Filters `the_posts` at priority 10 and handles both a `'fields' => 'ids'`
	 * result set and a full `WP_Post` result set, because the plugin's own read
	 * API requests IDs.
	 *
	 * @since 0.36.0
	 *
	 * @param array    $posts Results, either integers or `WP_Post` objects.
	 * @param WP_Query $query Query being filtered.
	 *
	 * @return array The results, carrying occurrence identity on each entry.
	 */
	public function attach_occurrences( array $posts, WP_Query $query ): array {
		return $posts;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

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
	 * populated by a separate projection step — reading the table here could
	 * observe a rule before its occurrences are projected and write a false
	 * `'0'`, which would hide every recurring event from every query on the
	 * site.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public static function refresh_has_recurring_events(): void {
		global $wpdb;

		// A lifecycle-triggered recompute, not a read path query; caching it
		// would only cache the flag it is itself in the process of producing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
				'gatherpress_recurrence_frequency'
			)
		);

		update_option( self::HAS_RECURRING_OPTION, $has ? '1' : '0', true );
	}
}
