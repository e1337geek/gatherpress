/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, PanelRow } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { usePostTypeSupports } from '../../../helpers/event';
import { EVENT_REST_API } from '../../../helpers/namespace';
import OccurrenceRow from './occurrence-row';

/**
 * Build the composite identity of an occurrence row.
 *
 * An occurrence is identified by `(series_post_id, recurrence_id)`, the
 * occurrence table's composite primary key, and never by either half alone.
 * The list route deliberately returns every sibling post's rows
 * (`Series::resolve_post_ids()`), so two rows can share a `recurrence_id`
 * while belonging to different posts: keying on `recurrence_id` alone gives
 * them the same React key, the same busy state, and makes a status update to
 * one flip both.
 *
 * @since 0.36.0
 *
 * @param {Object} occurrence Occurrence row from the `occurrences` REST route.
 *
 * @return {string} The row's composite key.
 */
function occurrenceKey( occurrence ) {
	return `${ occurrence.series_post_id }:${ occurrence.recurrence_id }`;
}

/**
 * Coerce the REST payload into rows with a numeric owner post ID.
 *
 * `$wpdb->get_results( …, ARRAY_A )` stringifies every column, so
 * `series_post_id` can arrive as `"84"`. Normalizing once on load keeps every
 * downstream identity comparison a plain `===` rather than a per-call-site
 * cast, and turns a non-array payload into the empty list the panel renders
 * as "nothing to show".
 *
 * @since 0.36.0
 *
 * @param {*} rows Raw REST payload.
 *
 * @return {Object[]} Occurrence rows with a numeric `series_post_id`.
 */
function normalizeOccurrences( rows ) {
	if ( ! Array.isArray( rows ) ) {
		return [];
	}

	return rows.map( ( row ) => ( {
		...row,
		series_post_id: Number( row.series_post_id ),
	} ) );
}

/**
 * Sidebar list of a series' upcoming occurrences, each with a cancel/restore
 * action.
 *
 * An organizer skips a holiday without touching the rest of the
 * series. The front-end drop of a canceled occurrence is already handled by
 * the occurrence-aware query filter (`Recurrence\Query::expand_event_clauses()`),
 * so this panel's only job is to read the list and flip one row's status via
 * the `occurrence-status` REST route. It never reaches into the recurrence
 * rule itself.
 *
 * Every request the panel issues carries the row's own composite identity:
 * the GET is scoped to the edited post, but the status write submits
 * `occurrence.series_post_id`, which is the sibling that actually owns the
 * row, not the post currently open in the editor.
 *
 * Renders nothing for a non-recurring event: the `occurrences` REST route
 * returns an empty list, and an empty list has no action worth surfacing. A
 * *failed* load is not an empty list, and is surfaced with a retry action
 * rather than silently collapsing the panel.
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
	const [ updatingKey, setUpdatingKey ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ reloadCount, setReloadCount ] = useState( 0 );

	useEffect( () => {
		if ( ! isEventDateSupported || ! postId ) {
			return;
		}

		apiFetch( {
			path: `${ EVENT_REST_API }/occurrences?post_id=${ postId }`,
		} )
			.then( ( result ) => {
				setOccurrences( normalizeOccurrences( result ) );
				setLoadError( null );
			} )
			.catch( ( error ) => {
				setOccurrences( [] );
				setLoadError(
					error?.message ||
						__(
							'Could not load the occurrences for this event.',
							'gatherpress',
						),
				);
			} );
	}, [ isEventDateSupported, postId, reloadCount ] );

	/**
	 * Flip one occurrence between scheduled and canceled.
	 *
	 * @param {Object} occurrence Occurrence row to toggle.
	 *
	 * @return {void}
	 */
	const handleToggle = ( occurrence ) => {
		const nextStatus =
			'cancelled' === occurrence.status ? 'scheduled' : 'cancelled';

		setUpdatingKey( occurrenceKey( occurrence ) );

		apiFetch( {
			path: `${ EVENT_REST_API }/occurrence-status`,
			method: 'POST',
			data: {
				post_id: occurrence.series_post_id,
				recurrence_id: occurrence.recurrence_id,
				status: nextStatus,
			},
		} )
			.then( ( updated ) => {
				setOccurrences( ( current ) =>
					current.map( ( entry ) =>
						occurrenceKey( entry ) === occurrenceKey( occurrence )
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
			.finally( () => setUpdatingKey( null ) );
	};

	if ( ! isEventDateSupported ) {
		return null;
	}

	if ( loadError ) {
		return (
			<PanelRow>
				<div className="gatherpress-occurrences-panel">
					<h3>{ __( 'Occurrences', 'gatherpress' ) }</h3>
					<output className="gatherpress-occurrences-panel__error">
						{ loadError }
					</output>
					<Button
						variant="secondary"
						size="small"
						onClick={ () =>
							setReloadCount( ( current ) => current + 1 )
						}
					>
						{ __( 'Retry', 'gatherpress' ) }
					</Button>
				</div>
			</PanelRow>
		);
	}

	if ( 0 === occurrences.length ) {
		return null;
	}

	return (
		<PanelRow>
			<div className="gatherpress-occurrences-panel">
				<h3>{ __( 'Occurrences', 'gatherpress' ) }</h3>
				{ occurrences.map( ( occurrence ) => (
					<OccurrenceRow
						key={ occurrenceKey( occurrence ) }
						occurrence={ occurrence }
						onToggle={ handleToggle }
						isUpdating={
							updatingKey === occurrenceKey( occurrence )
						}
					/>
				) ) }
			</div>
		</PanelRow>
	);
};

export default OccurrencesPanel;
