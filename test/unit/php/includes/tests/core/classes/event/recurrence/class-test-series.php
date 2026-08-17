<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Series.
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
use GatherPress\Core\Event\Recurrence\Rest_Api;
use GatherPress\Core\Event\Recurrence\Rsvp_Occurrence;
use GatherPress\Core\Event\Recurrence\Series;
use GatherPress\Core\Event\Recurrence\Splitter;
use GatherPress\Core\Setup;
use GatherPress\Tests\Base;
use PMC\Unit_Test\Utility;
use ReflectionClass;

/**
 * Class Test_Series.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Series
 */
class Test_Series extends Base {

	use Occurrence_Fixtures;

	/**
	 * The reference bi-weekly rule, widened to six occurrences.
	 *
	 * @since 0.36.0
	 * @var array
	 */
	const SIX_WEEK_RULE = array(
		'frequency' => 'weekly',
		'interval'  => 2,
		'weekdays'  => array( 2, 4 ),
		'end_type'  => 'count',
		'count'     => 6,
	);

	/**
	 * Ensure the occurrence table exists and no membership memo leaks between tests.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		Utility::invoke_hidden_method( Setup::get_instance(), 'create_tables' );
		Series::get_instance()->flush_memo();
		$this->forget_series_taxonomy();
	}

	/**
	 * Leave no membership memo behind for the next test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		Series::get_instance()->flush_memo();
		$this->forget_series_taxonomy();

		parent::tearDown();
	}

	/**
	 * Unregister the series taxonomy so no test inherits another's registration.
	 *
	 * Taxonomy registration is process-global and WordPress's test case does not
	 * roll it back, so without this the REQ-16 test would find the taxonomy
	 * already registered by whichever test ran before it -- and would pass or
	 * fail on execution order rather than on the guard it is about.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function forget_series_taxonomy(): void {
		if ( taxonomy_exists( Series::TAXONOMY ) ) {
			unregister_taxonomy( Series::TAXONOMY );
		}
	}

	/**
	 * Create the six-occurrence reference series, project it, and flag the site.
	 *
	 * @since 0.36.0
	 *
	 * @return int The projected series post ID.
	 */
	protected function create_and_project(): int {
		$post_id = $this->create_recurring_event( self::SIX_WEEK_RULE );

		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );
		Recurrence_Query::refresh_has_recurring_events();

