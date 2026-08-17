/**
 * External dependencies
 */
import {
	describe,
	expect,
	it,
	jest,
	beforeEach,
	afterEach,
} from '@jest/globals';

/**
 * Mock the Interactivity API with a namespace-merging store so every
 * module contributing to the `gatherpress` namespace (rsvp view,
 * modal-manager view, helpers) shares one registry, mirroring the
 * real runtime.
 */
jest.mock(
	'@wordpress/interactivity',
	() => {
		const registries = {};

		return {
			store: ( name, config = {} ) => {
				if ( ! registries[ name ] ) {
					registries[ name ] = {
						state: {},
						actions: {},
						callbacks: {},
					};
				}

				const registry = registries[ name ];

				Object.assign( registry.state, config.state );
				Object.assign( registry.actions, config.actions );
				Object.assign( registry.callbacks, config.callbacks );

				return registry;
			},
			getElement: jest.fn(),
			getContext: jest.fn(),
		};
	},
	{ virtual: true }
);

/**
 * WordPress dependencies
 */
import { store, getElement, getContext } from '@wordpress/interactivity';

/**
 * Internal dependencies
 */
import { getNonce } from '@src/helpers/interactivity';
// Import the actual modules so their actions register on the shared store.
import '@src/blocks/rsvp/view';
import '@src/blocks/modal-manager/view';

/**
 * The one series post every row on the page belongs to.
 *
 * A single post rendered many times is the whole point of the feature, so the
 * post ID deliberately cannot distinguish one row from the next.
 */
const SERIES_POST_ID = 42;

/**
 * Three occurrences of that one series, in the order the archive renders them.
 */
const OCCURRENCES = [ '20260823T180000', '20260829T180000', '20260830T180000' ];

/**
 * Three ordinary, unrelated events — the non-recurring control group.
 */
const PLAIN_POST_IDS = [ 11, 12, 13 ];

/**
 * Waits long enough for the fire-and-forget sendRsvpApiRequest promise
 * chain and the 10ms closeModal timeout inside updateRsvp to settle.
 *
 * @return {Promise<void>} Resolves after the RSVP flow settles.
 */
function flushRsvpFlow() {
	return new Promise( ( resolve ) => {
		setTimeout( resolve, 30 );
	} );
}

/**
 * Builds one RSVP block row, as the server renders it for a single loop
 * iteration: every status container present, all but the user's own hidden.
 *
 * @param {string} status The RSVP status the server rendered this row with.
 *
 * @return {string} The row markup.
 */
function rowMarkup( status ) {
	const details = JSON.stringify( { status, guests: 0, anonymous: 0 } );

	return `
		<div class="wp-block-gatherpress-rsvp" data-user-details='${ details }'>
			<div data-rsvp-status="no_status">
				<a href="#" class="gatherpress-rsvp--trigger-update" data-set-status="attending">RSVP</a>
			</div>
			<div class="gatherpress--is-hidden" data-rsvp-status="attending">
				<a href="#" class="gatherpress-rsvp--trigger-update" data-set-status="not_attending">Attending</a>
			</div>
			<div class="gatherpress--is-hidden" data-rsvp-status="not_attending">
				<a href="#" class="gatherpress-rsvp--trigger-update" data-set-status="attending">Not attending</a>
			</div>
		</div>
	`;
}

/**
 * Renders a page of RSVP rows and returns the row elements.
 *
 * @param {number} count Number of rows to render.
 *
 * @return {HTMLElement[]} The rendered row elements, in document order.
 */
function renderPage( count ) {
	document.body.innerHTML = Array.from( { length: count }, () =>
		rowMarkup( 'no_status' )
	).join( '' );

	return Array.from(
		document.querySelectorAll( '.wp-block-gatherpress-rsvp' )
	);
}

/**
 * Reads the RSVP status a row is currently showing the visitor.
 *
 * This is the thing a human sees, and the thing the hand-test that found this
 * defect was looking at.
 *
 * @param {HTMLElement} row A rendered RSVP block row.
 *
 * @return {string} The `data-rsvp-status` of the row's one visible container.
 */
function visibleStatus( row ) {
	const visible = Array.from(
		row.querySelectorAll( '[data-rsvp-status]' )
	).filter( ( node ) => ! node.classList.contains( 'gatherpress--is-hidden' ) );

	return visible.map( ( node ) => node.dataset.rsvpStatus ).join( '+' );
}

/**
 * Runs the block's own watch callback for every row, exactly as the
 * Interactivity runtime re-runs `callbacks.renderRsvpBlock` on each element
 * carrying the directive whenever the shared store changes.
 *
 * @param {Function}      renderRsvpBlock The registered callback.
 * @param {HTMLElement[]} rows            The rendered rows.
 * @param {Object[]}      contexts        One block context per row.
 *
 * @return {string[]} The per-row visible-status vector after the pass.
 */
function renderAllRows( renderRsvpBlock, rows, contexts ) {
	rows.forEach( ( row, index ) => {
		getElement.mockReturnValue( { ref: row } );
		getContext.mockReturnValue( contexts[ index ] );

		renderRsvpBlock();
	} );

	return rows.map( visibleStatus );
}

