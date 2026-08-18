<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package GatherPress
 * @subpackage Tests
 * @since 0.27.0
 */

// phpcs:disable Squiz.Commenting.FileComment.Missing

// Record every query so the suite's query-capture assertions have something to
// read. Several tests slice `$wpdb->queries` to prove a code path issues the
// SQL it claims to -- with SAVEQUERIES off that property stays null and those
// tests error on `count( null )` instead of asserting. This has to happen
// before `Bootstrap::start()` loads wp-config and boots `$wpdb`, and it is
// guarded so an environment that already turns query recording on (a wp-env
// override, a CI config, or the WordPress test config) keeps its own value.
// Applies to the single-site and multisite runs alike -- both configs load
// this same bootstrap.
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

// Enable WP-CLI stub so CLI code paths are covered in tests.
if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

// Shared test fixtures. The autoloader maps `class-*.php` only, and PHPUnit
// collects `class-test-*.php` only, so trait files are required by hand.
require_once __DIR__ . '/includes/tests/core/classes/event/recurrence/trait-occurrence-fixtures.php';

$gatherpress_bootstrap_instance = PMC\Unit_Test\Bootstrap::get_instance();

tests_add_filter(
	'plugins_loaded',
	static function () {
		// Manually load our plugin without having to setup the development folder in the correct plugin folder.
		require_once __DIR__ . '/../../../gatherpress.php';
	}
);

tests_add_filter(
	'gatherpress_autoloader',
	static function ( array $namespaces ): array {
		$namespaces['GatherPress\Tests'] = __DIR__;

		return $namespaces;
	}
);

$gatherpress_bootstrap_instance->start();
