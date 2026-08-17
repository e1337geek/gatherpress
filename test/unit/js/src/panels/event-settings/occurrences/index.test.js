/**
 * External dependencies
 */
import { render, fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, jest, test } from '@jest/globals';
import '@testing-library/jest-dom';

/**
 * WordPress dependencies
 */
jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

const mockApiFetch = jest.fn();

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: ( args ) => mockApiFetch( args ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelRow: ( { children } ) => <div>{ children }</div>,
	Button: ( { children, onClick, disabled, isBusy } ) => (
		<button
			type="button"
			onClick={ onClick }
			disabled={ disabled }
			data-busy={ !! isBusy }
		>
			{ children }
		</button>
	),
} ) );

jest.mock( '@wordpress/date', () => ( {
	dateI18n: ( format, date ) => `formatted:${ date }`,
	getSettings: () => ( { formats: { datetime: 'F j, Y g:i a' } } ),
} ) );

jest.mock( '@src/helpers/event', () => ( {
	usePostTypeSupports: jest.fn( () => true ),
} ) );

/**
 * Internal dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { usePostTypeSupports } from '@src/helpers/event';
import OccurrencesPanel from '@src/panels/event-settings/occurrences';

/**
 * Build a scheduled occurrence fixture.
 *
 * @param {Object} overrides Field overrides.
 *
 * @return {Object} An occurrence row, matching the `occurrences` REST route shape.
 */
function occurrence( overrides = {} ) {
	return {
		series_post_id: 42,
		recurrence_id: '20260903T180000',
		datetime_start: '2026-09-03 18:00:00',
		datetime_start_gmt: '2026-09-03 22:00:00',
		datetime_end: '2026-09-03 20:00:00',
		datetime_end_gmt: '2026-09-04 00:00:00',
		status: 'scheduled',
		...overrides,
	};
}

let createErrorNotice;

beforeEach( () => {
	jest.clearAllMocks();
	createErrorNotice = jest.fn();
	useDispatch.mockReturnValue( { createErrorNotice } );
	usePostTypeSupports.mockReturnValue( true );
	useSelect.mockImplementation( ( selector ) =>
		selector( ( storeName ) =>
			'core/editor' === storeName ? { getCurrentPostId: () => 42 } : undefined,
		),
	);
} );

describe( 'OccurrencesPanel', () => {
	test( 'renders nothing when the post type does not support gatherpress-event-date', async () => {
		usePostTypeSupports.mockReturnValue( false );
		mockApiFetch.mockResolvedValue( [ occurrence() ] );

		const { container } = render( <OccurrencesPanel /> );

		await waitFor( () => expect( mockApiFetch ).not.toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'does not fetch and renders nothing when there is no post id yet', () => {
		useSelect.mockImplementation( () => undefined );

		const { container } = render( <OccurrencesPanel /> );

		expect( mockApiFetch ).not.toHaveBeenCalled();
		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'renders nothing when the post has no upcoming occurrences', async () => {
		mockApiFetch.mockResolvedValue( [] );

		const { container } = render( <OccurrencesPanel /> );

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'falls back to an empty list when the initial fetch fails', async () => {
		mockApiFetch.mockRejectedValue( new Error( 'network error' ) );

		const { container } = render( <OccurrencesPanel /> );

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'falls back to an empty list when the fetch resolves with a nullish value', async () => {
		mockApiFetch.mockResolvedValue( null );

		const { container } = render( <OccurrencesPanel /> );

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'renders one row per upcoming occurrence', async () => {
		mockApiFetch.mockResolvedValue( [
			occurrence( { recurrence_id: '20260903T180000' } ),
			occurrence( {
				recurrence_id: '20260910T180000',
				status: 'cancelled',
			} ),
		] );

		render( <OccurrencesPanel /> );

		await waitFor( () =>
			expect( screen.getAllByRole( 'button' ) ).toHaveLength( 2 ),
		);

		expect( screen.getByText( 'Cancel' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Restore' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Scheduled' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Cancelled' ) ).toBeInTheDocument();
	} );

	test( 'calls the cancel endpoint and reflects the new status, leaving the other row untouched', async () => {
		mockApiFetch.mockResolvedValueOnce( [
			occurrence( { recurrence_id: '20260903T180000' } ),
			occurrence( { recurrence_id: '20260910T180000' } ),
		] );

		render( <OccurrencesPanel /> );

		await waitFor( () =>
			expect( screen.getAllByText( 'Cancel' ) ).toHaveLength( 2 ),
		);

		mockApiFetch.mockResolvedValueOnce(
			occurrence( {
				recurrence_id: '20260903T180000',
				status: 'cancelled',
			} ),
		);

		fireEvent.click( screen.getAllByText( 'Cancel' )[ 0 ] );

		await waitFor( () =>
			expect( screen.getByText( 'Restore' ) ).toBeInTheDocument(),
		);

		expect( mockApiFetch ).toHaveBeenLastCalledWith( {
			path: '/gatherpress/v1/event/occurrence-status',
			method: 'POST',
			data: {
				post_id: 42,
				recurrence_id: '20260903T180000',
				status: 'cancelled',
			},
		} );
		expect( screen.getByText( 'Cancelled' ) ).toBeInTheDocument();
		// The untouched second row must remain scheduled.
		expect( screen.getByText( 'Scheduled' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Cancel' ) ).toBeInTheDocument();
	} );

	test( 'calls the restore endpoint when the occurrence is already cancelled', async () => {
		mockApiFetch.mockResolvedValueOnce( [
			occurrence( { status: 'cancelled' } ),
		] );

		render( <OccurrencesPanel /> );

		await waitFor( () =>
			expect( screen.getByText( 'Restore' ) ).toBeInTheDocument(),
		);

		mockApiFetch.mockResolvedValueOnce(
			occurrence( { status: 'scheduled' } ),
		);

		fireEvent.click( screen.getByText( 'Restore' ) );

		await waitFor( () =>
			expect( screen.getByText( 'Cancel' ) ).toBeInTheDocument(),
		);

		expect( mockApiFetch ).toHaveBeenLastCalledWith( {
			path: '/gatherpress/v1/event/occurrence-status',
			method: 'POST',
			data: {
				post_id: 42,
				recurrence_id: '20260903T180000',
				status: 'scheduled',
			},
		} );
	} );

	test( 'shows an error notice and leaves the row unchanged when the status update fails', async () => {
		mockApiFetch.mockResolvedValueOnce( [ occurrence() ] );

		render( <OccurrencesPanel /> );

		await waitFor( () =>
			expect( screen.getByText( 'Cancel' ) ).toBeInTheDocument(),
		);

		mockApiFetch.mockRejectedValueOnce( new Error( 'network error' ) );

		fireEvent.click( screen.getByText( 'Cancel' ) );

		await waitFor( () =>
			expect( createErrorNotice ).toHaveBeenCalledWith(
				'Could not update the occurrence status.',
				{ type: 'snackbar' },
			),
		);

		expect( screen.getByText( 'Cancel' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Scheduled' ) ).toBeInTheDocument();
	} );
} );
