/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { PanelRow, ToggleControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { usePostTypeSupports } from '../../../helpers/event';
import FrequencyControl from './frequency';
import WeekdaysControl from './weekdays';
import MonthlyControl from './monthly';
import EndConditionControl from './end-condition';

/**
 * Default recurrence rule, written to the `gatherpress_recurrence` blob the
 * moment the "Repeat" toggle is switched on, and used to fill any field
 * missing from a stored or malformed blob.
 *
 * @since 0.36.0
 * @type {Object}
 */
const DEFAULT_RULE = {
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
};

/**
 * Clamp an integer to an inclusive range, matching `Rule::from_array()`'s own
 * clamping (interval below 1 becomes 1; the server additionally rejects
 * anything above `MAX_INTERVAL` / `MAX_COUNT` outright, so the UI clamps at
 * both ends rather than letting an over-range value round-trip into a
 * rejected save). Callers always pass an already-coerced `Number(...)` value
 * from a `type="number"` control, whose own sanitization keeps a non-numeric
 * entry from ever reaching here as `NaN` -- there is no separate not-a-number
 * guard because there is no reachable path that would exercise it.
 *
 * @since 0.36.0
 *
 * @param {number} value Value to clamp.
 * @param {number} min   Inclusive lower bound.
 * @param {number} max   Inclusive upper bound.
 *
 * @return {number} The clamped value.
 */
function clampInt( value, min, max ) {
	if ( value < min ) {
		return min;
	}

	return value > max ? max : value;
}

/**
 * Normalize a typed day-of-month entry to an in-range integer, or reject it.
 *
 * `Rule::is_valid_monthly_shape()` requires 1 through 31, and a blob that
 * fails it is discarded server-side without surfacing anything in the editor:
 * the ten mirrors and the projection are cleared while the panel still shows
 * an enabled recurrence. A native number input accepts typed and pasted values
 * its `min`/`max` never constrain, so `0`, `32`, `-1`, `1.9` and `31abc` all
 * reach here.
 *
 * Numeric input is normalized (truncated toward zero, then clamped into
 * range); input with no numeric value at all -- a blank field mid-edit, or
 * pasted text -- is rejected as `null`, which `isPersistable()` treats as an
 * incomplete rule so the last known-good blob stays on the post.
 *
 * @since 0.36.0
 *
 * @param {*} value Raw control value, of unknown shape.
 *
 * @return {number|null} A day of the month between 1 and 31, or null when the
 *                       value carries no numeric day at all.
 */
function normalizeMonthlyDay( value ) {
	const raw = String( value ).trim();
	const parsed = Number( raw );

	if ( '' === raw || ! Number.isFinite( parsed ) ) {
		return null;
	}

	return clampInt( Math.trunc( parsed ), 1, 31 );
}

/**
 * Coerce a decoded `weekdays` value to an array of in-range weekday numbers.
 *
 * A REST write or import can carry a non-array (or an array with
 * out-of-range values) for `weekdays` -- `weekdays.includes()` downstream
 * would throw on anything that is not an array at all, and an out-of-range
 * number would corrupt the rule server-side (`Rule::is_valid()` requires
 * every weekday within 0 through 6).
 *
 * @since 0.36.0
 *
 * @param {*} weekdays Decoded `weekdays` value of unknown shape.
 *
 * @return {number[]} Weekday numbers, 0 through 6, with anything else dropped.
 */
function sanitizeWeekdays( weekdays ) {
	if ( ! Array.isArray( weekdays ) ) {
		return [];
	}

	return weekdays.filter(
		( day ) => Number.isInteger( day ) && 0 <= day && 6 >= day,
	);
}

/**
 * Parse the `gatherpress_recurrence` blob into panel state.
 *
 * A missing, empty, or malformed blob is treated as "no recurrence" rather
 * than surfacing a parse error — the panel falls back to `DEFAULT_RULE` with
 * the "Repeat" toggle off. A valid blob is merged onto `DEFAULT_RULE` so a
 * blob written by an older shape (missing a field this panel added later)
 * still renders every control with a sane value. `weekdays` is coerced field
 * by field rather than trusted wholesale — a REST write or import can carry
 * a non-array (or an array with out-of-range values), and `weekdays.includes()`
 * downstream would throw on anything that is not an array at all.
 *
 * @since 0.36.0
 *
 * @param {string|undefined} raw Raw `gatherpress_recurrence` meta value.
 *
 * @return {Object} `{ enabled, rule }` panel state.
 */
function parseRecurrenceBlob( raw ) {
	if ( ! raw ) {
		return { enabled: false, rule: { ...DEFAULT_RULE } };
	}

	try {
		const parsed = JSON.parse( raw );

		if ( ! parsed || 'object' !== typeof parsed ) {
			return { enabled: false, rule: { ...DEFAULT_RULE } };
		}

		const rule = { ...DEFAULT_RULE, ...parsed };
		rule.weekdays = sanitizeWeekdays( rule.weekdays );
		rule.monthly_day = normalizeMonthlyDay( rule.monthly_day );

		return { enabled: true, rule };
	} catch {
		return { enabled: false, rule: { ...DEFAULT_RULE } };
	}
}

/**
 * Report whether a candidate rule is complete enough to persist.
 *
 * Scoped to the fields this panel's controls can leave momentarily
 * incomplete mid-edit: a weekly rule with no weekday selected yet, a monthly
 * day-of-month rule whose day field has been cleared or filled with something
 * that carries no day at all, or an end condition whose companion field
 * (`until`/`count`) has not been filled in yet. `applyRuleChange()` withholds the write while this is `false` rather
 * than persisting a blob the server would reject and silently discard --
 * `Meta::write_recurrence()` clears all ten mirrors when `Rule::from_array()`
 * rejects the decoded blob, and that rejection surfaces nowhere in the
 * editor, so the last known-good blob is left in place instead.
 *
 * @since 0.36.0
 *
 * @param {Object} rule Candidate rule values.
 *
 * @return {boolean} True when the rule matches `Rule::is_valid()`'s shape requirements.
 */
function isPersistable( rule ) {
	if ( 'weekly' === rule.frequency && 0 === rule.weekdays.length ) {
		return false;
	}

	if (
		'monthly' === rule.frequency &&
		'day_of_month' === rule.monthly_mode &&
		! Number.isInteger( rule.monthly_day )
	) {
		return false;
	}

	if ( 'until' === rule.end_type && ! rule.until ) {
		return false;
	}

	return ! ( 'count' === rule.end_type && ! ( 1 <= rule.count ) );
}

/**
 * A settings panel for configuring an event's recurrence rule.
 *
 * Reads and writes the single `gatherpress_recurrence` JSON-string blob on
 * `core/editor` post meta (see `Rule::from_array()` and `Meta::META_KEY` for
 * the authoritative shape). The ten derived `gatherpress_recurrence_*`
 * mirrors are server-computed and read-only, so this panel never reads or
 * writes them — its source of truth is the blob itself, kept in local state
 * so edits round-trip within a single editing session.
 *
 * Recurrence is refused outright on a fixed-offset timezone (e.g.
 * `UTC+5:30`), detected by the presence of a `:` in the
 * `gatherpress/datetime` store's timezone value — a named tz-database
 * identifier such as `America/New_York` never contains one.
 *
 * @since 0.36.0
 *
 * @return {JSX.Element|null} The recurrence panel, or null when the current
 *                            post type does not support `gatherpress-event-date`.
 */
const RecurrencePanel = () => {
	const isEventDateSupported = usePostTypeSupports( 'gatherpress-event-date' );
	const { editPost } = useDispatch( 'core/editor' );

	const { recurrenceMeta, timezone } = useSelect( ( select ) => {
		return {
			recurrenceMeta: select( 'core/editor' )
				?.getEditedPostAttribute( 'meta' )
				?.gatherpress_recurrence,
			timezone: select( 'gatherpress/datetime' )?.getTimezone(),
		};
	}, [] );

	const [ { enabled, rule }, setState ] = useState( () =>
		parseRecurrenceBlob( recurrenceMeta ),
	);

	// Tracks the blob this component itself last wrote, so the effect below
	// can tell "the store caught up with our own write" apart from "the
	// entity resolved, or was changed, out from under us". Without this, a
	// panel that mounts before `core/editor` resolves the post entity renders
	// "Repeat: off" for a saved recurring event and never corrects itself: a
	// late entity resolution would clobber the saved state.
	const lastWrittenBlobRef = useRef( recurrenceMeta );

	useEffect( () => {
		if ( recurrenceMeta === lastWrittenBlobRef.current ) {
			return;
		}

		lastWrittenBlobRef.current = recurrenceMeta;
		setState( parseRecurrenceBlob( recurrenceMeta ) );
	}, [ recurrenceMeta ] );

	const isFixedOffsetTimezone = !! timezone?.includes( ':' );

	/**
	 * Persist panel state to the `gatherpress_recurrence` meta blob.
	 *
	 * @param {boolean} nextEnabled Whether recurrence is turned on.
	 * @param {Object}  nextRule    Rule values to serialize when enabled.
	 *
	 * @return {void}
	 */
	const persist = ( nextEnabled, nextRule ) => {
		const blob = nextEnabled ? JSON.stringify( nextRule ) : '';

		lastWrittenBlobRef.current = blob;

		editPost( {
			meta: {
				gatherpress_recurrence: blob,
			},
		} );
	};

	/**
	 * Apply a partial rule update, enforcing the shape constraints
	 * `Rule::from_array()` requires structurally rather than by convention:
	 * clamped interval/count, `until`/`count` mutual exclusivity, no stale
	 * weekday or monthly state left over from a previous frequency, and no
	 * write at all while the edit leaves the rule momentarily incomplete
	 * (see `isPersistable()`) -- local state still updates so the control
	 * reflects the choice and the validation message it earns, but the last
	 * known-good blob stays on the post until the rule is complete again.
	 *
	 * @param {Object} partial Partial rule field update.
	 *
	 * @return {void}
	 */
	const applyRuleChange = ( partial ) => {
		const merged = { ...rule, ...partial };

		if ( 'frequency' in partial ) {
			if ( 'weekly' !== merged.frequency ) {
				merged.weekdays = [];
			}

			if ( 'monthly' !== merged.frequency ) {
				merged.monthly_mode = DEFAULT_RULE.monthly_mode;
				merged.monthly_day = DEFAULT_RULE.monthly_day;
				merged.monthly_ordinal = DEFAULT_RULE.monthly_ordinal;
				merged.monthly_weekday = DEFAULT_RULE.monthly_weekday;
			}
		}

		if ( 'interval' in partial ) {
			merged.interval = clampInt( partial.interval, 1, 52 );
		}

		if ( 'count' in partial ) {
			merged.count = clampInt( partial.count, 1, 730 );
		}

		if ( 'monthly_day' in partial ) {
			merged.monthly_day = normalizeMonthlyDay( partial.monthly_day );
		}

		if ( 'end_type' in partial ) {
			if ( 'until' === merged.end_type ) {
				merged.count = 0;
			} else if ( 'count' === merged.end_type ) {
				merged.until = '';
			} else {
				merged.until = '';
				merged.count = 0;
			}
		}

		setState( { enabled: true, rule: merged } );

		if ( isPersistable( merged ) ) {
			persist( true, merged );
		}
	};

	/**
	 * Handle the "Repeat" toggle. Turning recurrence on always starts from
	 * `DEFAULT_RULE` — there is no separate "save" step, so the default
	 * blob is written immediately, matching every other control in this
	 * panel writing straight to `core/editor` meta on change.
	 *
	 * @param {boolean} value Next toggle state.
	 *
	 * @return {void}
	 */
	const handleToggle = ( value ) => {
		const nextRule = value ? { ...DEFAULT_RULE } : rule;

		setState( { enabled: value, rule: nextRule } );
		persist( value, nextRule );
	};

	if ( ! isEventDateSupported ) {
		return null;
	}

	return (
		<PanelRow>
			<div className="gatherpress-recurrence-panel">
				{ isFixedOffsetTimezone && (
					<output>
						{ __(
							'Recurring events require a named timezone (e.g. America/New_York) rather than a fixed UTC offset. Change the timezone in the Date & Time panel to enable repeat.',
							'gatherpress',
						) }
					</output>
				) }
				<ToggleControl
					label={ __( 'Repeat', 'gatherpress' ) }
					checked={ enabled && ! isFixedOffsetTimezone }
					disabled={ isFixedOffsetTimezone }
					onChange={ handleToggle }
				/>
				{ enabled && ! isFixedOffsetTimezone && (
					<>
						<FrequencyControl
							frequency={ rule.frequency }
							interval={ rule.interval }
							onFrequencyChange={ ( value ) =>
								applyRuleChange( { frequency: value } )
							}
							onIntervalChange={ ( value ) =>
								applyRuleChange( { interval: value } )
							}
						/>
						{ 'weekly' === rule.frequency && (
							<>
								<WeekdaysControl
									weekdays={ rule.weekdays }
									onChange={ ( value ) =>
										applyRuleChange( { weekdays: value } )
									}
								/>
								{ 0 === rule.weekdays.length && (
									<output>
										{ __(
											'Select at least one day of the week.',
											'gatherpress',
										) }
									</output>
								) }
							</>
						) }
						{ 'monthly' === rule.frequency && (
							<>
								<MonthlyControl
									monthlyMode={ rule.monthly_mode }
									monthlyDay={ rule.monthly_day }
									monthlyOrdinal={ rule.monthly_ordinal }
									monthlyWeekday={ rule.monthly_weekday }
									onChange={ applyRuleChange }
								/>
								{ 'day_of_month' === rule.monthly_mode &&
									! Number.isInteger(
										rule.monthly_day,
									) && (
									<output>
										{ __(
											'Enter a day of the month between 1 and 31.',
											'gatherpress',
										) }
									</output>
								) }
							</>
						) }
						<EndConditionControl
							endType={ rule.end_type }
							until={ rule.until }
							count={ rule.count }
							onChange={ applyRuleChange }
						/>
						{ 'until' === rule.end_type && ! rule.until && (
							<output>
								{ __(
									'Choose an end date to save this recurrence.',
									'gatherpress',
								) }
							</output>
						) }
						{ 'count' === rule.end_type &&
							! ( 1 <= rule.count ) && (
							<output>
								{ __(
									'Enter how many times this event repeats.',
									'gatherpress',
								) }
							</output>
						) }
					</>
				) }
			</div>
		</PanelRow>
	);
};

export default RecurrencePanel;
