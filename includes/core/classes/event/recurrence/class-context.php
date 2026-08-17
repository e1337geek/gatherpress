<?php
/**
 * Request-scoped occurrence context, and the read path that feeds unmodified blocks.
 *
 * `Event::get_datetime()` reads from post meta rather than from the events
 * table, so filtering `get_post_metadata` is enough to make
 * every existing date-aware block render an occurrence's date without a single
 * block file changing.
 *
 * An occurrence's time of day comes from the occurrence record. It is
 * never computed by applying the series anchor's time to the occurrence's date.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Event;
use GatherPress\Core\Traits\Singleton;
use WP_Post;

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
	 * Occurrence table column serving each derived datetime meta key.
	 *
	 * These five keys are exactly the ones `Event::get_datetime()` reads, which
	 * is what makes a `get_post_metadata` filter sufficient to redirect every
	 * unmodified block's date read at the occurrence record. The array is
	 * ordered and keyed so its values double as the unprefixed keys of the
	 * `Event::get_datetime()` return shape.
	 *
	 * @since 0.36.0
	 * @var array<string, string>
	 */
	const META_KEY_COLUMNS = array(
		'gatherpress_datetime_start'     => 'datetime_start',
		'gatherpress_datetime_start_gmt' => 'datetime_start_gmt',
		'gatherpress_datetime_end'       => 'datetime_end',
		'gatherpress_datetime_end_gmt'   => 'datetime_end_gmt',
		'gatherpress_timezone'           => 'timezone',
	);

	/**
	 * The occurrence row the request is rendering, or null outside context.
	 *
	 * @since 0.36.0
	 * @var array|null
	 */
	protected ?array $occurrence = null;

	/**
	 * Whether the metadata filter is already inside a meta read of its own.
	 *
	 * The filter falls back to the series' own meta when an occurrence row's
	 * nullable `timezone` column is empty, and that fallback is a
	 * `get_post_meta()` call on the very key the filter is answering. Without
	 * this flag the call re-enters the filter, which re-enters the fallback,
	 * without end.
	 *
	 * @since 0.36.0
	 * @var bool
	 */
	protected bool $reading = false;

	/**
	 * Class constructor.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Set up hooks for occurrence context.
	 *
	 * Context is established on `wp`, after the main query has resolved and
	 * before `template_redirect` or any block renders. It is resynchronized on
	 * `the_post` and `wp_reset_postdata`, both of which move the loop to a post
	 * that may not be the requested occurrence's — a single callback clears
	 * first and re-derives from the request, so a Query Loop over unrelated
	 * events cannot inherit a stale occurrence value and a singular occurrence
	 * request does not lose its context the moment the loop starts.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_filter( 'get_post_metadata', array( $this, 'metadata' ), 10, 4 );
		add_action( 'wp', array( $this, 'sync' ) );
		add_action( 'the_post', array( $this, 'sync' ) );
		add_action( 'wp_reset_postdata', array( $this, 'sync' ) );
	}

	/**
	 * Enter the context of one occurrence.
	 *
	 * Context is only entered when the composite key resolves to a real row, so
	 * a fabricated or stale recurrence identifier leaves the request reading the
	 * series' own datetime rather than nothing at all.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return void
	 */
	public function set( int $post_id, string $recurrence_id ): void {
		$this->occurrence = Occurrences::get_instance()->get( $post_id, $recurrence_id );
	}

	/**
	 * Leave the current occurrence context.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->occurrence = null;
	}

	/**
	 * Get the occurrence the request is currently rendering.
	 *
	 * @since 0.36.0
	 *
	 * @return array|null The occurrence row, or null outside occurrence context.
	 */
	public function current(): ?array {
		return $this->occurrence;
	}

	/**
	 * Resynchronize occurrence context with the request and the current post.
	 *
	 * Hooked to `wp`, `the_post` and `wp_reset_postdata`. Each of those is a
	 * point where the post being rendered can change, so the context is dropped
	 * and then re-derived rather than merely cleared: dropping alone would kill
	 * a singular occurrence render the moment the loop started.
	 *
	 * @since 0.36.0
	 *
	 * @param mixed $post The post the loop moved to on `the_post`, or the `WP` instance on `wp`.
	 *
	 * @return void
	 */
	public function sync( $post = null ): void {
		$this->clear();

		$post_id = ( $post instanceof WP_Post ) ? $post->ID : get_queried_object_id();

		$this->maybe_set_from_request( (int) $post_id );
	}

	/**
	 * Enter occurrence context when the request asks for one on this post.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID the loop or the main query is on.
	 *
	 * @return void
	 */
	protected function maybe_set_from_request( int $post_id ): void {
		$recurrence_id = (string) get_query_var( self::QUERY_VAR );

		if ( '' === $recurrence_id || 1 > $post_id ) {
			return;
		}

		$this->set( $post_id, $recurrence_id );
	}

	/**
	 * Serve the occurrence's datetime for the five derived meta keys.
	 *
	 * Filters `get_post_metadata`. Outside occurrence context it returns the
	 * value untouched, so the meta read falls through to core. This is the
	 * compatibility path that lets unmodified blocks render an occurrence's
	 * date; code inside the recurrence subsystem calls `get_datetime()`
	 * instead.
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
		// Stand aside while already reading meta of our own (see `$reading`),
		// outside occurrence context, for keys the occurrence record does not
		// own, and for any post other than the one the context belongs to.
		if (
			$this->reading
			|| null === $this->occurrence
			|| ! isset( self::META_KEY_COLUMNS[ $meta_key ] )
			|| (int) $this->occurrence['series_post_id'] !== $object_id
		) {
			return $value;
		}

		$result = $this->occurrence_value( $meta_key );

		return $single ? $result : array( $result );
	}

	/**
	 * Read one derived datetime value from the occurrence record.
	 *
	 * PRD C-3 lives here: every value comes from the occurrence row's own
	 * column. Nothing recombines the occurrence's date with the series anchor's
	 * time of day, which is what would foreclose multiple-times-per-day rules
	 * later.
	 *
	 * The occurrence table's `timezone` column is nullable, so an empty column
	 * falls back to the series post's own timezone meta rather than to the site
	 * default.
	 *
	 * @since 0.36.0
	 *
	 * @param string $meta_key One of the keys of `META_KEY_COLUMNS`.
	 *
	 * @return mixed The occurrence's value, or the series' own value when the column is empty.
	 */
	protected function occurrence_value( string $meta_key ) {
		$value = $this->occurrence[ self::META_KEY_COLUMNS[ $meta_key ] ];

		return ( null === $value || '' === $value )
			? $this->read_series_meta( (int) $this->occurrence['series_post_id'], $meta_key )
			: $value;
	}

	/**
	 * Read a series post's own meta without the occurrence substitution.
	 *
	 * Raising `$reading` for the duration is what stops `metadata()` from
	 * re-entering itself: the nested `get_post_metadata` firing this call
	 * triggers sees the flag and returns the value untouched, so the read
	 * reaches core.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id  Series post ID.
	 * @param string $meta_key Meta key to read.
	 *
	 * @return mixed The series' own stored value.
	 */
	protected function read_series_meta( int $post_id, string $meta_key ) {
		$this->reading = true;
		$value         = get_post_meta( $post_id, $meta_key, true );
		$this->reading = false;

		return $value;
	}

	/**
	 * Get a post's datetime, occurrence-aware.
	 *
	 * The explicit accessor for new code inside the recurrence subsystem, which
	 * reads the occurrence record directly rather than relying on the
	 * `get_post_metadata` filter. Returns the same five-key shape as
	 * `Event::get_datetime()`, and falls back to it whenever the request is not
	 * rendering an occurrence of this post.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to read.
	 *
	 * @return array The `Event::get_datetime()` shape, carrying the occurrence's values in context.
	 */
	public function get_datetime( int $post_id ): array {
		if ( null === $this->occurrence || (int) $this->occurrence['series_post_id'] !== $post_id ) {
			return ( new Event( $post_id ) )->get_datetime();
		}

		$data = array();

		foreach ( self::META_KEY_COLUMNS as $meta_key => $column ) {
			$data[ $column ] = (string) $this->occurrence_value( $meta_key );
		}

		return $data;
	}

	/**
	 * Build the permalink of one occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return string The occurrence URL, or the series permalink when no occurrence is named.
	 */
	public static function occurrence_url( int $post_id, string $recurrence_id ): string {
		$permalink = (string) get_permalink( $post_id );

		if ( '' === $permalink || '' === $recurrence_id ) {
			return $permalink;
		}

		return (string) add_query_arg( self::QUERY_VAR, $recurrence_id, $permalink );
	}
}
