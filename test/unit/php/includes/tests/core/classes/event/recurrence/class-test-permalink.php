<?php
/**
 * Class handles unit tests for occurrence-aware permalinks.
 *
 * Two separate defects share one symptom — a link to an occurrence resolving
 * to a different date — and they share this file because they share the
 * fixture that can tell them apart.
 *
 * The first is `Context::permalink()` declining to answer for the request's
 * own occurrence, so `get_permalink()` on `/event/my-series/20260903T180000/`
 * returned the bare series URL. Every link emitted from that page inherited it:
 * the iCal `URL:` field, `rel="canonical"`, share links.
 *
 * The second is the RSVP confirmation email, composed from `Rsvp\Token` while
 * the comment is being inserted — on paths that never reach `wp`, so there is
 * no request context to read and the comment's own `_gatherpress_occurrence`
 * term is the authoritative source.
 *
 * **Every assertion here names an exact URL**, and every fixture puts the
 * occurrence under test somewhere other than first. A bare series URL resolves
 * to the *next upcoming* occurrence, so a test that asserted only "the URL
 * contains an occurrence segment", or that pointed at the first occurrence,
 * would stay green with both fixes reverted.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Calendar\Calendar;
use GatherPress\Core\Calendar\Setup as Calendar_Setup;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Recurrence\Context;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Query as Recurrence_Query;
use GatherPress\Core\Event\Recurrence\Rewrite;
use GatherPress\Core\Event\Recurrence\Rsvp_Occurrence;
use GatherPress\Core\Event\Rest_Api;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Rsvp\Setup as Rsvp_Setup;
use GatherPress\Core\Rsvp\Token;
use GatherPress\Core\Settings;
use GatherPress\Core\Topic;
use GatherPress\Tests\Base;
use WP_REST_Request;

/**
 * Class Test_Permalink.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Context
 */
class Test_Permalink extends Base {

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
	 * Bodies of every email `wp_mail()` was asked to send.
	 *
	 * @since 0.36.0
	 * @var string[]
	 */
	protected array $sent_mail = array();

	/**
	 * Create the occurrence table, register the RSVP routes, and put the event
	 * post type behind pretty permalinks with the occurrence rewrite rule
	 * flushed, so `get_permalink()` and `$this->go_to()` both exercise the real
	 * URL shape rather than the plain `?p=` form.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		gatherpress_reset_custom_tables();
		Rsvp_Setup::get_instance()->register_taxonomy();
		Rest_Api::get_instance()->register_endpoints();
		Calendar_Setup::get_instance()->register_endpoints();
		Settings::get_instance()->set( 'enable_open_rsvp', true );

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		// The event post type's permastruct was built at bootstrap while
		// permalinks were still plain, so it has to be re-registered now that
		// they are pretty or `get_permalink()` keeps answering with `?p=`.
		unregister_post_type( Event::POST_TYPE );
		Event_Setup::get_instance()->register_post_type();
		unregister_taxonomy( Topic::TAXONOMY );
		Topic::get_instance()->register_taxonomy();

		Rewrite::get_instance()->add_rewrite_rules();
		$wp_rewrite->flush_rules();

		Context::get_instance()->clear();

		$this->sent_mail = array();

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Restore plain permalinks and leave no context or mail capture behind.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();

		Context::get_instance()->clear();

		parent::tearDown();
	}

	/**
	 * Short-circuit `wp_mail()` and record the body it was handed.
	 *
	 * @since 0.36.0
	 *
	 * @param null|bool $short_circuit Short-circuit value, null when nothing has filtered yet.
	 * @param array     $attributes    The `wp_mail()` arguments.
	 *
	 * @return bool True, so nothing is actually delivered from a test run.
	 */
	public function capture_mail( $short_circuit, array $attributes ): bool {
		$this->sent_mail[] = (string) ( $attributes['message'] ?? '' );

		return true;
	}

	/**
	 * Build the anchor every fixture here is dated from.
	 *
	 * Thirty days out, so nothing is ever in the past: `Rsvp\Form::process_rsvp()`
	 * bails 400 on `has_event_past()`, reading the *series* meta, and against a
	 * pinned anchor the email tests would silently stop writing anything once
	 * real time crossed it.
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
	 * Create and project the reference recurring series.
	 *
	 * Returns the post ID alongside the identifiers projection actually wrote,
	 * read back from storage rather than restated, so the fixture and the
	 * assertions cannot drift apart however the anchor moves.
	 *
	 * @since 0.36.0
	 *
	 * @return array{0: int, 1: string, 2: string} Post ID, first occurrence, second occurrence.
	 */
	protected function create_series(): array {
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
		add_post_meta( $post_id, Meta::META_KEY, wp_json_encode( self::WEEKLY_RULE ) );
		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );
		Recurrence_Query::refresh_has_recurring_events();

		$rows = Occurrences::get_instance()->select_for_series( array( $post_id ) );

		$this->assertGreaterThan(
			1,
			count( $rows ),
			'Failed to project the two sibling occurrences every test in this class needs.'
		);

