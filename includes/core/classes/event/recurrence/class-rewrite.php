<?php
/**
 * Occurrence URLs: rewrite rule registration and request resolution.
 *
 * Every occurrence of a series gets its own URL, so an attendee can
 * link a friend to the specific date they are attending. The URL is the
 * event's own permalink plus a segment identifying the occurrence's start:
 * `/{event-slug}/{postname}/{Ymd\THis}/`. On a site whose permalinks are
 * query strings, the identifier travels as the `gatherpress_occurrence`
 * query variable instead, because a query-string permalink has no path to
 * append a segment to.
 *
 * The event post type's rewrite base is a **setting**, read from the
 * registered post type object at runtime (`WP_Post_Type::$rewrite['slug']`),
 * never hardcoded. Recurrence belongs to the `gatherpress-event-date` post
 * type support rather than to `gatherpress_event` specifically, so every
 * post type declaring that support gets its own occurrence rewrite rule.
 *
 * A spike proved `add_rewrite_endpoint( ..., EP_PERMALINK )` produces a rule
 * that is tried *after* the event post type's own generated
 * `event/[^/]+/([^/]+)/?$` attachment-slug catch-all. That catch-all greedily
 * matches an occurrence segment as an attachment slug and 404s before this
 * class ever sees the request. `add_rewrite_rule()` with the `'top'`
 * position (the same pattern `Calendar\Endpoint` already uses for the
 * feed/ical endpoints) is registered into `$wp_rewrite->extra_rules_top`,
 * which is merged into `$wp_rewrite->rules` strictly before every
 * post-type-generated rule, including that catch-all. See
 * `test/unit/php/includes/tests/core/classes/event/recurrence/class-test-rewrite.php`
 * for the regression coverage that pins this down.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Traits\Singleton;
use WP;
use WP_Post;
use WP_Post_Type;

/**
 * Class Rewrite.
 *
 * Singleton owning the occurrence rewrite rule and its `parse_request`
 * resolution.
 *
 * @since 0.36.0
 */
final class Rewrite {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Regex character class matching a `Ymd\THis` occurrence identifier.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const RECURRENCE_ID_REGEX = '[0-9]{8}T[0-9]{6}';

	/**
	 * Class constructor.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Set up hooks for occurrence URL registration and resolution.
	 *
	 * The rewrite rule is registered on `wp_loaded` rather than `init` so it
	 * is appended to `$wp_rewrite->extra_rules_top` strictly after
	 * `Calendar\Setup::register_endpoints()`, which registers GatherPress's
	 * feed/ical endpoints at `PHP_INT_MAX` on `init`. `wp_loaded` fires
	 * after every `init` callback has run, at any priority, so the append
	 * order (and therefore the match order among `'top'` rules) is
	 * deterministic regardless of subsystem instantiation order. The event
	 * post type itself must also already be registered, which happens on
	 * `init` at the default priority.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_action( 'wp_loaded', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'parse_request', array( $this, 'parse_request' ) );
	}

	/**
	 * Register the occurrence rewrite rule for every event-date-supporting post type.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
		foreach ( get_post_types_by_support( 'gatherpress-event-date' ) as $post_type ) {
			$this->add_rewrite_rule_for_post_type( $post_type );
		}
	}

	/**
	 * Register the occurrence rewrite rule for one post type.
	 *
	 * Reads the post type's registered permastruct and query var at call
	 * time rather than assuming `gatherpress_event` / `/event/`, so a
	 * non-default or localized `events_url` setting gets a working occurrence
	 * URL, and so does a companion plugin's own event-supporting post type,
	 * including hierarchical ones and ones whose permastruct keeps the
	 * rewrite front.
	 *
	 * @since 0.36.0
	 *
	 * @param string $post_type Post type slug declaring `gatherpress-event-date` support.
	 *
	 * @return void
	 */
	protected function add_rewrite_rule_for_post_type( string $post_type ): void {
		global $wp_rewrite;

		$type_object = get_post_type_object( $post_type );

		if ( ! $type_object instanceof WP_Post_Type || false === $type_object->rewrite ) {
			return;
		}

		// The pattern is built from the post type's registered permastruct
		// rather than from the bare rewrite slug: `register_post_type()`
		// prepends the rewrite front to every `with_front` permastruct, so
		// the struct's leading path is what every real permalink of the post
		// type carries, and a rule missing it would never match the URLs
		// `get_occurrence_url()` advertises.
		$struct = (string) $wp_rewrite->get_extra_permastruct( $post_type );

		if ( '' === $struct ) {
			return;
		}

		$base = trim( str_replace( '%' . $post_type . '%', '', $struct ), '/' );

		// A hierarchical post type publishes child posts at `parent/child`,
		// so the post-name capture must span path segments, matching the
		// `(.+?)` capture WordPress's own rewrite tag uses for hierarchical
		// post types. A non-hierarchical name is always one segment.
		$capture = $type_object->hierarchical ? '(.+?)' : '([^/]+)';

		$reg_ex = sprintf(
			'%s/%s/(%s)/?$',
			$base,
			$capture,
			self::RECURRENCE_ID_REGEX
		);

		// A post type registered with `query_var => false` still gets pretty
		// permalinks; WordPress's own rewrite tag for it routes through the
		// `post_type` plus `name` pair, or `post_type` plus `pagename` when
		// hierarchical, so the occurrence rule targets the same pair.
		if ( ! empty( $type_object->query_var ) ) {
			$post_query = array( $type_object->query_var => '$matches[1]' );
		} else {
			$post_name_key = $type_object->hierarchical ? 'pagename' : 'name';
			$post_query    = array(
				'post_type'    => $post_type,
				$post_name_key => '$matches[1]',
			);
		}

		$rewrite_url = add_query_arg(
			array_merge( $post_query, array( Context::QUERY_VAR => '$matches[2]' ) ),
			'index.php'
		);

		add_rewrite_rule( $reg_ex, $rewrite_url, 'top' );
		$this->maybe_flush_rewrite_rules( $reg_ex, $rewrite_url );
	}

