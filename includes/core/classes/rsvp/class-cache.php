<?php
/**
 * Manages RSVP caches.
 *
 * This class is responsible for caching RSVP information.
 *
 * @package GatherPress\Core\Rsvp
 * @since 0.35.0
 */

namespace GatherPress\Core\Rsvp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Event\Recurrence\Rsvp_Occurrence;

/**
 * Class of RSVP caches.
 *
 * This class is responsible for caching RSVP information.
 *
 * @since 0.35.0
 */
final class Cache {

	/**
	 * Cache key format for RSVPs.
	 *
	 * @since 0.35.0
	 *
	 * @var string $CACHE_KEY
	 */
	const CACHE_KEY = 'gatherpress_rsvp_%d';

	/**
	 * Cache key format for the RSVPs of one occurrence of a series.
	 *
	 * The series-wide key above is post-ID-only, which under a persistent
	 * object cache would serve one occurrence's viewer the counts of whichever
	 * occurrence was rendered first. The dimension therefore ships in the same
	 * change that makes RSVPs occurrence-scoped: adding it afterwards would be
	 * a breaking change for every site running Redis or Memcached, because
	 * their already-warm entries carry no occurrence in the key.
	 *
	 * @since 0.36.0
	 *
	 * @var string $CACHE_KEY_OCCURRENCE
	 */
	const CACHE_KEY_OCCURRENCE = 'gatherpress_rsvp_%d_%s';

	/**
	 * Lifetime of an RSVP cache entry, in seconds.
	 *
	 * @since 0.35.0
	 *
	 * @var int
	 */
	const CACHE_EXPIRATION = 15 * MINUTE_IN_SECONDS;

	/**
	 * Get the RSVP cache for an event by the event's WordPress post ID.
	 *
	 * Backed by a transient so the cache persists across requests on any
	 * site, and is transparently served from a persistent object cache
	 * (Redis / Memcached) when one is enabled.
	 *
	 * @since 0.35.0
	 *
	 * @param int         $post_id       The WordPress post ID of the event.
	 * @param string|null $recurrence_id Optional. Occurrence to read, or null to resolve from the request.
	 *
	 * @return array|null The cached RSVP data, or null when no valid cache exists.
	 */
	public static function get( int $post_id, ?string $recurrence_id = null ): ?array {
		$value = get_transient( self::cache_key( $post_id, self::resolve_recurrence_id( $post_id, $recurrence_id ) ) );

		if ( empty( $value ) || ! is_array( $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Set a GatherPress RSVP cache.
	 *
	 * @since 0.35.0
	 *
	 * @param int         $post_id       The WordPress post ID of the event.
	 * @param mixed       $value         The cache value to set.
	 * @param string|null $recurrence_id Optional. Occurrence to write, or null to resolve from the request.
	 *
	 * @return void
	 */
	public static function set( int $post_id, $value, ?string $recurrence_id = null ): void {
		set_transient(
			self::cache_key( $post_id, self::resolve_recurrence_id( $post_id, $recurrence_id ) ),
			$value,
			self::CACHE_EXPIRATION
		);
	}

	/**
	 * Delete an RSVP cache for an event.
	 *
	 * Both keys are dropped on every write. A response saved against one
	 * occurrence changes that occurrence's counts and the series' own, and the
	 * two are stored separately, so invalidating only the key the request
	 * happens to be scoped to would leave the other serving stale numbers.
	 *
	 * @since 0.35.0
	 *
	 * @param int         $post_id       The WordPress post ID of the event.
	 * @param string|null $recurrence_id Optional. Occurrence to drop, or null to resolve from the request.
	 *
	 * @return void
	 */
	public static function delete( int $post_id, ?string $recurrence_id = null ): void {
		delete_transient( self::cache_key( $post_id ) );

		$recurrence_id = self::resolve_recurrence_id( $post_id, $recurrence_id );

		if ( null !== $recurrence_id ) {
			delete_transient( self::cache_key( $post_id, $recurrence_id ) );
		}
	}

	/**
	 * Resolve which occurrence a cache operation belongs to.
	 *
	 * An explicit identifier always wins; passing none asks the request, which
	 * is what keeps every existing single-argument call site occurrence-aware
	 * without changing it. On a site with no recurring events the lookup
	 * short-circuits before any occurrence machinery is touched (REQ-16).
	 *
	 * @since 0.36.0
	 *
	 * @param int         $post_id       The WordPress post ID of the event.
	 * @param string|null $recurrence_id The caller's occurrence identifier, if any.
	 *
	 * @return string|null The occurrence identifier, or null for the series-wide key.
	 */
	private static function resolve_recurrence_id( int $post_id, ?string $recurrence_id ): ?string {
		if ( null !== $recurrence_id ) {
			return $recurrence_id;
		}

		return Rsvp_Occurrence::current_recurrence_id( $post_id );
	}

	/**
	 * Get the cache key.
	 *
	 * @since 0.35.0
	 *
	 * @param mixed       $post_id       The WordPress post ID of the event.
	 * @param string|null $recurrence_id Optional. Occurrence the key is scoped to, or null for the series.
	 *
	 * @return string The cache key for the given post ID.
	 */
	private static function cache_key( $post_id, ?string $recurrence_id = null ): string {
		return ( null === $recurrence_id || '' === $recurrence_id )
			? sprintf( self::CACHE_KEY, $post_id )
			: sprintf( self::CACHE_KEY_OCCURRENCE, $post_id, $recurrence_id );
	}
}
