/**
 * WordPress dependencies
 */
import { _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { EVENT_REST_API } from '../../../helpers/namespace';

/**
 * Tell the organizer how many RSVPs a rule change would strand.
 *
 * REQ-13's last acceptance criterion, and brief §6 Q12's reasoning for it: when
 * a rule change would move or remove occurrences carrying RSVPs, the organizer
 * is shown how many are affected and the RSVPs are **not** migrated. Migrating
 * them risks re-enrolling attendees in a date they never agreed to, so the
 * product answer is to surface the number rather than to quietly do something
 * with it.
 *
 * The count comes from the read-only `recurrence-impact` route, which compares
 * the candidate rule against the occurrences already projected. Nothing is
 * written, so an organizer who thinks better of the change and reverts it has
 * lost nothing.
 *
 * @since 0.36.0
 *
 * @param {Object} props        Component props.
 * @param {number} props.postId Post being edited.
 * @param {Object} props.rule   Candidate rule values.
 *
 * @return {JSX.Element|null} The notice, or null when nothing would be stranded.
 */
const RsvpImpact = ( { postId, rule } ) => {
	const [ stranded, setStranded ] = useState( 0 );
	const serialized = JSON.stringify( rule );

	useEffect( () => {
		if ( ! postId ) {
			setStranded( 0 );

			return;
		}

		apiFetch( {
			path:
				`${ EVENT_REST_API }/recurrence-impact?post_id=${ postId }` +
				`&recurrence=${ encodeURIComponent( serialized ) }`,
		} )
			.then( ( impact ) => setStranded( impact?.rsvp_count ?? 0 ) )
			.catch( () => setStranded( 0 ) );
	}, [ postId, serialized ] );

	if ( 0 === stranded ) {
		return null;
	}

	return (
		<output className="gatherpress-recurrence-panel__impact">
			{ sprintf(
				/* translators: %d: number of RSVPs on dates this change removes. */
				_n(
					'%d RSVP is on a date this change removes. It stays where it is and is not moved to another date.',
					'%d RSVPs are on dates this change removes. They stay where they are and are not moved to other dates.',
					stranded,
					'gatherpress'
				),
				stranded
			) }
		</output>
	);
};

export default RsvpImpact;