	/**
	 * Flush the rewrite_rules option when the stored rules do not already
	 * contain this exact pattern/target pair.
	 *
	 * `add_rewrite_rule()` only ever mutates `$wp_rewrite->extra_rules_top`
	 * in memory. `WP_Rewrite::wp_rewrite_rules()` returns the persisted
	 * `rewrite_rules` option verbatim whenever it is non-empty, so on every
	 * request after the *first* one this rule is registered for, the option
	 * already exists (built at plugin activation, or by any other rewrite
	 * consumer) without this rule in it, and it never gets added on an
	 * upgrading site until *something* deletes the option and forces a
	 * regeneration. This mirrors `Calendar\Endpoint::maybe_flush_rewrite_rules()`,
	 * the same in-place compare-and-delete pattern GatherPress already
	 * uses for its other custom endpoints, since `Setup::schedule_rewrite_flush()`
	 * is private and out of this class's reach. Once the option is
	 * regenerated with this pattern's exact target, the comparison is true
	 * and the condition never fires again, so this cannot flush on every
	 * request.
	 *
	 * @since 0.36.0
	 *
	 * @param string $reg_ex      The regular expression pattern this rule was registered under.
	 * @param string $rewrite_url The target URL this pattern should map to.
	 *
	 * @return void
	 */
	protected function maybe_flush_rewrite_rules( string $reg_ex, string $rewrite_url ): void {
		$rules = get_option( 'rewrite_rules' );

		if ( ! isset( $rules[ $reg_ex ] ) || $rules[ $reg_ex ] !== $rewrite_url ) {
			delete_option( 'rewrite_rules' );
		}
	}

	/**
	 * Filters the public query variables to allow the occurrence segment.
	 *
	 * @since 0.36.0
	 *
	 * @param string[] $public_query_vars Allowed public query variable names.
	 *
	 * @return string[] The updated list.
	 */
	public function add_query_vars( array $public_query_vars ): array {
		$public_query_vars[] = Context::QUERY_VAR;

		return $public_query_vars;
	}

