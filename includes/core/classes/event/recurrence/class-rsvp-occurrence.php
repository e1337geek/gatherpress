<?php
/**
 * Links an RSVP comment to the occurrence it belongs to.
 *
 * The link is a native taxonomy term on the comment, read through the existing
 * `Rsvp\Query::taxonomy_query()` path. Status and provider already use that
 * same mechanism. It is not a mapping table, not
 * comment meta, and not a provisional post ID.
 *
 * The term slug format is produced by exactly one function, `term_slug()`, so
 * a sentinel "all occurrences" slug is a one-line addition later rather
 * than a format change scattered across call sites.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Traits\Singleton;

/**
 * Class Rsvp_Occurrence.
 *
 * Singleton owning the `_gatherpress_occurrence` comment taxonomy.
 *
 * @since 0.36.0
 */
final class Rsvp_Occurrence {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Internal comment taxonomy joining an RSVP to an occurrence.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const TAXONOMY = '_gatherpress_occurrence';

	/**
	 * Class constructor.
	 *
	 * Protected, so the singleton contract is structural rather than
	 * conventional: the class is reached through `get_instance()` and
	 * external construction fails instead of quietly minting a second
	 * instance.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Unimplemented stub; delete with the body.
	/**
	 * Build the term slug for one occurrence.
	 *
	 * The single source of truth for the slug format.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return string The term slug, in `{series_post_id}-{recurrence_id}` form.
	 */
	public static function term_slug( int $post_id, string $recurrence_id ): string {
		return '';
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Unimplemented stub; delete with the body.
	/**
	 * Attach an RSVP comment to an occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $comment_id    RSVP comment ID.
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return bool True when the term was assigned.
	 */
	public function assign( int $comment_id, int $post_id, string $recurrence_id ): bool {
		return false;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Unimplemented stub; delete with the body.
	/**
	 * Build the taxonomy query scoping RSVPs to one occurrence.
	 *
	 * Passed through the existing `Rsvp\Query::get_rsvps()` path, so there is no
	 * new SQL, no new filter, and no table.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return array A `tax_query` clause.
	 */
	public function tax_query( int $post_id, string $recurrence_id ): array {
		return array();
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Unimplemented stub; delete with the body.
	/**
	 * Move occurrence terms from one series post to another.
	 *
	 * The forward-split seam. Unit-tested with no production caller in
	 * the POC.
	 *
	 * @since 0.36.0
	 *
	 * @param int      $from_post_id   Series post ID the occurrences currently belong to.
	 * @param int      $to_post_id     Series post ID they move to.
	 * @param string[] $recurrence_ids Occurrence identifiers to move.
	 *
	 * @return int Terms renamed.
	 */
	public function rename_series( int $from_post_id, int $to_post_id, array $recurrence_ids ): int {
		return 0;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}