		return $post_id;
	}

	/**
	 * Count the rows the series taxonomy holds across terms and relationships.
	 *
	 * Read straight out of storage rather than through `get_terms()`, because
	 * the claim under test is that **no record exists**, and a helper that
	 * filters by object type could hide one that does.
	 *
	 * @since 0.36.0
	 *
	 * @return array{terms: int, relationships: int} Row counts.
	 */
	protected function series_record_counts(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$terms = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE taxonomy = %s', $wpdb->term_taxonomy, Series::TAXONOMY )
		);

		$relationships = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS tr INNER JOIN %i AS tt'
					. ' ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s',
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				Series::TAXONOMY
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'terms'         => $terms,
			'relationships' => $relationships,
		);
	}

	/**
	 * Both posts a split produced resolve to one another.
	 *
	 * @covers ::resolve_post_ids
	 * @covers ::resolve_from_taxonomy
	 * @covers ::join
	 * @covers ::create_term_for
	 * @covers ::term_id_for_post
	 *
	 * @return void
	 */
	public function test_a_split_series_resolves_from_either_post(): void {
		$origin_id = $this->create_and_project();
		$result    = Splitter::get_instance()->split_forward( $origin_id, '20260917T180000' );
		$forward   = (int) $result['forward_post_id'];
		$expected  = array( min( $origin_id, $forward ), max( $origin_id, $forward ) );

		$instance = Series::get_instance();

		$this->assertSame(
			$expected,
			$instance->resolve_post_ids( $origin_id ),
			'Failed to assert the original post resolves to both halves of the split series.'
		);

		$instance->flush_memo();

		$this->assertSame(
			$expected,
			$instance->resolve_post_ids( $forward ),
			'Failed to assert the forward post resolves to both halves of the split series.'
		);
	}

	/**
	 * A series split twice resolves all three posts in one read, with one term.
	 *
	 * This is REQ-18's anti-traversal criterion, and the fixture is chosen so a
	 * parent-pointer implementation gives a different answer: with A -> B -> C
	 * pointers, resolving A finds B and stops, because A has no pointer at C.
	 * Asserting that all three share a single term is the same claim stated
	 * structurally.
	 *
	 * @covers ::resolve_post_ids
	 * @covers ::join
	 * @covers ::term_id_for_post
	 *
	 * @return void
	 */
	public function test_a_twice_split_series_resolves_without_traversal(): void {
		$instance = Series::get_instance();

		$post_a = $this->create_and_project();
		$post_b = (int) Splitter::get_instance()->split_forward( $post_a, '20260917T180000' )['forward_post_id'];

		$instance->flush_memo();

		$post_c = (int) Splitter::get_instance()->split_forward( $post_b, '20261001T180000' )['forward_post_id'];

		$expected = array( $post_a, $post_b, $post_c );
		sort( $expected );

		foreach ( array( $post_a, $post_b, $post_c ) as $post_id ) {
			$instance->flush_memo();

			$this->assertSame(
				$expected,
				$instance->resolve_post_ids( $post_id ),
				sprintf(
					'Failed to assert post %d resolves to all three posts of the twice-split series.',
					$post_id
				)
			);
		}

		$this->assertSame(
			1,
			$this->series_record_counts()['terms'],
			'Failed to assert the twice-split series is one term rather than a chain of two.'
		);
	}

	/**
	 * A series that has never been split creates no series records.
	 *
	 * @covers ::resolve_post_ids
	 * @covers ::resolve_from_taxonomy
	 *
	 * @return void
	 */
	public function test_an_unsplit_series_creates_no_records(): void {
		$post_id = $this->create_and_project();

		Series::register_taxonomy_for( Event::POST_TYPE );

		$this->assertSame(
			array( $post_id ),
			Series::get_instance()->resolve_post_ids( $post_id ),
			'Failed to assert an unsplit series is a series of one post.'
		);
		$this->assertSame(
			array(
				'terms'         => 0,
				'relationships' => 0,
			),
			$this->series_record_counts(),
			'Failed to assert a series that has never been split wrote no term and no relationship.'
		);
	}

	/**
	 * Resolution is memoized per post for the life of the request.
	 *
	 * @covers ::resolve_from_taxonomy
	 * @covers ::flush_memo
	 *
	 * @return void
	 */
	public function test_membership_is_memoized_until_flushed(): void {
		$origin_id = $this->create_and_project();
		$forward   = (int) Splitter::get_instance()->split_forward( $origin_id, '20260917T180000' )['forward_post_id'];
		$instance  = Series::get_instance();

		$instance->flush_memo();

		$first = $instance->resolve_post_ids( $origin_id );

		// Break storage under the memo: a second read that re-queried would see
		// the relationship gone and answer with one post.
		wp_delete_object_term_relationships( $forward, Series::TAXONOMY );
		clean_object_term_cache( $forward, Event::POST_TYPE );

		$this->assertSame(
			$first,
			$instance->resolve_post_ids( $origin_id ),
			'Failed to assert the memoized answer is reused within a request.'
		);

		$instance->flush_memo();

		$this->assertSame(
			array( $origin_id ),
			$instance->resolve_post_ids( $origin_id ),
			'Failed to assert flushing the memo re-reads storage.'
		);
	}

	/**
	 * The taxonomy is not registered on a site with no recurring events (REQ-16).
	 *
	 * `WP_Query` primes term caches with one query naming every taxonomy
	 * registered for the post type, so registering this one unconditionally would
	 * change the SQL text of a query that runs on every event listing. The
	 * assertion is not that the guard exists but that it is load-bearing: the
	 * same capture taken with the taxonomy force-registered differs.
	 *
	 * @covers ::register
	 *
	 * @return void
	 */
	public function test_a_site_without_recurring_events_never_registers_the_taxonomy(): void {
		$this->factory->post->create(
			array(
				'post_type'   => Event::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		Recurrence_Query::refresh_has_recurring_events();

		$this->assertFalse(
			Recurrence_Query::site_has_recurring_events(),
			'Failed to assert the fixture site has no recurring events.'
		);

		Series::get_instance()->register( Event::POST_TYPE );

		$this->assertFalse(
			taxonomy_exists( Series::TAXONOMY ),
			'Failed to assert the series taxonomy stays unregistered on a site with no recurring events.'
		);

		$guarded = $this->capture_archive_sql();

		Series::register_taxonomy_for( Event::POST_TYPE );

		$unguarded = $this->capture_archive_sql();

		$this->assertNotEmpty( $guarded, 'Failed to assert the capture observed the archive request at all.' );
		$this->assertNotSame(
			$unguarded,
			$guarded,
			'Failed to assert registering the taxonomy changes the SQL an event archive runs -- if it did not,'
				. ' this test could never fail and the REQ-16 guard would be unfalsifiable.'
		);
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$guarded,
					static function ( string $query ): bool {
						return str_contains( $query, Series::TAXONOMY );
					}
				)
			),
			'Failed to assert no query on a flag-off site names the series taxonomy.'
		);

		// The other half of REQ-16: no extra writes. Asserted over the whole
		// capture rather than over the taxonomy tables alone, so a write this
		// diff did not anticipate still fails here.
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$guarded,
					static function ( string $query ): bool {
						return (bool) preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query );
					}
				)
			),
			'Failed to assert an event archive on a flag-off site performs no writes at all.'
		);
	}

	/**
	 * The taxonomy registers once the site has a recurring event.
	 *
	 * @covers ::register
	 * @covers ::register_taxonomy_for
	 *
	 * @return void
	 */
	public function test_the_taxonomy_registers_once_the_site_has_recurring_events(): void {
		$this->create_and_project();

		Series::get_instance()->register( Event::POST_TYPE );

		$this->assertTrue(
			taxonomy_exists( Series::TAXONOMY ),
			'Failed to assert the series taxonomy registers once a recurring event exists.'
		);
		$this->assertContains(
			Series::TAXONOMY,
			get_object_taxonomies( Event::POST_TYPE ),
			'Failed to assert the taxonomy is attached to the event post type.'
		);
	}

	/**
	 * An unsupported post type never gets the taxonomy.
	 *
	 * @covers ::register
	 *
	 * @return void
	 */
	public function test_register_skips_post_types_without_event_date_support(): void {
		$this->create_and_project();

		Series::get_instance()->register( 'page' );

		$this->assertNotContains(
			Series::TAXONOMY,
			get_object_taxonomies( 'page' ),
			'Failed to assert a post type without gatherpress-event-date support is skipped.'
		);
	}

	/**
	 * An already-registered taxonomy is attached to a second post type rather than re-registered.
	 *
	 * @covers ::register_taxonomy_for
	 *
	 * @return void
	 */
	public function test_register_taxonomy_for_attaches_a_second_post_type(): void {
		register_post_type( 'gp_test_event', array( 'public' => true ) );
		add_post_type_support( 'gp_test_event', 'gatherpress-event-date' );

		Series::register_taxonomy_for( Event::POST_TYPE );
		Series::register_taxonomy_for( 'gp_test_event' );

		$attached = get_object_taxonomies( 'gp_test_event' );

		unregister_post_type( 'gp_test_event' );

		$this->assertContains(
			Series::TAXONOMY,
			$attached,
			'Failed to assert a second post type is attached to the existing taxonomy.'
		);
	}

	/**
	 * A term left behind without relationships is recovered rather than treated as a failure.
	 *
	 * @covers ::create_term_for
	 *
	 * @return void
	 */
	public function test_create_term_for_recovers_an_existing_term(): void {
		$origin_id = $this->create_and_project();

		Series::register_taxonomy_for( Event::POST_TYPE );

		$existing = wp_insert_term(
			sprintf( 'series-%d', $origin_id ),
			Series::TAXONOMY,
			array( 'slug' => sprintf( 'series-%d', $origin_id ) )
		);

		$this->assertSame(
			(int) $existing['term_id'],
			Utility::invoke_hidden_method( Series::get_instance(), 'create_term_for', array( $origin_id ) ),
			'Failed to assert an existing series term is recovered rather than reported as an error.'
		);
	}

	/**
	 * A term that can be neither created nor recovered reports zero.
	 *
	 * @covers ::create_term_for
	 * @covers ::join
	 *
	 * @return void
	 */
	public function test_join_reports_zero_when_the_term_cannot_be_created(): void {
		$origin_id  = $this->create_and_project();
		$forward_id = $this->factory->post->create( array( 'post_type' => Event::POST_TYPE ) );

		Series::register_taxonomy_for( Event::POST_TYPE );

		$refuse = static function () {
			return new \WP_Error( 'gatherpress_test_refused', 'Refused.' );
		};

		add_filter( 'pre_insert_term', $refuse );

		$term_id = Series::get_instance()->join( $origin_id, $forward_id );

		remove_filter( 'pre_insert_term', $refuse );

		$this->assertSame(
			0,
			$term_id,
			'Failed to assert a term that can be neither created nor recovered is reported as zero.'
		);
		$this->assertSame(
			array( $forward_id ),
			Series::get_instance()->resolve_post_ids( $forward_id ),
			'Failed to assert no partial relationship was written when the term could not be created.'
		);
	}

	/**
	 * Membership resolution survives a term whose relationship rows are missing.
	 *
	 * @covers ::resolve_from_taxonomy
	 *
	 * @return void
	 */
	public function test_resolution_always_includes_the_resolving_post(): void {
		$origin_id = $this->create_and_project();
		$forward   = (int) Splitter::get_instance()->split_forward( $origin_id, '20260917T180000' )['forward_post_id'];

		wp_delete_object_term_relationships( $forward, Series::TAXONOMY );
		clean_object_term_cache( $origin_id, Event::POST_TYPE );
		Series::get_instance()->flush_memo();

		$this->assertSame(
			array( $origin_id ),
			Utility::invoke_hidden_method( Series::get_instance(), 'resolve_from_taxonomy', array( $origin_id ) ),
			'Failed to assert the resolving post is always a member of its own series.'
		);
	}

	/**
	 * Capture the SQL an event archive request runs.
	 *
	 * @since 0.36.0
	 *
	 * @return string[] The captured statements.
	 */
	protected function capture_archive_sql(): array {
		global $wpdb;

		$this->go_to( (string) get_post_type_archive_link( Event::POST_TYPE ) );

		while ( have_posts() ) {
			the_post();
		}

		wp_reset_postdata();

		// The measured pass has to run against a cold object cache. Term caches
		// are exactly what the registered-taxonomy list feeds, so a warm cache
		// would serve the second capture from the first capture's primed
		// entries and hide the difference this test exists to measure.
		wp_cache_flush();

		$previous_queries   = $wpdb->queries;
		$previous_save      = $wpdb->save_queries;
		$wpdb->queries      = array();
		$wpdb->save_queries = true;

		$this->go_to( (string) get_post_type_archive_link( Event::POST_TYPE ) );

		while ( have_posts() ) {
			the_post();
		}

		wp_reset_postdata();

		$captured = array_map(
			static function ( array $entry ): string {
				return (string) preg_replace(
					'/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
					'{datetime}',
					(string) $entry[0]
				);
			},
			$wpdb->queries
		);

		$wpdb->queries      = $previous_queries;
		$wpdb->save_queries = $previous_save;

		return $captured;
	}

	/**
	 * Coverage for setup_hooks.
	 *
	 * @covers ::__construct
	 * @covers ::setup_hooks
	 *
	 * @return void
	 */
	public function test_setup_hooks(): void {
		$instance = Series::get_instance();
		$hooks    = array(
			array(
				'type'     => 'action',
				'name'     => 'registered_post_type',
				'priority' => 11,
				'callback' => array( $instance, 'register' ),
			),
		);

		$this->assert_hooks( $hooks, $instance );
	}

	/**
	 * Coverage for resolve_post_ids when no filter is registered.
	 *
	 * @covers ::resolve_post_ids
	 *
	 * @return void
	 */
	public function test_resolve_post_ids_returns_single_post(): void {
		$post_id  = $this->factory->post->create();
		$instance = Series::get_instance();

		$this->assertSame(
			array( $post_id ),
			$instance->resolve_post_ids( $post_id ),
			'Failed to assert that resolve_post_ids returns array( $post_id ) with no filter registered.'
		);
	}

	/**
	 * Coverage for resolve_post_ids when the gatherpress_series_post_ids filter is used.
	 *
	 * @covers ::resolve_post_ids
	 *
	 * @return void
	 */
	public function test_resolve_post_ids_is_filterable(): void {
		$post_id      = $this->factory->post->create();
		$companion_id = $this->factory->post->create();
		$instance     = Series::get_instance();
		$callback     = function ( array $post_ids, int $resolved_post_id ) use ( $post_id, $companion_id ) {
			$this->assertSame(
				array( $post_id ),
				$post_ids,
				'Failed to assert that the filter receives array( $post_id ) as its default value.'
			);
			$this->assertSame(
				$post_id,
				$resolved_post_id,
				'Failed to assert that the filter receives the resolved post ID as its second argument.'
			);

			return array( $post_id, $companion_id );
		};

		add_filter( 'gatherpress_series_post_ids', $callback, 10, 2 );

		$post_ids = $instance->resolve_post_ids( $post_id );

		remove_filter( 'gatherpress_series_post_ids', $callback, 10 );

		$this->assertSame(
			array( $post_id, $companion_id ),
			$post_ids,
			'Failed to assert that resolve_post_ids returns both post IDs when the filter adds a second one.'
		);
	}

	/**
	 * Every recurrence singleton declares a protected constructor of its own.
	 *
	 * The `Singleton` trait supplies only `get_instance()`, so a class that
	 * declares no constructor gets PHP's implicit public one and `new Foo()`
	 * quietly works, against both the project guidance and, for `Series`,
	 * its own docblock's claim of a protected constructor. Once later stack
	 * parts add state or hook registration, an independently constructed
	 * instance diverges from the singleton or duplicates hooks, and locking
	 * the constructor down after release changes a public contract.
	 *
	 * @covers ::__construct
	 * @covers \GatherPress\Core\Event\Recurrence\Context::__construct
	 * @covers \GatherPress\Core\Event\Recurrence\Rest_Api::__construct
	 * @covers \GatherPress\Core\Event\Recurrence\Rsvp_Occurrence::__construct
	 *
	 * @return void
	 */
	public function test_recurrence_singletons_declare_protected_constructors(): void {
		$singletons = array(
			Context::class,
			Rest_Api::class,
			Rsvp_Occurrence::class,
			Series::class,
		);

		foreach ( $singletons as $class_name ) {
			$constructor = ( new ReflectionClass( $class_name ) )->getConstructor();

			$this->assertNotNull(
				$constructor,
				sprintf( 'Failed to assert that %s declares its own constructor.', $class_name )
			);
			$this->assertTrue(
				$constructor->isProtected(),
				sprintf( 'Failed to assert that the %s constructor is protected.', $class_name )
			);

			// Invoke the empty body directly so it is covered: the singleton
			// instance already exists by the time this suite runs, so nothing
			// else constructs one inside a test.
			$constructor->setAccessible( true );
			$constructor->invoke( $class_name::get_instance() );
		}
	}
}
