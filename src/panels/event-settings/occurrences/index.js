/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { PanelRow } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { usePostTypeSupports } from '../../../helpers/event';
import { EVENT_REST_API } from '../../../helpers/namespace';
import OccurrenceRow from './occurrence-row';

/**
 * Sidebar list of a series' upcoming occurrences, each with a cancel/restore
 * action.
 *
 * REQ-12: an organizer skips a holiday without touching the rest of the
 * series. The front-end drop of a cancelled occurrence is already handled by
 * the occurrence-aware query filter (`Recurrence\Query::expand_event_clauses()`),
 * so this panel's only job is to read the list and flip one row's status via
 * the `occurrence-status` REST route -- it never reaches into the recurrence
 * rule itself (PRD C-5).
 *
 * Renders nothing for a non-recurring event: the `occurrences` REST route
 * returns an empty list, and an empty list has no action worth surfacing.
 *
 * @since 0.36.0
 *
 * @return {JSX.Element|null} The occurrences panel, or null when the current
 *                             post type does not support `gatherpress-event-date`,
 *                             or the post has no upcoming occurrences.
 */
const OccurrencesPanel = () => {
	const isEventDateSupported = usePostTypeSupports( 'gatherpress-event-date' );
	const { createErrorNotice } = useDispatch( 'core/notices' );

	const postId = useSelect(
		( select ) => select( 'core/editor' )?.getCurrentPostId(),
		[],
	);

	const [ occurrences, setOccurrences ] = useState( [] );
	const [ updatingId, setUpdatingId ] = useState( null );

	useEffect( () => {
		if ( ! isEventDateSupported || ! postId ) {
			return;
		}

		apiFetch( {
			path: `${ EVENT_REST_API }/occurrences?post_id=${ postId }`,
		} )
			.then( ( result ) => setOccurrences( result ?? [] ) )
			.catch( () => setOccurrences( [] ) );
	}, [ isEventDateSupported, postId ] );

	/**
	 * Flip one occurrence between scheduled and cancelled.
	 *
	 * @param {Object} occurrence Occurrence row to toggle.
	 *
	 * @return {void}
	 */
	const handleToggle = ( occurrence ) => {
		const nextStatus =
			'cancelled' === occurrence.status ? 'scheduled' : 'cancelled';

		setUpdatingId( occurrence.recurrence_id );

		apiFetch( {
			path: `${ EVENT_REST_API }/occurrence-status`,
			method: 'POST',
			data: {
				post_id: postId,
				recurrence_id: occurrence.recurrence_id,
				status: nextStatus,
			},
		} )
			.then( ( updated ) => {
				setOccurrences( ( current ) =>
					current.map( ( entry ) =>
						entry.recurrence_id === occurrence.recurrence_id
							? { ...entry, status: updated.status }
							: entry,
					),
				);
			} )
			.catch( () => {
				createErrorNotice(
					__(
						'Could not update the occurrence status.',
						'gatherpress',
					),
					{ type: 'snackbar' },
				);
			} )
			.finally( () => setUpdatingId( null ) );
	};

	if ( ! isEventDateSupported || 0 === occurrences.length ) {
		return null;
	}

	return (
		<PanelRow>
			<div className="gatherpress-occurrences-panel">
				<h3>{ __( 'Occurrences', 'gatherpress' ) }</h3>
				{ occurrences.map( ( occurrence ) => (
					<OccurrenceRow
						key={ occurrence.recurrence_id }
						occurrence={ occurrence }
						onToggle={ handleToggle }
						isUpdating={ updatingId === occurrence.recurrence_id }
					/>
				) ) }
			</div>
		</PanelRow>
	);
};

export default OccurrencesPanel;
