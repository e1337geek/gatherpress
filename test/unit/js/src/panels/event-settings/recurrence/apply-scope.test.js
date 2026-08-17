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
	_n: ( single, plural, number ) => ( 1 === number ? single : plural ),
	sprintf: ( format, ...args ) =>
		format.replace( /%d/g, () => String( args.shift() ) ),
} ) );

const mockApiFetch = jest.fn();

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: ( args ) => mockApiFetch( args ),
} ) );

jest.mock( '@wordpress/components', () => ( {
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
	RadioControl: ( { label, selected, options, onChange } ) => (
		<select
			aria-label={ label }
			value={ selected }
			onChange={ ( event ) => onChange( event.target.value ) }
		>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	),
	SelectControl: ( { label, value, options, onChange } ) => (
		<select
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	),
} ) );

jest.mock( '@wordpress/date', () => ( {
	dateI18n: ( format, date ) => `formatted:${ date }`,
	getSettings: () => ( { formats: { datetime: 'F j, Y g:i a' } } ),
} ) );

/**
 * Internal dependencies
 */
import ApplyScope from '@src/panels/event-settings/recurrence/apply-scope';

/**
 * Build an occurrence row as the `occurrences` REST route returns it.
 *
 * @param {string} recurrenceId Occurrence identifier.
 * @param {string} start        Local start.
 *
 * @return {Object} The row.
 */
function occurrence( recurrenceId, start ) {
	return {
		series_post_id: 42,
		recurrence_id: recurrenceId,
		datetime_start: start,
		status: 'scheduled',
	};
}

const rows = [
	occurrence( '20260903T180000', '2026-09-03 18:00:00' ),
	occurrence( '20260917T180000', '2026-09-17 18:00:00' ),
];

beforeEach( () => {
	jest.clearAllMocks();
} );

describe( 'ApplyScope', () => {
	test( 'defaults to retroactive and requests nothing', () => {
		render( <ApplyScope postId={ 42 } /> );

		expect(
			screen.getByLabelText( 'Applying changes' ),
		).toHaveValue( 'retroactive' );
		expect( mockApiFetch ).not.toHaveBeenCalled();
		expect(
			screen.queryByLabelText( 'Split from' ),
		).not.toBeInTheDocument();
	} );

	test( 'lists the upcoming occurrences once forward is chosen', async () => {
		mockApiFetch.mockResolvedValue( rows );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
				'20260903T180000',
			),
		);
		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/gatherpress/v1/event/occurrences?post_id=42',
		} );
	} );

	test( 'does not request the occurrence list without a post id', () => {
		render( <ApplyScope postId={ 0 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		expect( mockApiFetch ).not.toHaveBeenCalled();
	} );

	test( 'falls back to an empty list when the occurrence fetch fails', async () => {
		mockApiFetch.mockRejectedValue( new Error( 'nope' ) );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalled() );
		expect( screen.getByLabelText( 'Split from' ).children ).toHaveLength(
			0,
		);
		expect( screen.getByText( 'Split series' ) ).toBeDisabled();
	} );

	test( 'copes with an occurrence response that carries no rows at all', async () => {
		mockApiFetch.mockResolvedValue( undefined );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalled() );
		expect( screen.getByLabelText( 'Split from' ).children ).toHaveLength(
			0,
		);
		expect( screen.getByText( 'Split series' ) ).toBeDisabled();
	} );

	test( 'splits at the chosen occurrence and reports what moved', async () => {
		mockApiFetch
			.mockResolvedValueOnce( rows )
			.mockResolvedValueOnce( {
				split: true,
				reason: '',
				moved: 4,
				forward_post_id: 99,
				forward_recurring: true,
			} );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toBeInTheDocument(),
		);

		fireEvent.change( screen.getByLabelText( 'Split from' ), {
			target: { value: '20260917T180000' },
		} );
		fireEvent.click( screen.getByText( 'Split series' ) );

		await waitFor( () =>
			expect(
				screen.getByText(
					'4 occurrences moved to a new event. Make your change there.',
				),
			).toBeInTheDocument(),
		);
		expect( mockApiFetch ).toHaveBeenLastCalledWith( {
			path: '/gatherpress/v1/event/split-series',
			method: 'POST',
			data: { post_id: 42, recurrence_id: '20260917T180000' },
		} );
	} );

	test( 'reports the plain-event degradation when one date moves', async () => {
		mockApiFetch
			.mockResolvedValueOnce( rows )
			.mockResolvedValueOnce( {
				split: true,
				reason: '',
				moved: 1,
				forward_post_id: 99,
				forward_recurring: false,
			} );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toBeInTheDocument(),
		);

		fireEvent.click( screen.getByText( 'Split series' ) );

		await waitFor( () =>
			expect(
				screen.getByText(
					'1 occurrence moved to a new event. Make your change there.' +
						' It holds a single date, so it is a plain non-recurring event.',
				),
			).toBeInTheDocument(),
		);
	} );

	test( 'reports the retroactive degradation from the first occurrence', async () => {
		mockApiFetch
			.mockResolvedValueOnce( rows )
			.mockResolvedValueOnce( {
				split: false,
				reason: 'first_occurrence',
				moved: 0,
				forward_post_id: 0,
				forward_recurring: false,
			} );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toBeInTheDocument(),
		);

		fireEvent.click( screen.getByText( 'Split series' ) );

		await waitFor( () =>
			expect(
				screen.getByText(
					'This is the first occurrence, so applying the change forward is the same as applying it to the whole series. Nothing was split.',
				),
			).toBeInTheDocument(),
		);
	} );

	test( 'surfaces a failed split rather than reporting success', async () => {
		mockApiFetch
			.mockResolvedValueOnce( rows )
			.mockRejectedValueOnce( new Error( 'nope' ) );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toBeInTheDocument(),
		);

		fireEvent.click( screen.getByText( 'Split series' ) );

		await waitFor( () =>
			expect(
				screen.getByText( 'Could not split this series.' ),
			).toBeInTheDocument(),
		);
	} );

	test( 'cannot split until an occurrence is chosen', async () => {
		mockApiFetch.mockResolvedValue( [] );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalled() );
		expect( screen.getByText( 'Split series' ) ).toBeDisabled();
	} );
} );
