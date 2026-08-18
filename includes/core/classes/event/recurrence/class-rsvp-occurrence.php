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

use GatherPress\Core\Rsvp\Query as Rsvp_Query;
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
	 * Nothing to hook: the `delete_comment` relationship cleanup this class
	 * used to own cleans all three RSVP comment taxonomies, only one of which
	 * is about recurrence, and now lives on `Rsvp\Cleanup` alongside the
	 * hard-delete cron it belongs with.
	 *
	 * The declaration is not decorative. `Traits\Singleton` declares no
	 * constructor of its own, so dropping this one would hand the class PHP's
	 * implicit **public** constructor and make `new Rsvp_Occurrence()` legal
	 * from anywhere. That allows two instances of a singleton, which is the one
	 * thing `get_instance()` exists to prevent.
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
	 * what WordPress stores in `wp_terms.slug`. A caller can look a term up by
	 * this string without a second sanitization step, and the assigned and
	 * queried slugs cannot drift apart.
	 *
	 * The series post ID prefix is what makes a collision structurally
	 * impossible: two series can share a recurrence identifier, but not a post
	 * ID.
	 *
	 * @since 0.36.0
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
	 * the post they asked about. Resolution is series-wide: `Context` resolves an
	 * incoming identifier through `Series::resolve_post_ids()`, so once the
	 * forward split moves an occurrence onto a sibling post of the same series,
	 * the context legitimately holds a row whose `series_post_id` is not the
	 * post the request named. This method used to compare the two for equality,
	 * which rejected exactly the case `Context` had gone out of its way
	 * to admit, and every scoping consumer then fell back to series-wide with no
	 * error anywhere: the RSVP would be written with no occurrence term at all
	 * while the visitor believed they had booked a specific date.
	 *
	 * The first guard keeps the cost off ordinary sites: a site with no recurring events never
	 * reaches the occurrence context at all, so every RSVP read and write runs
	 * exactly the SQL it ran before this class existed.
	 *
	 * **The row's own stamp wins over the request's occurrence**, matching
	 * `Context::resolve()`. See its docblock for why, and for why the reverse
	 * order is a defect rather than a preference. The stamp is per-row and
	 * unambiguous; the request has one occurrence for the whole response, so
	 * preferring it collapses every row of a loop rendered on a singular
	 * occurrence page onto the requested date.
	 *
	 * The request arm keeps its widened-series membership check, and that
	 * admission is deliberate, for the split-series reason given above. The
	 * identity comparison stays ahead of `resolve_post_ids()`, so a one-post
	 * series never reaches the filter. That is the whole of today's traffic.
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

		// The occurrence the current loop iteration was stamped with, which is
		// pure property access on the result object and already scoped to the
		// post it was stamped onto, so it needs no membership check of its own.
		$occurrence = Context::get_instance()->loop_occurrence( $post_id );

		if ( null === $occurrence ) {
			$request        = Context::get_instance()->current();
			$series_post_id = ( null === $request ) ? 0 : (int) $request['series_post_id'];

			// The request's occurrence applies only when it belongs to this
			// post or to a sibling post of its series. On an archive or Query
			// Loop there is no request occurrence at all, and without the stamp
			// above every row would read the same series-wide RSVP state: an
			// attendee on the 18th appears to be attending every date.
			if (
				null !== $request
				&& (
					$series_post_id === $post_id
					|| in_array( $series_post_id, Series::get_instance()->resolve_post_ids( $post_id ), true )
				)
			) {
				$occurrence = $request;
			}
		}

		if ( null === $occurrence ) {
			return null;
		}

		return array(
			'series_post_id' => (int) $occurrence['series_post_id'],
			'recurrence_id'  => (string) $occurrence['recurrence_id'],
		);
	}

	/**
	 * Resolve just the identifier of the occurrence the request is scoped to.
	 *
	 * The thin accessor for the consumers that need the identifier without the
	 * post it belongs to: `block_context()` publishes it in the client's
	 * composite state key, and `Blocks\Rsvp_Form::occurrence_input()` emits it
	 * as the classic form's hidden field.
	 *
	 * Anything that also needs the owning post, in particular anything
	 * composing an occurrence **term slug**, must use `current_occurrence()`
	 * instead. Its docblock explains why.
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
	 * Report whether an event's classic RSVP writes need an explicit scope.
	 *
	 * True exactly when the event is a recurring series, which is when a
	 * response can mean either one date or all of them and the two must never
	 * be conflated. `Blocks\Rsvp_Form` renders a scope marker on every such
	 * form, an occurrence identifier or the explicit series value, and
	 * `Rsvp\Form` refuses a submission that carries neither: a marker-less
	 * submission to a recurring event can only come from markup rendered
	 * before the marker existed, and treating it as an intentional series-wide
	 * RSVP writes data nothing can afterwards tell apart from one.
	 *
	 * The presence test is `Occurrences::has_recurrence_rule()`, the same
	 * mirror every other series-shaped decision reads, so the renderer and the
	 * handler cannot disagree about which events require the marker. The site
	 * flag guard runs first: on a site with no recurring events this returns
	 * false from the autoloaded option alone, and both callers keep the exact
	 * behavior they had before the marker existed.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Event post ID the form belongs to.
	 *
	 * @return bool True when submissions must carry an explicit scope marker.
	 */
	public static function requires_explicit_scope( int $post_id ): bool {
		return Query::site_has_recurring_events()
			&& Occurrences::get_instance()->has_recurrence_rule( $post_id );
	}

	/**
	 * Build the interactivity block context one rendered row publishes to the client.
	 *
	 * The single source of truth for that payload, and the reason it exists is
	 * that occurrence identity is `(post_id, recurrence_id)`, and until this
	 * method every block emitted `postId` alone. On an archive or Query Loop the
	 * whole point is that one post appears many times, so a client store keyed
	 * on the post ID collapsed every row of a series into one entry. An RSVP
	 * on one date visibly applied to all of them, over server markup that was
	 * already correct per row.
	 *
	 * A post with no occurrence in play gets back exactly what it got before:
	 * `array( 'postId' => $post_id )`, byte-identical JSON. That covers every
	 * ordinary event, and every post on a site that has never authored a
	 * recurring series,
	 * so its state key stays the bare post ID and its request bodies do not
	 * change. The two key shapes cannot collide, either: a bare key is
	 * `/^\d+$/` and a composite one always carries a `:` separator.
	 *
	 * Resolution goes through `current_recurrence_id()` rather than through
	 * `Context::cache_key()` so the identity the client is handed is the same
	 * one the server scoped this row's RSVP reads by, widened-series admission
	 * included. The cost guard rides along on that call's first guard: on a site with no
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
	 * `wp`. `Rsvp\Token::handle_rsvp_token()` on `init` is the one that
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
		$occurrence = self::occurrence_for_comment( $comment_id );

		return null === $occurrence ? null : $occurrence['recurrence_id'];
	}

	/**
	 * Resolve the whole composite key an already-stored RSVP belongs to.
	 *
	 * The counterpart to `current_occurrence()` for callbacks that have no
	 * request to read. The RSVP confirmation email is the one that matters,
	 * since it is composed while a comment is inserted (`Rsvp\Form`, reached
	 * from the REST route and from `comment_post`) and is then *sent*, so a
	 * link to the wrong date cannot be corrected afterwards.
	 *
	 * Both halves of the composite identity come off the term slug rather than
	 * from the comment's `comment_post_ID`: `assign()` keys the slug on the
	 * **occurrence's own** `series_post_id`, which the forward split makes
	 * legitimately different from the post the responder RSVPd on. Composing a
	 * URL from `comment_post_ID` would name a post the occurrence no longer
	 * lives on, and `Rewrite::parse_request()` matches on the exact pair.
	 *
	 * The recurring-events check is the first guard: on a site with no recurring events this
	 * returns without reading a term relationship at all, so the email path
	 * runs byte-identical SQL there.
	 *
	 * @since 0.36.0
	 *
	 * @param int $comment_id RSVP comment ID.
	 *
	 * @return array{series_post_id: int, recurrence_id: string}|null The occurrence's composite key,
	 *               or null when the RSVP is not scoped to one.
	 */
	public static function occurrence_for_comment( int $comment_id ): ?array {
		if ( ! Query::site_has_recurring_events() || 1 > $comment_id ) {
			return null;
		}

		$slugs = wp_get_object_terms( $comment_id, self::TAXONOMY, array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
			return null;
		}

		$slug          = (string) $slugs[0];
		$recurrence_id = self::recurrence_id_from_slug( $slug );
		$post_id       = self::series_post_id_from_slug( $slug );

		if ( null === $recurrence_id || null === $post_id ) {
			return null;
		}

		return array(
			'series_post_id' => $post_id,
			'recurrence_id'  => $recurrence_id,
		);
	}

	/**
	 * Recover the series post ID from a term slug.
	 *
	 * The other half of `recurrence_id_from_slug()`'s inverse. The identifier
	 * is `Ymd\THis` and carries no `-`, and a post ID is decimal digits, so the
	 * final `-` is the only separator and the prefix is the post ID whole. A
	 * prefix that is not a positive integer means the slug was not produced by
	 * `term_slug()` at all, and is refused rather than cast to zero.
	 *
	 * @since 0.36.0
	 *
	 * @param string $slug Term slug, in the form `term_slug()` produces.
	 *
	 * @return int|null The series post ID, or null when the slug carries none.
	 */
	public static function series_post_id_from_slug( string $slug ): ?int {
		$separator = strrpos( $slug, '-' );

		if ( false === $separator ) {
			return null;
		}

		$prefix = substr( $slug, 0, $separator );

		return ctype_digit( $prefix ) && 0 < (int) $prefix ? (int) $prefix : null;
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
	 * @since 0.36.0
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
	 * @since 0.36.0
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
	 * Drop the occurrence terms naming a post's occurrences.
	 *
	 * Used when REQ-13 demotes a side of a split to a plain non-recurring event.
	 * Deleting the term removes its `term_relationships` rows, which is exactly
	 * what is wanted: the RSVPs stay on the same comments, on the same post, for
	 * the same date, and become readable series-wide again — which on a
	 * single-date event *is* the date. Nothing is migrated and nothing is
	 * deleted; only the scoping that no longer has anything to scope goes away.
	 *
	 * @since 0.36.0
	 *
	 * @param int      $post_id        Series post ID the terms name.
	 * @param string[] $recurrence_ids Occurrence identifiers to unscope.
	 *
	 * @return int Terms deleted.
	 */
	public function detach_series( int $post_id, array $recurrence_ids ): int {
		$deleted = 0;

		foreach ( $recurrence_ids as $recurrence_id ) {
			$term = get_term_by( 'slug', self::term_slug( $post_id, (string) $recurrence_id ), self::TAXONOMY );

			// An occurrence nobody has RSVPd to has no term to drop.
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			wp_delete_term( $term->term_id, self::TAXONOMY );

			++$deleted;
		}

		return $deleted;
	}

	/**
	 * Count the RSVPs attached to a set of a post's occurrences.
	 *
	 * REQ-13's last acceptance criterion (brief §6 Q12): when a rule change would
	 * move or remove occurrences carrying RSVPs, the organizer is **shown how
	 * many RSVPs are affected**, and the RSVPs are not silently migrated. This is
	 * the number that gets shown.
	 *
	 * Counts comments rather than `term_taxonomy.count`, because that column
	 * counts relationship rows and would include RSVPs whose comment has since
	 * been trashed — an organizer told "4 RSVPs affected" when two of them are in
	 * the trash has been told the wrong thing.
	 *
	 * `'status' => 'approve'` narrows it one step further, and does work the
	 * trashed case does not: `WP_Comment_Query` reads an absent status as `all`,
	 * which is `comment_approved IN ( '0', '1' )` — trash and spam are already
	 * out, but a **pending** RSVP is in. Guest responses arrive pending by
	 * design (`Rsvp\Form::prepare_comment_data()` inserts them with
	 * `comment_approved => 0`), so without this the count would include
	 * responses the organizer has not accepted.
	 *
	 * @since 0.36.0
	 *
	 * @param int      $post_id        Series post ID the terms name.
	 * @param string[] $recurrence_ids Occurrence identifiers to count across.
	 *
	 * @return int The number of approved RSVPs on those occurrences.
	 */
	public function count_rsvps( int $post_id, array $recurrence_ids ): int {
		$term_ids = array();

		foreach ( $recurrence_ids as $recurrence_id ) {
			$term = get_term_by( 'slug', self::term_slug( $post_id, (string) $recurrence_id ), self::TAXONOMY );

			if ( $term instanceof WP_Term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		if ( array() === $term_ids ) {
			return 0;
		}

		$comment_ids = get_objects_in_term( $term_ids, self::TAXONOMY );

		if ( is_wp_error( $comment_ids ) || empty( $comment_ids ) ) {
			return 0;
		}

		// Deliberately not narrowed by `post_id`: an RSVP's `comment_post_ID`
		// stays on the post it was left on, while a split moves the occurrence
		// term to a sibling post — so the term IDs are the authoritative scope
		// and a post filter would drop exactly the RSVPs a split just moved.
		return (int) Rsvp_Query::get_instance()->get_rsvps(
			array(
				'comment__in' => array_map( 'intval', $comment_ids ),
				'count'       => true,
				'status'      => 'approve',
			)
		);
	}
}
