<?php
/**
 * Class handles unit tests for GatherPress\Core\Calendar\Cache.
 *
 * @package GatherPress\Core\Calendar
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Calendar;

use GatherPress\Core\Calendar\Cache;
use GatherPress\Core\Calendar\Revision;
use GatherPress\Core\Event\Event;
use GatherPress\Core\Rsvp\Response\Status;
use GatherPress\Core\Venue;
use GatherPress\Tests\Base;

/**
 * Class Test_Cache.
 *
 * @coversDefaultClass \GatherPress\Core\Calendar\Cache
 * @group              endpoints
 */
class Test_Cache extends Base {

	/**
	 * Coverage for __construct.
	 *
	 * The instance is built during plugin bootstrap, so the constructor only
	 * runs inside a test once the stored instance is cleared.
	 *
	 * @covers ::__construct
	 *
	 * @return void
	 */
	public function test_construct_builds_the_instance(): void {
		$reflection = new \ReflectionClass( Cache::class );
		$property   = $reflection->getProperty( 'instance' );

		$property->setAccessible( true );
		$property->setValue( null, null );

		$this->assertInstanceOf(
			Cache::class,
			Cache::get_instance(),
			'Failed to assert that the constructor returns a Cache instance.'
		);
	}

	/**
	 * An occurrence announcement for a post no calendar reads is ignored.
	 *
	 * The action carries a bare post ID and any plugin may fire it. Stamping on
	 * one that contributes to no feed would strand every cached body on the site
	 * and advance a revision that describes nothing.
	 *
	 * @covers ::mark_changed_for_occurrences
	 *
	 * @return void
	 */
	public function test_an_occurrence_change_on_a_non_calendar_post_is_ignored(): void {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );
		$stamp   = Cache::get_instance()->get_last_modified();
		$count   = Cache::get_instance()->get_change_count();

		Cache::get_instance()->mark_changed_for_occurrences( $post_id );