	/**
	 * Resolve the occurrence segment of a request, or the bare series URL's
	 * next upcoming occurrence.
	 *
	 * A well-formed occurrence segment that does not resolve to a real row
	 * through `Occurrences::get()` 404s. A stale or hand-typed link must
	 * not silently render the series at its anchor date. A canceled
	 * occurrence resolves rather than 404s, so an attendee holding the link is
	 * told it was canceled: `Occurrences::get()` does not filter by status, so
	 * a canceled row is returned like any other and this method never inspects
	 * `status` itself.
	 *
	 * The "a site with no recurring events pays nothing" guarantee is enforced
	 * on the bare-series branch alone, because that branch is the one every
	 * ordinary request falls through to. The occurrence-segment branch is
	 * deliberately unguarded: it only runs for a request already carrying an
	 * occurrence identifier, and its one primary-key `Occurrences::get()`
	 * read is the price of the miss invariant above surviving the site-wide
	 * flag. A guard at the method entry was tried first and broke that
	 * invariant the moment `refresh_has_recurring_events()` wrote `'0'`: the
	 * rewrite rule still matched, the guard returned before the miss could
	 * 404, and every previously shared occurrence URL rendered the series at
	 * its anchor date with a `200`.
	 *
	 * @since 0.36.0
	 *
	 * @param WP $wp The main WP request object, mutated in place.
	 *
	 * @return void
	 */
	public function parse_request( WP $wp ): void {
		if ( ! isset( $wp->query_vars[ Context::QUERY_VAR ] ) || '' === $wp->query_vars[ Context::QUERY_VAR ] ) {
			if ( Query::site_has_recurring_events() ) {
				$this->maybe_resolve_bare_series( $wp );
			}

			return;
		}

		$post_id = $this->resolve_post_id_from_query_vars( $wp->query_vars );

		if ( null === $post_id ) {
			return;
		}

		$recurrence_id = (string) $wp->query_vars[ Context::QUERY_VAR ];
		$row           = Occurrences::get_instance()->get( $post_id, $recurrence_id );

		if ( null === $row ) {
			$wp->query_vars['error'] = '404';

			// redirect_canonical() otherwise finds the series post by its
			// `name` query var, decides the request is "close enough" to a
			// real permalink, and 301s to the bare series URL instead of
			// letting the 404 stand. That silently turns a stale or hand-typed
			// occurrence link into "renders the series at its anchor date",
			// exactly what a miss must not do.
			add_filter( 'redirect_canonical', '__return_false' );
		}
	}

	/**
	 * Resolve a bare series URL's query var to its next upcoming occurrence.
	 *
	 * Visiting a recurring series at its own permalink, with no
	 * occurrence segment, resolves to the next upcoming occurrence rather
	 * than the series anchor. A post with no scheduled upcoming occurrence
	 * rows is left untouched so it renders exactly as it does today. That
	 * covers a non-recurring event and a series that has run out.
	 *
	 * ## Bare-URL contract: fragment semantics, not logical-series semantics
	 *
	 * This is the contract the occurrence front end is built on, stated here
	 * because it is about to matter and because completing it is not this
	 * layer's to make. **A bare URL resolves within the fragment it names, never across
	 * the logical series.**
	 *
	 * Concretely: `next_upcoming_recurrence_id()` scopes its read to the post
	 * the request named, so the only rows it can answer with are that post's
	 * own. Today that is indistinguishable from reading the whole series,
	 * because `Series::resolve_post_ids()` returns `array( $post_id )` and one
	 * post is the whole series. They separate the moment the forward split
	 * makes a series span several posts, and the scoping is what decides the
	 * behavior then:
	 *
	 * - **Fragment semantics (what this class implements).** `/{slug-of-piece-A}/`
	 *   resolves to A's own next upcoming occurrence. Once A's occurrences are
	 *   all in the past, A's bare URL resolves to nothing and renders the post
	 *   at its anchor, even though the logical series continues in piece B.
	 * - **Logical-series semantics (not implemented here).** `/{slug-of-piece-A}/`
	 *   would resolve to the next upcoming occurrence of the *series*, wherever
	 *   it lives, and therefore emit a canonical URL under a different post's
	 *   slug than the one requested.
	 *
	 * Fragment semantics is the correct choice today and is not a
	 * placeholder: nothing in the plugin can produce a second fragment yet, so
	 * the two are indistinguishable at runtime, and the narrower rule is the
	 * one that cannot silently redirect a request to a post the visitor did not
	 * ask for. Two invariants this class does guarantee, which any future
	 * split implementation must preserve:
	 *
	 * 1. A pre-split occurrence URL keeps resolving to the same occurrence
	 *    after the split, whichever fragment ends up owning that row.
	 * 2. A bare URL never resolves to an occurrence belonging to another post,
	 *    so the canonical URL a bare request produces always sits under the
	 *    requested post's slug.
	 *
	 * **Completing the contract belongs to the split feature itself**, since
	 * the answers depend on how a split is orchestrated. That work has to
	 * decide and cover: whether a lapsed
	 * fragment's bare URL forwards to the live fragment or stays put; if it forwards, whether that
	 * is a resolution or a `301` and what `rel="canonical"` then says; and
	 * which post a logical series' "the series' URL" means for calendar
	 * subscriptions and revisions once more than one post can answer to it. The
	 * post IDs `next_upcoming_recurrence_id()` hands `select_for_series()` are
	 * the single place those decisions land: `Series::resolve_post_ids()` is
	 * what widens them, and a widened read cannot keep the row limit that
	 * bounds the scoped one.
	 *
	 * The no-recurring-events guard wraps this method's call inside
	 * `parse_request()`, so the `get_page_by_path()` lookup below is never
	 * paid on a site with no recurring events. It guards this branch alone:
	 * the occurrence-segment branch runs unguarded on purpose, so a stale
	 * link keeps 404ing after the flag flips off.
	 *
	 * @since 0.36.0
	 *
	 * @param WP $wp The main WP request object, mutated in place.
	 *
	 * @return void
	 */
	protected function maybe_resolve_bare_series( WP $wp ): void {
		$post_id = $this->resolve_post_id_from_query_vars( $wp->query_vars );

		if ( null === $post_id ) {
			return;
		}

		$recurrence_id = $this->next_upcoming_recurrence_id( $post_id );

		if ( null !== $recurrence_id ) {
			$wp->query_vars[ Context::QUERY_VAR ] = $recurrence_id;
		}
	}

