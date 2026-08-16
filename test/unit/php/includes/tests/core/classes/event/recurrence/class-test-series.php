<?php
/**
 * Class handles unit tests for GatherPress\Core\Event\Recurrence\Series.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Tests\Core\Event\Recurrence;

use GatherPress\Core\Event\Recurrence\Series;
use GatherPress\Tests\Base;

/**
 * Class Test_Series.
 *
 * @coversDefaultClass \GatherPress\Core\Event\Recurrence\Series
 */
class Test_Series extends Base {

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
}
