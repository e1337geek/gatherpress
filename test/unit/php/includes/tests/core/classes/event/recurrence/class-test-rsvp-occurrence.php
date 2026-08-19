<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Rsvp_Occurrence.
 *
 * T9's claim is that one RSVP belongs to one occurrence, not to the series. The
 * tests that matter here therefore drive the production entry points --
 * `Rsvp::save()`, `Rsvp::get()`, `Rsvp::responses()` -- inside a real occurrence
 * context, rather than calling the taxonomy helpers directly. A test that only
 * asserted `assign()` wrote a term would pass against a read path that ignores
 * the term entirely.
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
use GatherPress\Core\Rsvp\Cache;
use GatherPress\Core\Rsvp\Cleanup;
use GatherPress\Core\Rsvp\List_Table;
use GatherPress\Core\Rsvp\Response\Provider\Base as Provider;
use GatherPress\Core\Rsvp\Response\Status;
use GatherPress\Core\Rsvp\Rsvp;
use GatherPress\Core\Rsvp\Setup as Rsvp_Setup;
use GatherPress\Core\Rsvp\Token;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;

/**
 * Class Test_Rsvp_Occurrence.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Rsvp_Occurrence
 */
class Test_Rsvp_Occurrence extends Base {

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
	 * Start every test from an empty occurrence table, with no context left
	 * over from another test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		gatherpress_reset_custom_tables();
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
	 * Count the term relationship rows a comment holds in one taxonomy.
	 *
	 * Reads `term_relationships` directly rather than through
	 * `wp_get_object_terms()`, because the bug under test is an orphaned row
	 * whose term may still resolve perfectly well.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $comment_id Comment ID to count relationships for.
	 * @param string $taxonomy   Taxonomy to count within.
	 *
	 * @return int Number of rows.
	 */
	protected function relationship_count( int $comment_id, string $taxonomy ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS tr INNER JOIN %i AS tt'
					. ' ON tt.term_taxonomy_id = tr.term_taxonomy_id'
					. ' WHERE tr.object_id = %d AND tt.taxonomy = %s',
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$comment_id,
				$taxonomy
			)
		);
	}

	/**
	 * Count the relationship rows a comment holds across all three RSVP taxonomies.
	 *
	 * @since 0.36.0
	 *
	 * @param int $comment_id Comment ID to count relationships for.
	 *
	 * @return array<string, int> Row counts keyed by taxonomy.
	 */
	protected function all_relationship_counts( int $comment_id ): array {
		return array(
			Status::TAXONOMY          => $this->relationship_count( $comment_id, Status::TAXONOMY ),
			Provider::TAXONOMY        => $this->relationship_count( $comment_id, Provider::TAXONOMY ),
			Rsvp_Occurrence::TAXONOMY => $this->relationship_count( $comment_id, Rsvp_Occurrence::TAXONOMY ),
		);
	}

	/**
	 * Save an RSVP for a user while the request is rendering one occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence to enter before saving.
	 * @param int    $user_id       Responder.
	 * @param string $status        RSVP status to save.
	 *
	 * @return array The save result.
	 */
	protected function save_in_occurrence(
		int $post_id,
		string $recurrence_id,
		int $user_id,
		string $status = 'attending'
	): array {
		Context::get_instance()->set( $post_id, $recurrence_id );

		return ( new Rsvp( $post_id ) )->save( $user_id, $status );
	}

	/**
	 * The slug format is `{series_post_id}-{recurrence_id}`, in the sanitized form WordPress stores.
	 *
	 * The literal is asserted rather than recomputed, so a change to the format
	 * fails here rather than passing a tautology.
	 *
	 * @covers ::term_slug
	 *
	 * @return void
	 */
	public function test_term_slug_format_is_post_dash_recurrence_id(): void {
		$this->assertSame(
			'12-20260915t180000',
			Rsvp_Occurrence::term_slug( 12, '20260915T180000' ),
			'Failed to assert the occurrence term slug is the series post ID joined to the recurrence ID.'
		);

		$this->assertSame(
			sanitize_title( Rsvp_Occurrence::term_slug( 12, '20260915T180000' ) ),
			Rsvp_Occurrence::term_slug( 12, '20260915T180000' ),
			'Failed to assert the occurrence term slug survives WordPress slug sanitization unchanged.'
		);
	}

	/**
	 * The slug carries the post ID, so two series cannot collide on one recurrence ID.
	 *
	 * @covers ::term_slug
	 *
	 * @return void
	 */
	public function test_term_slug_differs_per_series_post(): void {
		$this->assertNotSame(
			Rsvp_Occurrence::term_slug( 12, self::OCCURRENCE_A ),
			Rsvp_Occurrence::term_slug( 13, self::OCCURRENCE_A ),
			'Failed to assert the occurrence term slug is scoped by series post ID.'
		);
	}

	/**
	 * The taxonomy is registered on the comment object type, privately.
	 *
	 * @covers \GatherPress\Core\Rsvp\Setup::register_taxonomy
	 *
	 * @return void
	 */
	public function test_taxonomy_is_registered_privately_on_comments(): void {
		Rsvp_Setup::get_instance()->register_taxonomy();

		$taxonomy = get_taxonomy( Rsvp_Occurrence::TAXONOMY );

		$this->assertNotFalse(
			$taxonomy,
			'Failed to assert the occurrence taxonomy is registered.'
		);
		$this->assertContains(
			'comment',
			$taxonomy->object_type,
			'Failed to assert the occurrence taxonomy is registered on comments.'
		);
		$this->assertFalse(
			$taxonomy->public,
			'Failed to assert the occurrence taxonomy is private.'
		);
		$this->assertFalse(
			$taxonomy->show_in_rest,
			'Failed to assert the occurrence taxonomy is withheld from REST.'
		);
		$this->assertFalse(
			$taxonomy->rewrite,
			'Failed to assert the occurrence taxonomy registers no rewrite rules.'
		);
	}

	/**
	 * THE core claim: an RSVP saved on one occurrence is invisible on another.
	 *
	 * @covers ::assign
	 * @covers ::tax_query
	 * @covers ::term_slug
	 * @covers ::current_recurrence_id
	 * @covers \GatherPress\Core\Rsvp\Storage::scope_to_occurrence
	 * @covers \GatherPress\Core\Rsvp\Storage::get
	 * @covers \GatherPress\Core\Rsvp\Storage::save
	 *
	 * @return void
	 */
	public function test_rsvp_on_occurrence_a_is_not_visible_on_occurrence_b(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id );

		Context::get_instance()->set( $post_id, self::OCCURRENCE_B );

		$this->assertNull(
			( new Rsvp( $post_id ) )->get( $user_id ),
			'Failed to assert an RSVP saved on one occurrence is absent from another.'
		);

		Context::get_instance()->set( $post_id, self::OCCURRENCE_A );

		$response = ( new Rsvp( $post_id ) )->get( $user_id );

		$this->assertIsArray(
			$response,
			'Failed to assert an RSVP saved on an occurrence is readable from that same occurrence.'
		);
		$this->assertSame(
			'attending',
			$response['status'],
			'Failed to assert the occurrence-scoped RSVP kept its status.'
		);
	}

	/**
	 * Attendee counts are per occurrence, not per series.
	 *
	 * `responses()` is read from each occurrence twice, in A-B-A order, so a
	 * cache key missing the occurrence dimension fails on the second read of A
	 * as well as the first read of B.
	 *
	 * @covers ::assign
	 * @covers ::tax_query
	 * @covers \GatherPress\Core\Rsvp\Cache::get
	 * @covers \GatherPress\Core\Rsvp\Cache::set
	 * @covers \GatherPress\Core\Rsvp\Cache::cache_key
	 * @covers \GatherPress\Core\Rsvp\Cache::resolve_occurrence
	 * @covers \GatherPress\Core\Rsvp\Storage::scope_to_occurrence
	 *
	 * @return void
	 */
	public function test_attendee_count_counts_only_that_occurrence(): void {
		$post_id  = $this->create_and_project();
		$first    = $this->factory->user->create();
		$second   = $this->factory->user->create();
		$third    = $this->factory->user->create();
		$expected = array(
			self::OCCURRENCE_A => 2,
			self::OCCURRENCE_B => 1,
		);

		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $first );
		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $second );
		$this->save_in_occurrence( $post_id, self::OCCURRENCE_B, $third );

		foreach ( array( self::OCCURRENCE_A, self::OCCURRENCE_B, self::OCCURRENCE_A ) as $recurrence_id ) {
			Context::get_instance()->set( $post_id, $recurrence_id );

			$responses = ( new Rsvp( $post_id ) )->responses();

			$this->assertSame(
				$expected[ $recurrence_id ],
				$responses['attending']['count'],
				sprintf(
					'Failed to assert occurrence %s counts only its own attendees.',
					$recurrence_id
				)
			);
		}
	}

	/**
	 * Changing a status on one occurrence leaves the other occurrence alone.
	 *
	 * @covers ::assign
	 * @covers ::tax_query
	 *
	 * @return void
	 */
	public function test_changing_status_on_a_does_not_affect_b(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id );
		$this->save_in_occurrence( $post_id, self::OCCURRENCE_B, $user_id );

		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id, 'not_attending' );

		Context::get_instance()->set( $post_id, self::OCCURRENCE_B );

		$this->assertSame(
			'attending',
			( new Rsvp( $post_id ) )->get( $user_id )['status'],
			'Failed to assert a status change on one occurrence left the other occurrence unchanged.'
		);

		Context::get_instance()->set( $post_id, self::OCCURRENCE_A );

		$this->assertSame(
			'not_attending',
			( new Rsvp( $post_id ) )->get( $user_id )['status'],
			'Failed to assert the status change landed on the occurrence it was made from.'
		);
	}

	/**
	 * A responder holds one independent RSVP row per occurrence.
	 *
	 * Guards against the read path being scoped while the write path silently
	 * updates the first matching comment, which would leave one row wearing two
	 * occurrence terms.
	 *
	 * @covers ::assign
	 *
	 * @return void
	 */
	public function test_each_occurrence_gets_its_own_comment_row(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id );
		$this->save_in_occurrence( $post_id, self::OCCURRENCE_B, $user_id );

		Context::get_instance()->set( $post_id, self::OCCURRENCE_A );
		$first = ( new Rsvp( $post_id ) )->find( $user_id );

		Context::get_instance()->set( $post_id, self::OCCURRENCE_B );
		$second = ( new Rsvp( $post_id ) )->find( $user_id );

		$this->assertNotSame(
			(int) $first->comment->comment_ID,
			(int) $second->comment->comment_ID,
			'Failed to assert each occurrence stores its own RSVP comment.'
		);

		$this->assertSame(
			1,
			$this->relationship_count( (int) $first->comment->comment_ID, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert an RSVP comment carries exactly one occurrence term.'
		);
	}

	/**
	 * A site with no recurring events behaves exactly as before.
	 *
	 * @covers ::assign
	 * @covers ::tax_query
	 *
	 * @return void
	 */
	public function test_non_recurring_rsvp_behavior_is_unchanged(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$user_id = $this->factory->user->create();

		Recurrence_Query::refresh_has_recurring_events();

		$rsvp = new Rsvp( $post_id );

		$rsvp->save( $user_id, 'attending' );

		$this->assertSame(
			'attending',
			$rsvp->get( $user_id )['status'],
			'Failed to assert an ordinary event RSVP still reads back.'
		);
		$this->assertSame(
			1,
			$rsvp->responses()['attending']['count'],
			'Failed to assert an ordinary event still counts its attendees.'
		);

		$comment_id = (int) $rsvp->find( $user_id )->comment->comment_ID;

		$this->assertSame(
			0,
			$this->relationship_count( $comment_id, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert a non-recurring RSVP is never given an occurrence term.'
		);
	}

	/**
	 * REQ-16: a site with no recurring events pays nothing for this feature.
	 *
	 * Asserts on the query log across the real `Rsvp::save()` entry point, not
	 * on a return value -- an occurrence term written on a non-recurring site
	 * is invisible to every return value in the flow.
	 *
	 * @covers ::assign
	 * @covers ::current_recurrence_id
	 *
	 * @return void
	 */
	public function test_non_recurring_site_issues_no_occurrence_queries_on_save(): void {
		global $wpdb;

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$user_id = $this->factory->user->create();

		Recurrence_Query::refresh_has_recurring_events();

		$occurrences_table  = sprintf( Occurrences::TABLE_FORMAT, $wpdb->prefix );
		$query_count_before = count( $wpdb->queries );

		( new Rsvp( $post_id ) )->save( $user_id, 'attending' );

		$queries_since = array_slice( $wpdb->queries, $query_count_before );

		$this->assertNotEmpty(
			$queries_since,
			'Failed to capture any queries; SAVEQUERIES must be on for this assertion to mean anything.'
		);

		$touched = array_values(
			array_filter(
				$queries_since,
				static function ( array $query ) use ( $occurrences_table ): bool {
					return str_contains( $query[0], $occurrences_table )
						|| str_contains( $query[0], Rsvp_Occurrence::TAXONOMY );
				}
			)
		);

		$this->assertSame(
			array(),
			$touched,
			'Failed to assert a non-recurring save touched neither the occurrence table nor its taxonomy.'
		);
	}

	/**
	 * The RSVP cache key carries the occurrence dimension.
	 *
	 * @covers ::current_recurrence_id
	 * @covers \GatherPress\Core\Rsvp\Cache::get
	 * @covers \GatherPress\Core\Rsvp\Cache::set
	 * @covers \GatherPress\Core\Rsvp\Cache::cache_key
	 * @covers \GatherPress\Core\Rsvp\Cache::resolve_occurrence
	 *
	 * @return void
	 */
	public function test_occurrence_cache_key_carries_the_occurrence_dimension(): void {
		$post_id = 987654;
		$value   = array( 'all' => array( 'count' => 3 ) );

		Cache::set( $post_id, $value, self::OCCURRENCE_A );

		$this->assertSame(
			$value,
			Cache::get( $post_id, self::OCCURRENCE_A ),
			'Failed to assert an occurrence-scoped cache entry reads back on its own occurrence.'
		);
		$this->assertNull(
			Cache::get( $post_id, self::OCCURRENCE_B ),
			'Failed to assert an occurrence-scoped cache entry misses on another occurrence.'
		);
		$this->assertNull(
			Cache::get( $post_id ),
			'Failed to assert an occurrence-scoped cache entry misses on the series-wide key.'
		);
	}

	/**
	 * Writing an RSVP invalidates both the series key and the occurrence key.
	 *
	 * @covers ::current_recurrence_id
	 * @covers \GatherPress\Core\Rsvp\Cache::delete
	 * @covers \GatherPress\Core\Rsvp\Cache::cache_key
	 * @covers \GatherPress\Core\Rsvp\Cache::resolve_occurrence
	 *
	 * @return void
	 */
	public function test_saving_an_rsvp_invalidates_both_cache_keys(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		Cache::set( $post_id, array( 'all' => array( 'count' => 99 ) ) );
		Cache::set( $post_id, array( 'all' => array( 'count' => 42 ) ), self::OCCURRENCE_A );

		// Both keys must exist independently before the write, or "both were
		// invalidated" is satisfied by there only ever having been one key.
		$this->assertSame(
			array( 'all' => array( 'count' => 99 ) ),
			Cache::get( $post_id ),
			'Failed to assert the series-wide cache entry is stored separately from the occurrence one.'
		);
		$this->assertSame(
			array( 'all' => array( 'count' => 42 ) ),
			Cache::get( $post_id, self::OCCURRENCE_A ),
			'Failed to assert the occurrence cache entry is stored separately from the series-wide one.'
		);

		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id );

		$this->assertNull(
			Cache::get( $post_id, self::OCCURRENCE_A ),
			'Failed to assert a write invalidated the occurrence-scoped cache key.'
		);
		$this->assertNull(
			Cache::get( $post_id ),
			'Failed to assert a write invalidated the series-wide cache key.'
		);
	}

	/**
	 * Approving an RSVP through its token invalidates the occurrence's cache key.
	 *
	 * `handle_rsvp_token()` runs on `init`, before `wp`, so there is no
	 * occurrence context for `Cache::delete()` to resolve from — the occurrence
	 * has to come off the comment's own term instead. Without that, the
	 * occurrence key survives the approval and serves stale counts for the
	 * length of `Cache::CACHE_EXPIRATION`, to every visitor at once under a
	 * persistent object cache.
	 *
	 * @covers ::recurrence_id_for_comment
	 * @covers ::recurrence_id_from_slug
	 * @covers \GatherPress\Core\Rsvp\Token::approve_comment
	 *
	 * @return void
	 */
	public function test_token_approval_invalidates_the_occurrence_cache_key(): void {
		$post_id    = $this->create_and_project();
		$user_id    = $this->factory->user->create();
		$comment_id = (int) $this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id )['comment_id'];

		wp_update_comment(
			array(
				'comment_ID'       => $comment_id,
				'comment_approved' => '0',
			)
		);

		// Leave occurrence context *first*, then warm both keys. The token
		// handler runs on `init`, where no context has been established, so
		// this is also the production shape. Warming while context was still
		// set made `Cache::set( $post_id, … )` resolve to the occurrence key
		// rather than the series one, so both writes landed on the same key and
		// the second silently overwrote the first — which the pre-assertions
		// below now catch.
		Context::get_instance()->clear();

		Cache::set( $post_id, array( 'all' => array( 'count' => 99 ) ) );
		Cache::set( $post_id, array( 'all' => array( 'count' => 42 ) ), self::OCCURRENCE_A );

		// Both keys must exist independently before the approval, exactly as in
		// the sibling test above. Without these, "both were invalidated" is
		// satisfied by there never having been anything to invalidate — and the
		// occurrence key is the one this test is actually about.
		$this->assertSame(
			array( 'all' => array( 'count' => 99 ) ),
			Cache::get( $post_id ),
			'Failed to assert the series-wide cache entry was warmed before the approval.'
		);
		$this->assertSame(
			array( 'all' => array( 'count' => 42 ) ),
			Cache::get( $post_id, self::OCCURRENCE_A ),
			'Failed to assert the occurrence cache entry was warmed before the approval.'
		);

		( new Token( $comment_id ) )->approve_comment();

		$this->assertNull(
			Cache::get( $post_id, self::OCCURRENCE_A ),
			'Failed to assert token approval invalidated the occurrence-scoped cache key.'
		);
		$this->assertNull(
			Cache::get( $post_id ),
			'Failed to assert token approval invalidated the series-wide cache key.'
		);
	}

	/**
	 * The occurrence recovered from a comment is the canonical identifier.
	 *
	 * `term_slug()` runs the composite through `sanitize_title()`, which
	 * lowercases the `T` of `Ymd\THis`. Handing that form back would compose a
	 * cache key no write has ever produced, so the round trip is asserted
	 * against the canonical value rather than against the slug.
	 *
	 * @covers ::recurrence_id_for_comment
	 * @covers ::recurrence_id_from_slug
	 *
	 * @return void
	 */
	public function test_recurrence_id_for_comment_recovers_the_canonical_identifier(): void {
		$post_id    = $this->create_and_project();
		$user_id    = $this->factory->user->create();
		$comment_id = (int) $this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id )['comment_id'];

		$this->assertSame(
			self::OCCURRENCE_A,
			Rsvp_Occurrence::recurrence_id_for_comment( $comment_id ),
			'Failed to assert the occurrence recovered from a comment is the canonical identifier.'
		);
	}

	/**
	 * A comment with no occurrence term, and an unusable ID, resolve to null.
	 *
	 * @covers ::recurrence_id_for_comment
	 * @covers ::recurrence_id_from_slug
	 *
	 * @return void
	 */
	public function test_recurrence_id_for_comment_returns_null_without_a_term(): void {
		$this->create_and_project();

		$comment_id = (int) $this->factory->comment->create();

		$this->assertNull(
			Rsvp_Occurrence::recurrence_id_for_comment( $comment_id ),
			'Failed to assert a comment carrying no occurrence term resolves to null.'
		);
		$this->assertNull(
			Rsvp_Occurrence::recurrence_id_for_comment( 0 ),
			'Failed to assert an unusable comment ID resolves to null.'
		);
		$this->assertNull(
			Rsvp_Occurrence::recurrence_id_from_slug( 'no-separator-here-' ),
			'Failed to assert a slug ending in the separator carries no identifier.'
		);
		$this->assertNull(
			Rsvp_Occurrence::recurrence_id_from_slug( '20260903t180000' ),
			'Failed to assert a slug with no separator at all carries no identifier.'
		);
	}

	/**
	 * On a non-recurring site the comment is never asked for an occurrence.
	 *
	 * @covers ::recurrence_id_for_comment
	 *
	 * @return void
	 */
	public function test_recurrence_id_for_comment_is_null_off_a_recurring_site(): void {
		$comment_id = (int) $this->factory->comment->create();

		Recurrence_Query::refresh_has_recurring_events();

		$this->assertNull(
			Rsvp_Occurrence::recurrence_id_for_comment( $comment_id ),
			'Failed to assert a non-recurring site resolves no occurrence for a comment.'
		);
	}

	/**
	 * The attendance limit and the waiting list are counted per occurrence.
	 *
	 * @covers ::assign
	 * @covers ::tax_query
	 *
	 * @return void
	 */
	public function test_attendance_limit_and_waiting_list_are_per_occurrence(): void {
		$post_id = $this->create_and_project();

		add_post_meta( $post_id, 'gatherpress_max_attendance_limit', 1 );

		$first  = $this->factory->user->create();
		$second = $this->factory->user->create();

		$this->assertSame(
			'attending',
			$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $first )['status'],
			'Failed to assert the first responder takes the only seat on occurrence A.'
		);
		$this->assertSame(
			'waiting_list',
			$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $second )['status'],
			'Failed to assert the second responder is waitlisted on occurrence A.'
		);
		$this->assertSame(
			'attending',
			$this->save_in_occurrence( $post_id, self::OCCURRENCE_B, $second )['status'],
			'Failed to assert occurrence B has its own seat, unconsumed by occurrence A.'
		);
	}

	/**
	 * The `delete_comment` relationship cleanup is owned by `Rsvp\Cleanup`.
	 *
	 * It clears all three RSVP comment taxonomies, only one of which is about
	 * recurrence, so it belongs beside the hard-delete cron rather than on a
	 * class named for the occurrence link. Asserted rather than described: a
	 * move back here would leave this failing.
	 *
	 * @covers ::__construct
	 * @covers \GatherPress\Core\Rsvp\Cleanup::setup_hooks
	 *
	 * @return void
	 */
	public function test_relationship_cleanup_is_hooked_from_the_rsvp_cleanup_class(): void {
		// The singleton is built once per process, so whichever test happens to
		// reach it first is the only one xdebug credits. Invoking the (now
		// empty) constructor directly is the documented way to trace it from
		// the test that is actually about it.
		Utility::invoke_hidden_method( Rsvp_Occurrence::get_instance(), '__construct' );

		// The constructor is empty but not pointless, and this is the assertion
		// that says so: `Traits\Singleton` declares no constructor, so deleting
		// this one hands the class PHP's implicit *public* one and `new
		// Rsvp_Occurrence()` becomes legal — two instances of a singleton.
		$this->assertTrue(
			( new \ReflectionClass( Rsvp_Occurrence::class ) )->getConstructor()->isProtected(),
			'Failed to assert the constructor stays protected so get_instance() is the only way to build one.'
		);

		$this->assertSame(
			10,
			has_action( 'delete_comment', array( Cleanup::get_instance(), 'delete_term_relationships' ) ),
			'Failed to assert Rsvp\Cleanup owns the delete_comment relationship cleanup.'
		);
		$this->assertFalse(
			has_action( 'delete_comment', array( Rsvp_Occurrence::get_instance(), 'delete_term_relationships' ) ),
			'Failed to assert Rsvp_Occurrence no longer hooks the relationship cleanup.'
		);
	}

	/**
	 * Deleting a comment that is not an RSVP touches no term relationships.
	 *
	 * @covers \GatherPress\Core\Rsvp\Cleanup::delete_term_relationships
	 *
	 * @return void
	 */
	public function test_delete_term_relationships_ignores_non_rsvp_comments(): void {
		Rsvp_Setup::get_instance()->register_taxonomy();

		// The site must look recurring, or the occurrence taxonomy is left out
		// of the cleanup list for a reason that has nothing to do with the
		// comment's type, and the assertion below would hold either way.
		update_option( Recurrence_Query::HAS_RECURRING_OPTION, '1' );

		$comment_id = (int) $this->factory->comment->create();

		Rsvp_Occurrence::get_instance()->assign( $comment_id, 12, self::OCCURRENCE_A );

		// A plain comment reaching the callback, and the same call with no
		// comment object at all — the shape WordPress uses in a few legacy
		// `do_action( 'delete_comment', $id )` call sites.
		Cleanup::get_instance()->delete_term_relationships( $comment_id, get_comment( $comment_id ) );
		Cleanup::get_instance()->delete_term_relationships( $comment_id );

		$this->assertSame(
			1,
			$this->relationship_count( $comment_id, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert a non-RSVP comment is left alone by the RSVP relationship cleanup.'
		);
	}

	/**
	 * On a non-recurring site the cleanup never names the occurrence taxonomy.
	 *
	 * @covers \GatherPress\Core\Rsvp\Cleanup::delete_term_relationships
	 *
	 * @return void
	 */
	public function test_delete_term_relationships_skips_the_occurrence_taxonomy_off_a_recurring_site(): void {
		global $wpdb;

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$user_id = $this->factory->user->create();

		Recurrence_Query::refresh_has_recurring_events();

		$comment_id = (int) ( new Rsvp( $post_id ) )->save( $user_id, 'attending' )['comment_id'];

		$query_count_before = count( $wpdb->queries );

		wp_delete_comment( $comment_id, true );

		$queries_since = array_slice( $wpdb->queries, $query_count_before );

		$this->assertNotEmpty(
			$queries_since,
			'Failed to capture any queries; SAVEQUERIES must be on for this assertion to mean anything.'
		);
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$queries_since,
					static function ( array $query ): bool {
						return str_contains( $query[0], Rsvp_Occurrence::TAXONOMY );
					}
				)
			),
			'Failed to assert a non-recurring site never queries the occurrence taxonomy on delete.'
		);
		$this->assertSame(
			array(
				Status::TAXONOMY          => 0,
				Provider::TAXONOMY        => 0,
				Rsvp_Occurrence::TAXONOMY => 0,
			),
			$this->all_relationship_counts( $comment_id ),
			'Failed to assert the pre-existing status and provider orphans are cleaned on a non-recurring site too.'
		);
	}

	/**
	 * Hard-deleting an RSVP comment leaves no orphaned term relationships.
	 *
	 * Core's `wp_delete_comment()` never removes term relationships, so all
	 * three RSVP taxonomies leaked a row on every hard delete. Trashing is
	 * deliberately not used here: `Storage::save( 'no_status' )` trashes rather
	 * than deletes, and a cleanup test written against it passes without ever
	 * deleting anything.
	 *
	 * @covers ::assign
	 * @covers \GatherPress\Core\Rsvp\Cleanup::delete_term_relationships
	 *
	 * @return void
	 */
	public function test_hard_deleting_an_rsvp_comment_leaves_no_orphan_term_relationships(): void {
		$post_id    = $this->create_and_project();
		$user_id    = $this->factory->user->create();
		$comment_id = (int) $this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id )['comment_id'];

		$this->assertSame(
			array(
				Status::TAXONOMY          => 1,
				Provider::TAXONOMY        => 1,
				Rsvp_Occurrence::TAXONOMY => 1,
			),
			$this->all_relationship_counts( $comment_id ),
			'Failed to assert a saved occurrence RSVP holds one relationship in each taxonomy.'
		);

		wp_delete_comment( $comment_id, true );

		$this->assertSame(
			array(
				Status::TAXONOMY          => 0,
				Provider::TAXONOMY        => 0,
				Rsvp_Occurrence::TAXONOMY => 0,
			),
			$this->all_relationship_counts( $comment_id ),
			'Failed to assert a hard delete removed every RSVP term relationship.'
		);
	}

	/**
	 * The RSVP cleanup cron's hard delete leaves no orphaned term relationships.
	 *
	 * @covers ::assign
	 * @covers \GatherPress\Core\Rsvp\Cleanup::delete_term_relationships
	 *
	 * @return void
	 */
	public function test_cleanup_cron_hard_delete_leaves_no_orphan_term_relationships(): void {
		$post_id    = $this->create_and_project();
		$user_id    = $this->factory->user->create();
		$comment_id = (int) $this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id )['comment_id'];

		// The cleanup cron only collects held RSVPs older than 24 hours.
		wp_update_comment(
			array(
				'comment_ID'       => $comment_id,
				'comment_approved' => '0',
				'comment_date'     => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) ),
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) ),
			)
		);

		Cleanup::get_instance()->rsvp_cleanup();

		$this->assertNull(
			get_comment( $comment_id ),
			'Failed to assert the cleanup cron hard-deleted the held RSVP.'
		);
		$this->assertSame(
			array(
				Status::TAXONOMY          => 0,
				Provider::TAXONOMY        => 0,
				Rsvp_Occurrence::TAXONOMY => 0,
			),
			$this->all_relationship_counts( $comment_id ),
			'Failed to assert the cleanup cron removed every RSVP term relationship.'
		);
	}

	/**
	 * The list table's bulk delete leaves no orphaned term relationships.
	 *
	 * @covers ::assign
	 * @covers \GatherPress\Core\Rsvp\Cleanup::delete_term_relationships
	 *
	 * @return void
	 */
	public function test_list_table_delete_leaves_no_orphan_term_relationships(): void {
		$post_id    = $this->create_and_project();
		$user_id    = $this->factory->user->create();
		$comment_id = (int) $this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id )['comment_id'];

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$list_table = new List_Table();
		$plural     = Utility::get_hidden_property( $list_table, '_args' )['plural'];

		$_REQUEST['_wpnonce']            = wp_create_nonce( sprintf( 'bulk-%s', $plural ) );
		$_REQUEST['gatherpress_rsvp_id'] = array( $comment_id );
		$_REQUEST['action']              = 'delete';

		$list_table->process_bulk_action();

		unset( $_REQUEST['_wpnonce'], $_REQUEST['gatherpress_rsvp_id'], $_REQUEST['action'] );

		$this->assertNull(
			get_comment( $comment_id ),
			'Failed to assert the list table hard-deleted the RSVP.'
		);
		$this->assertSame(
			array(
				Status::TAXONOMY          => 0,
				Provider::TAXONOMY        => 0,
				Rsvp_Occurrence::TAXONOMY => 0,
			),
			$this->all_relationship_counts( $comment_id ),
			'Failed to assert the list table delete removed every RSVP term relationship.'
		);
	}

	/**
	 * Assigning refuses an unusable comment, post, or recurrence identifier.
	 *
	 * @covers ::assign
	 *
	 * @return void
	 */
	public function test_assign_refuses_incomplete_identifiers(): void {
		$instance = Rsvp_Occurrence::get_instance();

		$this->assertFalse(
			$instance->assign( 0, 12, self::OCCURRENCE_A ),
			'Failed to assert assign() refuses a missing comment ID.'
		);
		$this->assertFalse(
			$instance->assign( 5, 0, self::OCCURRENCE_A ),
			'Failed to assert assign() refuses a missing post ID.'
		);
		$this->assertFalse(
			$instance->assign( 5, 12, '' ),
			'Failed to assert assign() refuses an empty recurrence ID.'
		);
	}

	/**
	 * Assigning writes the occurrence term onto the comment.
	 *
	 * @covers ::assign
	 *
	 * @return void
	 */
	public function test_assign_writes_the_occurrence_term(): void {
		Rsvp_Setup::get_instance()->register_taxonomy();

		$comment_id = (int) $this->factory->comment->create();

		$this->assertTrue(
			Rsvp_Occurrence::get_instance()->assign( $comment_id, 12, self::OCCURRENCE_A ),
			'Failed to assert assign() reports success.'
		);
		$this->assertSame(
			array( Rsvp_Occurrence::term_slug( 12, self::OCCURRENCE_A ) ),
			wp_list_pluck( wp_get_object_terms( $comment_id, Rsvp_Occurrence::TAXONOMY ), 'slug' ),
			'Failed to assert assign() wrote the occurrence term slug.'
		);
	}

	/**
	 * The tax query names the occurrence taxonomy and the composite slug.
	 *
	 * @covers ::tax_query
	 *
	 * @return void
	 */
	public function test_tax_query_scopes_to_one_occurrence_slug(): void {
		$this->assertSame(
			array(
				array(
					'taxonomy' => Rsvp_Occurrence::TAXONOMY,
					'field'    => 'slug',
					'terms'    => array( Rsvp_Occurrence::term_slug( 12, self::OCCURRENCE_A ) ),
				),
			),
			Rsvp_Occurrence::get_instance()->tax_query( 12, self::OCCURRENCE_A ),
			'Failed to assert the occurrence tax query scopes to the composite slug.'
		);
	}

	/**
	 * Renaming a series moves the terms and keeps every relationship row.
	 *
	 * This is what makes REQ-13's forward split a rename rather than a
	 * migration: relationships key on `term_taxonomy_id`, which `wp_update_term`
	 * does not change.
	 *
	 * @covers ::rename_series
	 *
	 * @return void
	 */
	public function test_rename_series_preserves_relationships(): void {
		$post_id      = $this->create_and_project();
		$to_post_id   = $post_id + 1000;
		$user_id      = $this->factory->user->create();
		$comment_id   = (int) $this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id )['comment_id'];
		$term_id      = (int) get_term_by(
			'slug',
			Rsvp_Occurrence::term_slug( $post_id, self::OCCURRENCE_A ),
			Rsvp_Occurrence::TAXONOMY
		)->term_id;
		$renamed      = Rsvp_Occurrence::get_instance()->rename_series(
			$post_id,
			$to_post_id,
			array( self::OCCURRENCE_A )
		);
		$renamed_term = get_term_by(
			'slug',
			Rsvp_Occurrence::term_slug( $to_post_id, self::OCCURRENCE_A ),
			Rsvp_Occurrence::TAXONOMY
		);

		$this->assertSame( 1, $renamed, 'Failed to assert rename_series() reported one renamed term.' );
		$this->assertNotFalse( $renamed_term, 'Failed to assert the term now carries the new series slug.' );
		$this->assertSame(
			$term_id,
			(int) $renamed_term->term_id,
			'Failed to assert the rename reused the same term rather than creating a new one.'
		);
		$this->assertSame(
			1,
			$this->relationship_count( $comment_id, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert the RSVP kept its relationship row across the rename.'
		);
		$this->assertFalse(
			get_term_by(
				'slug',
				Rsvp_Occurrence::term_slug( $post_id, self::OCCURRENCE_A ),
				Rsvp_Occurrence::TAXONOMY
			),
			'Failed to assert the old series slug no longer resolves.'
		);
	}

	/**
	 * Renaming skips a recurrence identifier that has no term.
	 *
	 * @covers ::rename_series
	 *
	 * @return void
	 */
	public function test_rename_series_skips_unknown_recurrence_ids(): void {
		Rsvp_Setup::get_instance()->register_taxonomy();

		$this->assertSame(
			0,
			Rsvp_Occurrence::get_instance()->rename_series( 12, 13, array( self::OCCURRENCE_A ) ),
			'Failed to assert rename_series() skips a recurrence ID that was never RSVPd to.'
		);
	}

	/**
	 * Renaming skips a term whose destination slug is already taken.
	 *
	 * @covers ::rename_series
	 *
	 * @return void
	 */
	public function test_rename_series_skips_a_taken_destination_slug(): void {
		Rsvp_Setup::get_instance()->register_taxonomy();

		$from_slug = Rsvp_Occurrence::term_slug( 12, self::OCCURRENCE_A );
		$to_slug   = Rsvp_Occurrence::term_slug( 13, self::OCCURRENCE_A );

		wp_insert_term( $from_slug, Rsvp_Occurrence::TAXONOMY, array( 'slug' => $from_slug ) );
		wp_insert_term( $to_slug, Rsvp_Occurrence::TAXONOMY, array( 'slug' => $to_slug ) );

		$this->assertSame(
			0,
			Rsvp_Occurrence::get_instance()->rename_series( 12, 13, array( self::OCCURRENCE_A ) ),
			'Failed to assert rename_series() skips a term whose destination slug already exists.'
		);
		$this->assertNotFalse(
			get_term_by( 'slug', $from_slug, Rsvp_Occurrence::TAXONOMY ),
			'Failed to assert a refused rename left the original term in place.'
		);
	}

	/**
	 * Outside occurrence context there is no recurrence ID to scope by.
	 *
	 * @covers ::current_recurrence_id
	 *
	 * @return void
	 */
	public function test_current_recurrence_id_is_null_outside_context(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->clear();

		$this->assertNull(
			Rsvp_Occurrence::current_recurrence_id( $post_id ),
			'Failed to assert no recurrence ID resolves outside occurrence context.'
		);
	}

	/**
	 * A context on another post does not scope this post's RSVPs.
	 *
	 * The mismatch is resolved through `Series::resolve_post_ids()` rather than
	 * refused outright (PRD C-2), so this pins the other side of that: a post
	 * that is genuinely not in the series still resolves to nothing. Without it,
	 * "resolve through the series" would be indistinguishable from "accept any
	 * post at all", and an occurrence of one event could scope another's RSVPs.
	 *
	 * @covers ::current_recurrence_id
	 * @covers ::current_occurrence
	 *
	 * @return void
	 */
	public function test_current_recurrence_id_is_null_for_another_post(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, self::OCCURRENCE_A );

		$this->assertSame(
			self::OCCURRENCE_A,
			Rsvp_Occurrence::current_recurrence_id( $post_id ),
			'Failed to assert the current occurrence resolves for its own series post.'
		);
		$this->assertSame(
			array(
				'series_post_id' => $post_id,
				'recurrence_id'  => self::OCCURRENCE_A,
			),
			Rsvp_Occurrence::current_occurrence( $post_id ),
			'Failed to assert the resolved occurrence carries the post its row lives on.'
		);
		$this->assertNull(
			Rsvp_Occurrence::current_recurrence_id( $post_id + 1000 ),
			'Failed to assert the current occurrence does not resolve for an unrelated post.'
		);
		$this->assertNull(
			Rsvp_Occurrence::current_occurrence( $post_id + 1000 ),
			'Failed to assert an unrelated post is refused rather than silently widened.'
		);
	}

	/**
	 * The has-recurring-events flag gates the resolution entirely.
	 *
	 * @covers ::current_recurrence_id
	 *
	 * @return void
	 */
	public function test_current_recurrence_id_is_null_when_the_site_has_no_recurring_events(): void {
		$post_id = $this->create_and_project();

		Context::get_instance()->set( $post_id, self::OCCURRENCE_A );

		update_option( Recurrence_Query::HAS_RECURRING_OPTION, '0' );

		$this->assertNull(
			Rsvp_Occurrence::current_recurrence_id( $post_id ),
			'Failed to assert the REQ-16 guard short-circuits occurrence resolution.'
		);
	}

	/**
	 * Removing an attendee invalidates the caches their removal changed.
	 *
	 * `Rsvp::save()` invalidated *after* bailing on a null `process()` result,
	 * and the `no_status` path — the one that trashes the comment — is exactly
	 * the path that returns null. So the single save that removes an attendee
	 * was the single save that skipped invalidation, leaving them visible in
	 * warm counts for the length of `Cache::CACHE_EXPIRATION` and, under a
	 * persistent object cache, visible to every visitor at once.
	 *
	 * @covers \GatherPress\Core\Rsvp\Rsvp::save
	 * @covers \GatherPress\Core\Rsvp\Cache::delete
	 *
	 * @return void
	 */
	public function test_removing_an_rsvp_invalidates_the_cache(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();

		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id );

		Cache::set( $post_id, array( 'all' => array( 'count' => 99 ) ), self::OCCURRENCE_A );

		$this->assertSame(
			array( 'all' => array( 'count' => 99 ) ),
			Cache::get( $post_id, self::OCCURRENCE_A ),
			'Failed to warm the occurrence cache entry the removal must invalidate.'
		);

		// The removal itself. `no_status` trashes the stored comment and makes
		// `process()` return null.
		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id, 'no_status' );

		$this->assertNull(
			Cache::get( $post_id, self::OCCURRENCE_A ),
			'Failed to assert removing an attendee invalidated the occurrence cache key.'
		);
	}

	/**
	 * The occurrence read path gets a scope-varying comment-query cache domain.
	 *
	 * `WP_Comment_Query::get_comments()` hashes its cache key from its declared
	 * query vars, and `tax_query` is not one of them — it reaches the SQL only
	 * through a `comments_clauses` filter. Two reads differing solely by
	 * occurrence would hash identically and the second would be served the
	 * first one's comment IDs.
	 *
	 * `Rsvp\Query::ensure_cache_domain()` is the single mechanism that prevents
	 * that, for every taxonomy-scoped read rather than only the ones that
	 * remember to set a domain. `Storage::scope_to_occurrence()` used to set one
	 * of its own, which short-circuited the derivation and left two mechanisms
	 * where the local one was the weaker — keyed on the identifier alone where
	 * the derived key covers the series post too.
	 *
	 * @covers \GatherPress\Core\Rsvp\Query::ensure_cache_domain
	 * @covers \GatherPress\Core\Rsvp\Storage::scope_to_occurrence
	 *
	 * @return void
	 */
	public function test_two_occurrences_do_not_share_a_comment_query_cache_key(): void {
		$post_id = $this->create_and_project();
		$user_id = $this->factory->user->create();
		$domains = array();
		$capture = static function ( $clauses, $query ) use ( &$domains ) {
			$domains[] = (string) $query->query_vars['cache_domain'];

			return $clauses;
		};

		$this->save_in_occurrence( $post_id, self::OCCURRENCE_A, $user_id );

		// An editor bypasses the response transient (`Rsvp::responses()` only
		// caches the public variant), so both reads below reach the comment
		// query this test is about rather than the second being served a
		// transient and never producing a cache domain at all.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// The save above already ran occurrence A's comment query, so without
		// this the A read below is served from `WP_Comment_Query`'s own object
		// cache and never reaches `comments_clauses` — leaving one domain
		// observed and the comparison vacuous.
		wp_cache_flush();

		add_filter( 'comments_clauses', $capture, 99, 2 );

		Context::get_instance()->set( $post_id, self::OCCURRENCE_A );
		( new Rsvp( $post_id ) )->responses();

		Context::get_instance()->set( $post_id, self::OCCURRENCE_B );
		( new Rsvp( $post_id ) )->responses();

		remove_filter( 'comments_clauses', $capture, 99 );

		$scoped = array_values(
			array_filter(
				$domains,
				static function ( string $domain ): bool {
					return '' !== $domain && 'core' !== $domain;
				}
			)
		);

		$this->assertNotEmpty(
			$scoped,
			'Failed to assert an occurrence-scoped read carries a cache domain at all.'
		);
		$this->assertCount(
			2,
			array_unique( $scoped ),
			'Failed to assert two occurrences of one series produce two distinct comment-query cache domains.'
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
}
