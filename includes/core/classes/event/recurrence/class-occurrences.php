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
 * written as `series_post_id = %d` forecloses REQ-18.
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
use GatherPress\Core\Traits\Singleton;

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

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
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
		return '';
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
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
	 * @param array $args  Optional query arguments.
	 *
	 * @return Occurrence_Ref[] Ordered ascending by start.
	 */
	public function select_upcoming( int $limit, array $args = array() ): array {
		return array();
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Select past occurrences and non-recurring events as one ordered list.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $limit Maximum entries to return.
	 * @param array $args  Optional query arguments.
	 *
	 * @return Occurrence_Ref[] Ordered descending by start.
	 */
	public function select_past( int $limit, array $args = array() ): array {
		return array();
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
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
		return 0;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Read one occurrence row by its composite key.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return array|null The row, or null when the composite key matches nothing.
	 * @phpstan-ignore-next-line -- T0 skeleton; the non-null return lands with the implementation.
	 */
	public function get( int $post_id, string $recurrence_id ): ?array {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
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
		return array();
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
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
		return false;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
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
		return 0;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}
