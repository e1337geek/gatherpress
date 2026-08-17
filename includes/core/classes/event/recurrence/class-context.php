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
	 * The `array( $object_id, $meta_key )` pair a meta write is about to compare.
	 *
	 * `update_metadata()` and `add_metadata()` discover the currently stored
	 * value through `get_metadata_raw()`, which fires this class's filter. With
	 * context set, that comparison would see the occurrence's value, so a write
	 * setting the series' start to the occurrence's current start would look
	 * like a no-op and be dropped. The pair is noted on the way in and consumed
	 * once, by the read the write itself performs.
	 *
	 * @since 0.36.0
	 * @var array|null
	 */
	protected ?array $writing = null;

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
	 * Context is established once, on `wp`, after the main query has resolved
	 * and before `template_redirect` or any block renders. It deliberately does
	 * not track the loop. Re-deriving on `the_post` would bind context to
	 * whichever post the loop reached, and since `recurrence_id` is `Ymd\THis`
	 * two series projected from the same rule share one — so an inner loop over
	 * a sibling series would silently adopt the requested occurrence's date. It
	 * would also cost an uncached occurrence query per iteration, and any inner
	 * loop that forgot `wp_reset_postdata()` would drop the request's context
	 * for every block below it.
	 *
	 * Isolation instead comes from `metadata()`, which serves the occurrence
	 * only for the post the context belongs to. That is scoping by identity
	 * rather than by loop position, which is what PRD C-1 asks for.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_filter( 'get_post_metadata', array( $this, 'metadata' ), 10, 4 );
		add_action( 'wp', array( $this, 'sync' ) );
		add_filter( 'update_post_metadata', array( $this, 'note_meta_write' ), 10, 3 );
		add_filter( 'add_post_metadata', array( $this, 'note_meta_write' ), 10, 3 );
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
	 * Establish occurrence context from the resolved request.
	 *
	 * Hooked to `wp`. Clears first so a previous request in the same process
	 * cannot leak, then derives context from the post the request actually
	 * named — never from whatever post a loop later reaches.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function sync(): void {
		$this->clear();

		$this->maybe_set_from_request( get_queried_object_id() );
	}

	/**
	 * Enter occurrence context when the request asks for one on this post.
	 *
	 * The `site_has_recurring_events()` check is REQ-16 on the read path, and it
	 * is evaluated here at callback time rather than at `setup_hooks()` so a
	 * mid-request flip of the option is honored. Without it, any crawler or
	 * referrer-spam bot appending the occurrence query string to an ordinary
	 * event permalink would reach `Occurrences::get()` — a raw, uncached
	 * `$wpdb->get_row()` — on a site that has never authored a recurring event.
	 *
	 * The remaining two arms never change the resulting context, since
	 * `Occurrences::get()` matches nothing for an empty identifier or a post ID
	 * of zero. They exist solely to skip that query.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID the request resolved to.
	 *
	 * @return void
	 */
	protected function maybe_set_from_request( int $post_id ): void {
		$recurrence_id = (string) get_query_var( self::QUERY_VAR );

		if ( ! Query::site_has_recurring_events() || '' === $recurrence_id || 1 > $post_id ) {
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
		// Stand aside for the read a meta write makes to decide whether the
		// stored value already equals the value being written. Checked and
		// consumed before every other guard, and unconditionally, so a note can
		// never outlive the write that left it and disable substitution for
		// that key later.
		if ( array( $object_id, $meta_key ) === $this->writing ) {
			$this->writing = null;

			return $value;
		}

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
	 * Note the post and key a meta write is about to compare against.
	 *
	 * Filters `update_post_metadata` and `add_post_metadata`, both of which fire
	 * immediately before core reads the currently stored value. The value passes
	 * through untouched — this is a notification, not a short-circuit.
	 *
	 * @since 0.36.0
	 *
	 * @param mixed  $check     Short-circuit value, null when nothing has filtered yet.
	 * @param int    $object_id Post ID being written to.
	 * @param string $meta_key  Meta key being written.
	 *
	 * @return mixed The short-circuit value, unchanged.
	 */
	public function note_meta_write( $check, int $object_id, string $meta_key ) {
		$this->writing = array( $object_id, $meta_key );

		return $check;
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
	 * The `finally` is load-bearing. Any third-party `get_post_metadata` filter
	 * that throws would otherwise leave the flag raised for the rest of the
	 * request, silently disabling occurrence substitution everywhere below —
	 * a wrong date with no error anywhere to explain it.
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

		try {
			return get_post_meta( $post_id, $meta_key, true );
		} finally {
			$this->reading = false;
		}
	}

	/**
	 * Build the datetime-cache key a post's `Event` instance should use.
	 *
	 * `Event::$datetime_cache` is keyed by this rather than held in a single
	 * slot, because nothing stops a plugin or theme from constructing an `Event`
	 * and reading its datetime before context is established on `wp`; a single
	 * slot would hand that instance the series' values for the rest of its life.
	 *
	 * The post ID is part of the decision because a `recurrence_id` is
	 * `Ymd\THis`, so two series share one whenever they occur at the same
	 * moment. Keying on the identifier alone would let one series' `Event` serve
	 * another series' cached entry — PRD C-1, identity is the composite.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID the `Event` instance wraps.
	 *
	 * @return string The occurrence's identifier when the context is this post's, otherwise an empty string.
	 */
	public function cache_key( int $post_id ): string {
		return ( null !== $this->occurrence && (int) $this->occurrence['series_post_id'] === $post_id )
			? (string) $this->occurrence['recurrence_id']
			: '';
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
	 * Delegates to `Rewrite::get_occurrence_url()`, which owns the canonical
	 * `/{event-slug}/{postname}/{Ymd\THis}/` form and the
	 * `gatherpress_recurrence_id_format` filter. This method predates that one
	 * and originally composed a `?gatherpress_occurrence=` query arg; keeping
	 * both shapes would mean two URLs resolving to the same occurrence, only
	 * one of which is canonical, and would let callers here emit links that
	 * never exercise the rewrite rules.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return string The occurrence URL, or the series permalink when no occurrence is named.
	 */
	public static function occurrence_url( int $post_id, string $recurrence_id ): string {
		if ( '' === $recurrence_id ) {
			return (string) get_permalink( $post_id );
		}

		return Rewrite::get_occurrence_url( $post_id, $recurrence_id );
	}
}
