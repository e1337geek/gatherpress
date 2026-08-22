<?php
/**
 * REST routes for occurrence state.
 *
 * Two routes: a read route the editor sidebar uses to list a series' upcoming
 * occurrences, and the write route that sets an occurrence's status, which is
 * how cancel and un-cancel are expressed. Cancellation is occurrence state on
 * the occurrence row, never an `EXDATE` in the rule and never a term.
 *
 * The authorization contract is `current_user_can( 'edit_post', $post_id )`,
 * never `is_user_logged_in()` and never the RSVP subsystem's
 * `moderate_comments`.
 * Validating `post_id` and `recurrence_id` independently is not sufficient: the
 * underlying update must scope by both columns of the composite key, so a user
 * with `edit_post` on one series cannot cancel another series' occurrence.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Traits\Singleton;
use GatherPress\Core\Validate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Rest_Api.
 *
 * Sibling singleton to `Recurrence\Setup`, matching `Event\Rest_Api`'s shape:
 * `register_endpoints()` loops a set of route definitions, each of which is
 * built by its own `*_route()` method.
 *
 * @since 0.36.0
 */
final class Rest_Api {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Class constructor.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Set up hooks for the occurrence REST routes.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * Register the occurrence REST routes.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function register_endpoints(): void {
		$routes = $this->get_occurrence_routes();

		foreach ( $routes as $route ) {
			register_rest_route(
				sprintf( '%s/event', GATHERPRESS_REST_NAMESPACE ),
				sprintf( '/%s', $route['route'] ),
				$route['args']
			);
		}
	}

	/**
	 * Get every occurrence route definition.
	 *
	 * @since 0.36.0
	 *
	 * @return array Route definitions in `register_rest_route()` shape.
	 */
	protected function get_occurrence_routes(): array {
		return array(
			$this->occurrences_route(),
			$this->occurrence_status_route(),
		);
	}

