<?php
/**
 * Class handles unit tests for the occurrence read path inside a Query Loop.
 *
 * Asserting that `Recurrence\Query` returns the right rows says nothing about
 * whether a block renders the right date from those rows. That gap is exactly
 * the shape of the regression this file covers: the query layer expands a
 * series correctly, `attach_occurrences()` stamps identity onto every result
 * object, and nothing in the render path reads the stamp, so every row of a
 * series shows the anchor's date and the bare series permalink.
 *
 * Every test here drives a real `WP_Query` loop through `the_post()` and
 * asserts the *rendered output* of a block, never an intermediate value.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use GatherPress\Core\Blocks\Event_Date as Event_Date_Block;
use GatherPress\Core\Event;
use GatherPress\Core\Event\Query as Event_Query;
use GatherPress\Core\Event\Recurrence\Context;
use GatherPress\Core\Event\Recurrence\Meta;
use GatherPress\Core\Event\Recurrence\Occurrences;
use GatherPress\Core\Event\Recurrence\Query;
use GatherPress\Core\Event\Recurrence\Rewrite;
use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use WP_Query;

/**
 * Class Test_Loop_Render.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Context
 */
class Test_Loop_Render extends Base {

	/**
	 * The reference daily rule: five consecutive daily occurrences.
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
	 * Start every test from an empty occurrence table, independent of
	 * execution order relative to Test_Schema.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		gatherpress_reset_custom_tables();

		update_option( Query::HAS_RECURRING_OPTION, '0', true );

		Context::get_instance()->clear();
	}

	/**
	 * Leave no occurrence context or postdata behind for the next test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		Context::get_instance()->clear();

		wp_reset_postdata();

		parent::tearDown();
	}

	/**
	 * Build "now" in UTC.
	 *
	 * Every fixture in this file is relative to this value rather than to a
	 * literal calendar date, so no test in the file is a date bomb, and the
	 * series timezone is UTC so a recurrence identifier (a *local* start) and
	 * the GMT columns read identically.
	 *
	 * @since 0.36.0
	 *
	 * @return DateTimeImmutable Current time in UTC.
	 */
	protected function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Create a published, non-recurring event with a datetime range.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $start Event start in UTC.
	 * @param DateTimeImmutable $end   Event end in UTC.
	 *
	 * @return int The created post ID.
	 */
	protected function create_event_at( DateTimeImmutable $start, DateTimeImmutable $end ): int {
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
					'timezone'      => 'UTC',
				)
			)
		);

		Event_Setup::get_instance()->set_datetimes( $post_id );

		return (int) $post_id;
	}

	/**
	 * Create a recurring series and project its occurrence rows.
	 *
	 * Fixtures are arranged in production order: the datetime blob and its
	 * derived row land first, then the recurrence blob, then the mirrors, then
	 * the projection. That is exactly the sequence a real save produces.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $start Anchor start in UTC.
	 * @param DateTimeImmutable $end   Anchor end in UTC.
	 * @param array             $rule  Recurrence rule values.
	 *
	 * @return int The created post ID.
	 */
	protected function create_series_at(
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		array $rule = self::DAILY_RULE
	): int {
		$post_id = $this->create_event_at( $start, $end );

		add_post_meta( $post_id, Meta::META_KEY, wp_json_encode( $rule ) );
		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );

		return $post_id;
	}

	/**
	 * Run an occurrence-aware upcoming-events query.
	 *
	 * Drives a real `WP_Query` through the production `pre_get_posts`,
	 * `posts_clauses` and `the_posts` wiring rather than calling any filter
	 * callback directly.
	 *
	 * @since 0.36.0
	 *
	 * @param array $args Additional query arguments.
	 *
	 * @return WP_Query The executed query.
	 */
	protected function run_upcoming_query( array $args = array() ): WP_Query {
		return new WP_Query(
			array_merge(
				array(
					'post_type'                    => Event::POST_TYPE,
					Event_Query::EVENT_QUERY_PARAM => 'upcoming',
					'posts_per_page'               => 20,
					'orderby'                      => 'datetime',
					'order'                        => 'ASC',
				),
				$args
			)
		);
	}

	/**
	 * Render the event-date block the way `core/post-template` renders it.
	 *
	 * No `postId` attribute is supplied, because a Query Loop does not supply
	 * one either: `Blocks\Setup::get_post_id()` falls through to
	 * `get_the_ID()`, which is whatever `the_post()` most recently set up.
	 * Passing an ID here would route around the very wiring under test.
	 *
	 * @since 0.36.0
	 *
	 * @param array $attrs Block attributes.
	 *
	 * @return string The rendered block markup.
	 */
	protected function render_event_date( array $attrs = array() ): string {
		return render_block(
			array(
				'blockName'    => Event_Date_Block::BLOCK_NAME,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Walk a query's loop and render the event-date block once per iteration.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_Query $query Executed query.
	 * @param array    $attrs Block attributes.
	 *
	 * @return array<int, array{id:int, html:string}> One entry per loop iteration.
	 */
	protected function render_loop( WP_Query $query, array $attrs = array() ): array {
		$rendered = array();

		while ( $query->have_posts() ) {
			$query->the_post();

			$rendered[] = array(
				'id'   => (int) get_the_ID(),
				'html' => $this->render_event_date( $attrs ),
			);
		}

		wp_reset_postdata();

		return $rendered;
	}

	/**
	 * Build the recurrence identifier of the nth occurrence of a daily series.
	 *
	 * @since 0.36.0
	 *
	 * @param DateTimeImmutable $anchor Anchor start in UTC.
	 * @param int               $index  Zero-based occurrence index.
	 *
	 * @return string The recurrence identifier in `Ymd\THis` form.
	 */
	protected function occurrence_id( DateTimeImmutable $anchor, int $index ): string {
		return $anchor->modify( sprintf( '+%d days', $index ) )->format( 'Ymd\THis' );
	}

	/**
	 * Coverage for the render path's first half: the event date block renders
	 * the occurrence's datetime, not the series anchor's.
	 *
	 * The series anchor started an hour ago, so the loop contains its four
	 * upcoming occurrences. Every rendered row must carry its own day. A render
	 * path that reads the anchor instead collapses all four to one distinct
	 * string.
	 *
	 * @covers ::metadata
	 * @covers ::occurrence_value
	 *
	 * @return void
	 */
	public function test_query_loop_renders_a_distinct_date_per_occurrence(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );

		$rendered = $this->render_loop( $this->run_upcoming_query() );

		$this->assertCount(
			4,
			$rendered,
			'Failed to assert the loop expanded the series to its four upcoming occurrences.'
		);

		$html = wp_list_pluck( $rendered, 'html' );

		$this->assertCount(
			4,
			array_unique( $html ),
			'Failed to assert each occurrence row rendered a distinct date. Every row showing the same'
				. ' string means the query expanded and the render path read the anchor.'
		);

		foreach ( array( 1, 2, 3, 4 ) as $offset => $index ) {
			$expected = $anchor->modify( sprintf( '+%d days', $index ) )->format( 'g:i a' );

			$this->assertStringContainsString(
				$anchor->modify( sprintf( '+%d days', $index ) )->format( 'F j, Y' ),
				$html[ $offset ],
				'Failed to assert row ' . $offset . ' rendered its own occurrence date.'
			);
			$this->assertStringContainsString(
				$expected,
				$html[ $offset ],
				'Failed to assert row ' . $offset . ' rendered the occurrence record\'s own time of day.'
			);
		}
	}

	/**
	 * Coverage for the render path's second half: the permalink of a loop row
	 * is the occurrence URL, not the bare series URL.
	 *
	 * Asserted through the block's own `isLink` markup rather than by calling
	 * `get_permalink()` in the test, so the assertion covers what a visitor
	 * would actually click.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_query_loop_renders_a_distinct_permalink_per_occurrence(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$series_id = $this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );

		$rendered = $this->render_loop( $this->run_upcoming_query(), array( 'isLink' => true ) );
		$hrefs    = array();

		foreach ( $rendered as $row ) {
			preg_match( '/href="([^"]+)"/', $row['html'], $matches );

			// Decoded because the markup entity-escapes the URL: a
			// plain-permalink occurrence URL carries two query variables, and
			// its ampersand renders as an entity. The decoded value is the
			// URL a browser actually navigates to.
			$hrefs[] = html_entity_decode( $matches[1] ?? '', ENT_QUOTES );
		}

		$this->assertCount(
			4,
			array_unique( $hrefs ),
			'Failed to assert each occurrence row linked to its own occurrence URL. Four identical bare'
				. ' series permalinks is what a visitor sees when the render path reads the series.'
		);

		$expected = array();

		foreach ( array( 1, 2, 3, 4 ) as $index ) {
			$expected[] = Rewrite::get_occurrence_url( $series_id, $this->occurrence_id( $anchor, $index ) );
		}

		$this->assertSame(
			$expected,
			$hrefs,
			'Failed to assert every row linked to the occurrence URL shape for its own occurrence.'
		);
	}

	/**
	 * Coverage for the non-recurring arm: an ordinary event in the same loop
	 * renders its own date and its own bare permalink.
	 *
	 * This is the branch where the stamp is `null`, and it is the one that
	 * breaks if a fix reaches for list position instead of the object.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_a_plain_event_row_renders_its_own_date_and_bare_permalink(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );

		$plain_id = $this->create_event_at( $now->modify( '+400 hours' ), $now->modify( '+401 hours' ) );

		$rendered = $this->render_loop( $this->run_upcoming_query(), array( 'isLink' => true ) );
		$last     = end( $rendered );

		$this->assertSame(
			$plain_id,
			$last['id'],
			'Failed to assert the plain event sorted last, after every occurrence.'
		);
		$this->assertStringContainsString(
			sprintf( 'href="%s"', get_permalink( $plain_id ) ),
			$last['html'],
			'Failed to assert a non-recurring row keeps its bare permalink.'
		);
		$this->assertStringContainsString(
			$now->modify( '+400 hours' )->format( 'F j, Y' ),
			$last['html'],
			'Failed to assert a non-recurring row renders its own anchor date.'
		);
	}

	/**
	 * Coverage for re-entrancy: an inner loop over a different post does not
	 * inherit the outer iteration's occurrence identity, and the outer loop
	 * does not inherit the inner post's.
	 *
	 * The inner query is a plain event query over an unrelated event, run and
	 * reset from inside every iteration of the outer occurrence loop, exactly
	 * as `render_block_core_post_template()` runs a nested Query Loop. Its row
	 * must render its own date and its own bare permalink on every pass, and
	 * every outer row must still render its own occurrence afterwards.
	 *
	 * Where the outer row's own blocks are rendered matters and is not this
	 * class's choice to make: `wp_reset_postdata()` restores `$GLOBALS['post']`
	 * from the *main* query, not from the enclosing secondary one, so blocks
	 * placed after a nested loop already read the main query's post in stock
	 * WordPress. This test therefore renders each outer row before its nested
	 * loop, which is the arrangement core itself produces.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_inner_loop_over_a_different_post_does_not_inherit_the_occurrence(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$series_id = $this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );
		$other_id  = $this->create_event_at( $now->modify( '+400 hours' ), $now->modify( '+401 hours' ) );

		$outer      = $this->run_upcoming_query( array( 'post__in' => array( $series_id ) ) );
		$outer_html = array();
		$inner_html = array();
		$inner_args = array(
			'post_type' => Event::POST_TYPE,
			'post__in'  => array( $other_id ),
		);
		$inner_date = $now->modify( '+400 hours' )->format( 'F j, Y' );
		$inner_link = sprintf( 'href="%s"', get_permalink( $other_id ) );

		while ( $outer->have_posts() ) {
			$outer->the_post();

			$outer_html[] = $this->render_event_date( array( 'isLink' => true ) );

			$inner = new WP_Query( $inner_args );

			while ( $inner->have_posts() ) {
				$inner->the_post();

				$inner_html[] = $this->render_event_date( array( 'isLink' => true ) );
			}

			wp_reset_postdata();
		}

		wp_reset_postdata();

		$this->assertCount(
			4,
			array_unique( $outer_html ),
			'Failed to assert every outer occurrence row still rendered its own date and permalink while a'
				. ' nested loop ran inside each iteration.'
		);

		foreach ( array( 1, 2, 3, 4 ) as $offset => $index ) {
			$this->assertStringContainsString(
				$anchor->modify( sprintf( '+%d days', $index ) )->format( 'F j, Y' ),
				$outer_html[ $offset ],
				'Failed to assert outer row ' . $offset . ' kept its own occurrence date.'
			);
		}

		$this->assertCount(
			4,
			$inner_html,
			'Failed to assert the nested loop ran once per outer iteration.'
		);
		$this->assertSame(
			array( $inner_html[0] ),
			array_values( array_unique( $inner_html ) ),
			'Failed to assert the nested loop rendered the same unrelated event every time rather than'
				. ' drifting with the outer occurrence.'
		);
		$this->assertStringContainsString(
			$inner_date,
			$inner_html[0],
			'Failed to assert the inner loop\'s post rendered its own date rather than inheriting the'
				. ' outer occurrence\'s.'
		);
		$this->assertStringContainsString(
			$inner_link,
			$inner_html[0],
			'Failed to assert the inner loop\'s post kept its own bare permalink.'
		);
	}

	/**
	 * Coverage for the no-recurring-events guarantee on every entry point this
	 * read path adds.
	 *
	 * Drives the real entry points, meaning a full `WP_Query` loop plus a block
	 * render plus a `get_permalink()` call, on a site whose
	 * `gatherpress_has_recurring_events` option is `'0'`, and asserts no query
	 * touches the occurrence table and no option is written. Naming the test
	 * after the loop rather than after the callbacks is deliberate: a "performs
	 * no writes" test that drives the body of the work and never the entry
	 * point passes while the entry point still queries the table.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_loop_render_touches_no_occurrence_table_without_recurring_events(): void {
		global $wpdb;

		$now      = $this->now();
		$plain_id = $this->create_event_at( $now->modify( '+2 hours' ), $now->modify( '+3 hours' ) );

		$this->assertFalse(
			Query::site_has_recurring_events(),
			'Failed to assert the fixture site has no recurring events.'
		);

		$table   = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$options = get_option( 'gatherpress_version', '' );

		$wpdb->queries = array();
		$saved         = $wpdb->save_queries;

		$wpdb->save_queries = true;

		$rendered = $this->render_loop( $this->run_upcoming_query(), array( 'isLink' => true ) );

		get_permalink( $plain_id );

		$captured = $wpdb->queries;

		$wpdb->save_queries = $saved;
		$wpdb->queries      = array();

		$occurrence_queries = array_values(
			array_filter(
				array_column( $captured, 0 ),
				static function ( string $sql ) use ( $table ): bool {
					return str_contains( $sql, $table );
				}
			)
		);
		$option_writes      = array_values(
			array_filter(
				array_column( $captured, 0 ),
				static function ( string $sql ) use ( $wpdb ): bool {
					return str_contains( $sql, $wpdb->options )
						&& ( str_contains( $sql, 'INSERT' ) || str_contains( $sql, 'UPDATE' ) );
				}
			)
		);

		$this->assertSame(
			array(),
			$occurrence_queries,
			'Failed to assert a full loop render on a site with no recurring events never reads the'
				. ' occurrence table.'
		);
		$this->assertSame(
			array(),
			$option_writes,
			'Failed to assert a full loop render on a site with no recurring events writes no option.'
		);
		$this->assertSame(
			$options,
			get_option( 'gatherpress_version', '' ),
			'Failed to assert no option value changed.'
		);
		$this->assertCount(
			1,
			$rendered,
			'Failed to assert the ordinary event still rendered.'
		);
		$this->assertStringContainsString(
			sprintf( 'href="%s"', get_permalink( $plain_id ) ),
			$rendered[0]['html'],
			'Failed to assert the ordinary event kept its bare permalink.'
		);
	}

	/**
	 * Coverage for `loop_occurrence()`'s no-current-post return path.
	 *
	 * Invoked directly, because xdebug does not trace a same-class helper's
	 * body reliably when it is only ever reached from the filter callbacks
	 * above.
	 *
	 * @covers ::loop_occurrence
	 *
	 * @return void
	 */
	public function test_loop_occurrence_returns_null_with_no_current_post(): void {
		$now      = $this->now();
		$plain_id = $this->create_event_at( $now->modify( '+2 hours' ), $now->modify( '+3 hours' ) );

		$this->assertNull(
			get_post(),
			'Failed to assert the fixture leaves no current post set up.'
		);
		$this->assertNull(
			Utility::invoke_hidden_method( Context::get_instance(), 'loop_occurrence', array( $plain_id ) ),
			'Failed to assert loop_occurrence returns null when no post is set up.'
		);
	}

	/**
	 * Coverage for `loop_occurrence()`'s wrong-post return path.
	 *
	 * The isolation rule: a stamped occurrence is served only for the post it
	 * was stamped onto, never for whatever else a consumer happens to ask
	 * about mid-iteration.
	 *
	 * @covers ::loop_occurrence
	 *
	 * @return void
	 */
	public function test_loop_occurrence_returns_null_for_another_post(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$series_id = $this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );
		$other_id  = $this->create_event_at( $now->modify( '+400 hours' ), $now->modify( '+401 hours' ) );
		$query     = $this->run_upcoming_query( array( 'post__in' => array( $series_id ) ) );

		$query->the_post();

		$mine   = Utility::invoke_hidden_method( Context::get_instance(), 'loop_occurrence', array( $series_id ) );
		$theirs = Utility::invoke_hidden_method( Context::get_instance(), 'loop_occurrence', array( $other_id ) );

		wp_reset_postdata();

		$this->assertIsArray(
			$mine,
			'Failed to assert loop_occurrence resolves the post it was stamped onto.'
		);
		$this->assertSame(
			$this->occurrence_id( $anchor, 1 ),
			$mine['recurrence_id'],
			'Failed to assert the resolved row carries the iteration\'s own recurrence ID.'
		);
		$this->assertSame(
			Occurrences::STATUS_SCHEDULED,
			$mine['status'],
			'Failed to assert only scheduled occurrences reach a loop.'
		);
		$this->assertNull(
			$theirs,
			'Failed to assert loop_occurrence refuses to answer for a different post.'
		);
	}

	/**
	 * Coverage for `loop_occurrence()`'s non-recurring return path.
	 *
	 * A plain event's row is stamped with a null datetime payload, which is
	 * the branch that must not be reached for by list position.
	 *
	 * @covers ::loop_occurrence
	 *
	 * @return void
	 */
	public function test_loop_occurrence_returns_null_for_a_non_recurring_row(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );

		$plain_id = $this->create_event_at( $now->modify( '+400 hours' ), $now->modify( '+401 hours' ) );
		$query    = $this->run_upcoming_query( array( 'post__in' => array( $plain_id ) ) );

		$query->the_post();

		$resolved = Utility::invoke_hidden_method( Context::get_instance(), 'loop_occurrence', array( $plain_id ) );
		$stamped  = get_post()->{Query::RESULT_DATETIME_PROPERTY};

		wp_reset_postdata();

		$this->assertNull(
			$stamped,
			'Failed to assert a non-recurring row carries a null datetime stamp.'
		);
		$this->assertNull(
			$resolved,
			'Failed to assert loop_occurrence returns null for a non-recurring row.'
		);
	}

	/**
	 * Coverage for `resolve()`'s precedence, both arms.
	 *
	 * The row's own stamp wins while a loop iteration is set up, and the
	 * request's occurrence applies where there is no stamp. Both are asserted
	 * here on purpose. The precedence the other way round gives every
	 * same-series Query Loop row the outer page's occurrence; the
	 * obvious correction, "the stamp is authoritative, drop the request arm",
	 * breaks every singular occurrence page instead, because a singular
	 * request's own post carries a null stamp. Only a test that pins both
	 * directions rejects both defects.
	 *
	 * The fixture makes the two answers differ: the request names occurrence 3
	 * while the loop's first row is occurrence 1.
	 *
	 * @covers ::resolve
	 * @covers ::loop_occurrence
	 *
	 * @return void
	 */
	public function test_resolve_prefers_the_rows_own_stamp_over_the_requests_occurrence(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$series_id = $this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );
		$requested = $this->occurrence_id( $anchor, 3 );
		$first_row = $this->occurrence_id( $anchor, 1 );

		Context::get_instance()->set( $series_id, $requested );

		$this->assertNotSame(
			$requested,
			$first_row,
			'Fixture is inert: the request and the loop\'s first row must name different occurrences.'
		);

		// Read before any `the_post()`, which is the singular occurrence page's
		// own shape: nothing is stamped, so the request is the only identity
		// there is. Reading after the loop instead would prove nothing, because
		// `wp_reset_postdata()` restores from the *main* query, which this test
		// has no post in, so the last stamped row would still be current.
		$unstamped = Utility::invoke_hidden_method( Context::get_instance(), 'resolve', array( $series_id ) );

		$query = $this->run_upcoming_query( array( 'post__in' => array( $series_id ) ) );

		$query->the_post();

		$in_loop = Utility::invoke_hidden_method( Context::get_instance(), 'resolve', array( $series_id ) );

		wp_reset_postdata();

		$this->assertSame(
			$first_row,
			$in_loop['recurrence_id'],
			'Failed to assert a stamped loop row resolves to its own occurrence rather than the request\'s.'
		);
		$this->assertSame(
			$requested,
			$unstamped['recurrence_id'],
			'Failed to assert an unstamped read still resolves to the request\'s occurrence. This is what'
				. ' every singular occurrence page depends on.'
		);
	}

	/**
	 * Coverage for a same-series Query Loop rendered inside an occurrence page.
	 *
	 * The acceptance shape for the precedence above, asserted through rendered
	 * output rather than through `resolve()`. Occurrence context is the one the
	 * outer page named; every row of the loop belongs to that same post and
	 * must still render its own date and its own link.
	 *
	 * The requested occurrence is deliberately one the loop does not contain,
	 * so no row can pass by coinciding with it, and the whole per-row vector is
	 * asserted rather than a single row.
	 *
	 * @covers ::resolve
	 * @covers ::metadata
	 * @covers ::permalink
	 *
	 * @return void
	 */
	public function test_same_series_loop_rows_keep_their_own_date_and_link_inside_an_occurrence(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$series_id = $this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );
		$requested = $this->occurrence_id( $anchor, 0 );

		Context::get_instance()->set( $series_id, $requested );

		$this->assertSame(
			$requested,
			Context::get_instance()->current()['recurrence_id'],
			'Fixture is inert: the outer occurrence context was not established.'
		);

		$rendered = $this->render_loop(
			$this->run_upcoming_query( array( 'post__in' => array( $series_id ) ) ),
			array( 'isLink' => true )
		);

		$hrefs    = array();
		$expected = array();
		$dates    = array();

		foreach ( $rendered as $row ) {
			preg_match( '/href="([^"]+)"/', $row['html'], $matches );

			// Decoded for the same reason as the distinct-permalink test: the
			// entity-escaped markup is compared as the URL a browser
			// navigates to.
			$hrefs[] = html_entity_decode( $matches[1] ?? '', ENT_QUOTES );
		}

		foreach ( array( 1, 2, 3, 4 ) as $index ) {
			$expected[] = Rewrite::get_occurrence_url( $series_id, $this->occurrence_id( $anchor, $index ) );
			$dates[]    = $anchor->modify( sprintf( '+%d days', $index ) )->format( 'F j, Y' );
		}

		$this->assertCount( 4, $rendered, 'Failed to assert the loop expanded to the four upcoming rows.' );
		$this->assertSame(
			$expected,
			$hrefs,
			'Failed to assert every same-series row linked to its own occurrence URL rather than to the'
				. ' outer request\'s.'
		);

		foreach ( $dates as $offset => $date ) {
			$this->assertStringContainsString(
				$date,
				$rendered[ $offset ]['html'],
				'Failed to assert same-series row ' . $offset . ' rendered its own occurrence date.'
			);
		}

		$this->assertStringNotContainsString(
			$anchor->format( 'F j, Y' ),
			implode( '', wp_list_pluck( $rendered, 'html' ) ),
			'Failed to assert no same-series row rendered the outer request\'s occurrence date.'
		);
		$this->assertSame(
			$requested,
			Context::get_instance()->current()['recurrence_id'],
			'Failed to assert the outer request\'s occurrence context survived the loop.'
		);
	}

	/**
	 * Coverage for `permalink()`'s two pass-through return paths.
	 *
	 * The first is a value core hands the filter that is not a post at all;
	 * the second is `series_permalink()`'s suppression, which is what stops
	 * `Rewrite::get_occurrence_url()` from composing an occurrence segment on
	 * top of a URL that already carries one.
	 *
	 * @covers ::permalink
	 * @covers ::series_permalink
	 *
	 * @return void
	 */
	public function test_permalink_passes_through_without_a_post_and_while_suppressed(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$series_id = $this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );
		$bare      = get_permalink( $series_id );
		$query     = $this->run_upcoming_query( array( 'post__in' => array( $series_id ) ) );

		$query->the_post();

		$without_post = Context::get_instance()->permalink( 'https://example.test/untouched/', null );
		$suppressed   = Context::get_instance()->series_permalink( $series_id );
		$filtered     = get_permalink( $series_id );

		wp_reset_postdata();

		$this->assertSame(
			'https://example.test/untouched/',
			$without_post,
			'Failed to assert permalink() leaves a non-post value untouched.'
		);
		$this->assertSame(
			$bare,
			$suppressed,
			'Failed to assert series_permalink() reads the bare series permalink during a loop iteration.'
		);
		$this->assertSame(
			Rewrite::get_occurrence_url( $series_id, $this->occurrence_id( $anchor, 1 ) ),
			$filtered,
			'Failed to assert the same read is filtered once suppression is restored.'
		);
	}

	/**
	 * Coverage for `permalink()`'s third pass-through: an occurrence whose post
	 * has no permalink to compose one on top of.
	 *
	 * `Rewrite::get_occurrence_url()` builds the occurrence URL from the series
	 * permalink and answers with an empty string when there is none, which is
	 * what `get_permalink()` returns for a post that is no longer there.
	 * Returning that to the `post_link` filter publishes `href=""`, which
	 * resolves to the current page. Reachability is narrow, because a resolved
	 * occurrence normally implies a live post, but an empty href is a worse
	 * answer than the permalink core already had.
	 *
	 * @covers ::permalink
	 *
	 * @return void
	 */
	public function test_permalink_falls_back_when_the_post_has_no_permalink(): void {
		$now    = $this->now();
		$anchor = $now->modify( '-1 hour' );

		$series_id = $this->create_series_at( $anchor, $now->modify( '-30 minutes' ) );
		$post      = get_post( $series_id );

		Context::get_instance()->set( $series_id, $this->occurrence_id( $anchor, 1 ) );

		wp_delete_post( $series_id, true );

		$result = Context::get_instance()->permalink( 'https://example.test/original/', $post );

		Context::get_instance()->clear();

		$this->assertSame(
			'https://example.test/original/',
			$result,
			'Failed to assert permalink() degrades to the permalink it was handed rather than to an empty href.'
		);
	}

	/**
	 * Coverage for `Occurrences::table_exists()` on both memo arms.
	 *
	 * The first call probes the schema, the second answers from the memo. The
	 * false arm belongs to the `@group multisite` suite, where a blog can be
	 * built without the table; here only the true arm is reachable.
	 *
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::table_exists
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::forget_table_exists
	 *
	 * @return void
	 */
	public function test_table_exists_memoizes_its_answer(): void {
		global $wpdb;

		$occurrences = Occurrences::get_instance();

		$occurrences->forget_table_exists();

		$wpdb->queries      = array();
		$saved              = $wpdb->save_queries;
		$wpdb->save_queries = true;

		$first  = $occurrences->table_exists();
		$probes = count( $wpdb->queries );
		$second = $occurrences->table_exists();
		$after  = count( $wpdb->queries );

		$wpdb->save_queries = $saved;
		$wpdb->queries      = array();

		$this->assertTrue( $first, 'Failed to assert the occurrence table exists on the fixture site.' );
		$this->assertTrue( $second, 'Failed to assert the memoized answer matches.' );
		$this->assertSame( 1, $probes, 'Failed to assert the first call probes the schema exactly once.' );
		$this->assertSame( $probes, $after, 'Failed to assert the second call answers from the memo.' );
	}

	/**
	 * Coverage for `Occurrences::table_exists()` refusing a lookalike table.
	 *
	 * Every `_` in `{prefix}gatherpress_event_occurrences` is a
	 * single-character `LIKE` wildcard, so an unescaped probe is satisfied by
	 * any table whose name differs only at those positions. The consequence is
	 * not cosmetic: the probe memoizes `true`, the occurrence join runs against
	 * a table that does not exist, and every published event disappears from
	 * the very lists this guard was added to keep working.
	 *
	 * The blog prefix is moved to one whose real occurrence table is absent,
	 * because that is the only state in which the question can be asked. On
	 * the fixture site the real table exists and would answer `true` honestly.
	 *
	 * @covers \GatherPress\Core\Event\Recurrence\Occurrences::table_exists
	 *
	 * @return void
	 */
	public function test_table_exists_refuses_a_lookalike_table(): void {
		global $wpdb;

		$occurrences = Occurrences::get_instance();
		$prefix      = $wpdb->prefix;
		$absent      = 'gplk_';
		$real        = sprintf( Occurrences::TABLE_FORMAT, $absent );
		// Same length, same characters everywhere except the underscores, which
		// `LIKE` treats as single-character wildcards unless escaped.
		$lookalike = str_replace( '_', 'x', $real );

		$this->assertNotSame( $real, $lookalike, 'Fixture is inert: the lookalike name must differ.' );

		// The suite rewrites every `CREATE TABLE` into `CREATE TEMPORARY TABLE`,
		// and a temporary table is invisible to `SHOW TABLES`. That would
		// make this fixture inert, passing against the unescaped probe it
		// exists to reject. The two rewriting filters are stood down for the
		// duration so the lookalike is a real table, and restored in `finally`
		// along with the prefix, the memo, and the table itself.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		try {
			$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$lookalike}` ( id BIGINT UNSIGNED NOT NULL )" );

			$created = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $lookalike ) ) );

			$wpdb->prefix = $absent;

			$occurrences->forget_table_exists();

			$answer = $occurrences->table_exists();
		} finally {
			$wpdb->prefix = $prefix;

			$occurrences->forget_table_exists();

			$wpdb->query( "DROP TABLE IF EXISTS `{$lookalike}`" );

			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

		$this->assertSame(
			$lookalike,
			$created,
			'Fixture is inert: the lookalike table was not created as a real, listable table.'
		);

		$this->assertFalse(
			$answer,
			'Failed to assert a lookalike table cannot satisfy the occurrence-table existence probe.'
		);
		$this->assertTrue(
			$occurrences->table_exists(),
			'Failed to assert the real table still answers true once the prefix is restored.'
		);
	}
}
