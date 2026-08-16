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
use GatherPress\Core\Utility;
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
	 * Class constructor.
	 *
	 * @since 0.36.0
	 */
	public function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Set up hooks for recurrence meta registration and projection.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		// Priority 11 so post types registered at the default priority 10 are
		// available for get_post_types_by_support().
		add_action( 'registered_post_type', array( $this, 'register' ), 11 );
		add_action( 'wp_after_insert_post', array( $this, 'set_recurrence' ) );
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
			'gatherpress_recurrence_frequency'       => 'sanitize_text_field',
			'gatherpress_recurrence_interval'        => 'absint',
			'gatherpress_recurrence_byday'           => 'sanitize_text_field',
			'gatherpress_recurrence_monthly_mode'    => 'sanitize_text_field',
			'gatherpress_recurrence_monthly_day'     => 'absint',
			'gatherpress_recurrence_monthly_ordinal' => array( self::class, 'sanitize_signed_int' ),
			'gatherpress_recurrence_monthly_weekday' => 'absint',
			'gatherpress_recurrence_end_type'        => 'sanitize_text_field',
			'gatherpress_recurrence_until'           => 'sanitize_text_field',
			'gatherpress_recurrence_count'           => 'absint',
		);

		foreach ( $derived_meta as $meta_key => $sanitize_callback ) {
			register_post_meta(
				$post_type,
				$meta_key,
				array(
					'auth_callback'     => '__return_false', // Read-only: derived from gatherpress_recurrence.
					'sanitize_callback' => $sanitize_callback,
					'show_in_rest'      => true,
					'single'            => true,
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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- $meta_key, $object_subtype,
	 * and $object_type are required by WP's sanitize_callback signature.
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
	 * The recurrence counterpart to `Event\Setup::set_datetimes()`.
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

		if ( empty( $data ) ) {
			return;
		}

		$values = json_decode( (string) $data, true );
		$rule   = is_array( $values ) ? Rule::from_array( $values ) : null;

		if ( ! $rule instanceof Rule ) {
			return;
		}

		// A named tz-database identifier is asserted before any recurrence
		// value could reach the expander. A fixed UTC offset carries no DST
		// rules and would silently drift a recurring series.
		Timezone_Guard::assert_named( (string) get_post_meta( $post_id, 'gatherpress_timezone', true ) );

		$this->write_mirrors( $post_id, $rule );

		Query::refresh_has_recurring_events();
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
