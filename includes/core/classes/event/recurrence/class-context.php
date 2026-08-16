<?php
/**
 * Request-scoped occurrence context, and the read path that feeds unmodified blocks.
 *
 * REQ-9 rests on this class. `Event::get_datetime()` reads from post meta rather
 * than from the events table, so filtering `get_post_metadata` is enough to make
 * every existing date-aware block render an occurrence's date without a single
 * block file changing.
 *
 * PRD C-3 — an occurrence's time of day comes from the occurrence record. It is
 * never computed by applying the series anchor's time to the occurrence's date.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Traits\Singleton;

/**
 * Class Context.
 *
 * Singleton holding the occurrence the current request is rendering, if any.
 * Context is set on request resolution and cleared on teardown, so no stale
 * occurrence value can leak into a later loop.
 *
 * @since 0.36.0
 */
final class Context {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Query var carrying the occurrence segment of a permalink.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const QUERY_VAR = 'gatherpress_occurrence';

	/**
	 * Enter the context of one occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return void
	 */
	public function set( int $post_id, string $recurrence_id ): void {
	}

	/**
	 * Leave the current occurrence context.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function clear(): void {
	}

	/**
	 * Get the occurrence the request is currently rendering.
	 *
	 * @since 0.36.0
	 *
	 * @return array|null The occurrence row, or null outside occurrence context.
	 * @phpstan-ignore-next-line -- T0 skeleton; the non-null return lands with the implementation.
	 */
	public function current(): ?array {
		return null;
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Serve the occurrence's datetime for the five derived meta keys.
	 *
	 * Filters `get_post_metadata`. Outside occurrence context it returns the
	 * value untouched, so the meta read falls through to core.
	 *
	 * @since 0.36.0
	 *
	 * @param mixed  $value      Short-circuit value, null when nothing has filtered yet.
	 * @param int    $object_id  Post ID the meta is read from.
	 * @param string $meta_key   Meta key being read.
	 * @param bool   $single     Whether a single value was requested.
	 *
	 * @return mixed The occurrence's value in context, otherwise the value unchanged.
	 */
	public function metadata( $value, int $object_id, string $meta_key, bool $single ) {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Build the permalink of one occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return string The occurrence URL.
	 */
	public static function occurrence_url( int $post_id, string $recurrence_id ): string {
		return '';
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}
