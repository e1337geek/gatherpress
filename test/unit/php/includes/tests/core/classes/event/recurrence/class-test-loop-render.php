<?php
/**
 * Class handles unit tests for the occurrence read path inside a Query Loop.
 *
 * The 2045-test suite that preceded this file asserted that
 * `Recurrence\Query` returns the right rows and never once asserted that a
 * block renders the right date from those rows. That gap is exactly the shape
 * of CF-8: the query layer expanded a series correctly, `attach_occurrences()`
 * stamped identity onto every result object, and nothing in the render path
 * read the stamp -- so every row of a series showed the anchor's date and the
 * bare series permalink.
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
	 * Ensure the occurrence table exists before every test, independent of
	 * execution order relative to Test_Schema.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );

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
	 * the projection -- exactly the sequence a real save produces.
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
	 * Coverage for CF-8's first half: the event date block renders the
	 * occurrence's datetime, not the series anchor's.
	 *
	 * The series anchor started an hour ago, so the loop contains its four
	 * upcoming occurrences. Every rendered row must carry its own day. Before
	 * the fix all four rendered the anchor's date and this collapses to one
	 * distinct string.
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
			'Failed to assert each occurrence row rendered a distinct date -- every row showing the same'
				. ' string is the CF-8 defect: the query expanded, the render path read the anchor.'
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
				'Failed to assert row ' . $offset . ' rendered the occurrence record\'s own time of day (PRD C-3).'
			);
		}
	}

	/**
	 * Coverage for CF-8's second half: the permalink of a loop row is the
	 * occurrence URL, not the bare series URL.
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

			$hrefs[] = $matches[1] ?? '';
		}

		$this->assertCount(
			4,
			array_unique( $hrefs ),
			'Failed to assert each occurrence row linked to its own occurrence URL -- four identical bare'
				. ' series permalinks is the CF-8 defect a browser measured.'
		);

		$expected = array();

		foreach ( array( 1, 2, 3, 4 ) as $index ) {
			$expected[] = Rewrite::get_occurrence_url( $series_id, $this->occurrence_id( $anchor, $index ) );
		}

		$this->assertSame(
			$expected,
			$hrefs,
			'Failed to assert every row linked to the REQ-8 occurrence URL shape for its own occurrence.'
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
	 * Coverage for REQ-16 on every entry point this read path adds.
	 *
	 * Drives the real entry points -- a full `WP_Query` loop plus a block
	 * render plus a `get_permalink()` call -- on a site whose
	 * `gatherpress_has_recurring_events` option is `'0'`, and asserts no query
	 * touches the occurrence table and no option is written. Naming the test
	 * after the loop rather than after the callbacks is deliberate: the two
	 * REQ-16 defects this build already shipped both had a passing "performs
	 * no writes" test that drove the body of the work and never the entry
	 * point.
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
}
