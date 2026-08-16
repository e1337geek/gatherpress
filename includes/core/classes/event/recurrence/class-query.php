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
		return false;
	}

	/**
	 * Recompute the has-recurring-events option from storage.
	 *
	 * Authoritative rather than incremental: the option is derived from what is
	 * stored, so a lost or duplicated lifecycle event cannot desynchronize it.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public static function refresh_has_recurring_events(): void {
	}
}
