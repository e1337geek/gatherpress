/**
 * External dependencies
 */
import {
	act,
	render,
	fireEvent,
	screen,
	waitFor,
} from '@testing-library/react';
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

const mockIsEditedPostDirty = jest.fn( () => false );

jest.mock( '@wordpress/data', () => ( {
	useSelect: ( callback ) =>
		callback( () => ( {
			isEditedPostDirty: mockIsEditedPostDirty,
		} ) ),
} ) );

jest.mock( '@wordpress/url', () => ( {
	addQueryArgs: ( path, args ) =>
		`${ path }?${ Object.entries( args )
			.map( ( [ key, value ] ) => `${ key }=${ value }` )
			.join( '&' ) }`,
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
	mockIsEditedPostDirty.mockReturnValue( false );
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
				'20260917T180000',
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
	test( 'defaults past the first listed occurrence so the first click is never a guaranteed no-op', async () => {
		mockApiFetch.mockResolvedValue( rows );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		// rows[ 0 ] is the series' first date whenever the series has not
		// started, and splitting there degrades to "Nothing was split" -- so it
		// is never the default.
		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
				'20260917T180000',
			),
		);
		expect(
			screen.queryByText(
				'If this is the series\u2019 first date, splitting here applies the change to the whole series.',
			),
		).not.toBeInTheDocument();
	} );

	test( 'explains the degradation before the click when only one occurrence is listed', async () => {
		mockApiFetch.mockResolvedValue( [ rows[ 0 ] ] );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
				'20260903T180000',
			),
		);
		expect(
			screen.getByText(
				'If this is the series\u2019 first date, splitting here applies the change to the whole series.',
			),
		).toBeInTheDocument();
	} );

	test( 'refuses to split while the editor holds unsaved changes', async () => {
		mockIsEditedPostDirty.mockReturnValue( true );
		mockApiFetch.mockResolvedValue( rows );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toBeInTheDocument(),
		);

		expect( screen.getByText( 'Split series' ) ).toBeDisabled();
		expect(
			screen.getByText(
				'Save or discard your current changes before splitting.',
			),
		).toBeInTheDocument();
	} );

	test( 'allows the split once the editor is clean', async () => {
		mockApiFetch.mockResolvedValue( rows );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toBeInTheDocument(),
		);

		expect( screen.getByText( 'Split series' ) ).not.toBeDisabled();
		expect(
			screen.queryByText(
				'Save or discard your current changes before splitting.',
			),
		).not.toBeInTheDocument();
	} );

	test( 'links to the forward event once a split completes', async () => {
		mockApiFetch.mockResolvedValueOnce( rows ).mockResolvedValueOnce( {
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

		fireEvent.click( screen.getByText( 'Split series' ) );

		await waitFor( () =>
			expect( screen.getByText( 'Edit the new event' ) ).toHaveAttribute(
				'href',
				'post.php?post=99&action=edit',
			),
		);
	} );

	test( 'offers no forward link when nothing was split', async () => {
		mockApiFetch.mockResolvedValueOnce( rows ).mockResolvedValueOnce( {
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
		expect(
			screen.queryByText( 'Edit the new event' ),
		).not.toBeInTheDocument();
	} );
	test( 'offers no forward link when the response carries no forward post id', async () => {
		mockApiFetch.mockResolvedValueOnce( rows ).mockResolvedValueOnce( {
			split: true,
			reason: '',
			moved: 4,
			forward_recurring: true,
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
					'4 occurrences moved to a new event. Make your change there.',
				),
			).toBeInTheDocument(),
		);
		expect(
			screen.queryByText( 'Edit the new event' ),
		).not.toBeInTheDocument();
	} );

	test( 'clears every post-scoped piece of state when the post changes', async () => {
		// The panel survives navigation from event A to event B. Until B's list
		// arrives, A's occurrences, A's split notice and A's "Edit the new
		// event" link would otherwise stay on screen and stay actionable, and
		// the enabled button would submit B's post with A's occurrence.
		mockApiFetch.mockResolvedValueOnce( rows );

		const { rerender } = render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
				'20260917T180000',
			),
		);

		mockApiFetch.mockResolvedValueOnce( {
			split: true,
			moved: 1,
			forward_post_id: 77,
			forward_recurring: true,
		} );

		fireEvent.click( screen.getByText( 'Split series' ) );

		await waitFor( () =>
			expect( screen.getByText( 'Edit the new event' ) ).toBeInTheDocument(),
		);

		let resolveNext;

		mockApiFetch.mockReturnValueOnce(
			new Promise( ( resolve ) => {
				resolveNext = resolve;
			} ),
		);

		rerender( <ApplyScope postId={ 99 } /> );

		expect( screen.queryByText( 'Edit the new event' ) ).not.toBeInTheDocument();
		expect(
			screen.queryByText(
				'1 occurrence moved to a new event. Make your change there.',
			),
		).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Split from' ).children ).toHaveLength( 0 );
		expect( screen.getByText( 'Split series' ) ).toBeDisabled();

		await act( async () => {
			resolveNext( [] );
		} );
	} );

	test( 'ignores an occurrence list that arrives for a post it has left', async () => {
		let resolveStale;

		mockApiFetch.mockReturnValueOnce(
			new Promise( ( resolve ) => {
				resolveStale = resolve;
			} ),
		);

		const { rerender } = render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		mockApiFetch.mockResolvedValueOnce( [] );

		rerender( <ApplyScope postId={ 99 } /> );

		await act( async () => {
			resolveStale( rows );
		} );

		expect( screen.getByLabelText( 'Split from' ).children ).toHaveLength( 0 );
	} );

	test( 'ignores a stale occurrence rejection for a post it has left', async () => {
		let rejectStale;

		const stale = new Promise( ( resolve, reject ) => {
			rejectStale = reject;
		} );

		mockApiFetch.mockReturnValueOnce( stale );

		const { rerender } = render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		mockApiFetch.mockResolvedValueOnce( rows );

		rerender( <ApplyScope postId={ 99 } /> );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
				'20260917T180000',
			),
		);

		await act( async () => {
			rejectStale( new Error( 'nope' ) );
			await stale.catch( () => {} );
		} );

		expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
			'20260917T180000',
		);
	} );

	test( 'ignores a split result that arrives for a post it has left', async () => {
		mockApiFetch.mockResolvedValueOnce( rows );

		const { rerender } = render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
				'20260917T180000',
			),
		);

		let resolveSplit;

		mockApiFetch.mockReturnValueOnce(
			new Promise( ( resolve ) => {
				resolveSplit = resolve;
			} ),
		);

		fireEvent.click( screen.getByText( 'Split series' ) );

		mockApiFetch.mockResolvedValueOnce( [] );

		rerender( <ApplyScope postId={ 99 } /> );

		await act( async () => {
			resolveSplit( {
				split: true,
				moved: 4,
				forward_post_id: 77,
				forward_recurring: true,
			} );
		} );

		expect( screen.queryByText( 'Edit the new event' ) ).not.toBeInTheDocument();
	} );

	test( 'submits the post that owns the chosen occurrence, not the post being edited', async () => {
		// A series already split once holds later occurrences on a sibling post.
		// Submitting the post open in the editor would ask the route to split a
		// post that does not own the chosen date.
		mockApiFetch.mockResolvedValueOnce( [
			occurrence( '20260903T180000', '2026-09-03 18:00:00' ),
			{
				...occurrence( '20260917T180000', '2026-09-17 18:00:00' ),
				series_post_id: 815,
			},
		] );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
				'20260917T180000',
			),
		);

		mockApiFetch.mockResolvedValueOnce( {
			split: true,
			moved: 1,
			forward_post_id: 77,
			forward_recurring: true,
		} );

		fireEvent.click( screen.getByText( 'Split series' ) );

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledWith( {
				path: '/gatherpress/v1/event/split-series',
				method: 'POST',
				data: { post_id: 815, recurrence_id: '20260917T180000' },
			} ),
		);
	} );

	test( 'falls back to the post being edited when the row names no owner', async () => {
		// A row from an older response, or from a filter that widened the series
		// without stamping an owner. The post open in the editor is the only
		// other answer available, and the route resolves the real owner anyway.
		mockApiFetch.mockResolvedValueOnce( [
			{
				recurrence_id: '20260903T180000',
				datetime_start: '2026-09-03 18:00:00',
				status: 'scheduled',
			},
		] );

		render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
				'20260903T180000',
			),
		);

		mockApiFetch.mockResolvedValueOnce( {
			split: false,
			moved: 0,
			forward_post_id: 0,
		} );

		fireEvent.click( screen.getByText( 'Split series' ) );

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledWith( {
				path: '/gatherpress/v1/event/split-series',
				method: 'POST',
				data: { post_id: 42, recurrence_id: '20260903T180000' },
			} ),
		);
	} );

	test( 'ignores a failed split that lands after the post has changed', async () => {
		mockApiFetch.mockResolvedValueOnce( rows );

		const { rerender } = render( <ApplyScope postId={ 42 } /> );

		fireEvent.change( screen.getByLabelText( 'Applying changes' ), {
			target: { value: 'forward' },
		} );

		await waitFor( () =>
			expect( screen.getByLabelText( 'Split from' ) ).toHaveValue(
				'20260917T180000',
			),
		);

		let rejectSplit;

		const pending = new Promise( ( resolve, reject ) => {
			rejectSplit = reject;
		} );

		mockApiFetch.mockReturnValueOnce( pending );

		fireEvent.click( screen.getByText( 'Split series' ) );

		mockApiFetch.mockResolvedValueOnce( [] );

		rerender( <ApplyScope postId={ 99 } /> );

		await act( async () => {
			rejectSplit( new Error( 'nope' ) );
			await pending.catch( () => {} );
		} );

		expect(
			screen.queryByText( 'Could not split this series.' ),
		).not.toBeInTheDocument();
	} );
} );
