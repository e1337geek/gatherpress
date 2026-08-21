/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, RadioControl, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { dateI18n, getSettings } from '@wordpress/date';
import { useEffect, useRef, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { EVENT_REST_API } from '../../../helpers/namespace';

/**
 * The apply-scope chooser, with retroactive as the default.
 *
 * ECP's three-scope model (all / this-and-following / single) is reduced to
 * two options with retroactive as the default, because most edits are
 * corrections rather than schedule changes and every forward edit costs a second
 * event post. "This occurrence only" is not a top-level scope here; it is a
 * per-occurrence operation and is Post-MVP.
 *
 * Retroactive needs no action at all, since it is what an ordinary save
 * already does. This component therefore only ever calls the server for the
 * forward choice, and a panel left on its default performs no request.
 *
 * **The split is refused while the editor holds unsaved changes**, and that is
 * not a nicety. A split moves the forward occurrences onto a *second* post; an
 * edit still sitting in the editor belongs to the origin, so pressing Update
 * after the split writes it to the occurrences that stayed behind, the past.
 * That is the exact inverse of what a forward edit promises: moving the venue
 * in October does not rewrite where we met in March. Carrying an in-flight edit through a
 * split would need the pre-edit state captured before the split runs, which is
 * fragile; refusing to split until the editor is clean costs the organizer one
 * save and cannot strand a change on the wrong side.
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
	const [ forwardPostId, setForwardPostId ] = useState( 0 );

	const isDirty = useSelect(
		( select ) => select( 'core/editor' ).isEditedPostDirty(),
		[]
	);

	const latestRequest = useRef( 0 );

	useEffect( () => {
		// Everything below is scoped to one post, and this component survives
		// navigation from event A to event B. Clearing first means B's panel
		// cannot offer A's occurrences, A's notice or A's "Edit the new event"
		// link while B's request is still in flight. A split fired from that
		// state would submit B's post with A's occurrence.
		latestRequest.current += 1;

		const generation = latestRequest.current;
		const isCurrent = () => generation === latestRequest.current;

		setOccurrences( [] );
		setSplitFrom( '' );
		setNotice( '' );
		setForwardPostId( 0 );
		setIsSplitting( false );

		if ( 'forward' !== scope || ! postId ) {
			return;
		}

		apiFetch( {
			path: `${ EVENT_REST_API }/occurrences?post_id=${ postId }`,
		} )
			.then( ( rows ) => {
				if ( ! isCurrent() ) {
					return;
				}

				setOccurrences( rows ?? [] );
				// The route lists upcoming occurrences only, so the first row is
				// the series' own first date whenever the series has not started
				// and splitting there degrades to "Nothing was split". It is
				// the one selection that can be a guaranteed no-op, so the
				// default steps past it whenever there is somewhere to step to.
				setSplitFrom(
					rows?.[ 1 ]?.recurrence_id ??
						rows?.[ 0 ]?.recurrence_id ??
						''
				);
			} )
			.catch( () => {
				if ( isCurrent() ) {
					setOccurrences( [] );
				}
			} );
	}, [ scope, postId ] );

	/**
	 * Describe what a completed split did, including the two automatic
	 * degradations the organizer must be told about rather than left to
	 * discover.
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
		const generation = latestRequest.current;
		const isCurrent = () => generation === latestRequest.current;
		// The row's own owner, not the post open in the editor. A series split
		// once already holds later occurrences on a sibling post, and the split
		// has to cap the rule that actually produces the chosen date.
		const owner =
			occurrences.find(
				( occurrence ) => occurrence.recurrence_id === splitFrom
			)?.series_post_id ?? postId;

		setIsSplitting( true );
		setNotice( '' );
		setForwardPostId( 0 );

		apiFetch( {
			path: `${ EVENT_REST_API }/split-series`,
			method: 'POST',
			data: { post_id: owner, recurrence_id: splitFrom },
		} )
			.then( ( result ) => {
				if ( ! isCurrent() ) {
					return;
				}

				setNotice( describe( result ) );
				setForwardPostId( result.forward_post_id ?? 0 );
			} )
			.catch( () => {
				if ( isCurrent() ) {
					setNotice(
						__( 'Could not split this series.', 'gatherpress' )
					);
				}
			} )
			.finally( () => {
				if ( isCurrent() ) {
					setIsSplitting( false );
				}
			} );
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
					{ splitFrom === occurrences[ 0 ]?.recurrence_id && (
						<p>
							{ __(
								'If this is the series’ first date, splitting here applies the change to the whole series.',
								'gatherpress'
							) }
						</p>
					) }
					{ isDirty && (
						<p>
							{ __(
								'Save or discard your current changes before splitting.',
								'gatherpress'
							) }
						</p>
					) }
					<Button
						variant="secondary"
						isBusy={ isSplitting }
						disabled={ isSplitting || ! splitFrom || isDirty }
						onClick={ handleSplit }
					>
						{ __( 'Split series', 'gatherpress' ) }
					</Button>
				</>
			) }
			{ '' !== notice && <output>{ notice }</output> }
			{ 0 < forwardPostId && (
				<a
					href={ addQueryArgs( 'post.php', {
						post: forwardPostId,
						action: 'edit',
					} ) }
				>
					{ __( 'Edit the new event', 'gatherpress' ) }
				</a>
			) }
		</div>
	);
};

export default ApplyScope;
