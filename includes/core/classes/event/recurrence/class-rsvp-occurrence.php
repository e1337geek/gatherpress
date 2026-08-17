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
use WP_Term;

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
	 * Nothing to hook: the `delete_comment` relationship cleanup this class
	 * used to own cleans all three RSVP comment taxonomies, only one of which
	 * is about recurrence, and now lives on `Rsvp\Cleanup` alongside the
	 * hard-delete cron it belongs with. The constructor stays declared and
	 * `protected` so `get_instance()` remains the only way to build one.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
	}

	/**
	 * Build the term slug for one occurrence.
	 *
	 * The single source of truth for the slug format. The composite is passed
	 * through `sanitize_title()` so the value this returns is byte-identical to
	 * what WordPress stores in `wp_terms.slug` — a caller can look a term up by
	 * this string without a second sanitization step, and the assigned and
	 * queried slugs cannot drift apart.
	 *
	 * The series post ID prefix is what makes a collision structurally
	 * impossible: two series can share a recurrence identifier, but not a post
	 * ID.
	 *
	 * Both identifiers are unread: this is a frozen signature whose body, the
	 * one composition of the two into a slug, lands on a later branch.
	 *
	 * @since 0.36.0
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return string The term slug, in `{series_post_id}-{recurrence_id}` form.
	 */
	public static function term_slug( int $post_id, string $recurrence_id ): string {
		return sanitize_title( sprintf( '%d-%s', $post_id, $recurrence_id ) );
	}

	/**
	 * Resolve the occurrence the current request scopes this post's RSVPs to.
	 *
	 * REQ-16 lives on the first guard: a site with no recurring events never
	 * reaches the occurrence context at all, so every RSVP read and write runs
	 * exactly the SQL it ran before this class existed.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID whose RSVPs are being read or written.
	 *
	 * @return string|null The recurrence identifier, or null when the request is not scoped to one.
	 */
	public static function current_recurrence_id( int $post_id ): ?string {
		if ( ! Query::site_has_recurring_events() ) {
			return null;
		}

		$occurrence = Context::get_instance()->current();

		if ( null === $occurrence || (int) $occurrence['series_post_id'] !== $post_id ) {
			return null;
		}

		return (string) $occurrence['recurrence_id'];
	}

	/**
	 * Resolve the occurrence an already-stored RSVP belongs to.
	 *
	 * Reads the comment's own `_gatherpress_occurrence` term rather than the
	 * request, which is what makes it usable from callbacks that run before
	 * `wp` — `Rsvp\Token::handle_rsvp_token()` on `init` being the one that
	 * matters, since without this its cache invalidation drops only the
	 * series-wide key and leaves the occurrence's warm counts stale for the
	 * length of `Cache::CACHE_EXPIRATION`, shared across every visitor under a
	 * persistent object cache.
	 *
	 * @since 0.36.0
	 *
	 * @param int $comment_id RSVP comment ID.
	 *
	 * @return string|null The occurrence identifier, or null when the RSVP is not scoped to one.
	 */
	public static function recurrence_id_for_comment( int $comment_id ): ?string {
		if ( ! Query::site_has_recurring_events() || 1 > $comment_id ) {
			return null;
		}

		$slugs = wp_get_object_terms( $comment_id, self::TAXONOMY, array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
			return null;
		}

		return self::recurrence_id_from_slug( (string) $slugs[0] );
	}

	/**
	 * Recover the canonical occurrence identifier from a term slug.
	 *
	 * The exact inverse of `term_slug()`, and it has to be: `term_slug()`
	 * passes the composite through `sanitize_title()`, which lowercases it, so
	 * the stored slug reads `12-20260903t180000` while every cache key and
	 * every occurrence row carries `20260903T180000`. `Ymd\THis` contains
	 * exactly one letter, so uppercasing recovers the identifier byte for
	 * byte; handing the lowercased form back would compose a cache key that
	 * matches nothing.
	 *
	 * @since 0.36.0
	 *
	 * @param string $slug Term slug, in the form `term_slug()` produces.
	 *
	 * @return string|null The occurrence identifier, or null when the slug carries none.
	 */
	public static function recurrence_id_from_slug( string $slug ): ?string {
		$separator = strrpos( $slug, '-' );

		if ( false === $separator || strlen( $slug ) - 1 === $separator ) {
			return null;
		}

		return strtoupper( substr( $slug, $separator + 1 ) );
	}

	/**
	 * Attach an RSVP comment to an occurrence.
	 *
	 * All three parameters are unread: this is a frozen signature whose body,
	 * the term assignment onto the comment, lands on a later branch.
	 *
	 * @since 0.36.0
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param int    $comment_id    RSVP comment ID.
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return bool True when the term was assigned.
	 */
	public function assign( int $comment_id, int $post_id, string $recurrence_id ): bool {
		// An incomplete composite key would produce a slug that silently
		// collides with every other incomplete one, so refuse it rather than
		// writing a term nothing can be scoped by.
		if ( 1 > $comment_id || 1 > $post_id || '' === $recurrence_id ) {
			return false;
		}

		$assigned = wp_set_object_terms(
			$comment_id,
			self::term_slug( $post_id, $recurrence_id ),
			self::TAXONOMY
		);

		return ! is_wp_error( $assigned ) && ! empty( $assigned );
	}

	/**
	 * Build the taxonomy query scoping RSVPs to one occurrence.
	 *
	 * Passed through the existing `Rsvp\Query::get_rsvps()` path, so there is no
	 * new SQL, no new filter, and no table.
	 *
	 * Both identifiers are unread: this is a frozen signature whose body, the
	 * clause built from the occurrence's term slug, lands on a later branch.
	 *
	 * @since 0.36.0
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return array A `tax_query` clause.
	 */
	public function tax_query( int $post_id, string $recurrence_id ): array {
		return array(
			array(
				'taxonomy' => self::TAXONOMY,
				'field'    => 'slug',
				'terms'    => array( self::term_slug( $post_id, $recurrence_id ) ),
			),
		);
	}

	/**
	 * Move occurrence terms from one series post to another.
	 *
	 * The forward-split seam, frozen here as a stub returning 0. Its callers
	 * land with the forward split itself.
	 *
	 * All three parameters are unread: this is a frozen signature whose body,
	 * the term rename they drive, lands with that forward split.
	 *
	 * Renaming rather than re-tagging is what makes a split cheap: every row in
	 * `term_relationships` keys on `term_taxonomy_id`, which `wp_update_term()`
	 * leaves alone, so no RSVP is touched however many of them the occurrence
	 * carries.
	 *
	 * @since 0.36.0
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param int      $from_post_id   Series post ID the occurrences currently belong to.
	 * @param int      $to_post_id     Series post ID they move to.
	 * @param string[] $recurrence_ids Occurrence identifiers to move.
	 *
	 * @return int Terms renamed.
	 */
	public function rename_series( int $from_post_id, int $to_post_id, array $recurrence_ids ): int {
		$renamed = 0;

		foreach ( $recurrence_ids as $recurrence_id ) {
			$term = get_term_by(
				'slug',
				self::term_slug( $from_post_id, (string) $recurrence_id ),
				self::TAXONOMY
			);

			// An occurrence nobody has RSVPd to has no term to move.
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$slug    = self::term_slug( $to_post_id, (string) $recurrence_id );
			$updated = wp_update_term(
				$term->term_id,
				self::TAXONOMY,
				array(
					'name' => $slug,
					'slug' => $slug,
				)
			);

			// The destination slug can already exist when a split runs twice;
			// leave the original term alone rather than merging two
			// occurrences' RSVPs into one.
			if ( is_wp_error( $updated ) ) {
				continue;
			}

			++$renamed;
		}

		return $renamed;
	}
}
