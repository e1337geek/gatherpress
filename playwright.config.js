// playwright.config.js
const { defineConfig, devices } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );
require( 'dotenv' ).config();

/**
 * Resolve the port of the WordPress instance `pretest:e2e` actually starts.
 *
 * `pretest:e2e` runs `wp-env start --config .wp-env.test.json`, and wp-env
 * layers `.wp-env.test.override.json` on top of it. That override is local,
 * per-checkout state (it is how parallel git worktrees avoid fighting over one
 * port) and is deliberately not tracked, so hard-coding a port here means the
 * config silently disagrees with the environment the npm script just started.
 *
 * When that happens Playwright still finds *a* WordPress on the stale port —
 * another worktree's, or a leftover container — authenticates against it
 * successfully, and then fails deep inside the specs with REST errors that
 * look like application bugs. Deriving the port from the same files wp-env
 * reads keeps the two in step by construction. `WP_BASE_URL` still wins, for
 * pointing the suite at something else entirely.
 *
 * @return {number} The port to run the e2e suite against.
 */
function resolveWpEnvPort() {
	// Override first: it is what wp-env applies last.
	for ( const file of [ '.wp-env.test.override.json', '.wp-env.test.json' ] ) {
		try {
			const config = JSON.parse( fs.readFileSync( path.join( __dirname, file ), 'utf8' ) );

			if ( config.port ) {
				return config.port;
			}
		} catch {
			// Absent (the override is local-only) or unreadable; try the next.
		}
	}

	return 8888; // wp-env's own default.
}

const baseURL = process.env.WP_BASE_URL || `http://localhost:${ resolveWpEnvPort() }`;

module.exports = defineConfig( {
	testDir: './test/e2e',
	fullyParallel: false, // Disable parallel for better stability
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 1,
	workers: 1, // Use single worker to avoid conflicts
	timeout: 180000,
	expect: {
		timeout: 10000, // Shorter expect timeout for faster feedback
	},
	reporter: [
		[ 'html' ],
		[ 'list', { printSteps: true } ],
		[ 'junit', { outputFile: 'test-results/junit.xml' } ],
	],
	globalSetup: './test/e2e/global-setup.js',
	use: {
		baseURL,
		trace: 'on-first-retry',
		video: 'on-first-retry',
		screenshot: 'only-on-failure',
		storageState: './test/e2e/storageState.json',
		actionTimeout: 15000, // Longer action timeout for slow WordPress admin
		navigationTimeout: 30000, // Longer navigation timeout
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				// In CI, run against the Google Chrome preinstalled on the
				// GitHub runner instead of Playwright's downloaded Chromium —
				// the `playwright install chromium` download hangs after
				// completing on the runners (observed 2026-05-30). Local runs
				// keep using the downloaded Chromium so no system Chrome is
				// required for development.
				...( process.env.CI ? { channel: 'chrome' } : {} ),
				storageState: './test/e2e/storageState.json',
			},
		},
	],
	outputDir: './test-results/',
} );
