<?php
/**
 * Occurrence URLs: rewrite rule registration and request resolution.
 *
 * REQ-8 gives every occurrence of a series its own URL, so an attendee can
 * link a friend to the specific date they are attending. The URL is the
 * event's own permalink plus a segment identifying the occurrence's start:
 * `/{event-slug}/{postname}/{Ymd\THis}/`.
 *
 * The event post type's rewrite base is a **setting**, read from the
 * registered post type object at runtime (`WP_Post_Type::$rewrite['slug']`),
 * never hardcoded. Recurrence belongs to the `gatherpress-event-date` post
 * type support rather than to `gatherpress_event` specifically, so every
 * post type declaring that support gets its own occurrence rewrite rule.
 *
 * A spike proved `add_rewrite_endpoint( ..., EP_PERMALINK )` produces a rule
 * that is tried *after* the event post type's own generated
 * `event/[^/]+/([^/]+)/?$` attachment-slug catch-all — which greedily
 * matches an occurrence segment as an attachment slug and 404s before this
 * class ever sees the request. `add_rewrite_rule()` with the `'top'`
 * position (the same pattern `Calendar\Endpoint` already uses for the
 * feed/ical endpoints) is registered into `$wp_rewrite->extra_rules_top`,
 * which is merged into `$wp_rewrite->rules` strictly before every
 * post-type-generated rule, including that catch-all. See
 * `test/unit/php/.../class-test-rewrite.php` for the regression coverage
 * that pins this down.
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
	public function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Set up hooks for occurrence URL registration and resolution.
	 *
	 * The rewrite rule is registered on `wp_loaded` rather than `init` so it
	 * is appended to `$wp_rewrite->extra_rules_top` strictly after
	 * `Calendar\Setup::register_endpoints()`, which registers GatherPress's
	 * feed/ical endpoints at `PHP_INT_MAX` on `init` -- `wp_loaded` fires
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
	 * Reads the post type's registered rewrite slug and query var at call
	 * time rather than assuming `gatherpress_event` / `/event/`, so a
	 * non-default or localized `events_url` setting -- and a companion
	 * plugin's own event-supporting post type -- both get a working
	 * occurrence URL.
	 *
	 * @since 0.36.0
	 *
	 * @param string $post_type Post type slug declaring `gatherpress-event-date` support.
	 *
	 * @return void
	 */
	protected function add_rewrite_rule_for_post_type( string $post_type ): void {
		$type_object = get_post_type_object( $post_type );

		if ( ! $type_object instanceof WP_Post_Type
			|| false === $type_object->rewrite
			|| empty( $type_object->query_var )
		) {
			return;
		}

		// A truthy `rewrite` is always normalized to an array with a 'slug' key by
		// WP_Post_Type -- `rewrite === true` resolves 'slug' to the post type name,
		// so there is no reachable case where the key is absent here.
		$slug = (string) $type_object->rewrite['slug'];

		$reg_ex = sprintf(
			'%s/([^/]+)/(%s)/?$',
			$slug,
			self::RECURRENCE_ID_REGEX
		);

		$rewrite_url = add_query_arg(
			array(
				$type_object->query_var => '$matches[1]',
				Context::QUERY_VAR      => '$matches[2]',
			),
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
	 * in memory -- `WP_Rewrite::wp_rewrite_rules()` returns the persisted
	 * `rewrite_rules` option verbatim whenever it is non-empty, so on every
	 * request after the *first* one this rule is registered for, the option
	 * already exists (built at plugin activation, or by any other rewrite
	 * consumer) without this rule in it, and it never gets added on an
	 * upgrading site until *something* deletes the option and forces a
	 * regeneration. Mirrors `Calendar\Endpoint::maybe_flush_rewrite_rules()`
	 * -- the same in-place compare-and-delete pattern GatherPress already
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
	 * through `Occurrences::get()` 404s -- a stale or hand-typed link must
	 * not silently render the series at its anchor date. A cancelled
	 * occurrence resolves rather than 404s (REQ-12): `Occurrences::get()`
	 * does not filter by status, so a cancelled row is returned like any
	 * other and this method never inspects `status` itself.
	 *
	 * REQ-16 is enforced here, at the single entry point, rather than inside
	 * each branch. Both branches reach the occurrence table -- the bare-series
	 * one through `next_upcoming_recurrence_id()`, the occurrence-segment one
	 * through `Occurrences::get()` -- and `Occurrences::get()` is a raw,
	 * uncached `$wpdb->get_row()`. A guard placed per branch is one a later
	 * branch can be added without, which is exactly how the occurrence-segment
	 * path shipped unguarded while the bare-series path was correct. Guarding
	 * the method instead of the path means a new branch inherits it.
	 *
	 * @since 0.36.0
	 *
	 * @param WP $wp The main WP request object, mutated in place.
	 *
	 * @return void
	 */
	public function parse_request( WP $wp ): void {
		if ( ! Query::site_has_recurring_events() ) {
			return;
		}

		if ( ! isset( $wp->query_vars[ Context::QUERY_VAR ] ) || '' === $wp->query_vars[ Context::QUERY_VAR ] ) {
			$this->maybe_resolve_bare_series( $wp );

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
			// letting the 404 stand -- silently turning a stale/hand-typed
			// occurrence link into "renders the series at its anchor date",
			// exactly what a miss must not do.
			add_filter( 'redirect_canonical', '__return_false' );
		}
	}

	/**
	 * Resolve a bare series URL's query var to its next upcoming occurrence.
	 *
	 * PRD D-4: visiting a recurring series at its own permalink, with no
	 * occurrence segment, resolves to the next upcoming occurrence rather
	 * than the series anchor. A post with no scheduled upcoming occurrence
	 * rows -- a non-recurring event, or a series that has run out -- is left
	 * untouched so it renders exactly as it does today.
	 *
	 * REQ-16 is handled by `parse_request()` before this method is reached, so
	 * the `get_page_by_path()` lookup below is never paid on a site with no
	 * recurring events. The guard deliberately does not live here: this is the
	 * branch every non-occurrence request falls through to, and guarding a
	 * branch rather than the entry point is what let the sibling
	 * occurrence-segment branch ship without one.
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
	 * Iterates every event-date-supporting post type's own query var (the
	 * one the occurrence rewrite rule was registered against) rather than
	 * assuming `gatherpress_event`, matching `add_rewrite_rule_for_post_type()`.
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

			if ( ! $type_object instanceof WP_Post_Type
				|| empty( $type_object->query_var )
				|| ! isset( $query_vars[ $type_object->query_var ] )
			) {
				continue;
			}

			$post = get_page_by_path( (string) $query_vars[ $type_object->query_var ], OBJECT, $post_type );

			if ( $post instanceof WP_Post ) {
				return (int) $post->ID;
			}
		}

		return null;
	}

	/**
	 * Resolve a series' next upcoming scheduled occurrence identifier.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID.
	 *
	 * @return string|null The recurrence ID, or null when none is upcoming.
	 */
	protected function next_upcoming_recurrence_id( int $post_id ): ?string {
		$post_ids = Series::get_instance()->resolve_post_ids( $post_id );
		$rows     = Occurrences::get_instance()->select_for_series(
			$post_ids,
			array( 'status' => Occurrences::STATUS_SCHEDULED )
		);
		$now      = current_time( 'mysql', true );

		foreach ( $rows as $row ) {
			if ( (int) $row['series_post_id'] === $post_id && $row['datetime_start_gmt'] >= $now ) {
				return (string) $row['recurrence_id'];
			}
		}

		return null;
	}

	/**
	 * Build the permalink of one occurrence.
	 *
	 * The segment is the occurrence's canonical `recurrence_id` (built by
	 * `Occurrences::recurrence_id()`, never re-derived here), passed through
	 * the `gatherpress_recurrence_id_format` filter so an integration can
	 * customize the URL representation. Resolution always matches against
	 * the canonical `recurrence_id`, so a filter that changes the segment's
	 * value rather than merely its formatting breaks round-tripping -- that
	 * trade-off belongs to whatever filters it.
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

		/**
		 * Filters the URL segment representing an occurrence's recurrence ID.
		 *
		 * @since 0.36.0
		 *
		 * @param string $segment       Segment appended to the permalink, default the
		 *                              canonical recurrence ID as produced by
		 *                              `Occurrences::recurrence_id()`.
		 * @param int    $post_id       Series post ID.
		 * @param string $recurrence_id Canonical occurrence identifier in `Ymd\THis` form.
		 *
		 * @return string The URL segment.
		 */
		$segment = (string) apply_filters(
			'gatherpress_recurrence_id_format',
			$recurrence_id,
			$post_id,
			$recurrence_id
		);

		return trailingslashit( $permalink ) . $segment . '/';
	}
}
