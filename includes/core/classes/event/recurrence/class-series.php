<?php
/**
 * Series resolver.
 *
 * The series-resolution seam, and the single most important extension point in the
 * subsystem. Every occurrence read passes through here to turn one post ID into
 * the set of post IDs that make up its series, so every occurrence query emits
 * `series_post_id IN (…)`.
 *
 * Today a series is one post and this returns `array( $post_id )`. That is
 * an implementation detail, not a contract. The forward split makes a series
 * span several posts, so code that assumed one post would have to be rewritten
 * rather than extended.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Traits\Singleton;

/**
 * Class Series.
 *
 * Singleton resolver mapping a post to the posts of its series.
 *
 * @since 0.36.0
 */
final class Series {

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
	 * Resolve a post to every post ID in its series.
	 *
	 * The result passes through the `gatherpress_series_post_ids` filter, which
	 * is the only seam by which a multi-post series can be produced: this class
	 * is `final` with a `protected` constructor, so no test can mock or subclass
	 * it. The filter name is frozen here rather than left to the implementation,
	 * because a one-post series makes `IN ( … )` and `= %d` behave identically
	 * and nothing else in the code marks the difference.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to resolve.
	 *
	 * @return int[] Every post ID in the series, which is currently `array( $post_id )`.
	 */
	public function resolve_post_ids( int $post_id ): array {
		/**
		 * Filters the post IDs that make up an event's series.
		 *
		 * @since 0.36.0
		 *
		 * @param int[] $post_ids Post IDs in the series, default `array( $post_id )`.
		 * @param int   $post_id  The post ID being resolved.
		 *
		 * @return int[] Post IDs in the series.
		 */
		return apply_filters( 'gatherpress_series_post_ids', array( $post_id ), $post_id );
	}
}