		$this->assertSame(
			$stamp,
			Cache::get_instance()->get_last_modified(),
			'A post that appears in no calendar cannot invalidate every calendar.'
		);
		$this->assertSame(
			$count,
			Cache::get_instance()->get_change_count(),
			'And it cannot move the cache namespace either.'
		);
		$this->assertSame(
			'',
			(string) get_post_meta( (int) $post_id, Revision::META_KEY, true ),
			'Nor acquire a calendar revision it has no use for.'
		);
	}

	/**
	 * Coverage for setup_hooks.
	 *
	 * @covers ::setup_hooks
	 *
	 * @return void
	 */
	public function test_setup_hooks(): void {
		$instance = Cache::get_instance();
		$hooks    = array(
			array(
				'type'     => 'action',
				'name'     => 'save_post',
				'priority' => 10,
				'callback' => array( $instance, 'mark_changed_for_post' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'deleted_post',
				'priority' => 10,
				'callback' => array( $instance, 'mark_changed_for_post' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'updated_post_meta',
				'priority' => 10,
				'callback' => array( $instance, 'mark_changed_for_meta' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'added_post_meta',
				'priority' => 10,
				'callback' => array( $instance, 'mark_changed_for_meta' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'deleted_post_meta',
				'priority' => 10,
				'callback' => array( $instance, 'mark_changed_for_meta' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'set_object_terms',
				'priority' => 10,
				'callback' => array( $instance, 'mark_changed_for_terms' ),
			),
			array(
				'type'     => 'action',
				'name'     => 'gatherpress_occurrences_changed',
				'priority' => 10,
				'callback' => array( $instance, 'mark_changed_for_occurrences' ),
			),
		);

		$this->assert_hooks( $hooks, $instance );
	}

	/**
	 * The version stamp seeds itself once and then holds still, so clients get
	 * a validator that means something.
	 *
	 * @covers ::get_last_modified
	 *
	 * @return void
	 */
	public function test_get_last_modified_is_stable_once_seeded(): void {
		delete_option( Cache::LAST_MODIFIED_OPTION );

		$instance = Cache::get_instance();
		$first    = $instance->get_last_modified();

		$this->assertNotEmpty( $first, 'A first read should seed a timestamp.' );
		$this->assertSame(
			$first,
			$instance->get_last_modified(),
			'Repeat reads should return the same timestamp rather than moving.'
		);
	}

	/**
	 * Marking the calendar changed moves the key namespace, which is what
	 * makes every cached response unreachable at once.
	 *
	 * @covers ::mark_changed
	 * @covers ::get_versioned_key
	 *
	 * @return void
	 */
	public function test_mark_changed_moves_the_versioned_key(): void {
		$instance = Cache::get_instance();
		$before   = $instance->get_versioned_key( 'ics:example' );

		update_option( Cache::LAST_MODIFIED_OPTION, '2030-01-01 00:00:00', false );

		$this->assertNotSame(
			$before,
			$instance->get_versioned_key( 'ics:example' ),
			'A new version stamp should produce a different cache key.'
		);
	}

	/**
	 * The payload is stored as a transient, so it survives a site without a
	 * persistent object cache, and its name stays inside the option-name limit.
	 *
	 * @covers ::remember
	 * @covers ::get_versioned_key
	 *
	 * @return void
	 */
	public function test_remember_stores_the_payload_in_a_transient(): void {
		$instance = Cache::get_instance();
		$key      = 'ics:' . str_repeat( 'long-scope-', 40 );
		$renderer = static function (): string {
			return 'BEGIN:VCALENDAR';
		};

		$instance->remember( $key, $renderer );

		$name = $instance->get_versioned_key( $key );

		$this->assertSame(
			'BEGIN:VCALENDAR',
			get_transient( $name ),
			'The rendered payload should be readable back as a transient.'
		);
		$this->assertStringStartsWith(
			Cache::TRANSIENT_PREFIX,
			$name,
			'Transient names should carry the calendar prefix.'
		);
		$this->assertLessThanOrEqual(
			172,
			strlen( $name ),
			'A long scope key must still produce a transient name within the option-name limit.'
		);
	}

	/**
	 * Coverage for remember: renders once, serves the cached copy after.
	 *
	 * @covers ::remember
	 *
	 * @return void
	 */
	public function test_remember_renders_once_then_serves_the_cache(): void {
		$instance = Cache::get_instance();
		$calls    = 0;
		$renderer = static function () use ( &$calls ): string {
			++$calls;

			return 'BEGIN:VCALENDAR';
		};

		$this->assertSame( 'BEGIN:VCALENDAR', $instance->remember( 'ics:test-a', $renderer ) );
		$this->assertSame( 'BEGIN:VCALENDAR', $instance->remember( 'ics:test-a', $renderer ) );
		$this->assertSame( 1, $calls, 'The renderer should run once for a repeated request.' );
	}

	/**
	 * A stamped calendar rebuilds rather than serving the previous payload.
	 *
	 * @covers ::remember
	 * @covers ::mark_changed
	 *
	 * @return void
	 */
	public function test_remember_rebuilds_after_the_calendar_is_marked_changed(): void {
		$instance = Cache::get_instance();
		$payload  = 'first';
		$renderer = static function () use ( &$payload ): string {
			return $payload;
		};

		$instance->remember( 'ics:test-b', $renderer );

		$payload = 'second';

		update_option( Cache::LAST_MODIFIED_OPTION, '2031-01-01 00:00:00', false );

		$this->assertSame(
			'second',
			$instance->remember( 'ics:test-b', $renderer ),
			'A stamped calendar should rebuild instead of serving the stale payload.'
		);
	}

	/**
	 * Filtering the max age to zero opts out of caching entirely.
	 *
	 * @covers ::get_max_age
	 * @covers ::remember
	 *
	 * @return void
	 */
	public function test_zero_max_age_disables_caching(): void {
		$instance = Cache::get_instance();
		$calls    = 0;
		$renderer = static function () use ( &$calls ): string {
			++$calls;

			return 'uncached';
		};

		add_filter( 'gatherpress_calendar_max_age', '__return_zero' );

		$max_age = $instance->get_max_age();

		$instance->remember( 'ics:test-c', $renderer );
		$instance->remember( 'ics:test-c', $renderer );

		remove_filter( 'gatherpress_calendar_max_age', '__return_zero' );

		$this->assertSame( 0, $max_age, 'The filter should be able to disable caching.' );
		$this->assertSame( 2, $calls, 'With caching off the renderer should run every time.' );
	}

	/**
	 * A negative max age is treated as zero rather than passed to the cache.
	 *
	 * @covers ::get_max_age
	 *
	 * @return void
	 */
	public function test_negative_max_age_is_clamped(): void {
		add_filter(
			'gatherpress_calendar_max_age',
			static function (): int {
				return -100;
			}
		);

		$max_age = Cache::get_instance()->get_max_age();

		remove_all_filters( 'gatherpress_calendar_max_age' );

		$this->assertSame( 0, $max_age, 'A negative max age should clamp to zero.' );
	}

	/**
	 * Saving an event stamps the calendar; saving an unrelated post does not.
	 *
	 * @covers ::mark_changed_for_post
	 * @covers ::is_calendar_post_type
	 *
	 * @return void
	 */
	public function test_mark_changed_for_post_only_fires_for_calendar_post_types(): void {
		$instance = Cache::get_instance();

		update_option( Cache::LAST_MODIFIED_OPTION, '2029-01-01 00:00:00', false );

		$instance->mark_changed_for_post( $this->mock->post( array( 'post_type' => 'post' ) )->get()->ID );

		$this->assertSame(
			'2029-01-01 00:00:00',
			$instance->get_last_modified(),
			'A regular post should not stamp the calendar.'
		);

		$instance->mark_changed_for_post( $this->mock->post( array( 'post_type' => Event::POST_TYPE ) )->get()->ID );

		$this->assertNotSame(
			'2029-01-01 00:00:00',
			$instance->get_last_modified(),
			'An event should stamp the calendar.'
		);
	}

	/**
	 * A venue is part of a VEVENT's LOCATION, so venue edits stamp too.
	 *
	 * @covers ::mark_changed_for_post
	 * @covers ::is_calendar_post_type
	 *
	 * @return void
	 */
	public function test_mark_changed_for_post_fires_for_venues(): void {
		$instance = Cache::get_instance();

		update_option( Cache::LAST_MODIFIED_OPTION, '2029-01-01 00:00:00', false );

		$instance->mark_changed_for_post( $this->mock->post( array( 'post_type' => Venue::POST_TYPE ) )->get()->ID );

		$this->assertNotSame(
			'2029-01-01 00:00:00',
			$instance->get_last_modified(),
			'A venue edit should stamp the calendar.'
		);
	}

	/**
	 * GatherPress meta stamps the calendar; unrelated meta does not.
	 *
	 * @covers ::mark_changed_for_meta
	 *
	 * @return void
	 */
	public function test_mark_changed_for_meta_only_fires_for_gatherpress_keys(): void {
		$instance = Cache::get_instance();
		$event_id = $this->mock->post( array( 'post_type' => Event::POST_TYPE ) )->get()->ID;

		update_option( Cache::LAST_MODIFIED_OPTION, '2029-01-01 00:00:00', false );

		$instance->mark_changed_for_meta( 1, $event_id, '_edit_lock' );

		$this->assertSame(
			'2029-01-01 00:00:00',
			$instance->get_last_modified(),
			'Unrelated meta should not stamp the calendar.'
		);

		$instance->mark_changed_for_meta( 1, $event_id, 'gatherpress_datetime' );

		$this->assertNotSame(
			'2029-01-01 00:00:00',
			$instance->get_last_modified(),
			'Datetime meta should stamp the calendar.'
		);
	}

	/**
	 * Term changes on an event stamp the calendar, but RSVP status changes,
	 * which travel on the same hook against a comment taxonomy, do not.
	 *
	 * @covers ::mark_changed_for_terms
	 *
	 * @return void
	 */
	public function test_mark_changed_for_terms_ignores_comment_taxonomies(): void {
		$instance = Cache::get_instance();
		$event_id = $this->mock->post( array( 'post_type' => Event::POST_TYPE ) )->get()->ID;

		update_option( Cache::LAST_MODIFIED_OPTION, '2029-01-01 00:00:00', false );

		$instance->mark_changed_for_terms( 1, array(), array(), Status::TAXONOMY );

		$this->assertSame(
			'2029-01-01 00:00:00',
			$instance->get_last_modified(),
			'An RSVP status change should not invalidate every calendar feed.'
		);

		$instance->mark_changed_for_terms( $event_id, array(), array(), Venue::TAXONOMY );

		$this->assertNotSame(
			'2029-01-01 00:00:00',
			$instance->get_last_modified(),
			"A change to an event's venue term should stamp the calendar."
		);
	}

	/**
	 * An unregistered taxonomy is ignored rather than stamping on a guess.
	 *
	 * @covers ::mark_changed_for_terms
	 *
	 * @return void
	 */
	public function test_mark_changed_for_terms_ignores_unknown_taxonomies(): void {
		$instance = Cache::get_instance();

		update_option( Cache::LAST_MODIFIED_OPTION, '2029-01-01 00:00:00', false );

		$instance->mark_changed_for_terms( 1, array(), array(), 'not_a_taxonomy' );

		$this->assertSame(
			'2029-01-01 00:00:00',
			$instance->get_last_modified(),
			'An unknown taxonomy should not stamp the calendar.'
		);
	}

	/**
	 * A change always advances the validator past the value a client may hold.
	 *
	 * `Last-Modified` has one-second resolution and it is what conditional
	 * requests are answered from. A stored validator at or ahead of the wall
	 * clock is exactly what a burst of same-second changes leaves behind, and a
	 * change that rewrites it with the same second answers the next
	 * `If-Modified-Since` with a 304 for a body the change just invalidated.
	 * The stored value ahead of the clock makes the requirement testable
	 * without a sleep: a write that only reports the clock can never satisfy
	 * it, whatever the timing.
	 *
	 * @covers ::mark_changed
	 * @covers ::get_last_modified
	 *
	 * @return void
	 */
	public function test_a_change_advances_the_validator_even_when_the_clock_does_not(): void {
		$instance = Cache::get_instance();

		update_option( Cache::LAST_MODIFIED_OPTION, '2030-06-01 12:00:00', false );

		$instance->mark_changed();

		$first = $instance->get_last_modified();

		$this->assertGreaterThan(
			strtotime( '2030-06-01 12:00:00' ),
			strtotime( $first ),
			'A change must move the validator past the value a client may already hold.'
		);

		$instance->mark_changed();

		$this->assertGreaterThan(
			strtotime( $first ),
			strtotime( $instance->get_last_modified() ),
			'A second change must move it again, however close together the two land.'
		);
	}

	/**
	 * The change count builds on the row, not on a read a writer already made.
	 *
	 * `get_change_count() + 1` followed by a write is the interleave two
	 * concurrent cancellations produce: both read N, both write N + 1, and one
	 * increment is lost, so a feed request between them can serve a stale
	 * namespace. One process cannot run two writers, so the stale first read is
	 * forced through the option cache, which is the same seam a concurrent
	 * writer's snapshot lives behind.
	 *
	 * @covers ::mark_changed
	 * @covers ::get_change_count
	 *
	 * @return void
	 */
	public function test_the_change_count_allocation_survives_a_stale_read(): void {
		global $wpdb;

		$instance = Cache::get_instance();

		update_option( Cache::CHANGE_COUNT_OPTION, 5, false );
		wp_cache_set( Cache::CHANGE_COUNT_OPTION, '3', 'options' );

		$instance->mark_changed();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT option_value FROM %i WHERE option_name = %s',
				$wpdb->options,
				Cache::CHANGE_COUNT_OPTION
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame(
			6,
			(int) $stored,
			'The allocation must increment the stored row rather than a stale read of it.'
		);
		$this->assertSame(
			6,
			$instance->get_change_count(),
			'And the stale cached copy must be dropped, or every namespaced key keeps using it.'
		);
	}

	/**
	 * A revision meta write does not stamp the calendar a second time.
	 *
	 * `mark_changed_for_occurrences()` advances the series' revision and then
	 * stamps once. The advance is `update_post_meta()` on a `gatherpress_`
	 * key of a calendar post type, so without a bail the meta hooks re-enter
	 * `mark_changed()` once per sibling, the change count moves two or three
	 * times per change, and its docblock's "strictly increasing count of
	 * changes" stops describing anything.
	 *
	 * @covers ::mark_changed_for_meta
	 *
	 * @return void
	 */
	public function test_a_revision_meta_write_does_not_stamp_the_calendar_again(): void {
		$instance = Cache::get_instance();
		$event_id = $this->mock->post( array( 'post_type' => Event::POST_TYPE ) )->get()->ID;
		$count    = $instance->get_change_count();

		// The production path: the revision advance writes its meta and the
		// meta hooks call back in.
		add_post_meta( $event_id, Revision::META_KEY, 123 );

		$this->assertSame(
			$count,
			$instance->get_change_count(),
			'The revision write is itself part of a stamp in progress and must not stamp again.'
		);

		// The direct call answers the same way.
		$instance->mark_changed_for_meta( 1, $event_id, Revision::META_KEY );

		$this->assertSame(
			$count,
			$instance->get_change_count(),
			'A revision key reaching the meta callback directly must bail too.'
		);
	}
}