	/**
	 * Resolve the series post ID a request's query vars point at.
	 *
	 * Iterates every event-date-supporting post type and reads the query vars
	 * its occurrence rewrite rule was registered against, matching
	 * `add_rewrite_rule_for_post_type()`: the post type's own query var when
	 * it has one, otherwise the `post_type` plus `name` or `pagename`
	 * fallback pair.
	 *
	 * @since 0.36.0
	 *
	 * @param array $query_vars The request's query vars.
	 *
	 * @return int|null The resolved post ID, or null when nothing matches.
	 */
	protected function resolve_post_id_from_query_vars( array $query_vars ): ?int {
		foreach ( get_post_types_by_support( 'gatherpress-event-date' ) as $post_type ) {
			$type_object = get_post_type_object( $post_type );

			if ( ! $type_object instanceof WP_Post_Type ) {
				continue;
			}

			// A post type without a query var is resolved through the same
			// `post_type` plus `name` or `pagename` pair its rewrite target
			// carries; see `add_rewrite_rule_for_post_type()`.
			if ( ! empty( $type_object->query_var ) ) {
				$path = (string) ( $query_vars[ $type_object->query_var ] ?? '' );
			} elseif ( ( $query_vars['post_type'] ?? '' ) === $post_type ) {
				$path = (string) ( $query_vars[ $type_object->hierarchical ? 'pagename' : 'name' ] ?? '' );
			} else {
				$path = '';
			}

			if ( '' === $path ) {
				continue;
			}

			$post = get_page_by_path( $path, OBJECT, $post_type );

			if ( $post instanceof WP_Post ) {
				return (int) $post->ID;
			}
		}

		return null;
	}

