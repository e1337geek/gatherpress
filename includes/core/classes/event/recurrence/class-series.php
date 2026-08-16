<?php
/**
 * Series resolver.
 *
 * The PRD C-2 seam, and the single most important extension point in the
 * subsystem. Every occurrence read passes through here to turn one post ID into
 * the set of post IDs that make up its series, so every occurrence query emits
 * `series_post_id IN (…)`.
 *
 * In the POC a series is one post and this returns `array( $post_id )`. That is
 * an implementation detail, not a contract — REQ-18's forward split makes a
 * series span several posts, and code that assumed one post would have to be
 * rewritten rather than extended.
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

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Resolve a post to every post ID in its series.
	 *
	 * The result passes through the `gatherpress_series_post_ids` filter, which
	 * is the only seam by which a multi-post series can be produced: this class
	 * is `final` with a `protected` constructor, so no test can mock or subclass
	 * it. The filter name is frozen here rather than left to the implementation,
	 * because a one-post series makes `IN ( … )` and `= %d` behave identically
	 * and the review gate cannot tell them apart without it.
	 *
	 * The frozen shape, which T1 reproduces as a canonical hook docblock above
	 * its call — description "Filters the post IDs that make up an event's
	 * series.", then `@since 0.36.0`, then two params in this order:
	 * `int[] $post_ids` (post IDs in the series, default `array( $post_id )`)
	 * and `int $post_id` (the post ID being resolved). The call itself is
	 * `apply_filters( 'gatherpress_series_post_ids', array( $post_id ), $post_id )`.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to resolve.
	 *
	 * @return int[] Every post ID in the series, which is `array( $post_id )` in the POC.
	 */
	public function resolve_post_ids( int $post_id ): array {
		return array();
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}
