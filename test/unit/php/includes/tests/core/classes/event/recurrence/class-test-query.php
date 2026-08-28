<?php
/**
 * Class handles unit tests for the frozen occurrence query filters on
 * GatherPress\Core\Event\Recurrence\Query.
 *
 * The table schema and the `gatherpress_has_recurring_events` flag are covered
 * in Test_Schema. This file covers the two `WP_Query` filters the subsystem
 * will eventually hang off `posts_clauses` and `the_posts`, both of which are
 * pass-throughs while the contract is frozen.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use GatherPress\Core\Event\Recurrence\Query;
use GatherPress\Tests\Base;
use WP_Query;

/**
 * Class Test_Query.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Query
 */
class Test_Query extends Base {

	/**
	 * Neither filter is registered yet. Registering either one early would put
	 * an unfinished join on every event query on the site, so absence from the
	 * hook table is part of the contract, not an oversight.
	 *
	 * @return void
	 */
	public function test_neither_occurrence_filter_is_registered(): void {
		$instance = Query::get_instance();

		$this->assertFalse(
			has_filter( 'posts_clauses', array( $instance, 'expand_event_clauses' ) ),
			'Failed to assert that expand_event_clauses is not hooked to posts_clauses.'
		);
		$this->assertFalse(
			has_filter( 'the_posts', array( $instance, 'attach_occurrences' ) ),
			'Failed to assert that attach_occurrences is not hooked to the_posts.'
		);
	}

	/**
	 * The clause filter hands back exactly what it was given, key order and
	 * all, so a query that ran through it is byte-identical to one that did
	 * not.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_expand_event_clauses_returns_the_clauses_unchanged(): void {
		$pieces = array(
			'where'    => ' AND post_type = \'gatherpress_event\'',
			'groupby'  => '',
			'join'     => '',
			'orderby'  => 'wp_posts.post_date DESC',
			'distinct' => '',
			'fields'   => 'wp_posts.ID',
			'limits'   => 'LIMIT 0, 10',
		);

		$this->assertSame(
			$pieces,
			Query::get_instance()->expand_event_clauses( $pieces, new WP_Query() ),
			'Failed to assert that expand_event_clauses returns the clauses unchanged.'
		);
	}

	/**
	 * An empty clause set survives the pass-through too, which is the shape a
	 * query with no filters of its own arrives in.
	 *
	 * @covers ::expand_event_clauses
	 *
	 * @return void
	 */
	public function test_expand_event_clauses_passes_an_empty_clause_set_through(): void {
		$this->assertSame(
			array(),
			Query::get_instance()->expand_event_clauses( array(), new WP_Query() ),
			'Failed to assert that expand_event_clauses passes an empty clause set through.'
		);
	}

	/**
	 * The results filter stamps nothing yet, and it has to leave both result
	 * shapes alone: the plugin's own read API asks for IDs, while a template
	 * loop gets `WP_Post` objects.
	 *
	 * @covers ::attach_occurrences
	 *
	 * @return void
	 */
	public function test_attach_occurrences_returns_both_result_shapes_unchanged(): void {
		$post_ids = array(
			$this->factory->post->create(),
			$this->factory->post->create(),
		);
		$posts    = array_map( 'get_post', $post_ids );
		$instance = Query::get_instance();

		$this->assertSame(
			$post_ids,
			$instance->attach_occurrences( $post_ids, new WP_Query() ),
			'Failed to assert that attach_occurrences returns an ID result set unchanged.'
		);
		$this->assertSame(
			$posts,
			$instance->attach_occurrences( $posts, new WP_Query() ),
			'Failed to assert that attach_occurrences returns a WP_Post result set unchanged.'
		);
		$this->assertSame(
			array(),
			$instance->attach_occurrences( array(), new WP_Query() ),
			'Failed to assert that attach_occurrences returns an empty result set unchanged.'
		);
	}
}
