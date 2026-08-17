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
	 * hard-delete cron it belongs with.
	 *
	 * The declaration is not decorative. `Traits\Singleton` declares no
	 * constructor of its own, so dropping this one would hand the class PHP's
	 * implicit **public** constructor and make `new Rsvp_Occurrence()` legal
	 * from anywhere — two instances of a singleton, and the one thing
	 * `get_instance()` exists to prevent.
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
	 * Returns the **occurrence's own** `series_post_id` alongside the
	 * identifier, and callers must key their term slug off that rather than off
	 * the post they asked about. PRD C-2 is the reason: `Context` resolves an
	 * incoming identifier through `Series::resolve_post_ids()`, so once REQ-18's
	 * forward split moves an occurrence onto a sibling post of the same series,
	 * the context legitimately holds a row whose `series_post_id` is not the
	 * post the request named. Comparing the two for equality — which this method
	 * used to do — rejected exactly the case `Context` had gone out of its way
	 * to admit, and every scoping consumer then fell back to series-wide with no
	 * error anywhere: the RSVP would be written with no occurrence term at all
	 * while the visitor believed they had booked a specific date.
	 *
	 * REQ-16 lives on the first guard: a site with no recurring events never
	 * reaches the occurrence context at all, so every RSVP read and write runs
	 * exactly the SQL it ran before this class existed. The identity comparison
	 * is kept as the fast path ahead of the resolver, so a one-post series — the
	 * whole of today's traffic — never reaches the filter either.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID whose RSVPs are being read or written.
	 *
	 * @return array{series_post_id: int, recurrence_id: string}|null The occurrence, or null when the
	 *               request is not scoped to one of this post's series.
	 */
	public static function current_occurrence( int $post_id ): ?array {
		if ( ! Query::site_has_recurring_events() ) {
			return null;
		}

		$occurrence     = Context::get_instance()->current();
		$series_post_id = ( null === $occurrence ) ? 0 : (int) $occurrence['series_post_id'];

		// The request's occurrence only applies when it belongs to this post or
		// to a sibling post of its series. When it does not -- and on an archive
		// or Query Loop, where there is no request occurrence at all -- fall
		// back to the occurrence the current loop iteration was stamped with.
		//
		// Without that fallback every row of a loop reads the same series-wide
		// RSVP state, because `current()` answers null for all of them: an
		// attendee on the 18th appears to be attending every date in the series.
		// `loop_occurrence()` is already scoped to the post it was stamped onto,
		// so it needs no membership check of its own.
		if (
			null === $occurrence
			|| (
				$series_post_id !== $post_id
				&& ! in_array( $series_post_id, Series::get_instance()->resolve_post_ids( $post_id ), true )
			)
		) {
			$occurrence = Context::get_instance()->loop_occurrence( $post_id );

			if ( null === $occurrence ) {
				return null;
			}

			$series_post_id = (int) $occurrence['series_post_id'];
		}

		return array(
			'series_post_id' => $series_post_id,
			'recurrence_id'  => (string) $occurrence['recurrence_id'],
		);
	}

	/**
	 * Resolve just the identifier of the occurrence the request is scoped to.
	 *
	 * The thin accessor for the one consumer that needs the identifier without
	 * the post it belongs to — `Rsvp\Cache`, whose occurrence-scoped transient
	 * key is deliberately built from the post the caller named. Its sibling
	 * call site, `Rsvp\Token`, has no request context at all and passes the
	 * identifier explicitly, so keying the implicit path off the occurrence's
	 * own post would make the two disagree about which transient to drop.
	 *
	 * Anything composing an occurrence **term slug** must use
	 * `current_occurrence()` instead — see its docblock for why.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID whose RSVPs are being read or written.
	 *
	 * @return string|null The recurrence identifier, or null when the request is not scoped to one.
	 */
	public static function current_recurrence_id( int $post_id ): ?string {
		$occurrence = self::current_occurrence( $post_id );

		return null === $occurrence ? null : $occurrence['recurrence_id'];
	}

	/**
	 * Build the interactivity block context one rendered row publishes to the client.
	 *
	 * The single source of truth for that payload, and the reason it exists is
	 * PRD C-1: occurrence identity is `(post_id, recurrence_id)`, and until this
	 * method every block emitted `postId` alone. On an archive or Query Loop the
	 * whole point is that one post appears many times, so a client store keyed
	 * on the post ID collapsed every row of a series into one entry -- an RSVP
	 * on one date visibly applied to all of them, over server markup that was
	 * already correct per row.
	 *
	 * A post with no occurrence in play -- every ordinary event, and every post
	 * on a site that has never authored a recurring series -- gets back exactly
	 * what it got before: `array( 'postId' => $post_id )`, byte-identical JSON,
	 * so its state key stays the bare post ID and its request bodies do not
	 * change. The two key shapes cannot collide, either: a bare key is
	 * `/^\d+$/` and a composite one always carries a `:` separator.
	 *
	 * Resolution goes through `current_recurrence_id()` rather than through
	 * `Context::cache_key()` so the identity the client is handed is the same
	 * one the server scoped this row's RSVP reads by, widened-series admission
	 * included. REQ-16 rides along on that call's first guard: on a site with no
	 * recurring events it returns before touching the occurrence table.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID the block instance is rendering.
	 *
	 * @return array{postId: int, recurrenceId?: string} The block's `data-wp-context` payload.
	 */
	public static function block_context( int $post_id ): array {
		$context       = array( 'postId' => $post_id );
		$recurrence_id = self::current_recurrence_id( $post_id );

		if ( null !== $recurrence_id ) {
			$context['recurrenceId'] = $recurrence_id;
		}

		return $context;
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
