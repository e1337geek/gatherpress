<?php
/**
 * Fails the PHP test run when `build/` is stale relative to `src/`.
 *
 * `build/` is gitignored and nothing regenerates it on a merge, a rebase or a
 * branch switch. `Blocks\Setup::register()` registers block types from
 * `build/blocks/`, never from `src/blocks/`, so a stale bundle means PHPUnit is
 * measuring the *previous* commit's block render templates while the working
 * tree shows the current one. That has cost this build three separate rounds:
 * a hand-test that measured a stale bundle and reported a defect that was
 * already fixed, a reviewer's mutation pass that edited a block's `render.php`
 * under `src` and returned a false OK because PHPUnit never loaded it, and a merge that went
 * red until someone thought to rebuild.
 *
 * It is a purely local hazard -- CI builds before both the PHPUnit job
 * (`phpunit-tests.yml:67`) and the e2e job (`e2e-tests.yml:119`) -- which is why
 * the guard lives at the PHPUnit bootstrap rather than in a workflow. The
 * bootstrap is the only place that runs on *every* local invocation: an
 * `npm run pretest:*` hook is skipped by the `wp-env run … phpunit` form that
 * agents and reviewers actually type, and a guard expressed as a test case
 * reports as one more red test among many, which is the mystery failure this is
 * meant to replace.
 *
 * @package GatherPress
 * @subpackage Tests
 * @since 0.36.0
 */

// phpcs:disable Squiz.Commenting.FileComment.Missing

/**
 * Find the most recently modified path under a directory.
 *
 * Directories are stat'd alongside files, so a deletion or a rename registers
 * as a change even though no surviving file's own timestamp moved.
 *
 * @since 0.36.0
 *
 * @param string $directory Absolute directory to walk.
 * @param string $skip      Path fragment to exclude, or '' to exclude nothing.
 *
 * @return int The newest modification time found, or 0 for an empty directory.
 */
function gatherpress_newest_mtime( string $directory, string $skip = '' ): int {
	$newest   = 0;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $path => $info ) {
		if ( '' !== $skip && str_contains( (string) $path, $skip ) ) {
			continue;
		}

		$newest = max( $newest, (int) $info->getMTime() );
	}

	return $newest;
}

/**
 * Abort the run with an explanation of what to do about it.
 *
 * @since 0.36.0
 *
 * @param string $reason Why the build is not usable.
 *
 * @return void
 */
function gatherpress_fail_stale_build( string $reason ): void {
	// STDERR is a stream, not a file, and WordPress is not loaded yet at
	// bootstrap time -- WP_Filesystem does not exist to be used here.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite(
		STDERR,
		PHP_EOL
			. 'GatherPress: refusing to run the PHP test suite against a stale build.' . PHP_EOL
			. $reason . PHP_EOL
			. 'PHPUnit registers blocks from build/blocks/, not src/blocks/, so every block-render'
			. ' assertion below would measure the previous build.' . PHP_EOL
			. 'Fix: npm run build' . PHP_EOL . PHP_EOL
	);

	exit( 1 );
}

/**
 * Refuse to run when `build/` is missing or older than `src/`.
 *
 * `build/coverage-report` is excluded from the build side of the comparison
 * because `npm run test:unit:php` writes its HTML coverage report there. Left
 * in, every run would leave `build/` looking newer than anything, and the guard
 * would pass forever after the first run -- silently, which is the failure mode
 * it exists to prevent.
 *
 * @since 0.36.0
 *
 * @return void
 */
function gatherpress_assert_build_is_fresh(): void {
	$root  = dirname( __DIR__, 3 );
	$src   = $root . '/src';
	$build = $root . '/build';

	if ( ! is_dir( $build ) ) {
		gatherpress_fail_stale_build( sprintf( 'No build directory at %s.', $build ) );
	}

	$newest_src   = gatherpress_newest_mtime( $src );
	$newest_build = gatherpress_newest_mtime( $build, '/coverage-report' );

	if ( $newest_src <= $newest_build ) {
		return;
	}

	gatherpress_fail_stale_build(
		sprintf(
			'src/ was modified at %s, after build/ was last written at %s.',
			gmdate( 'Y-m-d H:i:s', $newest_src ),
			gmdate( 'Y-m-d H:i:s', $newest_build )
		)
	);
}

gatherpress_assert_build_is_fresh();
