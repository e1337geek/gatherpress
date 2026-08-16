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
	 * `Meta::META_KEY` blob, because it is the key another lane's rule-meta
	 * derivation writes last, after the canonical blob and every other mirror.
	 * The lifecycle hooks below watch both keys for exactly this reason: a
	 * write to the canonical key alone can fire before this mirror exists.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const FREQUENCY_META_KEY = 'gatherpress_recurrence_frequency';

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
	 * revision, and unrelated post type it touches — `import_end` already
	 * sweeps once per import for the bulk case. The three meta hooks watch
	 * both `Meta::META_KEY` and `FREQUENCY_META_KEY`, because the two are
	 * written by separate statements in another lane's rule-meta derivation
	 * and either can be the one whose write completes a not-yet-recurring or
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
	}

	/**
	 * Refresh the has-recurring-events flag when a supported post's status changes.
	 *
	 * Covers publish, trash, untrash and draft transitions in one hook. Scoped
	 * to post types declaring `gatherpress-event-date` support, so attachments,
	 * revisions, and unrelated post types never trigger the recompute query.
	 *
	 * @since 0.36.0
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
	 * @since 0.36.0
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
	 * @since 0.36.0
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
				"SELECT 1 FROM %i WHERE meta_key = %s AND meta_value != '' LIMIT 1",
				$wpdb->postmeta,
				self::FREQUENCY_META_KEY
			)
		);

		update_option( self::HAS_RECURRING_OPTION, $has ? '1' : '0', true );
	}
}
