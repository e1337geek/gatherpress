<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Rest_Api.
 *
 * The class is a frozen contract at this point in the stack. The tests here pin
 * two things the implementation must not silently change: that nothing is
 * routed yet, and that the permission callback fails closed while it has no
 * capability check of its own.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use GatherPress\Core\Event\Recurrence\Rest_Api;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use WP_REST_Request;

/**
 * Class Test_Rest_Api.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Rest_Api
 */
class Test_Rest_Api extends Base {

	/**
	 * Nothing is routed while the contract is frozen, so calling the registrar
	 * must leave the REST server's route table exactly as it found it. A route
	 * that appeared here early would be a live, unauthorized write endpoint.
	 *
	 * @covers ::register_endpoints
	 *
	 * @return void
	 */
	public function test_register_endpoints_adds_no_routes(): void {
		$rest_server = rest_get_server();
		$before      = array_keys( $rest_server->get_routes() );

		Rest_Api::get_instance()->register_endpoints();

		$this->assertSame(
			$before,
			array_keys( $rest_server->get_routes() ),
			'Failed to assert that register_endpoints leaves the REST route table unchanged.'
		);
	}

	/**
	 * `register_endpoints()` loops whatever `get_occurrence_routes()` returns,
	 * so an empty list is the reason no route appears. Invoked directly because
	 * the method is protected and xdebug does not trace it through the loop.
	 *
	 * @covers ::get_occurrence_routes
	 *
	 * @return void
	 */
	public function test_get_occurrence_routes_is_empty(): void {
		$this->assertSame(
			array(),
			Utility::invoke_hidden_method( Rest_Api::get_instance(), 'get_occurrence_routes' ),
			'Failed to assert that get_occurrence_routes returns an empty list.'
		);
	}

	/**
	 * The occurrence-status route is the one route the class will own, and its
	 * definition is empty while the contract is frozen.
	 *
	 * @covers ::occurrence_status_route
	 *
	 * @return void
	 */
	public function test_occurrence_status_route_is_empty(): void {
		$this->assertSame(
			array(),
			Utility::invoke_hidden_method( Rest_Api::get_instance(), 'occurrence_status_route' ),
			'Failed to assert that occurrence_status_route returns an empty definition.'
		);
	}

	/**
	 * The status handler answers null for every request, which is the frozen
	 * stub's way of saying it updated nothing.
	 *
	 * @covers ::update_occurrence_status
	 *
	 * @return void
	 */
	public function test_update_occurrence_status_returns_null(): void {
		$post_id = $this->factory->post->create();
		$request = new WP_REST_Request( 'POST', '/gatherpress/v1/event/occurrence-status' );

		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'recurrence_id', '20260101T090000' );
		$request->set_param( 'status', 'cancelled' );

		$this->assertNull(
			Rest_Api::get_instance()->update_occurrence_status( $request ),
			'Failed to assert that update_occurrence_status returns null.'
		);
	}

	/**
	 * The permission callback fails closed: while it has no capability check of
	 * its own it must deny everyone, including an administrator who really does
	 * hold `edit_post` on the series. Failing open here would be the difference
	 * between an unbuilt endpoint and an unguarded one.
	 *
	 * @covers ::has_edit_permission
	 *
	 * @return void
	 */
	public function test_has_edit_permission_denies_even_an_administrator(): void {
		$post_id = $this->factory->post->create();
		$request = new WP_REST_Request( 'POST', '/gatherpress/v1/event/occurrence-status' );

		$request->set_param( 'post_id', $post_id );

		$this->assertFalse(
			Rest_Api::get_instance()->has_edit_permission( $request ),
			'Failed to assert that has_edit_permission denies a logged-out request.'
		);

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue(
			current_user_can( 'edit_post', $post_id ),
			'Failed to assert that the administrator really can edit the series post.'
		);
		$this->assertFalse(
			Rest_Api::get_instance()->has_edit_permission( $request ),
			'Failed to assert that has_edit_permission still denies an administrator.'
		);
	}
}
