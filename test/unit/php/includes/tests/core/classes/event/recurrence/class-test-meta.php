<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Meta.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use GatherPress\Core\Event;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Rule;
use GatherPress\Core\Utility;
use GatherPress\Tests\Base;
use stdClass;
use WP_REST_Request;

/**
 * Class Test_Meta.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Meta
 */
class Test_Meta extends Base {

	use Occurrence_Fixtures;

	/**
	 * Coverage for `__construct` and `setup_hooks`.
	 *
	 * @covers ::__construct
	 * @covers ::setup_hooks
	 *
	 * @return void
	 */
	public function test_setup_hooks(): void {
		$instance = Meta::get_instance();
		$hooks    = array(
			array(
				'type'     => 'action',
				'name'     => 'registered_post_type',
				'priority' => 11,
				'callback' => array( $instance, 'register' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'wp_after_insert_post',
				'priority' => 10,
				'callback' => array( $instance, 'set_recurrence' ),
			),
		);

		$this->assert_hooks( $hooks, $instance );
	}

	/**
	 * `register()` registers the writable blob plus the ten read-only mirrors
	 * for a post type declaring `gatherpress-event-date` support, and wires
	 * the REST readonly-strip filter.
	 *
	 * @covers ::register
	 *
	 * @return void
	 */
	public function test_register_on_event_date_supporting_post_type(): void {
		$instance = Meta::get_instance();

		$instance->register( Event::POST_TYPE );

		$meta = get_registered_meta_keys( 'post', Event::POST_TYPE );

		$this->assertArrayHasKey( 'gatherpress_recurrence', $meta );
		$this->assertTrue( $meta['gatherpress_recurrence']['show_in_rest'] );

		foreach ( Meta::DERIVED_META_KEYS as $derived_key ) {
			$this->assertArrayHasKey( $derived_key, $meta, "Expected {$derived_key} to be registered." );
			$this->assertTrue( $meta[ $derived_key ]['show_in_rest'] );
		}

		$this->assertNotFalse(
			has_filter(
				sprintf( 'rest_pre_insert_%s', Event::POST_TYPE ),
				array( $instance, 'filter_readonly_meta' )
			)
		);
	}

	/**
	 * `register()` is a no-op for a post type without `gatherpress-event-date`
	 * support: no meta registers, and no REST filter wires.
	 *
	 * @covers ::register
	 *
	 * @return void
	 */
	public function test_register_skips_unsupported_post_type(): void {
		$instance = Meta::get_instance();

		$instance->register( 'post' );

		$meta = get_registered_meta_keys( 'post', 'post' );

		$this->assertArrayNotHasKey( 'gatherpress_recurrence', $meta );
		$this->assertFalse(
			has_filter( 'rest_pre_insert_post', array( $instance, 'filter_readonly_meta' ) )
		);
	}

	/**
	 * The writable `gatherpress_recurrence` key registers with
	 * `Utility::can_edit_post_meta`; each of the ten derived mirrors registers
	 * with `__return_false`, so the writable/read-only split matches the
	 * documented shape.
	 *
	 * @covers ::register
	 *
	 * @return void
	 */
	public function test_writable_and_readonly_meta_split(): void {
		$instance = Meta::get_instance();
		$instance->register( Event::POST_TYPE );

		$meta = get_registered_meta_keys( 'post', Event::POST_TYPE );

		$this->assertSame(
			array( Utility::class, 'can_edit_post_meta' ),
			$meta['gatherpress_recurrence']['auth_callback']
		);

		foreach ( Meta::DERIVED_META_KEYS as $derived_key ) {
			$this->assertSame(
				'__return_false',
				$meta[ $derived_key ]['auth_callback'],
				"Expected {$derived_key} to be registered read-only."
			);
		}
	}

	/**
	 * `set_recurrence()` is a no-op on a post type without
	 * `gatherpress-event-date` support.
	 *
	 * @covers ::set_recurrence
	 *
	 * @return void
	 */
	public function test_set_recurrence_skips_unsupported_post_type(): void {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );

		add_post_meta( $post_id, 'gatherpress_recurrence', wp_json_encode( array( 'frequency' => 'daily' ) ) );

		Meta::get_instance()->set_recurrence( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, 'gatherpress_recurrence_frequency', true ) );
	}

	/**
	 * `set_recurrence()` is a no-op when the post carries no recurrence blob.
	 *
	 * @covers ::set_recurrence
	 *
	 * @return void
	 */
	public function test_set_recurrence_skips_when_no_blob(): void {
		$post_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );

		Meta::get_instance()->set_recurrence( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, 'gatherpress_recurrence_frequency', true ) );
	}

	/**
	 * `set_recurrence()` leaves the mirrors unwritten when the stored blob
	 * decodes to an invalid rule.
	 *
	 * @covers ::set_recurrence
	 *
	 * @return void
	 */
	public function test_set_recurrence_skips_invalid_rule(): void {
		$post_id = $this->create_recurring_event( array( 'frequency' => 'weekly' ) );

		Meta::get_instance()->set_recurrence( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, 'gatherpress_recurrence_frequency', true ) );
	}

	/**
	 * `set_recurrence()` writes all ten mirrors from a valid rule.
	 *
	 * @covers ::set_recurrence
	 * @covers ::write_mirrors
	 *
	 * @return void
	 */
	public function test_set_recurrence_writes_mirrors_for_valid_rule(): void {
		$post_id = $this->create_recurring_event(
			array(
				'frequency' => 'daily',
				'interval'  => 1,
				'end_type'  => 'count',
				'count'     => 3,
			)
		);

		Meta::get_instance()->set_recurrence( $post_id );

		$this->assertSame( 'daily', get_post_meta( $post_id, 'gatherpress_recurrence_frequency', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, 'gatherpress_recurrence_interval', true ) );
		$this->assertSame( 'count', get_post_meta( $post_id, 'gatherpress_recurrence_end_type', true ) );
		$this->assertSame( '3', get_post_meta( $post_id, 'gatherpress_recurrence_count', true ) );

		$this->assertInstanceOf( Rule::class, Rule::from_post( $post_id ) );
	}

	/**
	 * `filter_readonly_meta` strips the ten derived recurrence keys from a
	 * REST request's `meta` payload.
	 *
	 * @covers ::filter_readonly_meta
	 *
	 * @return void
	 */
	public function test_derived_meta_is_stripped_from_rest_writes(): void {
		$instance = Meta::get_instance();

		$request = new WP_REST_Request( 'POST', '/wp/v2/gatherpress_event' );

		$meta = array( 'gatherpress_recurrence' => wp_json_encode( array( 'frequency' => 'daily' ) ) );

		foreach ( Meta::DERIVED_META_KEYS as $derived_key ) {
			$meta[ $derived_key ] = 'stray-client-supplied-value';
		}

		$request->set_param( 'meta', $meta );

		$prepared_post     = new stdClass();
		$prepared_post->ID = 321;

		$result = $instance->filter_readonly_meta( $prepared_post, $request );

		$this->assertSame( $prepared_post, $result );

		$filtered_meta = $request->get_param( 'meta' );

		$this->assertArrayHasKey( 'gatherpress_recurrence', $filtered_meta );
		$this->assertCount( 1, $filtered_meta );

		foreach ( Meta::DERIVED_META_KEYS as $derived_key ) {
			$this->assertArrayNotHasKey( $derived_key, $filtered_meta );
		}
	}

	/**
	 * `filter_readonly_meta` returns the prepared post unchanged when the
	 * request has no `meta` param.
	 *
	 * @covers ::filter_readonly_meta
	 *
	 * @return void
	 */
	public function test_filter_readonly_meta_with_null_meta(): void {
		$instance = Meta::get_instance();
		$request  = new WP_REST_Request( 'POST', '/wp/v2/gatherpress_event' );

		$prepared_post     = new stdClass();
		$prepared_post->ID = 654;

		$result = $instance->filter_readonly_meta( $prepared_post, $request );

		$this->assertSame( $prepared_post, $result );
		$this->assertNull( $request->get_param( 'meta' ) );
	}

	/**
	 * `sanitize_signed_int()` casts to a signed integer, preserving a negative
	 * value -- the reason `intval` (which errors under WP's meta sanitize
	 * callback signature) and `absint()` (which would clamp `-1` to `1`) are
	 * both wrong for `gatherpress_recurrence_monthly_ordinal`.
	 *
	 * @covers ::sanitize_signed_int
	 *
	 * @return void
	 */
	public function test_sanitize_signed_int_preserves_negative_values(): void {
		$this->assertSame( -1, Meta::sanitize_signed_int( '-1' ) );
		$this->assertSame( 3, Meta::sanitize_signed_int( '3' ) );
		$this->assertSame( 0, Meta::sanitize_signed_int( '' ) );
	}
}
