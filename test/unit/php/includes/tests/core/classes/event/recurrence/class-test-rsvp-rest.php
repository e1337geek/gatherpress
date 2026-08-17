<?php
/**
 * Class handles unit tests for per-occurrence RSVP across the REST request surface.
 *
 * The occurrence scoping in `Rsvp\Storage` was correct before this suite
 * existed and still reached nothing: the only code that entered occurrence
 * context was the test suite itself. `Context::sync()` is hooked on `wp`, and
 * core's `rest_api_loaded()` runs on `parse_request` and ends in `die()`, so
 * `WP::main()` never fires `wp` for a REST request — the two front-end write
 * paths and the block's read path all ran series-wide.
 *
 * Every test here therefore drives `rest_do_request()`, the entry point the
 * browser actually reaches. A test that called `update_rsvp()` or
 * `process_rsvp()` directly after setting context by hand would pass against
 * exactly the defect this file exists to prevent.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use GatherPress\Core\Event;
use GatherPress\Core\Event\Recurrence\Context;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Query as Recurrence_Query;
use GatherPress\Core\Event\Recurrence\Rsvp_Occurrence;
use GatherPress\Core\Event\Rest_Api;
use GatherPress\Core\Rsvp\Form;
use GatherPress\Core\Rsvp\Rsvp;
use GatherPress\Core\Rsvp\Setup as Rsvp_Setup;
use GatherPress\Core\Settings;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use WP_REST_Request;

/**
 * Class Test_Rsvp_Rest.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Rest_Api
 */
class Test_Rsvp_Rest extends Base {

	use Occurrence_Fixtures;

	/**
	 * The reference weekly rule, matching `Occurrence_Fixtures::expected_weekly_set()`.
	 *
	 * @since 0.36.0
	 * @var array
	 */
	const WEEKLY_RULE = array(
		'frequency' => 'weekly',
		'interval'  => 2,
		'weekdays'  => array( 2, 4 ),
		'end_type'  => 'count',
		'count'     => 5,
	);

	/**
	 * Recurrence identifier of the reference set's first occurrence.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const OCCURRENCE_A = '20260903T180000';

	/**
	 * Recurrence identifier of the reference set's second occurrence.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const OCCURRENCE_B = '20260915T180000';

	/**
	 * Ensure the occurrence table and the RSVP routes exist, and no context leaks in.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );
		Rsvp_Setup::get_instance()->register_taxonomy();
		Rest_Api::get_instance()->register_endpoints();
		Settings::get_instance()->set( 'enable_open_rsvp', true );
		Context::get_instance()->clear();
	}

	/**
	 * Leave no occurrence context behind for the next test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		Context::get_instance()->clear();

		parent::tearDown();
	}

	/**
	 * Create the reference recurring event, project it, and flag the site as recurring.
	 *
	 * Mirrors the production order: the rule mirrors are written first, the
	 * occurrence rows are projected from them, and only then is the
	 * has-recurring-events flag recomputed from storage.
	 *
	 * @since 0.36.0
	 *
	 * @return int The projected series post ID.
	 */
	protected function create_and_project(): int {
		$post_id = $this->create_recurring_event( self::WEEKLY_RULE );

		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );
		Recurrence_Query::refresh_has_recurring_events();

