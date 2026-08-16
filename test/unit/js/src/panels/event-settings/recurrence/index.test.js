/**
 * External dependencies
 */
import { render, fireEvent, screen } from '@testing-library/react';
import { beforeEach, describe, expect, jest, test } from '@jest/globals';
import '@testing-library/jest-dom';

/**
 * WordPress dependencies
 */
jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelRow: ( { children } ) => <div>{ children }</div>,
	ToggleControl: ( { label, checked, disabled, onChange } ) => (
		<input
			aria-label={ label }
			type="checkbox"
			checked={ checked }
			disabled={ disabled }
			onChange={ ( event ) => onChange( event.target.checked ) }
		/>
	),
	CheckboxControl: ( { label, checked, onChange } ) => (
		<input
			aria-label={ label }
			type="checkbox"
			checked={ checked }
			onChange={ ( event ) => onChange( event.target.checked ) }
		/>
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
	TextControl: ( { label, value, onChange, type } ) => (
		<input
			aria-label={ label }
			type={ type }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	),
	__experimentalNumberControl: ( { label, value, onChange } ) => (
		<input
			aria-label={ label }
			type="number"
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	),
} ) );

jest.mock( '@src/helpers/event', () => ( {
	usePostTypeSupports: jest.fn( () => true ),
} ) );

/**
 * Internal dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { usePostTypeSupports } from '@src/helpers/event';
import RecurrencePanel from '@src/panels/event-settings/recurrence';

/**
 * Build a `select()` stand-in returning the given recurrence meta blob and
 * timezone, matching what `core/editor` and `gatherpress/datetime` expose.
 *
 * @param {string|undefined} recurrenceMeta Raw `gatherpress_recurrence` meta value.
 * @param {string}           timezone       Timezone value from the datetime store.
 *
 * @return {Function} A `select( storeName )` stand-in.
 */
function makeSelect( recurrenceMeta, timezone = 'America/New_York' ) {
	return ( storeName ) => {
		if ( 'core/editor' === storeName ) {
			return {
				getEditedPostAttribute: () => ( {
					gatherpress_recurrence: recurrenceMeta,
				} ),
			};
		}

		if ( 'gatherpress/datetime' === storeName ) {
			return { getTimezone: () => timezone };
		}

		return undefined;
	};
}

let editPost;

/**
 * Parse the recurrence blob from the last `editPost` call.
 *
 * @return {Object|string} The parsed blob, or '' when the call cleared it.
 */
function lastPersistedBlob() {
	const call = editPost.mock.calls.at( -1 );
	const raw = call[ 0 ].meta.gatherpress_recurrence;

	return '' === raw ? '' : JSON.parse( raw );
}

beforeEach( () => {
	jest.clearAllMocks();
	editPost = jest.fn();
	useDispatch.mockReturnValue( { editPost } );
	usePostTypeSupports.mockReturnValue( true );
	useSelect.mockImplementation( ( selector ) =>
		selector( makeSelect( undefined ) ),
	);
} );

