/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, jest } from '@jest/globals';

/**
 * Mock the Interactivity API with a namespace-merging store so every
 * module contributing to the `gatherpress` namespace shares one registry,
 * mirroring the real runtime — and so `withRecurrenceId()`, which reads the
 * same namespace from `src/helpers/interactivity`, sees what this file sets.
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
import '@src/blocks/rsvp-form/view';

/**
 * The open RSVP form is the anonymous write path, and the occurrence reaches
 * the server only because `handleRsvpFormSubmit()` spreads it into the request
 * body. Nothing in the PHP suite can see that spread: drop it and every open
 * RSVP submitted from an occurrence page lands series-wide, while the REST
 * layer behaves exactly as designed.
 */
describe( 'rsvp-form view handleRsvpFormSubmit', () => {
	let state;
	let actions;

	beforeEach( () => {
		jest.clearAllMocks();

		( { state, actions } = store( 'gatherpress' ) );

		state.eventApiUrl = 'https://example.test/wp-json/gatherpress/v1';
		state.rsvpForm = { isSubmitting: false };
		delete state.recurrenceId;

		document.body.innerHTML = `
			<form id="rsvp">
				<input name="author" value="Ada Lovelace" />
				<input name="email" value="ada@example.test" />
				<button class="gatherpress-submit-button" type="submit"></button>
			</form>
		`;

		getContext.mockReturnValue( { postId: 42 } );
		getElement.mockReturnValue( { ref: document.getElementById( 'rsvp' ) } );

		// URL-aware, because the submit path makes two requests: the nonce
		// fetch `getNonce()` performs, then the RSVP POST this test reads.
		global.fetch = jest.fn( ( url ) =>
			Promise.resolve( {
				status: 200,
				json: () =>
					Promise.resolve(
						url.endsWith( '/nonce' )
							? { nonce: 'test-nonce' }
							: { success: true }
					),
			} )
		);
	} );

	/**
	 * Submit the form through the registered action.
	 *
	 * @return {Promise<Object>} The decoded body of the RSVP request.
	 */
	const submit = async () => {
		await actions.handleRsvpFormSubmit( { preventDefault: jest.fn() } );

		const call = global.fetch.mock.calls.find( ( [ url ] ) =>
			url.endsWith( '/rsvp-form' )
		);

		expect( call ).toBeDefined();

		return JSON.parse( call[ 1 ].body );
	};

	it( 'sends the occurrence identifier when the page is rendering one', async () => {
		state.recurrenceId = '20260917T180000';

		await expect( submit() ).resolves.toMatchObject( {
			comment_post_ID: 42,
			email: 'ada@example.test',
			recurrence_id: '20260917T180000',
		} );
	} );

	it( 'omits the occurrence identifier off an occurrence page', async () => {
		const body = await submit();

		expect( body ).not.toHaveProperty( 'recurrence_id' );
		expect( body ).toMatchObject( { comment_post_ID: 42 } );
	} );

	it( 'keeps the occurrence identifier out of the custom-field passthrough', async () => {
		state.recurrenceId = '20260917T180000';

		const body = await submit();

		// The loop over remaining FormData entries must not overwrite it, and
		// nothing in the form can forge one — the value comes from server state.
		expect( body.recurrence_id ).toBe( '20260917T180000' );
	} );
} );
