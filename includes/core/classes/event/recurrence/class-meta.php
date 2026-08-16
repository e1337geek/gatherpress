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
 * Registration hooks `registered_post_type` at priority 11, after the default
 * priority 10 a post type normally registers at, so a companion plugin's own
 * `add_post_type_support()` call has already landed by the time this runs.
 * Keeping it in its own class rather than editing `Event\Meta` is what keeps
 * parallel tasks' file sets disjoint.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Traits\Singleton;
use GatherPress\Core\Utility;
use InvalidArgumentException;
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
	 * The ten derived, read-only recurrence meta keys.
	 *
	 * @since 0.36.0
	 * @var string[]
	 */
	const DERIVED_META_KEYS = array(
		'gatherpress_recurrence_frequency',
		'gatherpress_recurrence_interval',
		'gatherpress_recurrence_byday',
		'gatherpress_recurrence_monthly_mode',
		'gatherpress_recurrence_monthly_day',
		'gatherpress_recurrence_monthly_ordinal',
		'gatherpress_recurrence_monthly_weekday',
		'gatherpress_recurrence_end_type',
		'gatherpress_recurrence_until',
		'gatherpress_recurrence_count',
	);

	/**
	 * Posts whose recurrence blob still needs a late reconciliation pass.
	 *
	 * Two writers land a post here. `set_recurrence()` queues a post whose
	 * blob was empty at `wp_after_insert_post` time, because REST,
	 * duplication, and import can all write the blob after the insert
	 * completes (mirrors `Event\Setup::$pending_datetimes`). And
	 * `maybe_queue_reconciliation()` queues a post on any write to the blob
	 * itself, which is what catches writers that never fire the save hook at
	 * all: WP-CLI's `wp post meta update`, an importer updating an existing
	 * post, or any direct `update_post_meta()` call. Rather than guess, the
	 * post is noted and decided once more on `shutdown`, when every write
	 * this request is going to make has already happened. A save-path
	 * derivation that runs after the queuing write removes the entry again,
	 * so the ordinary editor save derives once, not twice.
	 *
	 * @since 0.36.0
	 * @var array<int, bool>
	 */
	protected array $pending_recurrence = array();

	/**
	 * Class constructor.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Set up hooks for recurrence meta registration and projection.
	 *
	 * The three meta hooks watch writes to the canonical blob itself, so a
	 * writer that never fires `wp_after_insert_post` after the blob lands
	 * (WP-CLI, an importer updating an existing post, direct
	 * `update_post_meta()` calls) still gets its mirrors reconciled on
	 * `shutdown`. The callbacks bail on the meta-key comparison alone for
	 * every other key, so they add no query and no write to unrelated saves.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_action( 'registered_post_type', array( $this, 'register' ), 11 );
		add_action( 'wp_after_insert_post', array( $this, 'set_recurrence' ) );
		add_action( 'added_post_meta', array( $this, 'maybe_queue_reconciliation' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'maybe_queue_reconciliation' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'maybe_queue_reconciliation' ), 10, 3 );
	}

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
		if ( ! post_type_supports( $post_type, 'gatherpress-event-date' ) ) {
			return;
		}

		register_post_meta(
			$post_type,
			self::META_KEY,
			array(
				'auth_callback'     => array( Utility::class, 'can_edit_post_meta' ),
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);

		$derived_meta = array(
			'gatherpress_recurrence_frequency'       => array( 'sanitize_text_field', 'string' ),
			'gatherpress_recurrence_interval'        => array( 'absint', 'integer' ),
			'gatherpress_recurrence_byday'           => array( 'sanitize_text_field', 'string' ),
			'gatherpress_recurrence_monthly_mode'    => array( 'sanitize_text_field', 'string' ),
			'gatherpress_recurrence_monthly_day'     => array( 'absint', 'integer' ),
			'gatherpress_recurrence_monthly_ordinal' => array( array( self::class, 'sanitize_signed_int' ), 'integer' ),
			'gatherpress_recurrence_monthly_weekday' => array( 'absint', 'integer' ),
			'gatherpress_recurrence_end_type'        => array( 'sanitize_text_field', 'string' ),
			'gatherpress_recurrence_until'           => array( 'sanitize_text_field', 'string' ),
			'gatherpress_recurrence_count'           => array( 'absint', 'integer' ),
		);

		foreach ( $derived_meta as $meta_key => $meta_args ) {
			[ $sanitize_callback, $type ] = $meta_args;

			register_post_meta(
				$post_type,
				$meta_key,
				array(
					'auth_callback'     => '__return_false', // Read-only: derived from gatherpress_recurrence.
					'sanitize_callback' => $sanitize_callback,
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => $type,
				)
			);
		}

		// Filter read-only recurrence meta from REST requests for this post type.
		add_filter(
			sprintf( 'rest_pre_insert_%s', $post_type ),
			array( $this, 'filter_readonly_meta' ),
			10,
			2
		);
	}

	/**
	 * Sanitize a value to a signed integer.
	 *
	 * `gatherpress_recurrence_monthly_ordinal` can legitimately be negative
	 * (`-1` for "last"), which rules out `absint()`. This can't be the bare
	 * `intval` string either: WordPress calls a meta `sanitize_callback` with
	 * more arguments than `intval()`'s internal signature accepts, and PHP 8
	 * throws `ArgumentCountError` for excess arguments on internal functions.
	 *
	 * @since 0.36.0
	 *
	 * @param mixed $value Raw meta value to sanitize.
	 *
	 * @return int The value cast to a signed integer.
	 */
	public static function sanitize_signed_int( $value ): int {
		return (int) $value;
	}

	/**
	 * Read the recurrence blob, write the derived mirrors, and trigger projection.
	 *
	 * The recurrence counterpart to `Event\Setup::set_datetimes()`, including
	 * the same deferred-to-`shutdown` handling for a blob that has not landed
	 * yet on this pass.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID whose recurrence blob was written.
	 *
	 * @return void
	 */
	public function set_recurrence( int $post_id ): void {
		if ( ! post_type_supports( (string) get_post_type( $post_id ), 'gatherpress-event-date' ) ) {
			return;
		}

		$data = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! empty( $data ) ) {
			$this->write_recurrence( $post_id, (string) $data );

			// This derivation consumed the blob as it stands right now, so a
			// reconciliation queued by an earlier write this request (the
			// classic-editor shape: meta box writes the blob, then the save
			// hook fires) is already satisfied. A blob write that happens
			// after this point re-queues the post.
			unset( $this->pending_recurrence[ $post_id ] );

			return;
		}

		// Nothing stored yet, but that does not mean nothing is coming, and it
		// does not mean the rule was removed either. `wp_after_insert_post`
		// fires from inside `wp_insert_post()`, before REST/editor/duplicate
		// callers have necessarily written the blob (#2116's datetime race,
		// same shape here). Decide once more at shutdown, once the request's
		// meta writes are done.
		$this->pending_recurrence[ $post_id ] = true;

		add_action( 'shutdown', array( $this, 'resolve_pending_recurrence' ) );
	}

	/**
	 * Queue a late reconciliation when the recurrence blob itself is written.
	 *
	 * `wp_after_insert_post` only covers writers that fire it after their
	 * meta writes land. A blob replaced or removed by WP-CLI, an importer
	 * updating an existing post, or a direct `update_post_meta()` call fires
	 * no save hook at all, and without this watcher its mirrors and
	 * occurrence rows would keep describing the old rule forever. The
	 * meta-key comparison runs first so every unrelated meta write bails
	 * with no query and no state change, keeping a site with no recurring
	 * events byte-identical on its save paths.
	 *
	 * @since 0.36.0
	 *
	 * @param int|int[] $meta_id  Meta ID, or an array of meta IDs for `deleted_post_meta`.
	 * @param int       $post_id  Post ID the meta belongs to.
	 * @param string    $meta_key Meta key that changed.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function maybe_queue_reconciliation( $meta_id, $post_id, $meta_key = '' ): void {
		$post_id = (int) $post_id;

		if (
			self::META_KEY !== $meta_key
			|| ! post_type_supports( (string) get_post_type( $post_id ), 'gatherpress-event-date' )
		) {
			return;
		}

		$this->pending_recurrence[ $post_id ] = true;

		add_action( 'shutdown', array( $this, 'resolve_pending_recurrence' ) );
	}

	/**
	 * Resolve every post whose recurrence blob still needs reconciling.
	 *
	 * Runs on shutdown, so the meta read here is whatever the request actually
	 * ended up with rather than what existed mid-insert or mid-write. A blob
	 * that is still empty here means the rule was genuinely removed (or never
	 * existed), so the mirrors are cleared rather than left stale; a nonempty
	 * one is derived, which covers a rule replaced by a writer that fired no
	 * save hook.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function resolve_pending_recurrence(): void {
		$pending                  = $this->pending_recurrence;
		$this->pending_recurrence = array();

		foreach ( array_keys( $pending ) as $post_id ) {
			// The post can be gone by shutdown, after a duplicate that failed
			// or an insert rolled back once this hook had run.
			if ( ! post_type_supports( (string) get_post_type( $post_id ), 'gatherpress-event-date' ) ) {
				continue;
			}

			$data = get_post_meta( $post_id, self::META_KEY, true );

			if ( empty( $data ) ) {
				$this->clear_mirrors( $post_id );

				continue;
			}

			$this->write_recurrence( $post_id, (string) $data, true );
		}
	}

	/**
	 * Decode a recurrence blob and either project it onto the mirrors or clear them.
	 *
	 * A blob that fails to decode into a valid `Rule`, or whose series carries
	 * a fixed-offset timezone rather than a named tz-database identifier, is
	 * treated the same way as no rule at all: the mirrors are cleared rather
	 * than left holding a previous, now-orphaned, rule. A fixed-offset
	 * timezone must reject the rule, not fatal the post save. A REST write
	 * can carry one just as easily as the editor can.
	 *
	 * An empty timezone read is ambiguous on a non-final pass: it can mean
	 * "fixed offset" just as easily as "the `gatherpress_datetime` blob has
	 * not been written yet this request" -- `wp_insert_post()` with
	 * `meta_input`, a WXR import, or a duplication plugin can all write the
	 * recurrence blob before the datetime blob lands. Treating an empty read
	 * as unnamed on the first pass would drop the rule on exactly the same
	 * race `set_recurrence()` already defends against in the other
	 * direction, so it is deferred to `shutdown` instead, and only clears the
	 * mirrors if the timezone is still unknown once every write this request
	 * is going to make has already happened.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id  Post the blob belongs to.
	 * @param string $data     Raw `gatherpress_recurrence` JSON blob.
	 * @param bool   $is_final Whether this is the final, `shutdown` pass -- an empty
	 *                         timezone read is cleared rather than deferred again.
	 *
	 * @return void
	 */
	protected function write_recurrence( int $post_id, string $data, bool $is_final = false ): void {
		$values = json_decode( $data, true );
		$rule   = is_array( $values ) ? Rule::from_array( $values ) : null;

		if ( ! $rule instanceof Rule ) {
			$this->clear_mirrors( $post_id );

			return;
		}

		// Read from the same `gatherpress_datetime` blob `Event\Setup::set_datetimes()`
		// reads, rather than the `gatherpress_timezone` mirror it derives --
		// depending on that mirror would make this depend on that class
		// having already run on this pass.
		$timezone = $this->read_timezone( $post_id );

		if ( '' === $timezone && ! $is_final ) {
			$this->pending_recurrence[ $post_id ] = true;

			add_action( 'shutdown', array( $this, 'resolve_pending_recurrence' ) );

			return;
		}

		try {
			// A named tz-database identifier is asserted before any recurrence
			// value could reach the expander. A fixed UTC offset carries no
			// DST rules and would silently drift a recurring series.
			Timezone_Guard::assert_named( $timezone );
		} catch ( InvalidArgumentException $e ) {
			// A fixed-offset timezone (`UTC+5:30`) rejects the rule rather
			// than fataling the save: a REST write can carry one just as
			// easily as the editor can. The mirrors are cleared here, not
			// merely left unwritten, because a series that *was* valid and
			// is now saved with a fixed offset would otherwise keep the
			// previous rule's mirrors and go on describing itself as
			// recurring after its rule was refused.
			$this->clear_mirrors( $post_id );

			return;
		}

		$this->write_mirrors( $post_id, $rule );

		Query::refresh_has_recurring_events();
	}

	/**
	 * Read the series timezone from the `gatherpress_datetime` blob.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post to read the datetime blob from.
	 *
	 * @return string The timezone value, or '' when the blob is missing or malformed.
	 */
	protected function read_timezone( int $post_id ): string {
		$data   = get_post_meta( $post_id, 'gatherpress_datetime', true );
		$values = ! empty( $data ) ? json_decode( (string) $data, true ) : null;

		return is_array( $values ) ? (string) ( $values['timezone'] ?? '' ) : '';
	}

	/**
	 * Write the ten derived mirrors from a validated rule.
	 *
	 * @since 0.36.0
	 *
	 * @param int  $post_id Post ID to write the mirrors on.
	 * @param Rule $rule    Validated rule to project.
	 *
	 * @return void
	 */
	protected function write_mirrors( int $post_id, Rule $rule ): void {
		$byday = implode(
			',',
			array_map(
				fn( int $weekday ) => Rule::WEEKDAY_CODES[ $weekday ],
				$rule->weekdays()
			)
		);

		$mirrors = array(
			'gatherpress_recurrence_frequency'       => $rule->frequency(),
			'gatherpress_recurrence_interval'        => $rule->interval(),
			'gatherpress_recurrence_byday'           => $byday,
			'gatherpress_recurrence_monthly_mode'    => $rule->monthly_mode(),
			'gatherpress_recurrence_monthly_day'     => $rule->monthly_day(),
			'gatherpress_recurrence_monthly_ordinal' => $rule->monthly_ordinal(),
			'gatherpress_recurrence_monthly_weekday' => $rule->monthly_weekday(),
			'gatherpress_recurrence_end_type'        => $rule->end_type(),
			'gatherpress_recurrence_until'           => $rule->until()?->format( 'Y-m-d' ) ?? '',
			'gatherpress_recurrence_count'           => $rule->count(),
		);

		foreach ( $mirrors as $meta_key => $value ) {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	/**
	 * Delete all ten derived mirrors and refresh the has-recurring-events flag.
	 *
	 * Called whenever a post ends up with no valid, expandable rule, because
	 * the blob was removed, failed to decode, or carries a fixed-offset
	 * timezone.
	 * Without this, a removed or invalidated rule's mirrors survive the save
	 * that removed it: `from_post()` keeps reconstructing the deleted rule,
	 * the site keeps expanding it, and `Query::refresh_has_recurring_events()`
	 * never sees the removal, because it reads the frequency mirror directly
	 * from `wp_postmeta`.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to clear the mirrors on.
	 *
	 * @return void
	 */
	protected function clear_mirrors( int $post_id ): void {
		foreach ( self::DERIVED_META_KEYS as $meta_key ) {
			delete_post_meta( $post_id, $meta_key );
		}

		Query::refresh_has_recurring_events();
	}

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
		$meta = $request->get_param( 'meta' );

		if ( is_array( $meta ) ) {
			foreach ( self::DERIVED_META_KEYS as $key ) {
				unset( $meta[ $key ] );
			}

			$request->set_param( 'meta', $meta );
		}

		return $prepared_post;
	}
}
