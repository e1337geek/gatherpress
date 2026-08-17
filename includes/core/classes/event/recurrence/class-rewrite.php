<?php
/**
 * Occurrence URLs: rewrite rule registration and request resolution.
 *
 * T0 skeleton -- signatures and hooks are frozen so the failing-test commit
 * compiles and red is an assertion failure, not a missing-class error. The
 * implementation lands in the following commit.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Traits\Singleton;
use WP;
use WP_Post;
use WP_Post_Type;

/**
 * Class Rewrite.
 *
 * Singleton owning the occurrence rewrite rule and its `parse_request`
 * resolution.
 *
 * @since 0.36.0
 */
final class Rewrite {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Regex character class matching a `Ymd\THis` occurrence identifier.
	 *
	 * @since 0.36.0
	 * @var string
	 */
	const RECURRENCE_ID_REGEX = '[0-9]{8}T[0-9]{6}';

	/**
	 * Class constructor.
	 *
	 * @since 0.36.0
	 */
	public function __construct() {
		$this->setup_hooks();
	}

	/**
	 * Set up hooks for occurrence URL registration and resolution.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	protected function setup_hooks(): void {
		add_action( 'wp_loaded', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'parse_request', array( $this, 'parse_request' ) );
	}

	/**
	 * Register the occurrence rewrite rule for every event-date-supporting post type.
	 *
	 * @since 0.36.0
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Register the occurrence rewrite rule for one post type.
	 *
	 * @since 0.36.0
	 *
	 * @param string $post_type Post type slug declaring `gatherpress-event-date` support.
	 *
	 * @return void
	 */
	protected function add_rewrite_rule_for_post_type( string $post_type ): void {
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Filters the public query variables to allow the occurrence segment.
	 *
	 * @since 0.36.0
	 *
	 * @param string[] $public_query_vars Allowed public query variable names.
	 *
	 * @return string[] The updated list.
	 */
	public function add_query_vars( array $public_query_vars ): array {
		return $public_query_vars;
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Resolve the occurrence segment of a request, or the bare series URL's
	 * next upcoming occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param WP $wp The main WP request object, mutated in place.
	 *
	 * @return void
	 */
	public function parse_request( WP $wp ): void {
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Resolve a bare series URL's query var to its next upcoming occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param WP $wp The main WP request object, mutated in place.
	 *
	 * @return void
	 */
	protected function maybe_resolve_bare_series( WP $wp ): void {
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Resolve the series post ID a request's query vars point at.
	 *
	 * @since 0.36.0
	 *
	 * @param array $query_vars The request's query vars.
	 *
	 * @return int|null The resolved post ID, or null when nothing matches.
	 */
	protected function resolve_post_id_from_query_vars( array $query_vars ): ?int {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Resolve a series' next upcoming scheduled occurrence identifier.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Series post ID.
	 *
	 * @return string|null The recurrence ID, or null when none is upcoming.
	 */
	protected function next_upcoming_recurrence_id( int $post_id ): ?string {
		return null;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- T0 skeleton; delete with the body.
	/**
	 * Build the permalink of one occurrence.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Series post ID.
	 * @param string $recurrence_id Occurrence identifier in `Ymd\THis` form.
	 *
	 * @return string The occurrence URL, or '' when the post has no permalink.
	 */
	public static function get_occurrence_url( int $post_id, string $recurrence_id ): string {
		return '';
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}
