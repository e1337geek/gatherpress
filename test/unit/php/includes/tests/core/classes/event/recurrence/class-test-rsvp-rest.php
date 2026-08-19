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

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Recurrence\Context;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Query as Recurrence_Query;
use GatherPress\Core\Event\Recurrence\Rsvp_Occurrence;
use GatherPress\Core\Event\Rest_Api;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Rsvp\Cache;
use GatherPress\Core\Rsvp\Form;
use GatherPress\Core\Rsvp\Rsvp;
use GatherPress\Core\Rsvp\Setup as Rsvp_Setup;
use GatherPress\Core\Rsvp\Token;
use GatherPress\Core\Settings;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use ReflectionMethod;
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
	 * A well-formed identifier that names no occurrence of anything.
	 *
	 * Far enough out that no relative fixture can ever project onto it, so the
	 * "unknown identifier" tests keep meaning what they say.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const UNKNOWN_OCCURRENCE = '20991231T235959';

	/**
	 * Recurrence identifier of the projected set's first occurrence.
	 *
	 * Derived from the rows `create_and_project()` actually wrote, never
	 * hard-coded. See that method for why.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	protected string $occurrence_a = '';

	/**
	 * Recurrence identifier of the projected set's second occurrence.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	protected string $occurrence_b = '';

	/**
	 * Series-widening filters installed by `widen_series()`, for teardown.
	 *
	 * @since 0.36.0
	 * @var callable[]
	 */
	protected array $series_filters = array();

	/**
	 * Ensure the occurrence table and the RSVP routes exist, and no context leaks in.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		gatherpress_reset_custom_tables();
		Rsvp_Setup::get_instance()->register_taxonomy();
		Rest_Api::get_instance()->register_endpoints();
		Settings::get_instance()->set( 'enable_open_rsvp', true );
		Context::get_instance()->clear();
		Context::flush_resolved();
	}

	/**
	 * Leave no occurrence context, resolution memo or series filter behind.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->series_filters as $filter ) {
			remove_filter( 'gatherpress_series_post_ids', $filter, 10 );
		}

		$this->series_filters = array();

		Context::get_instance()->clear();
		Context::flush_resolved();

		parent::tearDown();
	}

	/**
	 * Build the anchor every fixture in this class is dated from.
	 *
	 * Thirty days out, so nothing here is ever in the past. **This is the whole
	 * reason the class does not use `Occurrence_Fixtures`' fixed 2026-09-03
	 * anchor.** `Rest_Api::update_rsvp()` gates its write on
	 * `! $event->has_event_past()`, and `Rsvp\Form::process_rsvp()` bails 400 on
	 * the same check — both reading the *series* post meta, which occurrence
	 * context never substitutes. Against a pinned anchor the entire suite
	 * silently stops writing anything the day real time crosses it, and six of
	 * these tests would have started failing on 2026-09-04 while three others
	 * kept passing with nothing written at all.
	 *
	 * @since 0.36.0
	 *
	 * @return DateTimeImmutable The anchor start, in the fixture timezone.
	 */
	protected function relative_anchor(): DateTimeImmutable {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/New_York' ) ) )
			->modify( '+30 days' )
			->setTime( 18, 0 );
	}

	/**
	 * Create the reference recurring event, project it, and flag the site as recurring.
	 *
	 * Mirrors the production order: the rule mirrors are written first, the
	 * occurrence rows are projected from them, and only then is the
	 * has-recurring-events flag recomputed from storage.
	 *
	 * The two occurrence identifiers the tests use are read back out of the rows
	 * projection actually wrote, ordered as the table orders them, rather than
	 * restated as constants. Restating them couples every test to a calendar
	 * date; deriving them means the fixture and the assertions cannot drift
	 * apart however the anchor moves.
	 *
	 * @since 0.36.0
	 *
	 * @return int The projected series post ID.
	 */
	protected function create_and_project(): int {
		$anchor = $this->relative_anchor();

		$post_id = $this->create_relative_recurring_event(
			self::WEEKLY_RULE,
			$anchor,
			$anchor->modify( '+2 hours' )
		);

		Recurrence_Query::refresh_has_recurring_events();
		Context::flush_resolved();

		$rows = Occurrences::get_instance()->select_for_series( array( $post_id ) );

		$this->assertGreaterThan(
			1,
			count( $rows ),
			'Failed to project the two sibling occurrences every test in this class needs.'
		);

		$this->occurrence_a = (string) $rows[0]['recurrence_id'];
		$this->occurrence_b = (string) $rows[1]['recurrence_id'];

		$this->assertNotSame(
			$this->occurrence_a,
			$this->occurrence_b,
			'Failed to assert the two fixture occurrences are distinct.'
		);

		return $post_id;
	}

	/**
	 * Create an ordinary, never-recurring event on a site with no recurring events.
	 *
	 * Dated from the same relative anchor, and for the same reason: the
	 * no-recurring-events routes below include two that write, and a write to a past event is
	 * refused before it can touch the storage those tests are watching.
	 *
	 * @since 0.36.0
	 *
	 * @return int The created post ID.
	 */
	protected function create_plain_event(): int {
		$anchor  = $this->relative_anchor();
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
					'dateTimeStart' => $anchor->format( 'Y-m-d H:i:s' ),
					'dateTimeEnd'   => $anchor->modify( '+2 hours' )->format( 'Y-m-d H:i:s' ),
					'timezone'      => 'America/New_York',
				)
			)
		);

		Event_Setup::get_instance()->set_datetimes( $post_id );
		Recurrence_Query::refresh_has_recurring_events();
		Context::flush_resolved();

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
	 * The fixture this class writes against is always in the future.
	 *
	 * The guard against a date bomb, kept as a first-class test rather than left
	 * implicit in `relative_anchor()`. Every write in this file passes through
	 * `! $event->has_event_past()`, which reads the **series** post meta —
	 * occurrence context never substitutes it. When that gate closes, six tests
	 * here fail and three more keep passing with nothing written at all, and the
	 * failures name RSVP scoping rather than the fixture date, so the cause is
	 * expensive to find. If someone re-pins the anchor to a calendar date, this
	 * fails first and says why.
	 *
	 * It also states the derivation contract the rest of the file depends on:
	 * the two identifiers come out of the projected rows, so they track the
	 * anchor wherever it moves and cannot be restated as constants without this
	 * test noticing.
	 *
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::project
	 *
	 * @return void
	 */
	public function test_the_fixture_series_is_never_in_the_past(): void {
		$post_id = $this->create_and_project();

		$this->assertFalse(
			( new Event( $post_id ) )->has_event_past(),
			'Failed to assert the fixture event is in the future; every RSVP write in this class is gated on it.'
		);

		$now = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/New_York' ) ) )->format( 'Ymd\THis' );

		$this->assertGreaterThan(
			$now,
			$this->occurrence_a,
			'Failed to assert the first fixture occurrence is in the future.'
		);
		$this->assertGreaterThan(
			$this->occurrence_a,
			$this->occurrence_b,
			'Failed to assert the second fixture occurrence follows the first.'
		);
		$this->assertMatchesRegularExpression(
			'/^\d{8}T\d{6}$/',
			$this->occurrence_a,
			'Failed to assert the derived identifier keeps the Ymd\THis shape.'
		);
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
				'recurrence_id' => $this->occurrence_a,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Failed to assert the RSVP route accepted the request.' );
		$this->assertTrue( $response->get_data()['success'], 'Failed to assert the RSVP was recorded.' );

		Context::get_instance()->set( $post_id, $this->occurrence_a );

		$comment_id = (int) ( new Rsvp( $post_id ) )->find( $user_id )->comment->comment_ID;

		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( $post_id, $this->occurrence_a ) ),
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
				'recurrence_id' => $this->occurrence_a,
			)
		);

		$on_a = $this->dispatch(
			'GET',
			'rsvp-responses',
			array(
				'post_id'       => $post_id,
				'recurrence_id' => $this->occurrence_a,
			)
		);
		$on_b = $this->dispatch(
			'GET',
			'rsvp-responses',
			array(
				'post_id'       => $post_id,
				'recurrence_id' => $this->occurrence_b,
			)
		);

		// B hosts its own response too. Without this the test only proves "B does
		// not show A's RSVP", which an unconditionally empty B satisfies just as
		// well as a correctly scoped one.
		$second_user = $this->factory->user->create();

		wp_set_current_user( $second_user );

		$this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'recurrence_id' => $this->occurrence_b,
			)
		);

		$b_after = $this->dispatch(
			'GET',
			'rsvp-responses',
			array(
				'post_id'       => $post_id,
				'recurrence_id' => $this->occurrence_b,
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
		$this->assertSame(
			1,
			$b_after->get_data()['data']['attending']['count'],
			'Failed to assert the sibling occurrence hosts a response of its own.'
		);
		$this->assertSame(
			2,
			$this->dispatch( 'GET', 'rsvp-responses', array( 'post_id' => $post_id ) )
				->get_data()['data']['attending']['count'],
			'Failed to assert the series reports the union of both occurrences.'
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
				'recurrence_id' => $this->occurrence_a,
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
	 * The refusal is a 404 rather than the 400 an argument validator would
	 * produce, and the difference is the security property. Membership is
	 * resolved after the permission callback, so a well-formed identifier that
	 * names no row of this series cannot be distinguished from a real one until
	 * the caller has been authorized to look. Malformed syntax is still a 400,
	 * because that is refusable from the string alone.
	 *
	 * @covers ::enter_occurrence_context
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
				'recurrence_id' => self::UNKNOWN_OCCURRENCE,
			)
		);

		$this->assertSame(
			404,
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
	 * The composite key is the identity: the same `Ymd\THis` names an
	 * occurrence of every series that meets at that moment, so validating the
	 * identifier alone would let a caller scope one series' RSVPs by another's.
	 *
	 * @covers ::enter_occurrence_context
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
			404,
			$this->dispatch(
				'GET',
				'rsvp-responses',
				array(
					'post_id'       => $other_post_id,
					'recurrence_id' => $this->occurrence_a,
				)
			)->get_status(),
			'Failed to assert an occurrence belonging to another series is refused.'
		);
	}

	/**
	 * Omitting the argument keeps the series-wide behavior the routes always had.
	 *
	 * This is the non-recurring-parity half of the verdict, so it is written to
	 * be able to fail for the reason it names. The bare term assertion could
	 * not: `find()` returns null when no RSVP exists, `->comment->comment_ID`
	 * casts that to `0`, and `wp_get_object_terms( 0, … )` returns `array()` —
	 * identical to the expected value, so a refused write left it green (rule
	 * 3a #8). The comment ID is asserted real first, and the RSVP is asserted
	 * readable series-wide, which is the behavior "series-wide RSVP" actually
	 * names rather than merely the absence of a term.
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

		$rsvp = ( new Rsvp( $post_id ) )->find( $user_id );

		$this->assertNotNull( $rsvp, 'Failed to assert the series-wide RSVP was written at all.' );

		$comment_id = (int) $rsvp->comment->comment_ID;

		$this->assertGreaterThan(
			0,
			$comment_id,
			'Failed to assert the series-wide RSVP resolved to a real comment.'
		);
		$this->assertSame(
			array(),
			$this->term_slugs( $comment_id, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert a request naming no occurrence still writes a series-wide RSVP.'
		);
		$this->assertSame(
			1,
			$this->dispatch( 'GET', 'rsvp-responses', array( 'post_id' => $post_id ) )
				->get_data()['data']['attending']['count'],
			'Failed to assert a series-wide RSVP is readable series-wide.'
		);
	}

	/**
	 * The rsvp-status-html route renders only the named occurrence's roster.
	 *
	 * Both sides are asserted deliberately. A lone `0` on the sibling is
	 * produced by two different mechanisms — "the roster is scoped to B" and
	 * "no RSVP exists anywhere" — so on its own it stays green with the write
	 * path completely dead, which is exactly how it behaves once the fixture
	 * anchor goes stale. The `1` on A is what excludes the
	 * coincidence: it can only be produced by an RSVP that really was written
	 * and really is scoped, and this is the only REST-level test of the block
	 * render path.
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

		$written = $this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'recurrence_id' => $this->occurrence_a,
			)
		);

		$this->assertTrue(
			$written->get_data()['success'],
			'Failed to arrange the RSVP this test scopes; the assertions below would pass on an empty roster.'
		);

		$block_data = $this->block_data();

		$on_a = $this->dispatch(
			'POST',
			'rsvp-status-html',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'block_data'    => $block_data,
				'recurrence_id' => $this->occurrence_a,
			)
		);
		$on_b = $this->dispatch(
			'POST',
			'rsvp-status-html',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'block_data'    => $block_data,
				'recurrence_id' => $this->occurrence_b,
			)
		);

		$this->assertSame(
			1,
			$on_a->get_data()['responses']['attending']['count'],
			'Failed to assert the rendered roster reports the RSVP written on the occurrence it names.'
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
				'recurrence_id'   => $this->occurrence_a,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Failed to assert the form route accepted the request.' );

		$comment_id = (int) $response->get_data()['comment_id'];

		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( $post_id, $this->occurrence_a ) ),
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
				'recurrence_id'   => $this->occurrence_a,
			)
		);

		$second = $this->dispatch(
			'POST',
			'rsvp-form',
			array(
				'comment_post_ID' => $post_id,
				'author'          => 'Ada Lovelace',
				'email'           => 'ada@example.test',
				'recurrence_id'   => $this->occurrence_b,
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
			'recurrence_id'   => $this->occurrence_a,
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

		Context::get_instance()->set( $post_id, $this->occurrence_b );

		$result = Form::get_instance()->process_rsvp(
			array(
				'post_id' => $post_id,
				'author'  => 'Grace Hopper',
				'email'   => 'grace@example.test',
			)
		);

		$this->assertTrue( $result['success'], 'Failed to assert the RSVP was created.' );
		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( $post_id, $this->occurrence_b ) ),
			$this->term_slugs( (int) $result['comment_id'], Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert process_rsvp stamps the occurrence it was called inside.'
		);
	}

	/**
	 * Every RSVP route the change touches stays free on a plain site.
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
	 * A fabricated `recurrence_id` costs a plain site nothing either.
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
				'recurrence_id' => self::UNKNOWN_OCCURRENCE,
			)
		);

		$since = array_slice( $wpdb->queries, $before );

		$this->assertSame(
			404,
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

	/**
	 * The request path resolves through the series, not the named post.
	 *
	 * Nothing else in the suite pins this. `Test_Occurrences` pins that
	 * `find_in_series()` emits `IN (…)`, but that is one layer down — replacing
	 * `Series::resolve_post_ids( $post_id )` with `array( $post_id )` inside
	 * `Context::resolve_in_series()` left the whole suite green, because with a
	 * one-post series the two are indistinguishable. Installing the
	 * `gatherpress_series_post_ids` filter is what tells them apart: the request
	 * names a post that owns no occurrence rows at all, and only a resolver call
	 * can reach the sibling that does.
	 *
	 * @covers ::requested_occurrence
	 * @covers \GatherPress\Core\Event\Recurrence\Context::resolve_in_series
	 * @covers \GatherPress\Core\Event\Recurrence\Series::resolve_post_ids
	 *
	 * @return void
	 */
	public function test_rest_resolves_an_occurrence_through_a_widened_series(): void {
		$owner_post_id = $this->create_and_project();
		$occurrence_id = $this->occurrence_a;

		// A second event of the same notional series, carrying no occurrence
		// rows of its own. Without the filter below, nothing links the two.
		$sibling_post_id = $this->create_plain_event();

		Recurrence_Query::refresh_has_recurring_events();
		Context::flush_resolved();

		$this->widen_series( $sibling_post_id, $owner_post_id );

		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$response = $this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $sibling_post_id,
				'status'        => 'attending',
				'recurrence_id' => $occurrence_id,
			)
		);

		$this->assertSame(
			200,
			$response->get_status(),
			'Failed to assert an occurrence on a sibling post of the series resolves through the resolver.'
		);
		$this->assertTrue(
			$response->get_data()['success'],
			'Failed to assert the RSVP on the widened series was recorded.'
		);
	}

	/**
	 * An RSVP named on a sibling post lands on the occurrence's owner.
	 *
	 * The failure this pins is the quiet one. `Context` deliberately resolves
	 * across the series, so validation passes and context is entered — and
	 * `Rsvp_Occurrence` then used to compare the context's `series_post_id`
	 * against the post the request named and reject the mismatch. Every scoping
	 * consumer returned null, so the RSVP was written **series-wide with no
	 * occurrence term** while the responder believed they had booked one date,
	 * and there was no error anywhere to say so. A 200 cannot see that; only the
	 * stored row can.
	 *
	 * This test also states the ownership invariant. The comment's
	 * `comment_post_ID` and the occurrence term's post prefix must be the same
	 * post, because `Rsvp\Storage` narrows every occurrence-scoped read by both
	 * at once: a row whose two owners disagree is readable through neither, and
	 * the same responder can then create a second response on the other post.
	 * The owner is the post the occurrence row lives on, so the response is
	 * written there rather than on the sibling the request named.
	 *
	 * Visibility is asserted through `Rsvp::responses()`, the production roster
	 * every consumer reads, rather than through the taxonomy: a term assertion
	 * passes on exactly the split-owner state this invariant exists to forbid.
	 *
	 * @covers \GatherPress\Core\Event\Recurrence\Rsvp_Occurrence::current_occurrence
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrence_Identity::resolve
	 * @covers \GatherPress\Core\Rsvp\Storage::save
	 *
	 * @return void
	 */
	public function test_rsvp_on_a_sibling_post_of_the_series_is_stamped_with_the_occurrence(): void {
		$owner_post_id = $this->create_and_project();
		$occurrence_id = $this->occurrence_a;

		$sibling_post_id = $this->create_plain_event();

		Recurrence_Query::refresh_has_recurring_events();
		Context::flush_resolved();

		$this->widen_series( $sibling_post_id, $owner_post_id );

		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $sibling_post_id,
				'status'        => 'attending',
				'recurrence_id' => $occurrence_id,
			)
		);

		$rsvp = ( new Rsvp( $owner_post_id ) )->find( $user_id );

		$this->assertNotNull(
			$rsvp,
			'Failed to assert the RSVP named on the sibling post was written against the occurrence owner.'
		);

		$comment_id = (int) $rsvp->comment->comment_ID;

		// The two owners agree: the comment's post and the occurrence term's
		// post prefix are the same post.
		$this->assertSame(
			$owner_post_id,
			(int) $rsvp->comment->comment_post_ID,
			'Failed to assert the stored comment belongs to the post that owns the occurrence.'
		);
		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( $owner_post_id, $occurrence_id ) ),
			$this->term_slugs( $comment_id, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert an RSVP on a sibling post is stamped with the occurrence it names.'
		);

		// Rule 3a #3: the roster consumers actually read, not the taxonomy.
		Context::get_instance()->set_for_series( $owner_post_id, $occurrence_id );

		$responses = ( new Rsvp( $owner_post_id ) )->responses();

		Context::get_instance()->clear();

		$this->assertSame(
			array( $comment_id ),
			array_map( 'intval', wp_list_pluck( $responses['attending']['records'], 'comment_id' ) ),
			'Failed to assert the response is visible exactly once through the production roster.'
		);
	}

	/**
	 * F-2: a resolution that fails after validation is refused, not widened.
	 *
	 * `enter_occurrence_context()` runs a second lookup of an identifier the
	 * validate callback already resolved. Discarding its result meant that if
	 * the row disappeared between the two queries the request continued
	 * **series-wide under a 200** — the exact outcome the validate callback
	 * exists to prevent, arrived at by a different door.
	 *
	 * The race is simulated deterministically: the row is deleted from a filter
	 * that fires after validation and before the callback.
	 *
	 * Every one of the four routes that enters context is driven, because the
	 * refusal is four separate returns and one route honoring it says nothing
	 * about the other three.
	 *
	 * @covers ::enter_occurrence_context
	 * @covers ::update_rsvp
	 * @covers ::rsvp_responses
	 * @covers ::rsvp_status_html
	 * @covers ::handle_rsvp_form_submission
	 *
	 * @return void
	 */
	public function test_rest_refuses_when_the_occurrence_vanishes_after_validation(): void {
		global $wpdb;

		$user_id = $this->factory->user->create();
		$table   = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );

		$routes = array(
			'rsvp'             => static function ( int $post_id, string $rid ): array {
				return array(
					'POST',
					array(
						'post_id'       => $post_id,
						'status'        => 'attending',
						'recurrence_id' => $rid,
					),
				);
			},
			'rsvp-responses'   => static function ( int $post_id, string $rid ): array {
				return array(
					'GET',
					array(
						'post_id'       => $post_id,
						'recurrence_id' => $rid,
					),
				);
			},
			'rsvp-status-html' => function ( int $post_id, string $rid ): array {
				return array(
					'POST',
					array(
						'post_id'       => $post_id,
						'status'        => 'attending',
						'block_data'    => $this->block_data(),
						'recurrence_id' => $rid,
					),
				);
			},
			'rsvp-form'        => static function ( int $post_id, string $rid ): array {
				return array(
					'POST',
					array(
						'comment_post_ID' => $post_id,
						'author'          => 'Ada Lovelace',
						'email'           => 'ada@example.test',
						'recurrence_id'   => $rid,
					),
				);
			},
		);

		foreach ( $routes as $route => $build ) {
			wp_set_current_user( $user_id );

			$post_id       = $this->create_and_project();
			$occurrence_id = $this->occurrence_a;

			// `rest_request_before_callbacks` fires after validation and
			// permission checks, and before the route callback — the exact
			// window the second lookup can lose the row in.
			$vanish = static function ( $response ) use ( $wpdb, $table, $post_id, $occurrence_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete(
					$table,
					array(
						'series_post_id' => $post_id,
						'recurrence_id'  => $occurrence_id,
					)
				);
				Context::flush_resolved();

				return $response;
			};

			add_filter( 'rest_request_before_callbacks', $vanish, 5 );

			$dispatch = $build( $post_id, $occurrence_id );
			$response = $this->dispatch( $dispatch[0], $route, $dispatch[1] );

			remove_filter( 'rest_request_before_callbacks', $vanish, 5 );

			$this->assertSame(
				404,
				$response->get_status(),
				sprintf( 'Failed to assert %s refuses a request whose occurrence vanished.', $route )
			);
			$this->assertNull(
				( new Rsvp( $post_id ) )->find( $user_id ),
				sprintf( 'Failed to assert %s wrote no series-wide RSVP after refusing.', $route )
			);
		}
	}

	/**
	 * F-1: a nested dispatch does not tear down the outer request's context.
	 *
	 * `rest_request_after_callbacks` is global and fires for every dispatch,
	 * including one a route makes internally while holding context —
	 * `rsvp_status_html()` renders arbitrary blocks, any of which may call
	 * `rest_do_request()`. A teardown that could not tell which request it was
	 * leaving would clear the outer context mid-callback and unhook itself, so
	 * the rest of the outer route would read and write series-wide, silently,
	 * and never clear at all.
	 *
	 * The inner dispatch here deliberately names **no** occurrence, so it
	 * registers no teardown of its own — which is what makes the outer one the
	 * only thing that could have cleared the context.
	 *
	 * @covers ::leave_occurrence_context
	 *
	 * @return void
	 */
	public function test_a_nested_dispatch_leaves_the_outer_occurrence_context_intact(): void {
		$post_id       = $this->create_and_project();
		$occurrence_id = $this->occurrence_a;
		$observed      = null;

		// `comments_clauses` fires while the outer route is reading its roster —
		// inside the callback, with the context held. That is the position a
		// block render occupies on the `rsvp-status-html` route, which is the
		// real-world path that can dispatch internally.
		$nested = false;
		$nest   = static function ( $clauses ) use ( &$observed, &$nested, $post_id ) {
			if ( $nested ) {
				return $clauses;
			}

			$nested = true;

			$inner = new WP_REST_Request(
				'GET',
				sprintf( '/%s/event/rsvp-responses', GATHERPRESS_REST_NAMESPACE )
			);
			$inner->set_param( 'post_id', $post_id );

			rest_do_request( $inner );

			$observed = Context::get_instance()->current();

			return $clauses;
		};

		add_filter( 'comments_clauses', $nest );

		$this->dispatch(
			'GET',
			'rsvp-responses',
			array(
				'post_id'       => $post_id,
				'recurrence_id' => $occurrence_id,
			)
		);

		remove_filter( 'comments_clauses', $nest );

		$this->assertNotNull(
			$observed,
			'Failed to assert a nested dispatch left the outer request\'s occurrence context standing.'
		);
		$this->assertSame(
			$occurrence_id,
			(string) $observed['recurrence_id'],
			'Failed to assert the surviving context is still the outer request\'s occurrence.'
		);
		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert the outer dispatch still cleared its own context on the way out.'
		);
	}

	/**
	 * F-3: an open-form submission invalidates the counts it just changed.
	 *
	 * The form route inserts its comment directly rather than through
	 * `Rsvp\Storage::save()`, which is where every other write does its
	 * invalidation — `Cache::delete` appeared nowhere in `Rsvp\Form` at all. So
	 * an anonymous RSVP left the warm counts reading whatever they read before
	 * it, for the length of `Cache::CACHE_EXPIRATION`, and under a persistent
	 * object cache that stale total was shared by every visitor at once.
	 *
	 * Both keys are asserted, because both are wrong after the write: the
	 * response changes the occurrence's counts and the series' own.
	 *
	 * @covers \GatherPress\Core\Rsvp\Form::handle_rsvp_creation
	 * @covers \GatherPress\Core\Rsvp\Cache::delete
	 *
	 * @return void
	 */
	public function test_open_form_submission_invalidates_both_cache_keys(): void {
		$post_id = $this->create_and_project();

		wp_set_current_user( 0 );

		Cache::set( $post_id, array( 'all' => array( 'count' => 99 ) ) );
		Cache::set( $post_id, array( 'all' => array( 'count' => 42 ) ), $this->occurrence_a );

		// Both keys must be warm and distinct before the write, or "both were
		// invalidated" is satisfied by there having been nothing to invalidate.
		$this->assertSame(
			array( 'all' => array( 'count' => 99 ) ),
			Cache::get( $post_id ),
			'Failed to warm the series-wide cache entry the submission must invalidate.'
		);
		$this->assertSame(
			array( 'all' => array( 'count' => 42 ) ),
			Cache::get( $post_id, $this->occurrence_a ),
			'Failed to warm the occurrence cache entry the submission must invalidate.'
		);

		$response = $this->dispatch(
			'POST',
			'rsvp-form',
			array(
				'comment_post_ID' => $post_id,
				'author'          => 'Ada Lovelace',
				'email'           => 'ada@example.test',
				'recurrence_id'   => $this->occurrence_a,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Failed to arrange an accepted form submission.' );

		// The assertion is that the *stale* value is gone, not that the key is
		// absent: the route returns fresh counts and re-warms as it goes, which
		// is correct. What must not survive is the pre-write total.
		$this->assertNotSame(
			array( 'all' => array( 'count' => 42 ) ),
			Cache::get( $post_id, $this->occurrence_a ),
			'Failed to assert an open-form submission invalidated the occurrence-scoped cache key.'
		);
		$this->assertNotSame(
			array( 'all' => array( 'count' => 99 ) ),
			Cache::get( $post_id ),
			'Failed to assert an open-form submission invalidated the series-wide cache key.'
		);

		// The response the form created really exists on this occurrence, so the
		// invalidation above was invalidating something real. It is not yet
		// *attending* — an open-form RSVP stays unapproved until its token is
		// confirmed — so the assertion is on the stored comment, not the count.
		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( $post_id, $this->occurrence_a ) ),
			$this->term_slugs( (int) $response->get_data()['comment_id'], Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert the form submission this test invalidated for landed on the occurrence.'
		);
	}

	/**
	 * Widen a series so `$member` resolves to both posts.
	 *
	 * Installs the `gatherpress_series_post_ids` filter the forward split will
	 * populate for real, which is the only seam by which a multi-post series can exist —
	 * `Series` is final with a protected constructor precisely so no test can
	 * fake one any other way.
	 *
	 * @since 0.36.0
	 *
	 * @param int $member The post the request names.
	 * @param int $owner  The post the occurrence rows live on.
	 *
	 * @return void
	 */
	protected function widen_series( int $member, int $owner ): void {
		$filter = static function ( array $post_ids, int $post_id ) use ( $member, $owner ): array {
			return ( $member === $post_id || $owner === $post_id )
				? array( $member, $owner )
				: $post_ids;
		};

		add_filter( 'gatherpress_series_post_ids', $filter, 10, 2 );

		Context::flush_resolved();

		$this->series_filters[] = $filter;
	}

	/**
	 * Issue a real guest token against one occurrence.
	 *
	 * Goes through the public form route rather than constructing the comment,
	 * so the token and its occurrence term are the ones the production path
	 * produces, then redeems the link exactly as a visitor clicking it would.
	 * Redemption matters: an open-form RSVP is stored unapproved, and every
	 * roster read is scoped to approved responses, so an unredeemed response is
	 * invisible to the assertions these fixtures exist to make.
	 *
	 * @since 0.36.0
	 *
	 * @param int         $post_id       Event post ID.
	 * @param string      $email         Responder email.
	 * @param string|null $recurrence_id Occurrence to book, or null for a series-wide RSVP.
	 *
	 * @return array{comment_id: int, token: string} The stored comment and its magic-link token string.
	 */
	protected function issue_token( int $post_id, string $email, ?string $recurrence_id ): array {
		$params = array(
			'comment_post_ID' => $post_id,
			'author'          => 'Ada Lovelace',
			'email'           => $email,
		);

		if ( null !== $recurrence_id ) {
			$params['recurrence_id'] = $recurrence_id;
		}

		$response = $this->dispatch( 'POST', 'rsvp-form', $params );

		$this->assertSame( 200, $response->get_status(), 'Failed to arrange an accepted form submission.' );

		$comment_id = (int) $response->get_data()['comment_id'];
		$token      = new Token( $comment_id );
		$token_str  = sprintf( '%d_%s', $comment_id, $token->get_token() );

		$token->approve_comment();

		return array(
			'comment_id' => $comment_id,
			'token'      => $token_str,
		);
	}

	/**
	 * Count the responses stored against one occurrence, through the production roster.
	 *
	 * Rule 3a #3: read through `Rsvp::responses()`, the API every consumer
	 * reaches, rather than through the taxonomy. A term-level count passes on
	 * exactly the split-owner state the ownership invariant exists to forbid.
	 *
	 * @since 0.36.0
	 *
	 * @param int         $post_id       Event post ID.
	 * @param string|null $recurrence_id Occurrence to scope to, or null for the series.
	 *
	 * @return int[] Comment IDs on the roster, across every status.
	 */
	protected function roster( int $post_id, ?string $recurrence_id ): array {
		if ( null !== $recurrence_id ) {
			Context::get_instance()->set_for_series( $post_id, $recurrence_id );
		}

		$responses = ( new Rsvp( $post_id ) )->responses();

		Context::get_instance()->clear();

		$ids = array();

		foreach ( array( 'attending', 'not_attending', 'waiting_list' ) as $status ) {
			$ids = array_merge( $ids, array_column( $responses[ $status ]['records'], 'comment_id' ) );
		}

		sort( $ids );

		return array_map( 'intval', $ids );
	}

	/**
	 * B1: a magic-link token for occurrence A cannot write occurrence B.
	 *
	 * The measured defect. The permission callback compared the token's *event
	 * post* against the request's, which every occurrence of a series satisfies,
	 * and the callback then entered whichever occurrence the request named. So
	 * the holder of a confirmation link for one date could write the token
	 * holder's identity into any other date of the same series, and the route
	 * answered `success: true`.
	 *
	 * The refusal is asserted three ways, because a 403 alone does not prove the
	 * roster survived: B's roster is compared before and after, and A's own
	 * roster is compared too, so a refusal that quietly moved the response would
	 * fail.
	 *
	 * @covers ::can_update_rsvp
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrence_Identity::matches
	 *
	 * @return void
	 */
	public function test_a_token_for_one_occurrence_cannot_write_another(): void {
		$post_id = $this->create_and_project();

		wp_set_current_user( 0 );

		$issued = $this->issue_token( $post_id, 'ada@example.test', $this->occurrence_a );

		$roster_a_before = $this->roster( $post_id, $this->occurrence_a );
		$roster_b_before = $this->roster( $post_id, $this->occurrence_b );

		$this->assertSame(
			array( $issued['comment_id'] ),
			$roster_a_before,
			'Failed to arrange a response on occurrence A to be defended.'
		);

		$response = $this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'rsvp_token'    => $issued['token'],
				'recurrence_id' => $this->occurrence_b,
			)
		);

		$this->assertSame(
			403,
			$response->get_status(),
			'Failed to assert a token issued for one occurrence is refused on a sibling occurrence.'
		);
		$this->assertSame(
			$roster_b_before,
			$this->roster( $post_id, $this->occurrence_b ),
			'Failed to assert the refused request left the sibling occurrence roster unchanged.'
		);
		$this->assertSame(
			$roster_a_before,
			$this->roster( $post_id, $this->occurrence_a ),
			'Failed to assert the refused request left the token holder\'s own occurrence unchanged.'
		);
	}

	/**
	 * B1: the same token on its own occurrence still works.
	 *
	 * The other half of the verdict. A refusal that also broke the legitimate
	 * case would satisfy the test above and break every confirmation link.
	 *
	 * @covers ::can_update_rsvp
	 * @covers ::update_rsvp
	 *
	 * @return void
	 */
	public function test_a_token_writes_its_own_occurrence(): void {
		$post_id = $this->create_and_project();

		wp_set_current_user( 0 );

		$issued = $this->issue_token( $post_id, 'ada@example.test', $this->occurrence_a );

		$response = $this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $post_id,
				'status'        => 'not_attending',
				'rsvp_token'    => $issued['token'],
				'recurrence_id' => $this->occurrence_a,
			)
		);

		$this->assertSame(
			200,
			$response->get_status(),
			'Failed to assert a token authorizes a write to the occurrence it was issued for.'
		);
		$this->assertTrue(
			$response->get_data()['success'],
			'Failed to assert the token holder\'s own occurrence accepted the write.'
		);
		$this->assertSame(
			array( $issued['comment_id'] ),
			$this->roster( $post_id, $this->occurrence_a ),
			'Failed to assert the write landed on the token holder\'s own occurrence exactly once.'
		);
	}

	/**
	 * B1: authority is symmetric, so neither side of an absent occurrence leaks.
	 *
	 * An occurrence token acting series-wide would write a response readable on
	 * every date; a series-wide token acting on one occurrence would scope a
	 * response the responder never scoped. Both are refused, and both refusals
	 * are the same 403 an unknown candidate produces, so the status cannot be
	 * read as an answer about which occurrences exist.
	 *
	 * @covers ::can_update_rsvp
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrence_Identity::matches
	 *
	 * @return void
	 */
	public function test_token_authority_is_symmetric_about_an_absent_occurrence(): void {
		$post_id = $this->create_and_project();

		wp_set_current_user( 0 );

		$scoped = $this->issue_token( $post_id, 'ada@example.test', $this->occurrence_a );
		$series = $this->issue_token( $post_id, 'grace@example.test', null );

		$this->assertSame(
			403,
			$this->dispatch(
				'POST',
				'rsvp',
				array(
					'post_id'    => $post_id,
					'status'     => 'attending',
					'rsvp_token' => $scoped['token'],
				)
			)->get_status(),
			'Failed to assert an occurrence-scoped token cannot act series-wide.'
		);
		$this->assertSame(
			403,
			$this->dispatch(
				'POST',
				'rsvp',
				array(
					'post_id'       => $post_id,
					'status'        => 'attending',
					'rsvp_token'    => $series['token'],
					'recurrence_id' => $this->occurrence_a,
				)
			)->get_status(),
			'Failed to assert a series-wide token cannot act on one occurrence.'
		);
		$this->assertSame(
			403,
			$this->dispatch(
				'POST',
				'rsvp',
				array(
					'post_id'       => $post_id,
					'status'        => 'attending',
					'rsvp_token'    => $scoped['token'],
					'recurrence_id' => self::UNKNOWN_OCCURRENCE,
				)
			)->get_status(),
			'Failed to assert a token naming an occurrence that does not exist is refused identically.'
		);
	}

	/**
	 * B1: a request naming the token under two parameters is refused.
	 *
	 * The token used to arrive under two names, with authorization reading one
	 * and identification reading the other, so a caller could satisfy the check
	 * with one value and be identified by another. `rsvp_token` is now the only
	 * recognized name, and a request carrying the page-URL name beside it is
	 * treated as having presented no token at all rather than having one of the
	 * two silently win. An anonymous caller then falls to the logged-in branch,
	 * which is a 401.
	 *
	 * @covers ::request_token_string
	 * @covers ::can_update_rsvp
	 *
	 * @return void
	 */
	public function test_a_request_naming_the_token_twice_is_refused(): void {
		$post_id = $this->create_and_project();

		wp_set_current_user( 0 );

		$issued          = $this->issue_token( $post_id, 'ada@example.test', $this->occurrence_a );
		$roster_a_before = $this->roster( $post_id, $this->occurrence_a );

		$response = $this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'                => $post_id,
				'status'                 => 'not_attending',
				'rsvp_token'             => $issued['token'],
				'gatherpress_rsvp_token' => $issued['token'],
				'recurrence_id'          => $this->occurrence_a,
			)
		);

		$this->assertSame(
			401,
			$response->get_status(),
			'Failed to assert a request naming the token under two parameters is refused.'
		);
		$this->assertSame(
			$roster_a_before,
			$this->roster( $post_id, $this->occurrence_a ),
			'Failed to assert the refused duplicate-parameter request wrote nothing.'
		);
	}

	/**
	 * B4: an unauthorized caller cannot tell a real occurrence from a fabricated one.
	 *
	 * The measured defect was an ordering one. Argument validation runs before
	 * the permission callback, and the validator resolved the identifier against
	 * storage, so a real occurrence of a private event reached permission
	 * handling and returned 401 while an unknown one was rejected as an invalid
	 * argument and returned 400. Reading the difference gave an unauthenticated
	 * visitor the private event's whole schedule, one candidate at a time.
	 *
	 * Status and body are compared, and so is the work done to produce them: the
	 * query log is asserted to name the occurrence table zero times in both
	 * cases, which is what makes the two responses indistinguishable by timing
	 * as well as by content. A test asserting only equal statuses would pass
	 * against a fix that still ran the lookup and discarded it.
	 *
	 * @covers ::validate_recurrence_id
	 * @covers ::can_read_event_rsvps
	 *
	 * @return void
	 */
	public function test_a_private_events_occurrences_are_not_enumerable(): void {
		global $wpdb;

		$post_id = $this->create_and_project();

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'private',
			)
		);

		wp_set_current_user( 0 );

		$occurrences_table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$observed          = array();

		foreach ( array( $this->occurrence_a, self::UNKNOWN_OCCURRENCE ) as $candidate ) {
			$before   = count( $wpdb->queries );
			$response = $this->dispatch(
				'GET',
				'rsvp-responses',
				array(
					'post_id'       => $post_id,
					'recurrence_id' => $candidate,
				)
			);
			$since    = array_slice( $wpdb->queries, $before );

			$observed[] = array(
				'status'           => $response->get_status(),
				'body'             => $response->get_data(),
				'occurrence_reads' => count(
					array_filter(
						$since,
						static function ( array $query ) use ( $occurrences_table ): bool {
							return str_contains( $query[0], $occurrences_table );
						}
					)
				),
			);
		}

		$this->assertSame(
			$observed[0]['status'],
			$observed[1]['status'],
			'Failed to assert a real and a fabricated occurrence of a private event return the same status.'
		);
		$this->assertSame(
			$observed[0]['body'],
			$observed[1]['body'],
			'Failed to assert a real and a fabricated occurrence of a private event return the same body.'
		);
		$this->assertSame(
			0,
			$observed[0]['occurrence_reads'],
			'Failed to assert an unauthorized probe for a real occurrence runs no occurrence lookup.'
		);
		$this->assertSame(
			0,
			$observed[1]['occurrence_reads'],
			'Failed to assert an unauthorized probe for a fabricated occurrence runs no occurrence lookup.'
		);
	}

	/**
	 * B4: the public form route discloses nothing either.
	 *
	 * `rsvp-form` is the one RSVP route whose `permission_callback` is
	 * `__return_true`, so it is where an unauthenticated caller could otherwise
	 * reach occurrence resolution. Viewability is now settled first, and the
	 * same `Event not found.` 404 comes back for a real occurrence and a
	 * fabricated one, having run the same work.
	 *
	 * @covers ::handle_rsvp_form_submission
	 *
	 * @return void
	 */
	public function test_the_public_form_route_discloses_no_occurrence_of_a_draft_event(): void {
		global $wpdb;

		$post_id = $this->create_and_project();

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( 0 );

		$occurrences_table = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$observed          = array();

		foreach ( array( $this->occurrence_a, self::UNKNOWN_OCCURRENCE ) as $candidate ) {
			$before   = count( $wpdb->queries );
			$response = $this->dispatch(
				'POST',
				'rsvp-form',
				array(
					'comment_post_ID' => $post_id,
					'author'          => 'Ada Lovelace',
					'email'           => 'ada@example.test',
					'recurrence_id'   => $candidate,
				)
			);
			$since    = array_slice( $wpdb->queries, $before );

			$observed[] = array(
				'status'           => $response->get_status(),
				'body'             => $response->get_data(),
				'occurrence_reads' => count(
					array_filter(
						$since,
						static function ( array $query ) use ( $occurrences_table ): bool {
							return str_contains( $query[0], $occurrences_table );
						}
					)
				),
			);
		}

		$this->assertSame(
			404,
			$observed[0]['status'],
			'Failed to assert the public form route refuses an unviewable event.'
		);
		$this->assertSame(
			$observed[0],
			$observed[1],
			'Failed to assert a real and a fabricated occurrence of a draft event are indistinguishable.'
		);
	}

	/**
	 * B3: an inner dispatch for another occurrence restores the outer one.
	 *
	 * The committed nested test covered only an inner request naming *no*
	 * occurrence, which registers no teardown of its own and therefore cannot
	 * exercise the defect. With a single request slot, an inner request naming
	 * occurrence B overwrote the outer request's identity, and the inner
	 * teardown then recognized itself, cleared the context and removed the
	 * global filter: the outer route finished series-wide and never tore down.
	 *
	 * Three moments are asserted, because the fix is a stack rather than a
	 * comparison and any one of them can regress alone: B during the inner
	 * callback, A after it returns, and nothing at all once the outer dispatch
	 * completes.
	 *
	 * @covers ::enter_occurrence_context
	 * @covers ::leave_occurrence_context
	 * @covers \GatherPress\Core\Event\Recurrence\Context::restore
	 *
	 * @return void
	 */
	public function test_a_nested_dispatch_for_another_occurrence_restores_the_outer_context(): void {
		$post_id     = $this->create_and_project();
		$inner_scope = null;
		$after_inner = null;

		$inner = new WP_REST_Request(
			'GET',
			sprintf( '/%s/event/rsvp-responses', GATHERPRESS_REST_NAMESPACE )
		);

		$inner->set_param( 'post_id', $post_id );
		$inner->set_param( 'recurrence_id', $this->occurrence_b );

		// Priority 1 so this observes the inner request while its own teardown,
		// registered at priority 10, has not yet run.
		$observe = static function ( $response, $handler, $request ) use ( &$inner_scope, $inner ) {
			if ( $request === $inner ) {
				$inner_scope = Context::get_instance()->current();
			}

			return $response;
		};

		add_filter( 'rest_request_after_callbacks', $observe, 1, 3 );

		$nested = false;
		$nest   = static function ( $clauses ) use ( &$nested, &$after_inner, $inner ) {
			if ( $nested ) {
				return $clauses;
			}

			$nested = true;

			rest_do_request( $inner );

			$after_inner = Context::get_instance()->current();

			return $clauses;
		};

		add_filter( 'comments_clauses', $nest );

		$this->dispatch(
			'GET',
			'rsvp-responses',
			array(
				'post_id'       => $post_id,
				'recurrence_id' => $this->occurrence_a,
			)
		);

		remove_filter( 'comments_clauses', $nest );
		remove_filter( 'rest_request_after_callbacks', $observe, 1 );

		$this->assertNotNull( $inner_scope, 'Failed to observe the inner dispatch holding a context at all.' );
		$this->assertSame(
			$this->occurrence_b,
			(string) $inner_scope['recurrence_id'],
			'Failed to assert the inner dispatch ran in the occurrence it named.'
		);
		$this->assertNotNull(
			$after_inner,
			'Failed to assert the outer request still held an occurrence after the inner dispatch returned.'
		);
		$this->assertSame(
			$this->occurrence_a,
			(string) $after_inner['recurrence_id'],
			'Failed to assert the inner dispatch restored the outer request\'s occurrence rather than clearing it.'
		);
		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert the context is cleared only once the outermost dispatch completes.'
		);
	}

	/**
	 * B3: an outer write still lands on its own occurrence after nesting.
	 *
	 * The context assertions above are necessary but not sufficient: restoring
	 * the row and then storing against the wrong one would satisfy them. This
	 * drives the write itself, and it nests at the one moment that makes the
	 * defect visible in stored data — inside `Rsvp\Storage::save()`, after the
	 * comment args are built and before the occurrence term is stamped. With a
	 * teardown that cleared rather than restored, the stamp then read no context
	 * and the response was written series-wide while the responder believed they
	 * had booked one date.
	 *
	 * @covers ::leave_occurrence_context
	 * @covers \GatherPress\Core\Rsvp\Storage::save
	 *
	 * @return void
	 */
	public function test_an_outer_write_still_lands_on_its_own_occurrence_after_a_nested_dispatch(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		$inner = new WP_REST_Request(
			'GET',
			sprintf( '/%s/event/rsvp-responses', GATHERPRESS_REST_NAMESPACE )
		);

		$inner->set_param( 'post_id', $post_id );
		$inner->set_param( 'recurrence_id', $this->occurrence_b );

		$nested = false;
		$nest   = static function ( $args ) use ( &$nested, $inner ) {
			if ( ! $nested ) {
				$nested = true;

				rest_do_request( $inner );
			}

			return $args;
		};

		add_filter( 'gatherpress_save_rsvp', $nest );

		$this->dispatch(
			'POST',
			'rsvp',
			array(
				'post_id'       => $post_id,
				'status'        => 'attending',
				'recurrence_id' => $this->occurrence_a,
			)
		);

		remove_filter( 'gatherpress_save_rsvp', $nest );

		$this->assertTrue( $nested, 'Failed to arrange a nested dispatch during the outer write.' );

		$stored = ( new Rsvp( $post_id ) )->find( $user_id );

		$this->assertNotNull( $stored, 'Failed to assert the outer write stored anything at all.' );
		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( $post_id, $this->occurrence_a ) ),
			$this->term_slugs( (int) $stored->comment->comment_ID, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert the outer write landed on its own occurrence after a nested dispatch.'
		);
		$this->assertSame(
			array( (int) $stored->comment->comment_ID ),
			$this->roster( $post_id, $this->occurrence_a ),
			'Failed to assert the outer write is on the roster of the date it named.'
		);
		$this->assertSame(
			array(),
			$this->roster( $post_id, $this->occurrence_b ),
			'Failed to assert the outer write is absent from the nested dispatch\'s date.'
		);
	}

	/**
	 * B2/B5: the RSVP cache is keyed on the occurrence owner, not the named post.
	 *
	 * Storage, authorization and routing all follow the post that owns the
	 * occurrence row. A cache that kept following the post a caller named would
	 * hand the canonical page a roster warmed under a different identity, which
	 * is indistinguishable from an empty roster and expires no sooner than
	 * `Cache::CACHE_EXPIRATION`.
	 *
	 * @covers \GatherPress\Core\Rsvp\Cache::get
	 * @covers \GatherPress\Core\Rsvp\Cache::set
	 * @covers \GatherPress\Core\Rsvp\Cache::delete
	 *
	 * @return void
	 */
	public function test_the_rsvp_cache_follows_the_occurrence_owner(): void {
		$owner_post_id   = $this->create_and_project();
		$occurrence_id   = $this->occurrence_a;
		$sibling_post_id = $this->create_plain_event();

		Recurrence_Query::refresh_has_recurring_events();
		Context::flush_resolved();

		$this->widen_series( $sibling_post_id, $owner_post_id );

		// Warm the authoritative key explicitly, naming the owner.
		Cache::set( $owner_post_id, array( 'all' => array( 'count' => 7 ) ), $occurrence_id );

		// A reader that names the sibling, in the occurrence's context, must
		// compose the same key rather than a second one nobody invalidates.
		Context::get_instance()->set_for_series( $sibling_post_id, $occurrence_id );

		$this->assertSame(
			array( 'all' => array( 'count' => 7 ) ),
			Cache::get( $sibling_post_id ),
			'Failed to assert a read naming the sibling post composes the owner\'s cache key.'
		);

		Cache::delete( $sibling_post_id );

		Context::get_instance()->clear();

		$this->assertNull(
			Cache::get( $owner_post_id, $occurrence_id ),
			'Failed to assert an invalidation naming the sibling post dropped the owner\'s cache entry.'
		);
	}

	/**
	 * Direct invokes of the private helpers the routes delegate to.
	 *
	 * Xdebug does not reliably trace a private method called from a short
	 * delegation in the same class, so each branch is driven through
	 * `invoke_hidden_method()` as well as end to end. Both matter: the
	 * end-to-end tests prove the behavior, these prove every arm is reached.
	 *
	 * @covers ::requested_occurrence
	 * @covers ::request_token_string
	 * @covers ::can_write_occurrence_owner
	 *
	 * @return void
	 */
	public function test_the_route_helpers_cover_every_arm(): void {
		$post_id  = $this->create_and_project();
		$instance = Rest_Api::get_instance();

		$bare = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp' );

		$bare->set_param( 'post_id', $post_id );

		$named = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp' );

		$named->set_param( 'post_id', $post_id );
		$named->set_param( 'recurrence_id', $this->occurrence_a );

		$this->assertNull(
			Utility::invoke_hidden_method( $instance, 'requested_occurrence', array( $bare ) ),
			'Failed to assert a request naming no occurrence resolves to no identity.'
		);

		$identity = Utility::invoke_hidden_method( $instance, 'requested_occurrence', array( $named ) );

		$this->assertNotNull( $identity, 'Failed to assert a request naming an occurrence resolves one.' );
		$this->assertSame(
			$post_id,
			$identity->owner_post_id,
			'Failed to assert the resolved identity names the owning post.'
		);

		$this->assertNull(
			Utility::invoke_hidden_method( $instance, 'request_token_string', array( $bare ) ),
			'Failed to assert a request carrying no token reads as none.'
		);

		$canonical = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp' );

		$canonical->set_param( 'rsvp_token', '1_abc' );

		$this->assertSame(
			'1_abc',
			Utility::invoke_hidden_method( $instance, 'request_token_string', array( $canonical ) ),
			'Failed to assert the canonical token parameter is read.'
		);

		$doubled = new WP_REST_Request( 'POST', '/gatherpress/v1/event/rsvp' );

		$doubled->set_param( 'rsvp_token', '1_abc' );
		$doubled->set_param( Token::NAME, '1_abc' );

		$this->assertNull(
			Utility::invoke_hidden_method( $instance, 'request_token_string', array( $doubled ) ),
			'Failed to assert a request naming the token twice reads as none.'
		);

		$this->assertTrue(
			Utility::invoke_hidden_method(
				$instance,
				'can_write_occurrence_owner',
				array( null, $post_id )
			),
			'Failed to assert a series-wide request needs no owner check.'
		);
		$this->assertTrue(
			Utility::invoke_hidden_method(
				$instance,
				'can_write_occurrence_owner',
				array( $identity, $post_id )
			),
			'Failed to assert a request naming the owning post needs no second check.'
		);

		// The owner is now unreadable and the request names a different post of
		// the same series, which is the shape a forward split produces.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'private',
			)
		);

		$named_post_id = $this->create_plain_event();

		$this->widen_series( $named_post_id, $post_id );

		wp_set_current_user( 0 );

		$this->assertFalse(
			Utility::invoke_hidden_method(
				$instance,
				'can_write_occurrence_owner',
				array( $identity, $named_post_id )
			),
			'Failed to assert a caller with no read access to the owning post is refused.'
		);
	}

	/**
	 * Direct invokes of the RSVP cache's private key composition.
	 *
	 * Same xdebug gap as above: both helpers are reached only from short
	 * delegations inside `Cache` itself.
	 *
	 * @covers \GatherPress\Core\Rsvp\Cache::resolve_occurrence
	 * @covers \GatherPress\Core\Rsvp\Cache::resolved_key
	 *
	 * @return void
	 */
	public function test_the_cache_key_helpers_cover_every_arm(): void {
		$post_id = $this->create_and_project();

		$this->assertSame(
			array(
				'post_id'       => $post_id,
				'recurrence_id' => $this->occurrence_a,
			),
			$this->invoke_cache_helper( 'resolve_occurrence', array( $post_id, $this->occurrence_a ) ),
			'Failed to assert an explicit occurrence identifier is used as given.'
		);
		$this->assertNull(
			$this->invoke_cache_helper( 'resolve_occurrence', array( $post_id, null ) ),
			'Failed to assert no occurrence resolves outside occurrence context.'
		);
		$this->assertSame(
			sprintf( Cache::CACHE_KEY, $post_id ),
			$this->invoke_cache_helper( 'resolved_key', array( $post_id, null ) ),
			'Failed to assert the series-wide key is composed outside occurrence context.'
		);

		Context::get_instance()->set_for_series( $post_id, $this->occurrence_a );

		$this->assertSame(
			array(
				'post_id'       => $post_id,
				'recurrence_id' => $this->occurrence_a,
			),
			$this->invoke_cache_helper( 'resolve_occurrence', array( $post_id, null ) ),
			'Failed to assert the ambient occurrence is resolved when none is named.'
		);
		$this->assertSame(
			sprintf( Cache::CACHE_KEY_OCCURRENCE, $post_id, $this->occurrence_a ),
			$this->invoke_cache_helper( 'resolved_key', array( $post_id, null ) ),
			'Failed to assert the occurrence-scoped key is composed inside occurrence context.'
		);

		Context::get_instance()->clear();
	}

	/**
	 * Call one of `Rsvp\Cache`'s private static helpers.
	 *
	 * The PMC helper takes an instance, and `Cache` is static-only, so this is
	 * the reflection equivalent.
	 *
	 * @since 0.36.0
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 *
	 * @return mixed The method's return value.
	 */
	protected function invoke_cache_helper( string $method, array $args ) {
		$reflection = new ReflectionMethod( Cache::class, $method );

		$reflection->setAccessible( true );

		return $reflection->invokeArgs( null, $args );
	}
}