	/**
	 * Resolve a series' next upcoming scheduled occurrence identifier.
	 *
	 * One bounded statement: the requested post's scheduled rows whose end has
	 * not passed, in the table's total order, limited to the single row the
	 * answer needs. Every part of that is decided in SQL, so a series' whole
	 * scheduled history is never hydrated to produce one identifier.
	 *
	 * Scoping the read to the requested post *is* the fragment-semantics
	 * narrowing documented on `maybe_resolve_bare_series()`, not a departure
	 * from it. Widening the query across the series and then discarding every
	 * row that does not belong to the requested post returns exactly the rows
	 * this scoped query returns, in the same order, because discarding rows
	 * cannot reorder the ones that survive. What the widened shape cannot
	 * survive is the row limit: a sibling's occurrence can sort ahead of the
	 * requested post's own, so a widened read cut to one row answers with
	 * another post's occurrence, which is the one thing the contract's second
	 * invariant forbids. Scoping the query is what makes the bound safe.
	 *
	 * Upcoming is bounded inclusively on the occurrence's end, through
	 * `select_for_series()`'s end-inclusive `after` argument, matching
	 * `Event\Query::get_datetime_comparison_column()`'s "a running event is
	 * still upcoming" rule, which the occurrence list preserves through its
	 * `COALESCE()` rewrite. An occurrence that is in progress, or that ends at
	 * this exact second, still resolves; bounding on the start instead skipped
	 * a running occurrence and sent the visitor to the next one while the list
	 * beside the page still showed the running one. The column is `NOT NULL`
	 * and written by every projection, so there is no empty-end shape to fall
	 * back from.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID.
	 *
	 * @return string|null The recurrence ID, or null when none is upcoming.
	 */
	protected function next_upcoming_recurrence_id( int $post_id ): ?string {
		$rows = Occurrences::get_instance()->select_for_series(
			array( $post_id ),
			array(
				'status' => Occurrences::STATUS_SCHEDULED,
				'after'  => current_time( 'mysql', true ),
				'limit'  => 1,
			)
		);

		if ( array() === $rows ) {
			return null;
		}

		return (string) $rows[0]['recurrence_id'];
	}

	/**
	 * Build the permalink of one occurrence.
	 *
	 * The segment is the occurrence's canonical `recurrence_id`, built by
	 * `Occurrences::recurrence_id()` and never re-derived here.
	 *
	 * There is deliberately no filter over the segment. One shipped in an
	 * earlier revision of this class as `gatherpress_recurrence_id_format`,
	 * and it was one-way: the emitted segment was filterable, but
	 * `add_rewrite_rule_for_post_type()` registers a single fixed
	 * `RECURRENCE_ID_REGEX` and `parse_request()` matches the raw segment
	 * against the canonical `recurrence_id` column, so any value the filter
	 * changed produced an advertised URL that 404s. A hook whose documented use
	 * breaks the feature it customizes is worse than no hook.
	 *
	 * Making it bidirectional was considered and rejected for now. It is not
	 * one filter but a coupled set: a filtered regex consumed at rule
	 * registration on `wp_loaded`, a filtered parser consumed in
	 * `parse_request()`, and rewrite-option invalidation keyed off the filtered
	 * regex so a changed pattern reaches the persisted `rewrite_rules`. Every
	 * one of them has to agree or the site 404s its own links. Against
	 * that, the segment *is* the composite identity: an invertible transform is
	 * a re-encoding with no product behind it, and a
	 * non-invertible one is the defect above. The filter is unreleased, has no
	 * known consumer, and nothing outside this class ever composed the segment,
	 * so removing it costs no compatibility. If a real requirement appears
	 * (localized or slug-shaped segments), the contract to add is the paired
	 * format/parse/regex set designed against that requirement.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return string The occurrence URL, or '' when the post has no permalink.
	 */
	public static function get_occurrence_url( int $post_id, string $recurrence_id ): string {
		// Read through `Context`, which stands its own permalink filter down
		// for the duration: the occurrence URL is composed on top of the
		// *series* permalink, and reading a filtered one during a loop
		// iteration would append a second occurrence segment to a URL that
		// already carries one.
		$permalink = Context::get_instance()->series_permalink( $post_id );

		if ( false === $permalink ) {
			return '';
		}

		// A query-string permalink appears when the site has no permalink
		// structure at all, or when the rewrite rules have not been
		// regenerated for this post type yet. Appending a path segment there
		// would push the identifier into the event query value, producing
		// `?gatherpress_event=slug/{id}/`, which identifies no post. The
		// occurrence rides as its own query variable instead, the same
		// composition `Calendar::get_endpoint_url()` uses for its endpoints.
		if ( str_contains( $permalink, '?' ) ) {
			return add_query_arg( Context::QUERY_VAR, $recurrence_id, $permalink );
		}

		return trailingslashit( $permalink ) . $recurrence_id . '/';
	}
}