		return $post_id;
	}

	/**
	 * Create an ordinary, never-recurring event on a site with no recurring events.
	 *
	 * @since 0.36.0
	 *
	 * @return int The created post ID.
	 */
	protected function create_plain_event(): int {
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
					'dateTimeStart' => $this->reference_anchor_start,
					'dateTimeEnd'   => $this->reference_anchor_end,
					'timezone'      => 'America/New_York',
				)
			)
		);

		\GatherPress\Core\Event\Setup::get_instance()->set_datetimes( $post_id );
		Recurrence_Query::refresh_has_recurring_events();

		return (int) $post_id;
	}

	/**
	 * Dispatch one RSVP REST request through the real server.
	 *
	 * @since 0.36.0
	 *
	 * @param string $method Request method.
	 * @param string $route  Route below the `event` namespace, e.g. `rsvp`.
	 * @param array  $params Request parameters.
	 *
	 * @return \WP_REST_Response The dispatched response.
	 */
	protected function dispatch( string $method, string $route, array $params ) {
		$request = new WP_REST_Request(
			$method,
			sprintf( '/%s/event/%s', GATHERPRESS_REST_NAMESPACE, $route )
		);

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Build the parsed-block payload the rsvp-status-html route validates against.
	 *
	 * @since 0.36.0
	 *
	 * @return string JSON-encoded parsed block.
	 */
	protected function block_data(): string {
		return (string) wp_json_encode(
			array(
				'blockName'   => 'gatherpress/rsvp-template',
				'attrs'       => array(),
				'innerBlocks' => array(),
			)
		);
	}

	/**
	 * Count the term relationship rows a comment holds in one taxonomy.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $comment_id Comment ID to count relationships for.
	 * @param string $taxonomy   Taxonomy to count within.
	 *
	 * @return string[] The slugs the comment holds in that taxonomy.
	 */
	protected function term_slugs( int $comment_id, string $taxonomy ): array {
		$slugs = wp_get_object_terms( $comment_id, $taxonomy, array( 'fields' => 'slugs' ) );

		return is_wp_error( $slugs ) ? array() : array_map( 'strval', $slugs );
	}

	/**
	 * An RSVP written through the real REST route carries the occurrence's term.
	 *
	 * This is the assertion the whole feature turns on: without it the response
	 * is a series RSVP, invisible on the occurrence page that created it,
	 * because the read path filters on exactly this term.
	 *
	 * @covers ::update_rsvp
	 * @covers ::enter_occurrence_context
	 * @covers ::request_post_id
	 * @covers ::recurrence_id_arg
	 * @covers \GatherPress\Core\Event\Recurrence\Context::set_for_series
	 * @covers \GatherPress\Core\Event\Recurrence\Context::resolve_in_series
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::find_in_series
	 *
	 * @return void
	 */
	public function test_rest_rsvp_write_stamps_the_occurrence_on_the_comment(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$response = $this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'recurrence_id' => self::OCCURRENCE_A,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Failed to assert the RSVP route accepted the request.' );
		$this->assertTrue( $response->get_data()['success'], 'Failed to assert the RSVP was recorded.' );

		Context::get_instance()->set( $post_id, self::OCCURRENCE_A );

		$comment_id = (int) ( new Rsvp( $post_id ) )->find( $user_id )->comment->comment_ID;

		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( $post_id, self::OCCURRENCE_A ) ),
			$this->term_slugs( $comment_id, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert an RSVP written through rest_do_request carries the occurrence term.'
		);
	}

	/**
	 * An RSVP written on one occurrence is invisible from a sibling occurrence.
	 *
	 * @covers ::update_rsvp
	 * @covers ::rsvp_responses
	 * @covers ::enter_occurrence_context
	 * @covers ::leave_occurrence_context
	 *
	 * @return void
	 */
	public function test_rest_rsvp_written_on_one_occurrence_is_invisible_from_the_sibling(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'recurrence_id' => self::OCCURRENCE_A,
			)
		);

		$on_a = $this->dispatch(
			'GET',
			'rsvp-responses',
			array(
				'post_id'       => $post_id,
				'recurrence_id' => self::OCCURRENCE_A,
			)
		);
		$on_b = $this->dispatch(
			'GET',
			'rsvp-responses',
			array(
				'post_id'       => $post_id,
				'recurrence_id' => self::OCCURRENCE_B,
			)
		);

		$this->assertSame(
			1,
			$on_a->get_data()['data']['attending']['count'],
			'Failed to assert the occurrence the RSVP was written on reports it.'
		);
		$this->assertSame(
			0,
			$on_b->get_data()['data']['attending']['count'],
			'Failed to assert a sibling occurrence does not report another occurrence\'s RSVP.'
		);
	}

	/**
	 * Context does not survive the dispatch that entered it.
	 *
	 * @covers ::leave_occurrence_context
	 *
	 * @return void
	 */
	public function test_rest_dispatch_leaves_no_occurrence_context_behind(): void {
		$post_id = $this->create_and_project();

		$this->dispatch(
			'GET',
			'rsvp-responses',
			array(
				'post_id'       => $post_id,
				'recurrence_id' => self::OCCURRENCE_A,
			)
		);

		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert a REST dispatch cleared the occurrence context it entered.'
		);
	}

	/**
	 * An unknown occurrence identifier is refused rather than read as the series.
	 *
	 * @covers ::validate_recurrence_id
	 *
	 * @return void
	 */
	public function test_rest_rsvp_rejects_an_unknown_recurrence_id(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$response = $this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'recurrence_id' => '20991231T235959',
			)
		);

		$this->assertSame(
			400,
			$response->get_status(),
			'Failed to assert an occurrence identifier matching no row is refused.'
		);
		$this->assertNull(
			( new Rsvp( $post_id ) )->find( $user_id ),
			'Failed to assert a refused request wrote no RSVP at all.'
		);
	}

	/**
	 * A malformed occurrence identifier is refused.
	 *
	 * @covers ::validate_recurrence_id
	 *
	 * @return void
	 */
	public function test_rest_rsvp_rejects_a_malformed_recurrence_id(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$this->assertSame(
			400,
			$this->dispatch(
				'POST',
				'rsvp',
				array(
					'post_id'       => $post_id,
					'status'        => 'attending',
					'recurrence_id' => 'not-a-recurrence-id',
				)
			)->get_status(),
			'Failed to assert a malformed occurrence identifier is refused.'
		);
		$this->assertSame(
			400,
			$this->dispatch(
				'POST',
				'rsvp',
				array(
					'post_id'       => $post_id,
					'status'        => 'attending',
					'recurrence_id' => '',
				)
			)->get_status(),
			'Failed to assert an empty occurrence identifier is refused.'
		);
	}

	/**
	 * An occurrence of another series is refused on this one.
	 *
	 * The composite key is the identity (PRD C-1): the same `Ymd\THis` names an
	 * occurrence of every series that meets at that moment, so validating the
	 * identifier alone would let a caller scope one series' RSVPs by another's.
	 *
	 * @covers ::validate_recurrence_id
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::find_in_series
	 *
	 * @return void
	 */
	public function test_rest_rsvp_rejects_an_occurrence_of_a_different_series(): void {
		$this->create_and_project();

		$other_post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$user_id       = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$this->assertSame(
			400,
			$this->dispatch(
				'GET',
				'rsvp-responses',
				array(
					'post_id'       => $other_post_id,
					'recurrence_id' => self::OCCURRENCE_A,
				)
			)->get_status(),
			'Failed to assert an occurrence belonging to another series is refused.'
		);
	}

	/**
	 * Omitting the argument keeps the series-wide behavior the routes always had.
	 *
	 * @covers ::update_rsvp
	 * @covers ::enter_occurrence_context
	 *
	 * @return void
	 */
	public function test_rest_rsvp_without_a_recurrence_id_writes_a_series_rsvp(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id' => $post_id,
				'status'  => 'attending',
			)
		);

		$comment_id = (int) ( new Rsvp( $post_id ) )->find( $user_id )->comment->comment_ID;

		$this->assertSame(
			array(),
			$this->term_slugs( $comment_id, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert a request naming no occurrence still writes a series-wide RSVP.'
		);
	}

	/**
	 * The rsvp-status-html route renders only the named occurrence's roster.
	 *
	 * @covers ::rsvp_status_html
	 * @covers ::enter_occurrence_context
	 *
	 * @return void
	 */
	public function test_rest_rsvp_status_html_reports_only_the_named_occurrence(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'recurrence_id' => self::OCCURRENCE_A,
			)
		);

		$block_data = $this->block_data();

		$on_b = $this->dispatch(
			'POST',
			'rsvp-status-html',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'block_data'    => $block_data,
				'recurrence_id' => self::OCCURRENCE_B,
			)
		);

		$this->assertSame(
			0,
			$on_b->get_data()['responses']['attending']['count'],
			'Failed to assert the rendered roster is scoped to the occurrence the request named.'
		);
	}

	/**
	 * The open RSVP form route stamps the occurrence on the comment it inserts.
	 *
	 * `Rsvp\Form::process_rsvp()` calls `wp_insert_comment()` directly rather
	 * than going through `Rsvp\Storage::save()`, so this path has its own
	 * occurrence stamping and needs its own end-to-end test.
	 *
	 * @covers ::handle_rsvp_form_submission
	 * @covers ::enter_occurrence_context
	 * @covers \GatherPress\Core\Rsvp\Form::process_rsvp
	 * @covers \GatherPress\Core\Rsvp\Form::assign_occurrence
	 *
	 * @return void
	 */
	public function test_rest_rsvp_form_stamps_the_occurrence_on_the_comment(): void {
		$post_id = $this->create_and_project();

		wp_set_current_user( 0 );

		$response = $this->dispatch(
			'POST',
			'rsvp-form',
			array(
				'comment_post_ID' => $post_id,
				'author'          => 'Ada Lovelace',
				'email'           => 'ada@example.test',
				'recurrence_id'   => self::OCCURRENCE_A,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Failed to assert the form route accepted the request.' );

		$comment_id = (int) $response->get_data()['comment_id'];

		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( $post_id, self::OCCURRENCE_A ) ),
			$this->term_slugs( $comment_id, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert an open RSVP submitted through the form route carries the occurrence term.'
		);
	}

	/**
	 * The same responder may take a sibling occurrence of the same series.
	 *
	 * Duplicate detection was series-wide, so the second date in a series was
	 * unbookable by anyone who had taken the first.
	 *
	 * @covers \GatherPress\Core\Rsvp\Form::has_duplicate_rsvp
	 * @covers \GatherPress\Core\Rsvp\Form::duplicate_occurrence_clause
	 *
	 * @return void
	 */
	public function test_rest_rsvp_form_allows_the_same_responder_on_a_sibling_occurrence(): void {
		$post_id = $this->create_and_project();

		wp_set_current_user( 0 );

		$first = $this->dispatch(
			'POST',
			'rsvp-form',
			array(
				'comment_post_ID' => $post_id,
				'author'          => 'Ada Lovelace',
				'email'           => 'ada@example.test',
				'recurrence_id'   => self::OCCURRENCE_A,
			)
		);

		$second = $this->dispatch(
			'POST',
			'rsvp-form',
			array(
				'comment_post_ID' => $post_id,
				'author'          => 'Ada Lovelace',
				'email'           => 'ada@example.test',
				'recurrence_id'   => self::OCCURRENCE_B,
			)
		);

		$this->assertSame( 200, $first->get_status(), 'Failed to assert the first occurrence accepted the RSVP.' );
		$this->assertSame(
			200,
			$second->get_status(),
			'Failed to assert a sibling occurrence accepts the same responder.'
		);
		$this->assertNotSame(
			$first->get_data()['comment_id'],
			$second->get_data()['comment_id'],
			'Failed to assert each occurrence stores its own response row.'
		);
	}

	/**
	 * A second RSVP to the same occurrence is still refused.
	 *
	 * @covers \GatherPress\Core\Rsvp\Form::has_duplicate_rsvp
	 * @covers \GatherPress\Core\Rsvp\Form::duplicate_occurrence_clause
	 *
	 * @return void
	 */
	public function test_rest_rsvp_form_still_refuses_a_duplicate_on_the_same_occurrence(): void {
		$post_id = $this->create_and_project();

		wp_set_current_user( 0 );

		$params = array(
			'comment_post_ID' => $post_id,
			'author'          => 'Ada Lovelace',
			'email'           => 'ada@example.test',
			'recurrence_id'   => self::OCCURRENCE_A,
		);

		$this->dispatch( 'POST', 'rsvp-form', $params );

		$this->assertSame(
			409,
			$this->dispatch( 'POST', 'rsvp-form', $params )->get_status(),
			'Failed to assert a repeat RSVP to the same occurrence is still refused.'
		);
	}

	/**
	 * Without an occurrence, duplicate detection stays series-wide.
	 *
	 * @covers \GatherPress\Core\Rsvp\Form::has_duplicate_rsvp
	 * @covers \GatherPress\Core\Rsvp\Form::duplicate_occurrence_clause
	 * @covers \GatherPress\Core\Rsvp\Form::assign_occurrence
	 *
	 * @return void
	 */
	public function test_rest_rsvp_form_refuses_a_duplicate_series_wide_without_an_occurrence(): void {
		$post_id = $this->create_and_project();

		wp_set_current_user( 0 );

		$params = array(
			'comment_post_ID' => $post_id,
			'author'          => 'Ada Lovelace',
			'email'           => 'ada@example.test',
		);

		$this->dispatch( 'POST', 'rsvp-form', $params );

		$this->assertSame(
			409,
			$this->dispatch( 'POST', 'rsvp-form', $params )->get_status(),
			'Failed to assert series-wide duplicate detection is unchanged when no occurrence is named.'
		);
	}

	/**
	 * `Form::process_rsvp()` stamps the occurrence when called in context.
	 *
	 * The REST tests above cover the wiring; this one pins the unit that does
	 * the stamping, so a future caller that enters context by another route
	 * still gets the term.
	 *
	 * @covers \GatherPress\Core\Rsvp\Form::process_rsvp
	 * @covers \GatherPress\Core\Rsvp\Form::assign_occurrence
	 *
	 * @return void
	 */
	public function test_process_rsvp_stamps_the_occurrence_it_is_called_in(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, self::OCCURRENCE_B );

		$result = Form::get_instance()->process_rsvp(
			array(
				'post_id' => $post_id,
				'author'  => 'Grace Hopper',
				'email'   => 'grace@example.test',
			)
		);

		$this->assertTrue( $result['success'], 'Failed to assert the RSVP was created.' );
		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( $post_id, self::OCCURRENCE_B ) ),
			$this->term_slugs( (int) $result['comment_id'], Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert process_rsvp stamps the occurrence it was called inside.'
		);
	}

	/**
	 * REQ-16: every RSVP route the change touches stays free on a plain site.
	 *
	 * Driven through `rest_do_request()` — the entry point, not the callback —
	 * because the argument definition, its validation and the context entry all
	 * live between the two. Each of the four routes is dispatched with the
	 * shape a real client sends, and the query log is checked for any mention
	 * of the occurrence table or the occurrence taxonomy.
	 *
	 * @covers ::recurrence_id_arg
	 * @covers ::enter_occurrence_context
	 * @covers ::update_rsvp
	 * @covers ::rsvp_responses
	 * @covers ::rsvp_status_html
	 * @covers ::handle_rsvp_form_submission
	 *
	 * @return void
	 */
	public function test_non_recurring_site_touches_no_occurrence_storage_through_any_rsvp_route(): void {
		global $wpdb;

		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		// One event per route, so no route is handed another's warm caches and
		// every dispatch below does real work.
		$dispatches = array(
			'rsvp'             => static function ( int $post_id ): array {
				return array(
					'POST',
					array(
						'post_id' => $post_id,
						'status'  => 'attending',
					),
				);
			},
			'rsvp-responses'   => static function ( int $post_id ): array {
				return array( 'GET', array( 'post_id' => $post_id ) );
			},
			'rsvp-status-html' => function ( int $post_id ): array {
				return array(
					'POST',
					array(
						'post_id'    => $post_id,
						'status'     => 'attending',
						'block_data' => $this->block_data(),
					),
				);
			},
			'rsvp-form'        => static function ( int $post_id ): array {
				return array(
					'POST',
					array(
						'comment_post_ID' => $post_id,
						'author'          => 'Ada Lovelace',
						'email'           => 'ada@example.test',
					),
				);
			},
		);

		$occurrences_table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );

		foreach ( $dispatches as $route => $build ) {
			$dispatch = $build( $this->create_plain_event() );
			$before   = count( $wpdb->queries );

			$this->dispatch( $dispatch[0], $route, $dispatch[1] );

			$since = array_slice( $wpdb->queries, $before );

			$this->assertNotEmpty(
				$since,
				sprintf(
					'Failed to capture queries for %s; SAVEQUERIES must be on for this assertion to mean anything.',
					$route
				)
			);
			$this->assertSame(
				array(),
				array_values(
					array_filter(
						$since,
						static function ( array $query ) use ( $occurrences_table ): bool {
							return str_contains( $query[0], $occurrences_table )
								|| str_contains( $query[0], Rsvp_Occurrence::TAXONOMY );
						}
					)
				),
				sprintf( 'Failed to assert %s touched no occurrence storage on a non-recurring site.', $route )
			);
		}
	}

	/**
	 * REQ-16: a fabricated `recurrence_id` costs a plain site nothing either.
	 *
	 * A crawler appending the argument to an ordinary event's RSVP request
	 * must not reach the occurrence table — the validation short-circuits on
	 * the autoloaded option before any lookup.
	 *
	 * @covers ::validate_recurrence_id
	 * @covers \GatherPress\Core\Event\Recurrence\Context::resolve_in_series
	 *
	 * @return void
	 */
	public function test_non_recurring_site_runs_no_occurrence_query_for_a_fabricated_argument(): void {
		global $wpdb;

		$post_id = $this->create_plain_event();
		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$occurrences_table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$before            = count( $wpdb->queries );

		$response = $this->dispatch(
			'GET',
			'rsvp-responses',
			array(
				'post_id'       => $post_id,
				'recurrence_id' => self::OCCURRENCE_A,
			)
		);

		$since = array_slice( $wpdb->queries, $before );

		$this->assertSame(
			400,
			$response->get_status(),
			'Failed to assert a fabricated occurrence identifier is refused on a non-recurring site too.'
		);
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$since,
					static function ( array $query ) use ( $occurrences_table ): bool {
						return str_contains( $query[0], $occurrences_table );
					}
				)
			),
			'Failed to assert a non-recurring site refuses the argument without querying the occurrence table.'
		);
	}
}
