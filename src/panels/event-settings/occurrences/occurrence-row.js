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
 * row is the per-occurrence surface that action lives on. Canceling writes
 * the occurrence's `status` column via the `occurrence-status` REST route, and
 * never touches the rule.
 *
 * The date is formatted from the row's GMT instant in the row's own named
 * timezone, never from the timezone-free local `datetime_start`. A bare
 * local string handed to `dateI18n()` is parsed in the browser timezone and
 * then converted to the site timezone, so an 18:00 New York occurrence can
 * print as 11:00 for a traveling organizer. Converting the MySQL GMT value
 * to ISO UTC pins the instant, and the `timezone` column names the clock to
 * render it on; the column is nullable, and an empty value falls back to
 * `dateI18n()`'s own default, the site timezone.
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
	const startUtc = `${ occurrence.datetime_start_gmt.replace( ' ', 'T' ) }Z`;

	return (
		<div className="gatherpress-occurrence-row">
			<span className="gatherpress-occurrence-row__date">
				{ dateI18n(
					getSettings().formats.datetime,
					startUtc,
					occurrence.timezone || undefined,
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
