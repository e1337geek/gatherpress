<?php
/**
 * Series resolver.
 *
 * The series-resolution seam, and the single most important extension point in the
 * subsystem. Every occurrence read passes through here to turn one post ID into
 * the set of post IDs that make up its series, so every occurrence query emits
 * `series_post_id IN (…)`.
 *
 * The durable relationship between the event posts a split produces is the
 * `_gatherpress_series` taxonomy on the **event posts**: a shared private
 * taxonomy term resolves every sibling in one lookup. Not a term on each
 * instance, which presupposes instances that are posts and is excluded by
 * #80's R1; not a Series post type, which is ECP's answer and which ECP needs
 * only because it allows multiple rules per event, which this subsystem does
 * not.
 *
 * Two properties of that choice are load-bearing and are asserted by tests
 * rather than left to the reader:
 *
 * - **No parent pointer.** Every post of a series carries the *same* term, so a
 *   series split twice resolves all three posts in one read. A
 *   `_gatherpress_series_parent` meta pointer would answer the same question by
 *   walking, and the series must not be a chain requiring traversal.
 * - **Nothing is written until the first split.** A recurring event that has
 *   never been split has no term, no term relationship and no meta. The save
 *   path stays free of series writes, and creating a term for every recurring
 *   event on the chance it might later split would be a per-event write on it.
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
	 * Taxonomy grouping the event posts a forward split produced.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const TAXONOMY = '_gatherpress_series';

	/**
	 * Resolved series membership, memoized per post for the life of the request.
	 *
	 * A single front-end response resolves the same post many times — once per
	 * occurrence rendered, plus once for every RSVP read on it — and the answer
	 * cannot change mid-request, because the only thing that changes it is a
	 * split, which is a write.
	 *
	 * @since 0.36.0
	 * @var array<int, int[]>
	 */
	protected array $memo = array();

	/**
	 * Class constructor.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Set up hooks for the series taxonomy.
	 *
	 * Priority 11 on `registered_post_type`, matching `Recurrence\Meta` — after
	 * the default priority 10 a post type normally registers at, so a companion
	 * plugin's own `add_post_type_support()` call has already landed.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_action( 'registered_post_type', array( $this, 'register' ), 11 );
	}

	/**
	 * Register the series taxonomy on a post type, when the site has recurring events.
	 *
	 * The has-recurring-events check lives on the second guard, and it is not a
	 * micro-optimization: `WP_Query` primes term caches through
	 * `update_object_term_cache()`, which reads `get_object_taxonomies()` and
	 * issues **one** query naming every taxonomy registered for the post type.
	 * Registering this taxonomy unconditionally would therefore change the SQL
	 * text of a query that runs on every event listing, on a site that has never
	 * authored a recurring event — a byte-for-byte difference where such a site
	 * is promised none.
	 *
	 * @since 0.36.0
	 *
	 * @param string $post_type Post type that was just registered.
	 *
	 * @return void
	 */
	public function register( string $post_type ): void {
		if ( ! post_type_supports( $post_type, 'gatherpress-event-date' )
			|| ! Query::site_has_recurring_events()
		) {
			return;
		}

		self::register_taxonomy_for( $post_type );
	}

	/**
	 * Register the series taxonomy, or attach it to one more post type.
	 *
	 * Called both from the `registered_post_type` hook and directly from
	 * `Splitter`, which has to be able to write a term on the very first split
	 * performed on a site whose flag was still `'0'` when post types registered.
	 *
	 * @since 0.36.0
	 *
	 * @param string $post_type Post type to attach the taxonomy to.
	 *
	 * @return void
	 */
	public static function register_taxonomy_for( string $post_type ): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			register_taxonomy_for_object_type( self::TAXONOMY, $post_type );

			return;
		}

		register_taxonomy(
			self::TAXONOMY,
			$post_type,
			array(
				'hierarchical'       => false,
				'labels'             => array( 'name' => __( 'Series', 'gatherpress' ) ),
				'public'             => false,
				'publicly_queryable' => false,
				'query_var'          => false,
				'rewrite'            => false,
				'show_admin_column'  => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_tagcloud'      => false,
				'show_ui'            => false,
			)
		);
	}

	/**
	 * Resolve a post to every post ID in its series.
	 *
	 * The result passes through the `gatherpress_series_post_ids` filter, which
	 * remains the seam by which an integration can widen a series beyond what
	 * the taxonomy records: this class is `final` with a `protected` constructor,
	 * so no test can mock or subclass it.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to resolve.
	 *
	 * @return int[] Every post ID in the series, ascending, always including `$post_id`.
	 */
	public function resolve_post_ids( int $post_id ): array {
		$post_ids = array( $post_id );

		// With no recurring events on the site the taxonomy is never registered,
		// so no term relationship can exist and none is read.
		if ( Query::site_has_recurring_events() && taxonomy_exists( self::TAXONOMY ) ) {
			$post_ids = $this->resolve_from_taxonomy( $post_id );
		}

		/**
		 * Filters the post IDs that make up an event's series.
		 *
		 * @since 0.36.0
		 *
		 * @param int[] $post_ids Post IDs in the series, default the posts sharing this post's series term.
		 * @param int   $post_id  The post ID being resolved.
		 *
		 * @return int[] Post IDs in the series.
		 */
		return apply_filters( 'gatherpress_series_post_ids', $post_ids, $post_id );
	}

	/**
	 * Read a post's series membership off the taxonomy.
	 *
	 * A post with no series term is a series of one — the whole of a site that
	 * has never split anything — and that answer costs one cached term read,
	 * never a term insert.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to resolve.
	 *
	 * @return int[] Every post ID sharing this post's series term, ascending.
	 */
	protected function resolve_from_taxonomy( int $post_id ): array {
		if ( isset( $this->memo[ $post_id ] ) ) {
			return $this->memo[ $post_id ];
		}

		$term_id = $this->term_id_for_post( $post_id );

		if ( 0 === $term_id ) {
			$this->memo[ $post_id ] = array( $post_id );

			return $this->memo[ $post_id ];
		}

		// `get_objects_in_term()` answers a `WP_Error` only for an unregistered
		// taxonomy, and `resolve_post_ids()` has already established that this
		// one is registered before calling here. The union is narrowed rather
		// than branched on, so no arm is left that no fixture can reach.
		$post_ids = array_map( 'intval', (array) get_objects_in_term( array( $term_id ), self::TAXONOMY ) );

		// The resolving post is always a member of its own series, even if the
		// relationship row is missing: returning a set that excludes it would
		// make every occurrence query silently skip its own rows.
		$post_ids[] = $post_id;
		$post_ids   = array_values( array_unique( $post_ids ) );

		sort( $post_ids );

		$this->memo[ $post_id ] = $post_ids;

		return $post_ids;
	}

	/**
	 * Get the series term one post belongs to.
	 *
	 * `get_the_terms()` rather than `wp_get_object_terms()` so the read is served
	 * from the object term cache `WP_Query` primes for the whole loop, instead of
	 * one query per rendered occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to read.
	 *
	 * @return int The term ID, or 0 when the post belongs to no series term.
	 */
	public function term_id_for_post( int $post_id ): int {
		$terms = get_the_terms( $post_id, self::TAXONOMY );

		// `get_the_terms()` answers `false` for a post with no terms and a
		// `WP_Error` for an unregistered taxonomy. The array test has to come
		// first: `isset()` on an offset of an object that is not `ArrayAccess`
		// is a fatal `Error` in PHP 8, not a falsy read, so testing the offset
		// alone crashed on the error shape rather than absorbing it.
		return is_array( $terms ) && isset( $terms[0] ) ? (int) $terms[0]->term_id : 0;
	}

	/**
	 * Record that two event posts belong to one series.
	 *
	 * The term comes into existence here, at the moment of the first split, and
	 * every later split of **either** post joins that same term rather than
	 * pointing at its predecessor. That is what makes a thrice-split series one
	 * read instead of a walk.
	 *
	 * @since 0.36.0
	 *
	 * @param int $origin_post_id  Post the series already existed on.
	 * @param int $forward_post_id Post the split just created.
	 *
	 * @return int The series term ID, or 0 when the term could not be created.
	 */
	public function join( int $origin_post_id, int $forward_post_id ): int {
		self::register_taxonomy_for( (string) get_post_type( $origin_post_id ) );

		$term_id = $this->term_id_for_post( $origin_post_id );

		if ( 0 === $term_id ) {
			$term_id = $this->create_term_for( $origin_post_id );

			if ( 0 === $term_id ) {
				return 0;
			}

			wp_set_object_terms( $origin_post_id, array( $term_id ), self::TAXONOMY );
		}

		wp_set_object_terms( $forward_post_id, array( $term_id ), self::TAXONOMY );

		$this->flush_memo();

		return $term_id;
	}

	/**
	 * Create the series term naming one post.
	 *
	 * The slug names the post the series started on, which is stable and
	 * readable; nothing derives meaning from it, so a later split of a later post
	 * does not rename it.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post the series started on.
	 *
	 * @return int The term ID, or 0 when the term could neither be created nor recovered.
	 */
	protected function create_term_for( int $post_id ): int {
		$slug   = sprintf( 'series-%d', $post_id );
		$result = wp_insert_term( $slug, self::TAXONOMY, array( 'slug' => $slug ) );

		if ( ! is_wp_error( $result ) ) {
			return (int) $result['term_id'];
		}

		// A term left behind by an earlier split whose relationship rows were
		// removed (a restored-from-trash post, a partial import) is recovered
		// rather than treated as a failure -- `wp_insert_term()` reports the
		// existing term's ID in the error data.
		$existing = $result->get_error_data( 'term_exists' );

		return is_numeric( $existing ) ? (int) $existing : 0;
	}

	/**
	 * Discard the request-scoped membership memo.
	 *
	 * Production needs this on exactly one path — a split, which changes
	 * membership inside the request that performs it. Tests split and re-read
	 * inside one PHP lifetime and need it for the same reason.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function flush_memo(): void {
		$this->memo = array();
	}
}
