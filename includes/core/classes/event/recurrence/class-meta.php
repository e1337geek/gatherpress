<?php
/**
 * Owns the recurrence post-meta surface.
 *
 * Registers the single writable `gatherpress_recurrence` JSON blob plus the ten
 * derived read-only mirrors on any post type that declares
 * `gatherpress-event-date` support, mirroring the shape `Event\Meta` already
 * uses for `gatherpress_datetime`. Recurrence belongs to that support rather
 * than to the event post type, and no new `post_type_supports` flag is
 * introduced.
 *
 * Registration hooks `registered_post_type` at priority 11 and loops
 * `get_post_types_by_support( 'gatherpress-event-date' )`. Keeping it in its own
 * class rather than editing `Event\Meta` is what keeps parallel tasks' file sets
 * disjoint.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Traits\Singleton;
use stdClass;
use WP_REST_Request;

/**
 * Class Meta.
 *
 * Sibling singleton to `Recurrence\Setup`, matching `Event\Meta`'s split between
 * post-type wiring and everything that touches `register_post_meta()`.
 *
 * @since 0.36.0
 */
final class Meta {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * The writable recurrence rule meta key, holding a JSON blob.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const META_KEY = 'gatherpress_recurrence';

	/**
	 * Register the recurrence meta on a post type.
	 *
	 * @since 0.36.0
	 *
	 * @param string $post_type Post type that was just registered.
	 *
	 * @return void
	 */
	public function register( string $post_type ): void {
	}

	/**
	 * Read the recurrence blob, write the derived mirrors, and trigger projection.
	 *
	 * The recurrence counterpart to `Event\Setup::set_datetimes()`.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID whose recurrence blob was written.
	 *
	 * @return void
	 */
	public function set_recurrence( int $post_id ): void {
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Strip the derived read-only recurrence meta from REST writes.
	 *
	 * Filters `rest_pre_insert_{$post_type}`, alongside but separate from
	 * `Event\Meta::filter_readonly_meta()`.
	 *
	 * @since 0.36.0
	 *
	 * @param stdClass        $prepared_post An object representing a single post prepared for inserting or updating.
	 * @param WP_REST_Request $request       Request object.
	 *
	 * @return stdClass The prepared post object, with derived recurrence meta removed.
	 */
	public function filter_readonly_meta( stdClass $prepared_post, WP_REST_Request $request ): stdClass {
		return $prepared_post;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}
