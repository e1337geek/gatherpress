<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Rsvp_Occurrence.
 *
 * The class is a frozen contract at this point in the stack, so the tests here
 * pin the taxonomy name and the return value of each stub. The taxonomy name is
 * the part that matters most: it is stored on comments, so changing it after
 * release orphans every RSVP already linked to an occurrence.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use GatherPress\Core\Event\Recurrence\Rsvp_Occurrence;
use GatherPress\Tests\Base;

/**
 * Class Test_Rsvp_Occurrence.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Rsvp_Occurrence
 */
class Test_Rsvp_Occurrence extends Base {

	/**
	 * The leading underscore is what marks the taxonomy internal, and the name
	 * is the storage location for every occurrence-linked RSVP. Nothing
	 * registers it while the contract is frozen, so a site running this branch
	 * carries no new taxonomy at all.
	 *
	 * @return void
	 */
	public function test_taxonomy_is_the_internal_occurrence_name(): void {
		$this->assertSame(
			'_gatherpress_occurrence',
			Rsvp_Occurrence::TAXONOMY,
			'Failed to assert that the comment taxonomy is _gatherpress_occurrence.'
		);
		$this->assertFalse(
			taxonomy_exists( Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert that the occurrence taxonomy is not registered yet.'
		);
	}

	/**
	 * The slug builder is the single source of truth for the format, and it
	 * answers with the empty string for every pair while frozen. Two different
	 * pairs are checked so a stub that happened to echo one argument back would
	 * be caught.
	 *
	 * @covers ::term_slug
	 *
	 * @return void
	 */
	public function test_term_slug_returns_an_empty_string(): void {
		$this->assertSame(
			'',
			Rsvp_Occurrence::term_slug( 42, '20260101T090000' ),
			'Failed to assert that term_slug returns an empty string.'
		);
		$this->assertSame(
			'',
			Rsvp_Occurrence::term_slug( 7, '20260214T173000' ),
			'Failed to assert that term_slug returns an empty string for a second series and occurrence.'
		);
	}

	/**
	 * Assignment reports false, meaning no term was set. It reports false for a
	 * real comment on a real post as well, so the answer is the stub's and not
	 * a rejection of the arguments.
	 *
	 * @covers ::assign
	 *
	 * @return void
	 */
	public function test_assign_reports_no_term_was_set(): void {
		$post_id    = $this->factory->post->create();
		$comment_id = $this->factory->comment->create( array( 'comment_post_ID' => $post_id ) );
		$instance   = Rsvp_Occurrence::get_instance();

		$this->assertFalse(
			$instance->assign( $comment_id, $post_id, '20260101T090000' ),
			'Failed to assert that assign reports false for a real comment.'
		);
		$this->assertFalse(
			$instance->assign( $comment_id, $post_id, '20260108T090000' ),
			'Failed to assert that assign reports false for a second occurrence of the same series.'
		);
	}

	/**
	 * An empty clause is what makes the RSVP read path behave exactly as it did
	 * before recurrence existed: `Rsvp\Query::get_rsvps()` merges this in, so an
	 * empty array narrows nothing.
	 *
	 * @covers ::tax_query
	 *
	 * @return void
	 */
	public function test_tax_query_returns_an_empty_clause(): void {
		$this->assertSame(
			array(),
			Rsvp_Occurrence::get_instance()->tax_query( 42, '20260101T090000' ),
			'Failed to assert that tax_query returns an empty clause.'
		);
	}

	/**
	 * The forward-split seam reports zero terms renamed, for one occurrence and
	 * for many alike.
	 *
	 * @covers ::rename_series
	 *
	 * @return void
	 */
	public function test_rename_series_reports_no_terms_renamed(): void {
		$instance = Rsvp_Occurrence::get_instance();

		$this->assertSame(
			0,
			$instance->rename_series( 42, 43, array( '20260101T090000' ) ),
			'Failed to assert that rename_series reports zero for a single occurrence.'
		);
		$this->assertSame(
			0,
			$instance->rename_series( 42, 43, array( '20260101T090000', '20260108T090000' ) ),
			'Failed to assert that rename_series reports zero for several occurrences.'
		);
		$this->assertSame(
			0,
			$instance->rename_series( 42, 43, array() ),
			'Failed to assert that rename_series reports zero when handed no occurrences.'
		);
	}
}
