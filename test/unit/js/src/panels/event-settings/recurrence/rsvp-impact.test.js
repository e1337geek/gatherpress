/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, jest, test } from '@jest/globals';
import '@testing-library/jest-dom';

/**
 * WordPress dependencies
 */
jest.mock( '@wordpress/i18n', () => ( {
	_n: ( single, plural, number ) => ( 1 === number ? single : plural ),
	sprintf: ( format, ...args ) =>
		format.replace( /%d/g, () => String( args.shift() ) ),
} ) );

const mockApiFetch = jest.fn();

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: ( args ) => mockApiFetch( args ),
} ) );

/**
 * Internal dependencies
 */
import RsvpImpact from '@src/panels/event-settings/recurrence/rsvp-impact';

const RULE = {
	frequency: 'weekly',
	interval: 2,
	weekdays: [ 2, 4 ],
	end_type: 'count',
	count: 4,
};

beforeEach( () => {
	jest.clearAllMocks();
} );

describe( 'RsvpImpact', () => {
	test( 'says nothing when the change strands no RSVP', async () => {
		mockApiFetch.mockResolvedValue( { removed: [ 'x' ], rsvp_count: 0 } );

		const { container } = render(
			<RsvpImpact postId={ 42 } rule={ RULE } />,
		);

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'reports a single stranded RSVP in the singular', async () => {
		mockApiFetch.mockResolvedValue( { removed: [ 'x' ], rsvp_count: 1 } );

		render( <RsvpImpact postId={ 42 } rule={ RULE } /> );

		await waitFor( () =>
			expect(
				screen.getByText(
					'1 RSVP is on a date this change removes. It stays where it is and is not moved to another date.',
				),
			).toBeInTheDocument(),
		);
	} );

	test( 'reports several stranded RSVPs in the plural, and says they are not moved', async () => {
		mockApiFetch.mockResolvedValue( {
			removed: [ 'x', 'y' ],
			rsvp_count: 3,
		} );

		render( <RsvpImpact postId={ 42 } rule={ RULE } /> );

		await waitFor( () =>
			expect(
				screen.getByText(
					'3 RSVPs are on dates this change removes. They stay where they are and are not moved to other dates.',
				),
			).toBeInTheDocument(),
		);
	} );

	test( 'asks the impact route about the rule currently being edited', async () => {
		mockApiFetch.mockResolvedValue( { removed: [], rsvp_count: 0 } );

		render( <RsvpImpact postId={ 42 } rule={ RULE } /> );

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledWith( {
				path:
					'/gatherpress/v1/event/recurrence-impact?post_id=42' +
					`&recurrence=${ encodeURIComponent(
						JSON.stringify( RULE ),
					) }`,
			} ),
		);
	} );

	test( 'asks again when the rule changes', async () => {
		mockApiFetch.mockResolvedValue( { removed: [], rsvp_count: 0 } );

		const { rerender } = render(
			<RsvpImpact postId={ 42 } rule={ RULE } />,
		);

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalledTimes( 1 ) );

		rerender(
			<RsvpImpact postId={ 42 } rule={ { ...RULE, count: 2 } } />,
		);

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalledTimes( 2 ) );

		// A new object with identical values is the same rule, and re-asking on
		// every render would put the editor into a request loop.
		rerender(
			<RsvpImpact postId={ 42 } rule={ { ...RULE, count: 2 } } />,
		);

		expect( mockApiFetch ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'asks nothing before the post has an ID', () => {
		const { container } = render(
			<RsvpImpact postId={ 0 } rule={ RULE } />,
		);

		expect( mockApiFetch ).not.toHaveBeenCalled();
		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'reports nothing when the impact request fails', async () => {
		mockApiFetch.mockRejectedValue( new Error( 'nope' ) );

		const { container } = render(
			<RsvpImpact postId={ 42 } rule={ RULE } />,
		);

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'reports nothing when the response carries no count', async () => {
		mockApiFetch.mockResolvedValue( undefined );

		const { container } = render(
			<RsvpImpact postId={ 42 } rule={ RULE } />,
		);

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'ignores a stale answer that lands after the rule on screen was answered', async () => {
		// Rule A is slow and reports nothing stranded; rule B is fast and
		// reports four. Accepting A's completion afterwards would hide the
		// warning for the rule the organizer is actually looking at, moments
		// before they commit a destructive change.
		let resolveSlow;

		const slow = new Promise( ( resolve ) => {
			resolveSlow = resolve;
		} );

		mockApiFetch.mockReturnValueOnce( slow );
		mockApiFetch.mockResolvedValueOnce( { removed: [ 'x' ], rsvp_count: 4 } );

		const { rerender } = render(
			<RsvpImpact postId={ 42 } rule={ RULE } />,
		);

		rerender(
			<RsvpImpact postId={ 42 } rule={ { ...RULE, count: 2 } } />,
		);

		await waitFor( () =>
			expect(
				screen.getByText(
					'4 RSVPs are on dates this change removes. They stay where they are and are not moved to other dates.',
				),
			).toBeInTheDocument(),
		);

		await act( async () => {
			resolveSlow( { removed: [], rsvp_count: 0 } );
			await slow;
		} );

		expect(
			screen.getByText(
				'4 RSVPs are on dates this change removes. They stay where they are and are not moved to other dates.',
			),
		).toBeInTheDocument();
	} );

	test( 'ignores a stale rejection that lands after the rule on screen was answered', async () => {
		let rejectSlow;

		const slow = new Promise( ( resolve, reject ) => {
			rejectSlow = reject;
		} );

		mockApiFetch.mockReturnValueOnce( slow );
		mockApiFetch.mockResolvedValueOnce( { removed: [ 'x' ], rsvp_count: 2 } );

		const { rerender } = render(
			<RsvpImpact postId={ 42 } rule={ RULE } />,
		);

		rerender(
			<RsvpImpact postId={ 42 } rule={ { ...RULE, count: 3 } } />,
		);

		await waitFor( () =>
			expect(
				screen.getByText(
					'2 RSVPs are on dates this change removes. They stay where they are and are not moved to other dates.',
				),
			).toBeInTheDocument(),
		);

		await act( async () => {
			rejectSlow( new Error( 'network' ) );
			await slow.catch( () => {} );
		} );

		expect(
			screen.getByText(
				'2 RSVPs are on dates this change removes. They stay where they are and are not moved to other dates.',
			),
		).toBeInTheDocument();
	} );
} );