/**
 * Regression coverage for the client half of the per-occurrence RSVP defect.
 *
 * A human found it by hand: on an archive or Query Loop where one recurring
 * series renders many occurrence rows, RSVPing to one occurrence visually
 * applied the RSVP to every row of that series. The server renders each row
 * correctly; the client store keys on the post ID alone, so all of a series'
 * rows share one entry in `state.posts` and collapse to a single status the
 * moment anything writes to it.
 *
 * Every assertion here is on the whole per-row vector rather than on one row
 * (rule 3a anti-pattern #8): "row 2 shows attending" passes just as well when
 * all three rows show attending, which is the defect. The right answer and the
 * collapsed answer only differ across the vector.
 */
describe( 'RSVP state across many occurrence rows of one series', () => {
	let state;
	let actions;
	let callbacks;
	let requestBodies;

	beforeEach( () => {
		( { state, actions, callbacks } = store( 'gatherpress' ) );

		getNonce.clearCache();

		state.eventApiUrl = 'https://example.test/wp-json/gatherpress/v1';
		delete state.posts;

		requestBodies = [];

		window.alert = jest.fn();
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );

		global.fetch = jest.fn( ( url, options ) => {
			if ( url.endsWith( '/nonce' ) ) {
				return Promise.resolve( {
					json: () => Promise.resolve( { nonce: 'test-nonce' } ),
				} );
			}

			requestBodies.push( JSON.parse( options.body ) );

			return Promise.resolve( {
				status: 200,
				json: () =>
					Promise.resolve( {
						success: true,
						status: 'attending',
						guests: 0,
						anonymous: 0,
						responses: {
							attending: { count: 1 },
							waiting_list: { count: 0 },
							not_attending: { count: 0 },
						},
					} ),
			} );
		} );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		delete global.fetch;
		document.body.innerHTML = '';
	} );

	/**
	 * Clicks the "RSVP" trigger inside one row and lets the request settle.
	 *
	 * @param {HTMLElement} row     The row being RSVPd to.
	 * @param {Object}      context That row's block context.
	 *
	 * @return {Promise<void>} Resolves once the RSVP flow has settled.
	 */
	async function rsvpTo( row, context ) {
		const trigger = row.querySelector(
			'[data-rsvp-status="no_status"] .gatherpress-rsvp--trigger-update'
		);

		getElement.mockReturnValue( { ref: trigger } );
		getContext.mockReturnValue( context );

		actions.updateRsvp( { preventDefault: jest.fn() } );

		await flushRsvpFlow();
	}

	it( 'keeps every other occurrence of the series unchanged when one is RSVPd to', async () => {
		const rows = renderPage( OCCURRENCES.length );
		const contexts = OCCURRENCES.map( ( recurrenceId ) => ( {
			postId: SERIES_POST_ID,
			recurrenceId,
		} ) );

		expect( renderAllRows( callbacks.renderRsvpBlock, rows, contexts ) ).toEqual( [
			'no_status',
			'no_status',
			'no_status',
		] );

		await rsvpTo( rows[ 1 ], contexts[ 1 ] );

		expect( renderAllRows( callbacks.renderRsvpBlock, rows, contexts ) ).toEqual( [
			'no_status',
			'attending',
			'no_status',
		] );
	} );

	it( 'gives each occurrence of the series its own entry in the store', async () => {
		const rows = renderPage( OCCURRENCES.length );
		const contexts = OCCURRENCES.map( ( recurrenceId ) => ( {
			postId: SERIES_POST_ID,
			recurrenceId,
		} ) );

		renderAllRows( callbacks.renderRsvpBlock, rows, contexts );

		await rsvpTo( rows[ 1 ], contexts[ 1 ] );

		expect(
			OCCURRENCES.map(
				( recurrenceId ) =>
					state.posts[ `${ SERIES_POST_ID }:${ recurrenceId }` ]
						?.currentUser?.status ?? 'missing'
			)
		).toEqual( [ 'no_status', 'attending', 'no_status' ] );
	} );

	it( 'sends the RSVPd row occurrence with the request, not the page occurrence', async () => {
		const rows = renderPage( OCCURRENCES.length );
		const contexts = OCCURRENCES.map( ( recurrenceId ) => ( {
			postId: SERIES_POST_ID,
			recurrenceId,
		} ) );

		renderAllRows( callbacks.renderRsvpBlock, rows, contexts );

		await rsvpTo( rows[ 1 ], contexts[ 1 ] );

		expect( requestBodies ).toHaveLength( 1 );
		expect( requestBodies[ 0 ].post_id ).toBe( SERIES_POST_ID );
		expect( requestBodies[ 0 ].recurrence_id ).toBe( OCCURRENCES[ 1 ] );
	} );

	it( 'leaves ordinary non-recurring events keyed and requested exactly as before', async () => {
		const rows = renderPage( PLAIN_POST_IDS.length );
		const contexts = PLAIN_POST_IDS.map( ( postId ) => ( { postId } ) );

		expect( renderAllRows( callbacks.renderRsvpBlock, rows, contexts ) ).toEqual( [
			'no_status',
			'no_status',
			'no_status',
		] );

		await rsvpTo( rows[ 1 ], contexts[ 1 ] );

		expect( renderAllRows( callbacks.renderRsvpBlock, rows, contexts ) ).toEqual( [
			'no_status',
			'attending',
			'no_status',
		] );

		// The state key stays the bare post ID and the request body stays
		// byte-identical to what a site with no recurring events has always sent.
		expect( Object.keys( state.posts ) ).toEqual(
			PLAIN_POST_IDS.map( String )
		);
		expect( requestBodies[ 0 ] ).not.toHaveProperty( 'recurrence_id' );
	} );
} );
