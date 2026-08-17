/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, RadioControl, SelectControl } from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { EVENT_REST_API } from '../../../helpers/namespace';

/**
 * The chooser REQ-13 describes, with retroactive as the default.
 *
 * PRD D-2 replaced ECP's three-scope model (all / this-and-following / single)
 * with two options and made retroactive the default, because most edits are
 * corrections rather than schedule changes and every forward edit costs a second
 * event post. "This occurrence only" is not a top-level scope here; it is a
 * per-occurrence operation and is Post-MVP (REQ-13a).
 *
 * Retroactive needs no action at all — it is what an ordinary save already does
 * — so this component only ever calls the server for the forward choice, and a
 * panel left on its default performs no request.
 *
 * @since 0.36.0
 *
 * @param {Object} props        Component props.
 * @param {number} props.postId Post being edited.
 *
 * @return {JSX.Element} The apply-scope chooser.
 */
const ApplyScope = ( { postId } ) => {
	const [ scope, setScope ] = useState( 'retroactive' );
	const [ occurrences, setOccurrences ] = useState( [] );
	const [ splitFrom, setSplitFrom ] = useState( '' );
	const [ isSplitting, setIsSplitting ] = useState( false );
	const [ notice, setNotice ] = useState( '' );

	useEffect( () => {
		if ( 'forward' !== scope || ! postId ) {
			return;
		}

		apiFetch( {
			path: `${ EVENT_REST_API }/occurrences?post_id=${ postId }`,
		} )
			.then( ( rows ) => {
				setOccurrences( rows ?? [] );
				setSplitFrom( rows?.[ 0 ]?.recurrence_id ?? '' );
			} )
			.catch( () => setOccurrences( [] ) );
	}, [ scope, postId ] );

	/**
	 * Describe what a completed split did, including the two automatic
	 * degradations REQ-13 requires the organizer to be told about rather than
	 * left to discover.
	 *
	 * @param {Object} result Split result from the `split-series` REST route.
	 *
	 * @return {string} Message to show.
	 */
	const describe = ( result ) => {
		if ( ! result.split ) {
			return __(
				'This is the first occurrence, so applying the change forward is the same as applying it to the whole series. Nothing was split.',
				'gatherpress'
			);
		}

		const moved = sprintf(
			/* translators: %d: number of occurrences moved onto the new event. */
			_n(
				'%d occurrence moved to a new event. Make your change there.',
				'%d occurrences moved to a new event. Make your change there.',
				result.moved,
				'gatherpress'
			),
			result.moved
		);

		if ( result.forward_recurring ) {
			return moved;
		}

		return `${ moved } ${ __(
			'It holds a single date, so it is a plain non-recurring event.',
			'gatherpress'
		) }`;
	};

	/**
	 * Split the series at the chosen occurrence.
	 *
	 * @return {void}
	 */
	const handleSplit = () => {
		setIsSplitting( true );
		setNotice( '' );

		apiFetch( {
			path: `${ EVENT_REST_API }/split-series`,
			method: 'POST',
			data: { post_id: postId, recurrence_id: splitFrom },
		} )
			.then( ( result ) => setNotice( describe( result ) ) )
			.catch( () =>
				setNotice(
					__( 'Could not split this series.', 'gatherpress' )
				)
			)
			.finally( () => setIsSplitting( false ) );
	};

	return (
		<div className="gatherpress-recurrence-panel__scope">
			<RadioControl
				label={ __( 'Applying changes', 'gatherpress' ) }
				selected={ scope }
				options={ [
					{
						label: __( 'Apply retroactively', 'gatherpress' ),
						value: 'retroactive',
					},
					{
						label: __( 'Apply going forward', 'gatherpress' ),
						value: 'forward',
					},
				] }
				onChange={ setScope }
			/>
			{ 'forward' === scope && (
				<>
					<SelectControl
						label={ __( 'Split from', 'gatherpress' ) }
						value={ splitFrom }
						options={ occurrences.map( ( occurrence ) => ( {
							label: dateI18n(
								getSettings().formats.datetime,
								occurrence.datetime_start
							),
							value: occurrence.recurrence_id,
						} ) ) }
						onChange={ setSplitFrom }
					/>
					<Button
						variant="secondary"
						isBusy={ isSplitting }
						disabled={ isSplitting || ! splitFrom }
						onClick={ handleSplit }
					>
						{ __( 'Split series', 'gatherpress' ) }
					</Button>
				</>
			) }
			{ '' !== notice && <output>{ notice }</output> }
		</div>
	);
};

export default ApplyScope;
