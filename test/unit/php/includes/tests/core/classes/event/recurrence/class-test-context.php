<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Context.
 *
 * The class is a frozen contract at this point in the stack: every method is
 * callable and every method has a documented return value, so the tests here
 * pin those return values rather than the behavior that lands later. When an
 * implementation arrives, these assertions are the ones that must change with
 * it, which is what makes the contract enforceable instead of decorative.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use GatherPress\Core\Event\Recurrence\Context;
use GatherPress\Tests\Base;

/**
 * Class Test_Context.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Context
 */
class Test_Context extends Base {

	/**
	 * The query var is the permalink's occurrence segment, so its value is a
	 * public contract shared with rewrite rules and with block render paths.
	 *
	 * @return void
	 */
	public function test_query_var_is_the_prefixed_occurrence_name(): void {
		$this->assertSame(
			'gatherpress_occurrence',
			Context::QUERY_VAR,
			'Failed to assert that the occurrence query var is gatherpress_occurrence.'
		);
	}

	/**
	 * Outside occurrence context there is no occurrence to report, and the
	 * frozen stub is always outside it.
	 *
	 * @covers ::current
	 *
	 * @return void
	 */
	public function test_current_returns_null_outside_occurrence_context(): void {
		$this->assertNull(
			Context::get_instance()->current(),
			'Failed to assert that current returns null when no occurrence is set.'
		);
	}

	/**
	 * `set()` and `clear()` are the entry and exit of occurrence context. The
	 * stub stores nothing, so entering context must not make `current()` start
	 * answering, and leaving it must be safe to call whether or not context was
	 * ever entered.
	 *
	 * @covers ::set
	 * @covers ::clear
	 * @covers ::current
	 *
	 * @return void
	 */
	public function test_set_and_clear_leave_current_unanswered(): void {
		$post_id  = $this->factory->post->create();
		$instance = Context::get_instance();

		$instance->set( $post_id, '20260101T090000' );

		$this->assertNull(
			$instance->current(),
			'Failed to assert that current still returns null after set.'
		);

		$instance->clear();

		$this->assertNull(
			$instance->current(),
			'Failed to assert that current returns null after clear.'
		);

		// Clearing a context that was never entered is a no-op, not an error.
		$instance->clear();

		$this->assertNull(
			$instance->current(),
			'Failed to assert that a second clear leaves current returning null.'
		);
	}

	/**
	 * Returning null from `get_post_metadata` is that filter's
	 * do-not-short-circuit convention, so the stub returning null for every
	 * read is what keeps unmodified blocks reading core's own meta. A non-null
	 * `$value` from an earlier filter must get the same answer: the stub has no
	 * occurrence to serve, so it declines rather than passing anything along.
	 *
	 * @covers ::metadata
	 *
	 * @return void
	 */
	public function test_metadata_declines_to_short_circuit_every_read(): void {
		$post_id  = $this->factory->post->create();
		$instance = Context::get_instance();

		$this->assertNull(
			$instance->metadata( null, $post_id, 'gatherpress_datetime_start', true ),
			'Failed to assert that metadata returns null for an unfiltered single read.'
		);
		$this->assertNull(
			$instance->metadata( array( '2026-01-01 09:00:00' ), $post_id, 'gatherpress_datetime_start', false ),
			'Failed to assert that metadata returns null for a non-single read another filter already answered.'
		);
		$this->assertNull(
			$instance->metadata( null, $post_id, 'unrelated_meta_key', true ),
			'Failed to assert that metadata returns null for a meta key recurrence does not derive.'
		);
	}

	/**
	 * The permalink builder is a frozen static, so it answers with the empty
	 * string for every series and every occurrence identifier alike.
	 *
	 * @covers ::occurrence_url
	 *
	 * @return void
	 */
	public function test_occurrence_url_returns_an_empty_string(): void {
		$post_id = $this->factory->post->create();

		$this->assertSame(
			'',
			Context::occurrence_url( $post_id, '20260101T090000' ),
			'Failed to assert that occurrence_url returns an empty string.'
		);
		$this->assertSame(
			'',
			Context::occurrence_url( $post_id, '20260214T173000' ),
			'Failed to assert that occurrence_url returns an empty string for a second occurrence of the same series.'
		);
	}
}