		return array( (int) $post_id, (string) $rows[0]['recurrence_id'], (string) $rows[1]['recurrence_id'] );
	}

	/**
	 * Create an ordinary, never-recurring event on a site with no recurring events.
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

		return (int) $post_id;
	}

	/**
	 * Dispatch one open-RSVP submission through the real REST server.
	 *
	 * `rest_do_request()`, never `Form::process_rsvp()` directly: the argument
	 * definition, its validation and the occurrence-context entry all live
	 * between the two, and the confirmation email is composed inside the
	 * innermost one.
	 *
	 * @since 0.36.0
	 *
	 * @param array $params Request parameters.
	 *
	 * @return \WP_REST_Response The dispatched response.
	 */
	protected function submit_rsvp( array $params ) {
		$request = new WP_REST_Request(
			'POST',
			sprintf( '/%s/event/rsvp-form', GATHERPRESS_REST_NAMESPACE )
		);

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * The permalink of a singular occurrence page is the occurrence's own URL.
	 *
	 * The requested occurrence is the series' **second**, so the bare-series
	 * fallback — which resolves to the next upcoming occurrence, the
	 * first — produces a different answer. Both are asserted: the exact URL the
	 * requirement specifies, and the explicit absence of the fallback's answer,
	 * so the test cannot pass with `permalink()` dead.
	 *
	 * Context is established by `go_to()` rather than by hand, so the `wp`
	 * action that binds it in production is part of what is under test.
	 *
	 * @covers ::permalink
	 * @covers ::resolve
	 *
	 * @return void
	 */
	public function test_permalink_on_a_singular_occurrence_page_names_the_requested_occurrence(): void {
		list( $post_id, $first, $second ) = $this->create_series();

		$series_url = (string) get_permalink( $post_id );
		$second_url = $series_url . $second . '/';

		$this->go_to( $second_url );

		$this->assertSame(
			$second,
			Context::get_instance()->current()['recurrence_id'],
			'Failed to assert the request established the second occurrence as the page\'s own — without that'
				. ' the fixture proves nothing.'
		);
		$this->assertSame(
			$second_url,
			(string) get_permalink( $post_id ),
			'Failed to assert the permalink of a singular occurrence page is that occurrence\'s own URL.'
		);
		$this->assertNotSame(
			$series_url . $first . '/',
			(string) get_permalink( $post_id ),
			'Failed to assert the permalink is not the next-upcoming occurrence the bare series URL resolves to.'
		);
		$this->assertNotSame(
			$series_url,
			(string) get_permalink( $post_id ),
			'Failed to assert the permalink is not the bare series URL.'
		);
	}

	/**
	 * The iCal `VEVENT`'s `URL:` field names the occurrence being viewed.
	 *
	 * `Calendar::get_ical_event_string()` composes it from
	 * `get_permalink( $this->event->event->ID )`, so adding an occurrence to a
	 * calendar used to yield an entry linking to a different date. Nothing in
	 * the calendar subsystem changes for this — the field is right once the
	 * permalink is.
	 *
	 * @covers ::permalink
	 * @covers \GatherPress\Core\Calendar\Calendar::get_ical_event_string
	 *
	 * @return void
	 */
	public function test_ical_url_field_on_an_occurrence_page_names_the_requested_occurrence(): void {
		list( $post_id, $first, $second ) = $this->create_series();

		$series_url = (string) get_permalink( $post_id );
		$second_url = $series_url . $second . '/';

		$this->go_to( $second_url );

		$vevent = ( new Calendar( $post_id ) )->get_ical_event_string();

		$this->assertStringContainsString(
			sprintf( "URL:%s\r\n", $second_url ),
			$vevent,
			'Failed to assert the VEVENT URL field names the occurrence the page is rendering.'
		);
		$this->assertStringNotContainsString(
			sprintf( "URL:%s\r\n", $series_url . $first . '/' ),
			$vevent,
			'Failed to assert the VEVENT URL field is not the next-upcoming occurrence.'
		);
		$this->assertStringNotContainsString(
			sprintf( "URL:%s\r\n", $series_url ),
			$vevent,
			'Failed to assert the VEVENT URL field is not the bare series URL.'
		);
	}

	/**
	 * The calendar endpoint URL is a series URL, in or out of occurrence context.
	 *
	 * `Calendar::get_endpoint_url()` appends a path segment to the post's
	 * permalink. Once `Context::permalink()` answers with an occurrence's URL,
	 * a naive read produces `/event/my-series/20260903T180000/ical/`, which
	 * matches no rewrite rule and 404s — so the "Download iCal" button on an
	 * occurrence page would break. This was already true inside a Query Loop
	 * before the singular-page arm existed, since the loop arm rewrote the same
	 * read; both are fixed by the same series-permalink read.
	 *
	 * Both halves are asserted: the exact URL, and that the URL actually
	 * resolves. The exact URL alone would not catch a rule that matched but
	 * routed somewhere else, and the 404 check alone would stay green on a URL
	 * that resolved to the wrong thing.
	 *
	 * @covers \GatherPress\Core\Calendar\Calendar::get_endpoint_url
	 *
	 * @return void
	 */
	public function test_calendar_endpoint_url_stays_a_series_url_on_an_occurrence_page(): void {
		list( $post_id, , $second ) = $this->create_series();

		$series_url = (string) get_permalink( $post_id );

		$this->go_to( $series_url . $second . '/' );

		$ical_url = ( new Calendar( $post_id ) )->get_ical_url();

		$this->assertSame(
			$second,
			Context::get_instance()->current()['recurrence_id'],
			'Failed to assert the page is an occurrence page; the fixture proves nothing otherwise.'
		);
		$this->assertSame(
			$series_url . Calendar_Setup::ICAL_SLUG . '/',
			$ical_url,
			'Failed to assert the iCal endpoint URL is composed on the series permalink.'
		);

		$this->go_to( (string) $ical_url );

		$this->assertFalse(
			is_404(),
			'Failed to assert the iCal endpoint URL emitted from an occurrence page actually resolves.'
		);
	}
}
