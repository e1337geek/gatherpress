// resolve-wp-env-port.js
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The repository root, where the wp-env config files live.
 *
 * @type {string}
 */
const ROOT_DIR = path.join( __dirname, '..', '..' );

/**
 * Resolve the port of the WordPress instance `pretest:e2e` actually starts.
 *
 * `pretest:e2e` runs `wp-env start --config .wp-env.test.json`, and wp-env
 * layers `.wp-env.test.override.json` on top of it. That override is local,
 * per-checkout state (it is how parallel git worktrees avoid fighting over one
 * port) and is deliberately not tracked, so hard-coding a port here means the
 * config silently disagrees with the environment the npm script just started.
 *
 * When that happens Playwright still finds *a* WordPress on the stale port,
 * either another worktree's or a leftover container. It authenticates against
 * that one successfully, and then fails deep inside the specs with REST errors
 * that look like application bugs. Reading the same sources wp-env reads keeps
 * the two aligned for every input this function knows about. `WP_BASE_URL`
 * still wins in `playwright.config.js`, for pointing the suite at something
 * else entirely.
 *
 * @param {Object} [options]           Resolution inputs, injectable for tests.
 * @param {string} [options.configDir] Directory holding the wp-env config files.
 *
 * @return {number} The port to run the e2e suite against.
 */
function resolveWpEnvPort( { configDir = ROOT_DIR } = {} ) {
	// Override first: it is what wp-env applies last.
	for ( const file of [
		'.wp-env.test.override.json',
		'.wp-env.test.json',
	] ) {
		try {
			const config = JSON.parse(
				fs.readFileSync( path.join( configDir, file ), 'utf8' ),
			);

			if ( config.port ) {
				return config.port;
			}
		} catch {
			// Absent (the override is local-only) or unreadable; try the next.
		}
	}

	return 8888; // wp-env's own default.
}

/**
 * Resolve the base URL for the e2e suite.
 *
 * An explicit `WP_BASE_URL` wins outright, for pointing the suite at
 * something other than the wp-env instance; otherwise the URL is built from
 * the resolved wp-env port.
 *
 * @param {Object} [options]           Resolution inputs, injectable for tests.
 * @param {Object} [options.env]       Environment variables to consult.
 * @param {string} [options.configDir] Directory holding the wp-env config files.
 *
 * @return {string} The base URL to run the e2e suite against.
 */
function resolveBaseUrl( { env = process.env, configDir = ROOT_DIR } = {} ) {
	return (
		env.WP_BASE_URL || `http://localhost:${ resolveWpEnvPort( { configDir } ) }`
	);
}

module.exports = { resolveWpEnvPort, resolveBaseUrl };