	/**
	 * Get the occurrences-list route definition.
	 *
	 * Backs the editor sidebar's occurrence list. Read access is gated the
	 * same as the write route (`edit_post` on the series), since the list
	 * exists to drive the cancel/restore action, not for public consumption.
	 *
	 * @since 0.36.0
	 *
	 * @return array The route definition, including its `args` and `permission_callback`.
	 */
	protected function occurrences_route(): array {
		return array(
			'route' => 'occurrences',
			'args'  => array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_occurrences' ),
				'permission_callback' => array( $this, 'has_edit_permission' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => array( Validate::class, 'event_post_id' ),
					),
				),
			),
		);
	}

	/**
	 * Get the occurrence-status route definition.
	 *
	 * Validating `post_id` and `recurrence_id` independently is not
	 * sufficient authorization: a caller with `edit_post` on post A could
	 * submit A's ID alongside a `recurrence_id` belonging to post B. The
	 * composite-key scoping that closes that hole lives in
	 * `Occurrences::set_status()`, which this route's callback must treat as
	 * authoritative. A `false` return there means "no such occurrence for
	 * this post," and is reported as a 404, never silently as success.
	 *
	 * @since 0.36.0
	 *
	 * @return array The route definition, including its `args` and `permission_callback`.
	 */
	protected function occurrence_status_route(): array {
		return array(
			'route' => 'occurrence-status',
			'args'  => array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_occurrence_status' ),
				'permission_callback' => array( $this, 'has_edit_permission' ),
				'args'                => array(
					'post_id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => array( Validate::class, 'event_post_id' ),
					),
					'recurrence_id' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $param ): bool {
							return 1 === preg_match( '/^\d{8}T\d{6}$/', (string) $param );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'        => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( Occurrences::STATUS_SCHEDULED, Occurrences::STATUS_CANCELED ),
					),
				),
			),
		);
	}

	/**
	 * List a series' upcoming occurrences, canceled and scheduled alike.
	 *
	 * Canceled occurrences are included deliberately. The whole point of
	 * the sidebar list is to offer a restore action, and a canceled
	 * occurrence that dropped out of the list would have no way back.
	 *
	 * "Upcoming" is inclusive of an occurrence that has started but not
	 * finished, bounding on `datetime_end_gmt` like
	 * `Occurrences::select_bounded_occurrence()` and
	 * `Event\Query::get_datetime_comparison_column()`. The in-progress
	 * occurrence is the one most urgently needing a cancel action; only a
	 * finished occurrence has nothing left to act on.
	 *
	 * Guarded by `Query::site_has_recurring_events()`: unlike the
	 * write route, this one is reachable from every ordinary event's editor
	 * screen the moment the sidebar mounts, not just when an organizer
	 * explicitly acts on a recurring series. Without the guard, opening any
	 * event on a site that has never authored a recurring one still pays an
	 * uncached `SELECT` against the occurrence table.
	 *
	 * Reads `post_ids` from `Series::resolve_post_ids()` rather than
	 * wrapping `$post_id` alone, so a future series split across posts
	 * (the `gatherpress_series_post_ids` seam) still lists every
	 * sibling post's occurrences here. The permission callback authorizes the
	 * *requested* post only, so every resolved sibling is authorized
	 * separately before it is selected. See
	 * `authorized_series_post_ids()`.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_REST_Request $request Request carrying `post_id`.
	 *
	 * @return WP_REST_Response The upcoming occurrence rows, ordered ascending by start.
	 */
	public function get_occurrences( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Query::site_has_recurring_events() ) {
			return new WP_REST_Response( array() );
		}

		$post_id  = (int) $request->get_param( 'post_id' );
		$post_ids = $this->authorized_series_post_ids( $post_id );
		$now      = current_time( 'mysql', true );

		$rows = array_values(
			array_filter(
				Occurrences::get_instance()->select_for_series( $post_ids ),
				static function ( array $row ) use ( $now ): bool {
					return $row['datetime_end_gmt'] >= $now;
				}
			)
		);

		return new WP_REST_Response( $rows );
	}

	/**
	 * Set an occurrence's status.
	 *
	 * `Occurrences::set_status()` scopes its update by both `post_id` and
	 * `recurrence_id`. A `false` return means the composite key matched no
	 * row, either because the recurrence ID does not belong to this post or
	 * because it does not exist at all, and it is reported as a 404 rather
	 * than as success.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_REST_Request $request Request carrying `post_id`, `recurrence_id` and `status`.
	 *
	 * @return WP_REST_Response|WP_Error The updated row, or an error when the composite key matches nothing.
	 */
	public function update_occurrence_status( WP_REST_Request $request ) {
		$post_id       = (int) $request->get_param( 'post_id' );
		$recurrence_id = (string) $request->get_param( 'recurrence_id' );
		$status        = (string) $request->get_param( 'status' );

		$updated = Occurrences::get_instance()->set_status( $post_id, $recurrence_id, $status );

		if ( ! $updated ) {
			return new WP_Error(
				'gatherpress_occurrence_not_found',
				__( 'No occurrence matches the given post and recurrence ID.', 'gatherpress' ),
				array( 'status' => 404 )
			);
		}

		// The write can succeed and the row still be gone by this read: a
		// concurrent rule save reprojecting a shortened rule deletes rows. A
		// success response with a null body would make the client's
		// `updated.status` read throw and surface as a failure notice, so a
		// vanished row is reported as the 404 it has become.
		$row = Occurrences::get_instance()->get( $post_id, $recurrence_id );

		if ( null === $row ) {
			return new WP_Error(
				'gatherpress_occurrence_not_found',
				__( 'No occurrence matches the given post and recurrence ID.', 'gatherpress' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $row );
	}

	/**
	 * Resolve a post's series and drop every sibling the caller cannot edit.
	 *
	 * `has_edit_permission()` authorizes exactly one post: the `post_id` the
	 * request named. `Series::resolve_post_ids()` can return more than that
	 * post, which is the whole point of the `gatherpress_series_post_ids`
	 * seam, so selecting straight from its result would return occurrence
	 * dates and statuses for siblings no capability check ever covered. A
	 * caller who can edit A but not B must not learn anything about B.
	 *
	 * Inaccessible siblings are filtered rather than failing the whole
	 * request: a partially-visible series is a legitimate state (a sibling
	 * owned by another author, or private), and the panel's job is to offer
	 * the actions this caller may actually take. Filtering also keeps the
	 * response indistinguishable from a series that never had that sibling,
	 * so the route does not report existence it refuses to describe.
	 *
	 * This is the read half of the resolve-authorize-use invariant; the write
	 * half is `has_edit_permission()` running against the row's own owner,
	 * which is why the client submits `occurrence.series_post_id` rather than
	 * the post open in the editor.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID the request named and was authorized against.
	 *
	 * @return int[] Series post IDs the current user may edit, possibly empty.
	 */
	protected function authorized_series_post_ids( int $post_id ): array {
		$post_ids = Series::get_instance()->resolve_post_ids( $post_id );

		return array_values(
			array_filter(
				$post_ids,
				static function ( $series_post_id ): bool {
					return current_user_can( 'edit_post', (int) $series_post_id );
				}
			)
		);
	}

	/**
	 * Report whether the current user may change this occurrence's status.
	 *
	 * `current_user_can( 'edit_post', $post_id )`, never
	 * `is_user_logged_in()` and never the RSVP subsystem's `moderate_comments`.
	 *
	 * The `post_id` this authorizes is the post that owns the occurrence being
	 * mutated, not necessarily the post open in the editor: the client submits
	 * the row's own `series_post_id`, and `Occurrences::set_status()` scopes
	 * its update to that same post. Authorization, resolution, and mutation
	 * therefore all name one identity.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_REST_Request $request Request carrying the `post_id` to authorize against.
	 *
	 * @return bool True when the user can edit the series post.
	 */
	public function has_edit_permission( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
	}
}
