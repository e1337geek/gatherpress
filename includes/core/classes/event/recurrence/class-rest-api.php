<?php
/**
 * REST routes for occurrence state.
 *
 * Two routes: a read route the editor sidebar uses to list a series' upcoming
 * occurrences, and the write route that sets an occurrence's status, which is
 * how cancel and un-cancel are expressed. Cancellation is occurrence
 * state on the occurrence row, never an `EXDATE` in the rule and
 * never a term.
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
	 * Backs the editor sidebar's occurrence list -- read access is gated the
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
	 * authoritative -- a `false` return there means "no such occurrence for
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
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( Occurrences::STATUS_SCHEDULED, Occurrences::STATUS_CANCELLED ),
						'validate_callback' => static function ( $param ): bool {
							return in_array(
								$param,
								array( Occurrences::STATUS_SCHEDULED, Occurrences::STATUS_CANCELLED ),
								true
							);
						},
					),
				),
			),
		);
	}

	/**
	 * List a series' upcoming occurrences, cancelled and scheduled alike.
	 *
	 * Cancelled occurrences are included deliberately -- the whole point of
	 * the sidebar list is to offer a restore action, and a cancelled
	 * occurrence that dropped out of the list would have no way back.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_REST_Request $request Request carrying `post_id`.
	 *
	 * @return WP_REST_Response The upcoming occurrence rows, ordered ascending by start.
	 */
	public function get_occurrences( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$now     = current_time( 'mysql', true );

		$rows = array_values(
			array_filter(
				Occurrences::get_instance()->select_for_series( array( $post_id ) ),
				static function ( array $row ) use ( $now ): bool {
					return $row['datetime_start_gmt'] >= $now;
				}
			)
		);

		return new WP_REST_Response( $rows );
	}

	/**
	 * Set an occurrence's status.
	 *
	 * `Occurrences::set_status()` scopes its update by both `post_id` and
	 * `recurrence_id`; a `false` return means the composite key matched no
	 * row -- either the recurrence ID does not belong to this post, or it
	 * does not exist at all -- and is reported as a 404 rather than as
	 * success (PRD C-1, C-2).
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

		return new WP_REST_Response( Occurrences::get_instance()->get( $post_id, $recurrence_id ) );
	}

	/**
	 * Report whether the current user may change this occurrence's status.
	 *
	 * `current_user_can( 'edit_post', $post_id )` -- never
	 * `is_user_logged_in()`, never the RSVP subsystem's `moderate_comments`.
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