describe( 'RecurrencePanel', () => {
	test( 'renders nothing when the post type does not support gatherpress-event-date', () => {
		usePostTypeSupports.mockReturnValue( false );

		const { container } = render( <RecurrencePanel /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	test( 'writes an empty blob when the repeat toggle is off', () => {
		render( <RecurrencePanel /> );

		expect( editPost ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );
		fireEvent.click( screen.getByLabelText( 'Repeat' ) );

		expect( lastPersistedBlob() ).toBe( '' );
	} );

	test( 'writes a sensible default blob when the repeat toggle is turned on', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );

		expect( lastPersistedBlob() ).toEqual( {
			frequency: 'daily',
			interval: 1,
			weekdays: [],
			monthly_mode: 'day_of_month',
			monthly_day: 1,
			monthly_ordinal: 1,
			monthly_weekday: 1,
			end_type: 'never',
			until: '',
			count: 0,
		} );
	} );

	test( 'round-trips a weekly interval-2 Tue/Thu rule ending on a date', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );
		fireEvent.change( screen.getByLabelText( 'Frequency' ), {
			target: { value: 'weekly' },
		} );
		fireEvent.change( screen.getByLabelText( 'Repeat every' ), {
			target: { value: '2' },
		} );
		fireEvent.click( screen.getByLabelText( 'Tuesday' ) );
		fireEvent.click( screen.getByLabelText( 'Thursday' ) );
		fireEvent.change( screen.getByLabelText( 'Ends' ), {
			target: { value: 'until' },
		} );
		fireEvent.change( screen.getByLabelText( 'End date' ), {
			target: { value: '2026-12-31' },
		} );

		expect( lastPersistedBlob() ).toEqual( {
			frequency: 'weekly',
			interval: 2,
			weekdays: [ 2, 4 ],
			monthly_mode: 'day_of_month',
			monthly_day: 1,
			monthly_ordinal: 1,
			monthly_weekday: 1,
			end_type: 'until',
			until: '2026-12-31',
			count: 0,
		} );
	} );

	test( 'round-trips a monthly day-of-month-15 rule', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );
		fireEvent.change( screen.getByLabelText( 'Frequency' ), {
			target: { value: 'monthly' },
		} );
		fireEvent.change( screen.getByLabelText( 'Day of the month' ), {
			target: { value: '15' },
		} );

		const blob = lastPersistedBlob();

		expect( blob.frequency ).toBe( 'monthly' );
		expect( blob.monthly_mode ).toBe( 'day_of_month' );
		expect( blob.monthly_day ).toBe( 15 );
		expect( blob.weekdays ).toEqual( [] );
	} );

	test( 'round-trips a monthly last-Wednesday rule', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );
		fireEvent.change( screen.getByLabelText( 'Frequency' ), {
			target: { value: 'monthly' },
		} );
		fireEvent.change( screen.getByLabelText( 'Repeat by' ), {
			target: { value: 'nth_weekday' },
		} );
		fireEvent.change( screen.getByLabelText( 'Week' ), {
			target: { value: '-1' },
		} );
		fireEvent.change( screen.getByLabelText( 'Day' ), {
			target: { value: '3' },
		} );

		const blob = lastPersistedBlob();

		expect( blob.frequency ).toBe( 'monthly' );
		expect( blob.monthly_mode ).toBe( 'nth_weekday' );
		expect( blob.monthly_ordinal ).toBe( -1 );
		expect( blob.monthly_weekday ).toBe( 3 );
	} );

	test( 'does not leak weekday selection into monthly, or back again', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );
		fireEvent.change( screen.getByLabelText( 'Frequency' ), {
			target: { value: 'weekly' },
		} );
		fireEvent.click( screen.getByLabelText( 'Tuesday' ) );
		fireEvent.click( screen.getByLabelText( 'Thursday' ) );

		expect( lastPersistedBlob().weekdays ).toEqual( [ 2, 4 ] );

		fireEvent.change( screen.getByLabelText( 'Frequency' ), {
			target: { value: 'monthly' },
		} );

		expect( lastPersistedBlob().weekdays ).toEqual( [] );

		fireEvent.change( screen.getByLabelText( 'Day of the month' ), {
			target: { value: '20' },
		} );

		fireEvent.change( screen.getByLabelText( 'Frequency' ), {
			target: { value: 'weekly' },
		} );

		const blob = lastPersistedBlob();

		expect( blob.weekdays ).toEqual( [] );
		expect( blob.monthly_day ).toBe( 1 );
	} );

	test( 'never persists both an end date and a count, either direction', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );
		fireEvent.change( screen.getByLabelText( 'Ends' ), {
			target: { value: 'until' },
		} );
		fireEvent.change( screen.getByLabelText( 'End date' ), {
			target: { value: '2026-06-15' },
		} );

		let blob = lastPersistedBlob();
		expect( blob.until ).toBe( '2026-06-15' );
		expect( blob.count ).toBe( 0 );

		fireEvent.change( screen.getByLabelText( 'Ends' ), {
			target: { value: 'count' },
		} );
		fireEvent.change( screen.getByLabelText( 'Number of occurrences' ), {
			target: { value: '10' },
		} );

		blob = lastPersistedBlob();
		expect( blob.count ).toBe( 10 );
		expect( blob.until ).toBe( '' );

		fireEvent.change( screen.getByLabelText( 'Ends' ), {
			target: { value: 'until' },
		} );

		blob = lastPersistedBlob();
		expect( blob.count ).toBe( 0 );
		expect( blob.until ).toBe( '' );

		fireEvent.change( screen.getByLabelText( 'End date' ), {
			target: { value: '2026-06-15' },
		} );
		fireEvent.change( screen.getByLabelText( 'Ends' ), {
			target: { value: 'never' },
		} );

		blob = lastPersistedBlob();
		expect( blob.end_type ).toBe( 'never' );
		expect( blob.count ).toBe( 0 );
		expect( blob.until ).toBe( '' );
	} );

	test( 'surfaces a validation message when weekly has no weekday selected', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );
		fireEvent.change( screen.getByLabelText( 'Frequency' ), {
			target: { value: 'weekly' },
		} );

		expect(
			screen.getByText( 'Select at least one day of the week.' ),
		).toBeInTheDocument();

		fireEvent.click( screen.getByLabelText( 'Monday' ) );

		expect(
			screen.queryByText( 'Select at least one day of the week.' ),
		).not.toBeInTheDocument();
	} );

	test( 'clamps interval 0 and negative input to 1', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );

		fireEvent.change( screen.getByLabelText( 'Repeat every' ), {
			target: { value: '0' },
		} );
		expect( lastPersistedBlob().interval ).toBe( 1 );

		fireEvent.change( screen.getByLabelText( 'Repeat every' ), {
			target: { value: '-5' },
		} );
		expect( lastPersistedBlob().interval ).toBe( 1 );
	} );

	test( 'clamps interval 53 to 52', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );

		fireEvent.change( screen.getByLabelText( 'Repeat every' ), {
			target: { value: '53' },
		} );
		expect( lastPersistedBlob().interval ).toBe( 52 );
	} );

	test( 'clamps count 731 to 730', () => {
		render( <RecurrencePanel /> );

		fireEvent.click( screen.getByLabelText( 'Repeat' ) );
		fireEvent.change( screen.getByLabelText( 'Ends' ), {
			target: { value: 'count' },
		} );
		fireEvent.change( screen.getByLabelText( 'Number of occurrences' ), {
			target: { value: '731' },
		} );

		expect( lastPersistedBlob().count ).toBe( 730 );
	} );

	test( 'refuses recurrence and shows a message on a fixed-offset timezone', () => {
		useSelect.mockImplementation( ( selector ) =>
			selector( makeSelect( undefined, 'UTC+5:30' ) ),
		);

		render( <RecurrencePanel /> );

		expect(
			screen.getByText(
				'Recurring events require a named timezone (e.g. America/New_York) rather than a fixed UTC offset. Change the timezone in the Date & Time panel to enable repeat.',
			),
		).toBeInTheDocument();

		const toggle = screen.getByLabelText( 'Repeat' );
		expect( toggle ).toBeDisabled();
	} );

	test( 'keeps refusing edits when recurrence was already enabled and the timezone becomes fixed-offset', () => {
		const existingBlob = JSON.stringify( {
			frequency: 'daily',
			interval: 1,
			weekdays: [],
			monthly_mode: 'day_of_month',
			monthly_day: 1,
			monthly_ordinal: 1,
			monthly_weekday: 1,
			end_type: 'never',
			until: '',
			count: 0,
		} );

		useSelect.mockImplementation( ( selector ) =>
			selector( makeSelect( existingBlob, 'UTC-8:00' ) ),
		);

		render( <RecurrencePanel /> );

		expect( screen.getByLabelText( 'Repeat' ) ).toBeDisabled();
		expect(
			screen.queryByLabelText( 'Frequency' ),
		).not.toBeInTheDocument();
	} );

	test( 'treats a missing timezone as not fixed-offset', () => {
		useSelect.mockImplementation( ( selector ) =>
			selector( makeSelect( undefined, undefined ) ),
		);

		render( <RecurrencePanel /> );

		expect( screen.getByLabelText( 'Repeat' ) ).not.toBeDisabled();
	} );

	test( 'falls back to the default, disabled state when the meta is malformed JSON', () => {
		useSelect.mockImplementation( ( selector ) =>
			selector( makeSelect( '{not valid json' ) ),
		);

		render( <RecurrencePanel /> );

		expect( screen.getByLabelText( 'Repeat' ) ).not.toBeChecked();
		expect( screen.queryByLabelText( 'Frequency' ) ).not.toBeInTheDocument();
	} );

	test( 'falls back to the default, disabled state when the meta decodes to a non-object', () => {
		useSelect.mockImplementation( ( selector ) =>
			selector( makeSelect( '"just a string"' ) ),
		);

		render( <RecurrencePanel /> );

		expect( screen.getByLabelText( 'Repeat' ) ).not.toBeChecked();
	} );

	test( 'restores a previously stored, valid recurrence blob as enabled', () => {
		const existingBlob = JSON.stringify( {
			frequency: 'weekly',
			interval: 3,
			weekdays: [ 1, 5 ],
			monthly_mode: 'day_of_month',
			monthly_day: 1,
			monthly_ordinal: 1,
			monthly_weekday: 1,
			end_type: 'never',
			until: '',
			count: 0,
		} );

		useSelect.mockImplementation( ( selector ) =>
			selector( makeSelect( existingBlob ) ),
		);

		render( <RecurrencePanel /> );

		expect( screen.getByLabelText( 'Repeat' ) ).toBeChecked();
		expect( screen.getByLabelText( 'Frequency' ) ).toHaveValue( 'weekly' );
		expect( screen.getByLabelText( 'Repeat every' ) ).toHaveValue( 3 );
	} );
} );
