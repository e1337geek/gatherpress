/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';

/**
 * One row in the occurrence list: its date, its status, and the
 * cancel/restore action.
 *
 * An organizer skips a holiday without touching the rest of the series. This
 * row is the per-occurrence surface that action lives on -- canceling writes
 * the occurrence's `status` column via the `occurrence-status` REST route; it
 * never touches the rule.
 *
 * @since 0.36.0
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.occurrence Occurrence row, as returned by the `occurrences` REST route.
 * @param {Function} props.onToggle   Called with the occurrence when the action button is pressed.
 * @param {boolean}  props.isUpdating Whether a status change for this row is in flight.
 *
 * @return {JSX.Element} The occurrence row.
 */
const OccurrenceRow = ( { occurrence, onToggle, isUpdating } ) => {
	const isCancelled = 'cancelled' === occurrence.status;

	return (
		<div className="gatherpress-occurrence-row">
			<span className="gatherpress-occurrence-row__date">
				{ dateI18n(
					getSettings().formats.datetime,
					occurrence.datetime_start,
				) }
			</span>
			<span className="gatherpress-occurrence-row__status">
				{ isCancelled
					? __( 'Canceled', 'gatherpress' )
					: __( 'Scheduled', 'gatherpress' ) }
			</span>
			<Button
				variant="secondary"
				size="small"
				isBusy={ isUpdating }
				disabled={ isUpdating }
				onClick={ () => onToggle( occurrence ) }
			>
				{ isCancelled
					? __( 'Restore', 'gatherpress' )
					: __( 'Cancel', 'gatherpress' ) }
			</Button>
		</div>
	);
};

export default OccurrenceRow;
