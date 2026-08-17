<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Rest_Api.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Query as Event_Query;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Query;
use GatherPress\Core\Event\Recurrence\Rest_Api;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Test_Rest_Api.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Rest_Api
 */
class Test_Rest_Api extends Base {

	use Occurrence_Fixtures;

	/**
	 * A five-occurrence daily rule, cheap to project and easy to reason about.
	 *
	 * @since 0.36.0
	 * @var array
	 */
	const DAILY_RULE = array(
		'frequency' => 'daily',
		'interval'  => 1,
		'end_type'  => 'count',
		'count'     => 5,
	);

	/**
	 * Ensure the occurrence table exists and the route is registered before
	 * every test, independent of execution order.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );
		update_option( Query::HAS_RECURRING_OPTION, '0', true );

		Rest_Api::get_instance()->register_endpoints();
	}

	/**
	 * Clear any nonce simulation state a test may have left behind.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rest_auth_cookie;

		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core's own global, not ours to prefix.
		$wp_rest_auth_cookie = false;

		parent::tearDown();
	}

	/**
	 * Build "now" in UTC so fixtures are never a date bomb.
	 *
	 * @since 0.36.0
	 *
	 * @return DateTimeImmutable Current time in UTC.
	 */
	protected function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Create and project a recurring event anchored relative to "now".
	 *
	 * @since 0.36.0
	 *
	 * @param array             $rule     Recurrence rule values.
	 * @param DateTimeImmutable $start    Anchor start, in `$timezone`.
	 * @param DateTimeImmutable $end      Anchor end, in `$timezone`.
	 * @param string            $timezone Named tz-database identifier for the series.
	 *
	 * @return int The projected post ID.
	 */
	protected function create_relative_recurring_event(
		array $rule,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		string $timezone = 'UTC'
	): int {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		add_post_meta(
			$post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => $start->format( 'Y-m-d H:i:s' ),
					'dateTimeEnd'   => $end->format( 'Y-m-d H:i:s' ),
					'timezone'      => $timezone,
				)
			)
		);
		Event_Setup::get_instance()->set_datetimes( $post_id );
		add_post_meta( $post_id, Meta::META_KEY, wp_json_encode( $rule ) );
		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );

		return (int) $post_id;
	}

	/**
	 * Create a relative recurring event and return its first recurrence ID.
	 *
	 * @since 0.36.0
	 *
	 * @param int $offset_days Days to add to "+1 day" for the anchor start, so
	 *                         two fixtures in the same test do not collide on
	 *                         the same recurrence ID.
	 *
	 * @return array{0: int, 1: string} The post ID and its first recurrence ID.
	 */
	protected function create_event_with_occurrence( int $offset_days = 0 ): array {
		$start = $this->now()->modify( sprintf( '+%d days', 1 + $offset_days ) );

		$post_id = $this->create_relative_recurring_event(
			self::DAILY_RULE,
			$start,
			$start->modify( '+1 hour' )
		);

		$rows = Occurrences::get_instance()->select_for_series( array( $post_id ) );

		return array( $post_id, $rows[0]['recurrence_id'] );
	}

	/**
	 * Build a POST request against the occurrence-status route.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier.
	 * @param string $status        Target status.
	 *
	 * @return WP_REST_Request The built request.
	 */
	protected function build_request( int $post_id, string $recurrence_id, string $status ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/gatherpress/v1/event/occurrence-status' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'recurrence_id', $recurrence_id );
		$request->set_param( 'status', $status );

		return $request;
	}

	/**
	 * Dispatch a request through the real REST server.
	 *
	 * Runs authentication, route matching, `permission_callback`, and args
	 * validation exactly as a live HTTP request would, rather than calling
	 * the route callback directly. Mirrors what
	 * `WP_REST_Server::serve_request()` does internally, without echoing
	 * output or sending headers.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_REST_Request $request Request to dispatch.
	 *
	 * @return WP_REST_Response The response, including error responses.
	 */
	protected function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$server = rest_get_server();
		$result = $server->check_authentication();

		if ( ! is_wp_error( $result ) ) {
			$result = $server->dispatch( $request );
		}

		if ( is_wp_error( $result ) ) {
			$result = rest_convert_error_to_response( $result );
		}

		return $result;
	}

	/**
	 * Coverage for `__construct` and `setup_hooks`.
	 *
	 * @covers ::__construct
	 * @covers ::setup_hooks
	 *
	 * @return void
	 */
	public function test_setup_hooks(): void {
		$instance = Rest_Api::get_instance();

		$hooks = array(
			array(
				'type'     => 'action',
				'name'     => 'rest_api_init',
				'priority' => 10,
				'callback' => array( $instance, 'register_endpoints' ),
			),
		);

		$this->assert_hooks( $hooks, $instance );
	}

	/**
	 * Coverage for `register_endpoints`.
	 *
	 * @covers ::register_endpoints
	 *
	 * @return void
	 */
	public function test_register_endpoints(): void {
		$rest_server    = rest_get_server();
		$event_route    = sprintf( '%s/event', GATHERPRESS_REST_NAMESPACE );
		$all_namespaces = Utility::get_hidden_property( $rest_server, 'namespaces' );
		$namespace      = $all_namespaces[ $event_route ];

		$this->assertEquals(
			1,
			$namespace[ sprintf( '/%s/event/occurrences', GATHERPRESS_REST_NAMESPACE ) ],
			'Failed to assert occurrences endpoint is registered'
		);
		$this->assertEquals(
			1,
			$namespace[ sprintf( '/%s/event/occurrence-status', GATHERPRESS_REST_NAMESPACE ) ],
			'Failed to assert occurrence-status endpoint is registered'
		);
	}

	/**
	 * Coverage for `get_occurrence_routes`, `occurrences_route` and
	 * `occurrence_status_route`.
	 *
	 * @covers ::get_occurrence_routes
	 * @covers ::occurrences_route
	 * @covers ::occurrence_status_route
	 *
	 * @return void
	 */
	public function test_get_occurrence_routes(): void {
		$instance = Rest_Api::get_instance();
		$routes   = Utility::invoke_hidden_method( $instance, 'get_occurrence_routes' );

		$this->assertSame( 'occurrences', $routes[0]['route'] );
		$this->assertSame( WP_REST_Server::READABLE, $routes[0]['args']['methods'] );
		$this->assertSame(
			array( $instance, 'get_occurrences' ),
			$routes[0]['args']['callback']
		);
		$this->assertSame(
			array( $instance, 'has_edit_permission' ),
			$routes[0]['args']['permission_callback']
		);

		$this->assertSame( 'occurrence-status', $routes[1]['route'] );
		$this->assertSame( WP_REST_Server::EDITABLE, $routes[1]['args']['methods'] );
		$this->assertSame(
			array( $instance, 'update_occurrence_status' ),
			$routes[1]['args']['callback']
		);
		$this->assertSame(
			array( $instance, 'has_edit_permission' ),
			$routes[1]['args']['permission_callback']
		);
		$this->assertSame(
			array( Occurrences::STATUS_SCHEDULED, Occurrences::STATUS_CANCELLED ),
			$routes[1]['args']['args']['status']['enum']
		);
	}

	/**
	 * The `recurrence_id` `validate_callback` accepts the `Ymd\THis` shape and
	 * rejects anything else.
	 *
	 * @covers ::occurrence_status_route
	 *
	 * @return void
	 */
	public function test_recurrence_id_validate_callback_accepts_and_rejects(): void {
		$instance = Rest_Api::get_instance();
		$route    = Utility::invoke_hidden_method( $instance, 'occurrence_status_route' );
		$callback = $route['args']['args']['recurrence_id']['validate_callback'];

		$this->assertTrue(
			call_user_func( $callback, '20260903T180000' ),
			'A well-formed recurrence ID must validate.'
		);
		$this->assertFalse(
			call_user_func( $callback, 'not-a-recurrence-id' ),
			'A malformed recurrence ID must not validate.'
		);
	}

	/**
	 * The `status` `validate_callback` accepts only the two known statuses.
	 *
	 * @covers ::occurrence_status_route
	 *
	 * @return void
	 */
	public function test_status_validate_callback_accepts_and_rejects(): void {
		$instance = Rest_Api::get_instance();
		$route    = Utility::invoke_hidden_method( $instance, 'occurrence_status_route' );
		$callback = $route['args']['args']['status']['validate_callback'];

		$this->assertTrue( call_user_func( $callback, Occurrences::STATUS_SCHEDULED ) );
		$this->assertTrue( call_user_func( $callback, Occurrences::STATUS_CANCELLED ) );
		$this->assertFalse( call_user_func( $callback, 'deleted' ) );
	}

	/**
	 * Coverage for both branches of `has_edit_permission`.
	 *
	 * @covers ::has_edit_permission
	 *
	 * @return void
	 */
	public function test_has_edit_permission_both_branches(): void {
		list( $post_id, $recurrence_id ) = $this->create_event_with_occurrence();
		$instance                        = Rest_Api::get_instance();

		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$request = $this->build_request( $post_id, $recurrence_id, Occurrences::STATUS_CANCELLED );
		$this->assertTrue(
			$instance->has_edit_permission( $request ),
			'An editor may edit any post, including this one.'
		);

		wp_set_current_user( 0 );
		$this->assertFalse(
			$instance->has_edit_permission( $request ),
			'A logged-out visitor may not change occurrence status.'
		);
	}

	/**
	 * A subscriber, who cannot edit the series post, gets a 403 -- driven
	 * through the real REST server, not a direct callback call.
	 *
	 * @covers ::has_edit_permission
	 *
	 * @return void
	 */
	public function test_cancel_route_requires_edit_post_capability(): void {
		list( $post_id, $recurrence_id ) = $this->create_event_with_occurrence();

		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$response = $this->dispatch( $this->build_request( $post_id, $recurrence_id, Occurrences::STATUS_CANCELLED ) );

		$this->assertSame( 403, $response->get_status() );

		// The occurrence must remain untouched.
		$row = Occurrences::get_instance()->get( $post_id, $recurrence_id );
		$this->assertSame( Occurrences::STATUS_SCHEDULED, $row['status'] );
	}

	/**
	 * A bad `X-WP-Nonce` on an otherwise-authorized cookie session is
	 * rejected by WordPress's own cookie-auth nonce check before the route's
	 * own permission callback ever runs.
	 *
	 * @covers ::update_occurrence_status
	 *
	 * @return void
	 */
	public function test_cancel_route_rejects_a_bad_nonce(): void {
		global $wp_rest_auth_cookie;

		list( $post_id, $recurrence_id ) = $this->create_event_with_occurrence();

		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		// Simulate a cookie-authenticated request carrying an invalid nonce,
		// matching how `rest_cookie_check_errors()` gates every route.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core's own global, not ours to prefix.
		$wp_rest_auth_cookie        = true;
		$_SERVER['HTTP_X_WP_NONCE'] = 'not-a-real-nonce';

		$response = $this->dispatch( $this->build_request( $post_id, $recurrence_id, Occurrences::STATUS_CANCELLED ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cookie_invalid_nonce', $response->get_data()['code'] );
	}

	/**
	 * A `recurrence_id` that belongs to a different post's series must not
	 * authorize a status change, even though the caller can edit the post ID
	 * they submitted. This is the authorization hole the composite-key scope
	 * on `Occurrences::set_status()` closes.
	 *
	 * @covers ::update_occurrence_status
	 *
	 * @return void
	 */
	public function test_cancel_route_rejects_a_recurrence_id_belonging_to_another_post(): void {
		list( $post_a, $recurrence_a ) = $this->create_event_with_occurrence();
		// Offset by 10 days so post B's five daily occurrences (days 11-15)
		// never overlap post A's (days 1-5) -- an overlapping date would
		// coincidentally also exist as a row for post A, defeating the point
		// of this test.
		list( $post_b, $recurrence_b ) = $this->create_event_with_occurrence( 10 );

		$this->assertNotSame(
			$recurrence_a,
			$recurrence_b,
			'Fixture assumption: the two series must not share a recurrence ID.'
		);

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		// Post A's ID, but Post B's recurrence ID.
		$response = $this->dispatch( $this->build_request( $post_a, $recurrence_b, Occurrences::STATUS_CANCELLED ) );

		$this->assertSame(
			404,
			$response->get_status(),
			'A recurrence_id belonging to another post must 404, not silently succeed.'
		);
		// Pin the specific rejection reason so this test cannot pass merely
		// because the route itself is unregistered (which also 404s, as
		// `rest_no_route`) -- it must be *our* callback reporting the
		// composite-key mismatch.
		$this->assertSame(
			'gatherpress_occurrence_not_found',
			$response->get_data()['code'],
			'The 404 must come from the composite-key check, not from the route being missing.'
		);

		$row_b = Occurrences::get_instance()->get( $post_b, $recurrence_b );
		$this->assertSame(
			Occurrences::STATUS_SCHEDULED,
			$row_b['status'],
			'Post B\'s occurrence must remain untouched by a request scoped to post A.'
		);
	}

	/**
	 * An unknown `recurrence_id` for an otherwise-valid post returns 404.
	 *
	 * @covers ::update_occurrence_status
	 *
	 * @return void
	 */
	public function test_cancel_route_returns_404_for_an_unknown_recurrence_id(): void {
		list( $post_id ) = $this->create_event_with_occurrence();

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$response = $this->dispatch(
			$this->build_request( $post_id, '20990101T000000', Occurrences::STATUS_CANCELLED )
		);

		$this->assertSame( 404, $response->get_status() );
		// Pins the 404 to our own "no such row" error rather than the route
		// itself being unregistered, which also 404s as `rest_no_route`.
		$this->assertSame( 'gatherpress_occurrence_not_found', $response->get_data()['code'] );
	}

	/**
	 * Cancelling sets the status column and the front-end upcoming list --
	 * driven through a real `WP_Query`, not just a column read -- drops it.
	 *
	 * @covers ::update_occurrence_status
	 *
	 * @return void
	 */
	public function test_cancel_sets_status_and_front_end_list_drops_it(): void {
		list( $post_id, $recurrence_id ) = $this->create_event_with_occurrence();

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$response = $this->dispatch( $this->build_request( $post_id, $recurrence_id, Occurrences::STATUS_CANCELLED ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Occurrences::STATUS_CANCELLED, $response->get_data()['status'] );

		$entries = $this->run_upcoming_query_entries();

		$this->assertNotContains(
			$post_id . '|' . $recurrence_id,
			$entries,
			'A cancelled occurrence must drop out of the upcoming list.'
		);
	}

	/**
	 * Un-cancelling restores the occurrence to the front-end upcoming list.
	 *
	 * @covers ::update_occurrence_status
	 *
	 * @return void
	 */
	public function test_uncancel_restores_it_to_the_list(): void {
		list( $post_id, $recurrence_id ) = $this->create_event_with_occurrence();

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->dispatch( $this->build_request( $post_id, $recurrence_id, Occurrences::STATUS_CANCELLED ) );
		$this->assertNotContains( $post_id . '|' . $recurrence_id, $this->run_upcoming_query_entries() );

		$response = $this->dispatch( $this->build_request( $post_id, $recurrence_id, Occurrences::STATUS_SCHEDULED ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Occurrences::STATUS_SCHEDULED, $response->get_data()['status'] );
		$this->assertContains(
			$post_id . '|' . $recurrence_id,
			$this->run_upcoming_query_entries(),
			'Un-cancelling must restore the occurrence to the upcoming list.'
		);
	}

	/**
	 * REQ-12 is explicit that a cancelled occurrence's RSVPs are retained,
	 * never deleted.
	 *
	 * @covers ::update_occurrence_status
	 *
	 * @return void
	 */
	public function test_cancelled_occurrence_retains_its_rsvps(): void {
		list( $post_id, $recurrence_id ) = $this->create_event_with_occurrence();

		$event = new Event( $post_id );
		$event->rsvp->save( 'attendee@example.com', 'attending' );

		$before = get_comments(
			array(
				'post_id' => $post_id,
				'count'   => true,
			)
		);

		$this->assertGreaterThan( 0, $before, 'Fixture assumption: the RSVP comment must exist before cancellation.' );

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$response = $this->dispatch( $this->build_request( $post_id, $recurrence_id, Occurrences::STATUS_CANCELLED ) );

		$this->assertSame( 200, $response->get_status(), 'Fixture assumption: the cancellation itself must succeed.' );

		$after = get_comments(
			array(
				'post_id' => $post_id,
				'count'   => true,
			)
		);

		$this->assertSame( $before, $after, "Cancelling an occurrence must not delete the event's RSVPs." );
	}

	/**
	 * PRD C-5: cancellation is occurrence state, never expressed by mutating
	 * the rule. Re-projecting the rule after a cancellation -- exactly what
	 * happens when the series is re-saved -- must not clear the cancellation.
	 * Pinned from the REST side: the cancellation itself is set through the
	 * real route, not through `Occurrences::set_status()` directly.
	 *
	 * @covers ::update_occurrence_status
	 *
	 * @return void
	 */
	public function test_cancellation_survives_rule_regeneration(): void {
		list( $post_id, $recurrence_id ) = $this->create_event_with_occurrence();

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$response = $this->dispatch( $this->build_request( $post_id, $recurrence_id, Occurrences::STATUS_CANCELLED ) );
		$this->assertSame( 200, $response->get_status() );

		// Re-save the rule so it still produces this exact date.
		Occurrences::get_instance()->project( $post_id );

		$row = Occurrences::get_instance()->get( $post_id, $recurrence_id );

		$this->assertSame(
			Occurrences::STATUS_CANCELLED,
			$row['status'],
			'Re-projecting the rule must not clear a cancellation (PRD C-5).'
		);
	}

	/**
	 * The occurrences-list route requires `edit_post`, matching the write
	 * route -- driven through the real server, not a direct callback call.
	 *
	 * @covers ::get_occurrences
	 *
	 * @return void
	 */
	public function test_occurrences_route_requires_edit_post_capability(): void {
		list( $post_id ) = $this->create_event_with_occurrence();

		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'GET', '/gatherpress/v1/event/occurrences' );
		$request->set_param( 'post_id', $post_id );

		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The occurrences-list route returns every upcoming row, cancelled and
	 * scheduled alike, so the sidebar can offer a restore action.
	 *
	 * @covers ::get_occurrences
	 *
	 * @return void
	 */
	public function test_occurrences_route_lists_upcoming_rows_including_cancelled(): void {
		list( $post_id, $recurrence_id ) = $this->create_event_with_occurrence();

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->dispatch( $this->build_request( $post_id, $recurrence_id, Occurrences::STATUS_CANCELLED ) );

		$request = new WP_REST_Request( 'GET', '/gatherpress/v1/event/occurrences' );
		$request->set_param( 'post_id', $post_id );

		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 5, $data, 'All five projected occurrences must be listed, cancelled included.' );

		$recurrence_ids = array_column( $data, 'recurrence_id' );
		$this->assertContains( $recurrence_id, $recurrence_ids );

		$index = array_search( $recurrence_id, $recurrence_ids, true );
		$this->assertSame( Occurrences::STATUS_CANCELLED, $data[ $index ]['status'] );
	}

	/**
	 * Run the occurrence-aware "upcoming" query and reduce the results to
	 * `post_id|recurrence_id` strings.
	 *
	 * @since 0.36.0
	 *
	 * @return string[] One entry per result row.
	 */
	protected function run_upcoming_query_entries(): array {
		$query = new WP_Query(
			array(
				'post_type'                    => Event::POST_TYPE,
				Event_Query::EVENT_QUERY_PARAM => 'upcoming',
				'posts_per_page'               => 20,
				'orderby'                      => 'datetime',
				'order'                        => 'ASC',
			)
		);

		return array_map(
			static function ( WP_Post $post ): string {
				return $post->ID . '|' . (string) $post->gatherpress_recurrence_id;
			},
			$query->posts
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
}
