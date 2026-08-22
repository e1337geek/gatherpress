<?php
/**
 * REST routes for occurrence state.
 *
 * One route so far: setting an occurrence's status, which is how cancel and
 * un-cancel are expressed. Cancellation is occurrence state on the occurrence
 * row, never an `EXDATE` in the rule and never a term.
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
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

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
	 * Protected, so the singleton contract is structural rather than
	 * conventional: the class is reached through `get_instance()` and
	 * external construction fails instead of quietly minting a second
	 * instance.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
	}

	/**
	 * Register the occurrence REST routes.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function register_endpoints(): void {
	}

	/**
	 * Get every occurrence route definition.
	 *
	 * @since 0.36.0
	 *
	 * @return array Route definitions in `register_rest_route()` shape.
	 */
	protected function get_occurrence_routes(): array {
		return array();
	}

	/**
	 * Get the occurrence-status route definition.
	 *
	 * @since 0.36.0
	 *
	 * @return array The route definition, including its `args` and `permission_callback`.
	 */
	protected function occurrence_status_route(): array {
		return array();
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Unimplemented stub; delete with the body.
	/**
	 * Set an occurrence's status.
	 *
	 * `$request` is unread: this is a frozen signature whose body, the
	 * composite-key row update that reads `post_id`, `recurrence_id` and
	 * `status` off the request, lands on a later branch.
	 *
	 * @since 0.36.0
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param WP_REST_Request $request Request carrying `post_id`, `recurrence_id` and `status`.
	 *
	 * @return WP_REST_Response|WP_Error|null The updated row, or an error when the composite key matches nothing.
	 * @phpstan-ignore-next-line -- Unimplemented stub; the non-null return lands with the implementation.
	 */
	public function update_occurrence_status( WP_REST_Request $request ) {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Unimplemented stub; delete with the body.
	/**
	 * Report whether the current user may change this occurrence's status.
	 *
	 * `$request` is unread: this is a frozen signature whose body, the
	 * `current_user_can( 'edit_post', $post_id )` check that reads `post_id` off
	 * the request, lands on a later branch.
	 *
	 * @since 0.36.0
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param WP_REST_Request $request Request carrying the `post_id` to authorize against.
	 *
	 * @return bool True when the user can edit the series post.
	 */
	public function has_edit_permission( WP_REST_Request $request ): bool {
		return false;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}
