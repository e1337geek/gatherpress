const { expect } = require( '@playwright/test' );

/**
 * Playwright helpers for the recurring-events e2e suite (#80).
 *
 * Carries the reusable parts of the recurrence journey: seeding an event
 * with a datetime, opening the editor's Recurrence panel, driving its
 * controls, saving/publishing, and reading occurrence rows off the front
 * end. Selectors are anchored to GatherPress's own labels and markup
 * (`gatherpress-recurrence-panel`, the panel's own `SelectControl`/
 * `CheckboxControl` labels) rather than WordPress admin implementation
 * details, per `AGENTS.md`'s e2e guidance.
 *
 * Every function takes a Playwright `page` that is already authenticated
 * (via the shared `storageState.json` `global-setup.js` produces) and, where
 * relevant, already navigated to `/wp-admin/` so `window.wp.apiFetch` is
 * available with a valid REST nonce.
 *
 * @since 0.36.0
 */

/**
 * Dismiss any modal overlay blocking the block editor (welcome guide,
 * pattern picker, etc). Waits for the overlay to actually detach rather than
 * a fixed timeout, and loops to cover modals that stack.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 *
 * @return {Promise<void>}
 */
async function dismissEditorModals( page ) {
	const modalOverlay = page.locator( '.components-modal__screen-overlay' ).first();

	for ( let attempt = 0; 3 > attempt; attempt++ ) {
		const isOpen = await modalOverlay.isVisible().catch( () => false );

		if ( ! isOpen ) {
			break;
		}

		await page.keyboard.press( 'Escape' );
		await modalOverlay.waitFor( { state: 'hidden', timeout: 5000 } ).catch( () => {} );
	}
}

/**
 * Ensure the "Event settings" sidebar panel is expanded.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 *
 * @return {Promise<void>}
 */
async function openEventSettingsPanel( page ) {
	const eventSettingsButton = page.getByRole( 'button', { name: /Event settings/i } ).first();

	await expect( eventSettingsButton ).toBeVisible( { timeout: 15000 } );

	const expanded = await eventSettingsButton.getAttribute( 'aria-expanded' );

	if ( 'true' !== expanded ) {
		await eventSettingsButton.click();
	}

	// The Recurrence panel's own wrapper div confirms the panel actually
	// mounted, not merely that the toggle reports expanded.
	await expect( page.locator( '.gatherpress-recurrence-panel' ) ).toBeVisible( { timeout: 15000 } );
}

/**
 * Seed a GatherPress event with a datetime via the REST API, reusing the
 * same `wp.apiFetch` seeding pattern the existing event specs use (avoids
 * `npm run wp-env run`'s separate Docker compose, which conflicts on port
 * with the environment `pretest:e2e` already started).
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page                    Playwright page object, already on an admin page.
 * @param {Object}                          options                 Seed options.
 * @param {string}                          options.title           Event title.
 * @param {number}                          [options.daysFromNow]   Days from now the event starts, derived from "now"
 *                                                                  rather than a hard-coded date.
 * @param {number}                          [options.hour]          Local start hour (24h).
 * @param {number}                          [options.durationHours] Event duration in hours.
 * @param {string}                          [options.timezone]      IANA timezone string.
 * @param {string}                          [options.status]        Post status ('publish' or 'draft').
 *
 * @return {Promise<{eventId: number, link: string}>} The created event's ID and permalink.
 */
async function seedEventWithDatetime( page, {
	title,
	daysFromNow = 7,
	hour = 18,
	durationHours = 2,
	timezone = 'America/New_York',
	status = 'publish',
} = {} ) {
	const result = await page.evaluate(
		async ( { title: t, daysFromNow: d, hour: h, durationHours: dur, timezone: tz, status: s } ) => {
			// Built entirely from UTC fields, deliberately: `gatherpress_datetime`
			// wants a naive "local wall clock" string paired with the `timezone`
			// field, and the browser's own system timezone (whatever the host
			// machine happens to be set to) must never leak into which
			// calendar day that string names. Mixing local-timezone `Date`
			// getters/setters with `toISOString()`'s UTC conversion shifts the
			// calendar day near midnight on any host not itself running UTC.
			const now = new Date();
			const daysAhead = new Date( now.getTime() + ( d * 24 * 60 * 60 * 1000 ) );
			const start = new Date( Date.UTC(
				daysAhead.getUTCFullYear(),
				daysAhead.getUTCMonth(),
				daysAhead.getUTCDate(),
				h, 0, 0
			) );
			const end = new Date( start.getTime() + ( dur * 60 * 60 * 1000 ) );
			const pad = ( n ) => String( n ).padStart( 2, '0' );
			const fmt = ( date ) => `${ date.getUTCFullYear() }-${ pad( date.getUTCMonth() + 1 ) }-` +
				`${ pad( date.getUTCDate() ) } ${ pad( date.getUTCHours() ) }:00:00`;

			const res = await window.wp.apiFetch( {
				path: '/wp/v2/gatherpress_events',
				method: 'POST',
				data: {
					title: t,
					status: s,
					// Non-empty content so the editor's pattern-picker start
					// page never covers the sidebar controls a later step
					// interacts with.
					// Includes a linked event-date block so specs can read the
					// resolved occurrence date straight off the single event
					// page (bare series URL or a direct occurrence URL).
					content: '<!-- wp:gatherpress/event-date {"isLink":true} /-->' +
						'<!-- wp:paragraph --><p>Seeded by the recurring-events e2e suite.</p><!-- /wp:paragraph -->',
					meta: {
						gatherpress_datetime: JSON.stringify( {
							dateTimeStart: fmt( start ),
							dateTimeEnd: fmt( end ),
							timezone: tz,
						} ),
					},
				},
			} );

			return {
				eventId: res.id,
				link: res.link,
				// Plain components (not a Date instance — page.evaluate can
				// only return serializable values) so a caller can rebuild
				// the exact local anchor date/time this event was seeded
				// with, e.g. to compute an expected occurrence ID.
				anchor: {
					year: start.getUTCFullYear(),
					month: start.getUTCMonth(),
					day: start.getUTCDate(),
					hour: start.getUTCHours(),
				},
			};
		},
		{ title, daysFromNow, hour, durationHours, timezone, status }
	);

	return result;
}

/**
 * Navigate to an event's editor and get it into a state where the "Event
 * settings" panel's controls (including Recurrence) are visible.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page    Playwright page object.
 * @param {number}                          eventId Event post ID.
 *
 * @return {Promise<void>}
 */
async function openEventEditor( page, eventId ) {
	await page.goto( `/wp-admin/post.php?post=${ eventId }&action=edit` );
	await page.waitForLoadState( 'load' );
	await dismissEditorModals( page );
	await openEventSettingsPanel( page );
}

/**
 * Set an event's own Time Zone control, inside the "Event settings" panel.
 *
 * This is the three-click organizer path REQ-3 targets: open Event settings
 * (already open by the time a spec reaches this helper), open the Time Zone
 * dropdown, pick a manual UTC offset.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page  Playwright page object.
 * @param {string}                          label Visible option label, e.g. `UTC-5`.
 *
 * @return {Promise<void>}
 */
async function setEventTimezone( page, label ) {
	await page.getByLabel( 'Time Zone' ).selectOption( { label } );
	await page.waitForTimeout( 300 );
}

/**
 * Turn on the "Repeat" toggle in the Recurrence panel.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 *
 * @return {Promise<void>}
 */
async function enableRecurrence( page ) {
	const repeatToggle = page.getByRole( 'checkbox', { name: 'Repeat' } );

	await expect( repeatToggle ).toBeEnabled( { timeout: 10000 } );

	if ( ! ( await repeatToggle.isChecked() ) ) {
		await repeatToggle.click();
	}
}

/**
 * Set the recurrence frequency and repeat interval.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page                Playwright page object.
 * @param {Object}                          options             Frequency options.
 * @param {string}                          [options.frequency] One of `daily`, `weekly`, `monthly`.
 * @param {number}                          [options.interval]  Repeat-every interval.
 *
 * @return {Promise<void>}
 */
async function setFrequency( page, { frequency, interval } = {} ) {
	if ( frequency ) {
		await page.getByLabel( 'Frequency' ).selectOption( frequency );
		// Switching frequency conditionally mounts/unmounts the weekday and
		// monthly controls below it; give React a moment to settle before a
		// caller reaches for one of them.
		await page.waitForTimeout( 300 );
	}

	if ( interval ) {
		await page.getByLabel( 'Repeat every' ).fill( String( interval ) );
		await page.keyboard.press( 'Tab' );
	}
}

/**
 * Check the given weekday checkboxes in a weekly rule's weekday picker.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 * @param {string[]}                        days Full weekday names, e.g. `[ 'Tuesday', 'Thursday' ]`.
 *
 * @return {Promise<void>}
 */
async function setWeekdays( page, days ) {
	for ( const day of days ) {
		const checkbox = page.getByRole( 'checkbox', { name: day, exact: true } );
		await expect( checkbox ).toBeVisible();

		if ( ! ( await checkbox.isChecked() ) ) {
			await checkbox.check();
		}
	}
}

/**
 * Configure a monthly rule's mode and companion fields.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page              Playwright page object.
 * @param {Object}                          options           Monthly options.
 * @param {string}                          options.mode      One of `day_of_month`, `nth_weekday`.
 * @param {number}                          [options.day]     Day of the month, when `mode` is `day_of_month`.
 * @param {string}                          [options.ordinal] Ordinal label ('First'…'Fourth', 'Last'), when
 *                                                            `mode` is `nth_weekday`.
 * @param {string}                          [options.weekday] Full weekday name, when `mode` is `nth_weekday`.
 *
 * @return {Promise<void>}
 */
async function setMonthly( page, { mode, day, ordinal, weekday } = {} ) {
	await page.getByLabel( 'Repeat by' ).selectOption(
		'day_of_month' === mode
			? { label: 'Day of the month' }
			: { label: 'Day of the week' }
	);
	// Switching mode conditionally swaps the day-of-month field for the
	// week/day ordinal pair; give React a moment to settle first.
	await page.waitForTimeout( 300 );

	if ( 'day_of_month' === mode && day ) {
		await page.getByLabel( 'Day of the month' ).fill( String( day ) );
		await page.keyboard.press( 'Tab' );
	}

	if ( 'nth_weekday' === mode ) {
		if ( ordinal ) {
			await page.getByLabel( 'Week' ).selectOption( { label: ordinal } );
		}

		if ( weekday ) {
			await page.getByLabel( 'Day', { exact: true } ).selectOption( { label: weekday } );
		}
	}
}

/**
 * Configure the series end condition.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page            Playwright page object.
 * @param {Object}                          options         End-condition options.
 * @param {string}                          options.endType One of `never`, `until`, `count`.
 * @param {string}                          [options.until] End date, `YYYY-MM-DD`, when `endType` is `until`.
 * @param {number}                          [options.count] Occurrence count, when `endType` is `count`.
 *
 * @return {Promise<void>}
 */
async function setEndCondition( page, { endType, until, count } = {} ) {
	const labels = { never: 'Never', until: 'On date', count: 'After' };

	await page.getByLabel( 'Ends' ).selectOption( { label: labels[ endType ] } );

	if ( 'until' === endType && until ) {
		await page.getByLabel( 'End date' ).fill( until );
		await page.keyboard.press( 'Tab' );
	}

	if ( 'count' === endType && count ) {
		await page.getByLabel( 'Number of occurrences' ).fill( String( count ) );
		await page.keyboard.press( 'Tab' );
	}
}

/**
 * Save the currently open event (works for both "Publish" a draft and
 * "Save"/"Update" an already-published post — the block editor's header
 * button carries whichever label matches the post's current status).
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 *
 * @return {Promise<void>}
 */
async function saveEvent( page ) {
	const headerButton = page
		.getByRole( 'button', { name: /^(Save|Update|Publish)$/ } )
		.first();

	await expect( headerButton ).toBeEnabled( { timeout: 10000 } );
	await headerButton.click();

	// Publishing a draft opens a confirmation panel with its own "Publish"
	// button; saving an already-published post does not. Handle both.
	const finalPublishButton = page
		.getByRole( 'button', { name: 'Publish', exact: true } )
		.last();

	if ( await finalPublishButton.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		await finalPublishButton.click();
	}

	await page.waitForSelector( '.components-snackbar', { timeout: 20000 } );

	// The snackbar confirms the save request resolved, but the editor's own
	// entity-record resolution can still land a moment later and briefly
	// re-sync the Recurrence panel's local state to what was just saved.
	// Editing again before that settles can race a subsequent change to
	// arrive first and get clobbered by the delayed resync. Give it a beat.
	await page.waitForTimeout( 800 );
}

/**
 * Read an event's `gatherpress_recurrence` blob and derived mirrors via the
 * REST API.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page    Playwright page object, already on an admin page.
 * @param {number}                          eventId Event post ID.
 *
 * @return {Promise<Object>} The event's `meta` object.
 */
async function getEventMeta( page, eventId ) {
	return page.evaluate( async ( id ) => {
		const res = await window.wp.apiFetch( {
			path: `/wp/v2/gatherpress_events/${ id }?context=edit`,
		} );
		return res.meta;
	}, eventId );
}

/**
 * Create a page containing the GatherPress "Event Query Loop" variation,
 * scoped to upcoming events, and return its front-end URL. Mirrors the
 * `namespace`/`query` shape the block's own starter pattern
 * (`src/variations/core/query/patterns/index.js`) writes.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page  Playwright page object, already on an admin page.
 * @param {string}                          title Page title.
 *
 * @return {Promise<{pageId: number, link: string}>} The created page's ID and permalink.
 */
async function createUpcomingEventsListPage( page, title ) {
	const content = '<!-- wp:query {"queryId":1,"query":{"perPage":20,"pages":0,"offset":0,' +
		'"postType":"gatherpress_event","gatherpress_event_query":"upcoming",' +
		'"include_unfinished":1,"order":"asc","orderBy":"datetime","inherit":false},' +
		'"namespace":"gatherpress-event-query","className":"gatherpress-event-query"} -->' +
		'<div class="wp-block-query gatherpress-event-query"><!-- wp:post-template -->' +
		'<!-- wp:post-title {"isLink":true} /--><!-- wp:gatherpress/event-date {"isLink":true} /-->' +
		'<!-- /wp:post-template --></div><!-- /wp:query -->';

	return page.evaluate( async ( { title: t, content: c } ) => {
		const res = await window.wp.apiFetch( {
			path: '/wp/v2/pages',
			method: 'POST',
			data: { title: t, status: 'publish', content: c },
		} );
		return { pageId: res.id, link: res.link };
	}, { title, content } );
}

/**
 * Visit the upcoming-events list page and read its rendered occurrence rows.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page    Playwright page object.
 * @param {string}                          pageUrl Front-end URL of the list page.
 *
 * @return {Promise<Array<{title: string, dateText: string, href: string}>>} One entry per rendered row.
 */
async function getUpcomingRows( page, pageUrl ) {
	await page.goto( pageUrl );
	await page.waitForLoadState( 'load' );

	const rows = page.locator( '.wp-block-post-template > li, .wp-block-post-template > .wp-block-post' );
	const count = await rows.count();
	const results = [];

	for ( let i = 0; i < count; i++ ) {
		const row = rows.nth( i );
		const link = row.locator( 'a' ).first();
		results.push( {
			title: ( await row.locator( '.wp-block-post-title' ).innerText().catch( () => '' ) ).trim(),
			dateText: ( await row.locator( '.wp-block-gatherpress-event-date' ).innerText().catch( () => '' ) ).trim(),
			href: await link.getAttribute( 'href' ).catch( () => null ),
		} );
	}

	return results;
}

/**
 * Delete a post via the REST API, tolerating a post type whose collection
 * base differs from its slug (e.g. `gatherpress_events` for
 * `gatherpress_event`).
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page       Playwright page object.
 * @param {number}                          id         Post ID to delete.
 * @param {string}                          [restBase] REST collection base, defaults to `gatherpress_events`.
 *
 * @return {Promise<void>}
 */
async function deletePost( page, id, restBase = 'gatherpress_events' ) {
	await page.evaluate( async ( { id: postId, restBase: base } ) => {
		await window.wp.apiFetch( {
			path: `/wp/v2/${ base }/${ postId }?force=true`,
			method: 'DELETE',
		} );
	}, { id, restBase } );
}

/**
 * Set the site timezone to a named tz-database identifier via the REST
 * settings endpoint. Recurrence requires this (REQ-3): tests that author a
 * rule must call this before touching the Recurrence panel.
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page Playwright page object, already on an admin page.
 * @param {string}                          tz   IANA timezone string, e.g. `America/New_York`.
 *
 * @return {Promise<void>}
 */
async function setSiteTimezone( page, tz ) {
	await page.evaluate( async ( timezone ) => {
		await window.wp.apiFetch( {
			path: '/wp/v2/settings',
			method: 'POST',
			data: { timezone },
		} );
	}, tz );
}

/**
 * Set the site timezone to a manual UTC offset through the real wp-admin
 * "Settings > General" form — the three-click path an organizer actually
 * reaches, and the one that exercises WordPress core's own
 * `timezone_string`/`gmt_offset` conversion (a REST write of a `UTC±N`
 * string does not trigger that conversion; only the admin form does).
 *
 * @since 0.36.0
 *
 * @param {import('@playwright/test').Page} page        Playwright page object.
 * @param {string}                          offsetLabel Visible option label, e.g. `UTC-5`.
 *
 * @return {Promise<void>}
 */
async function setManualUtcOffsetViaAdmin( page, offsetLabel ) {
	await page.goto( '/wp-admin/options-general.php' );
	await page.waitForLoadState( 'load' );

	await page.locator( '#timezone_string' ).selectOption( { label: offsetLabel } );
	await page.locator( '#submit' ).click();
	await page.waitForLoadState( 'load' );
}

/**
 * Zero out the time portion of a Date, in local time.
 *
 * @since 0.36.0
 *
 * @param {Date} date Date to normalize.
 *
 * @return {Date} A new Date at local midnight of the same calendar day.
 */
function dateOnly( date ) {
	return new Date( date.getFullYear(), date.getMonth(), date.getDate() );
}

/**
 * Compute a `daysFromNow` offset (for `seedEventWithDatetime`) that lands on
 * a specific UTC calendar weekday, at least `minDays` out.
 *
 * A spec that seeds an anchor date and then asserts a *different*, later
 * occurrence's date needs the anchor itself to fall outside the rule's own
 * weekdays — otherwise the anchor and the first occurrence coincide by
 * chance on any day the test happens to run, and a broken resolution path
 * would go uncaught. Picking the offset dynamically (never a pinned
 * calendar date) keeps the guarantee true on every run.
 *
 * @since 0.36.0
 *
 * @param {number} targetWeekday UTC day-of-week to land on, 0 (Sunday) through 6 (Saturday).
 * @param {number} [minDays]     Minimum number of days out.
 *
 * @return {number} Days from now, suitable for `seedEventWithDatetime`'s `daysFromNow`.
 */
function daysUntilUtcWeekday( targetWeekday, minDays = 3 ) {
	const now = new Date();

	for ( let d = minDays; d < minDays + 7; d++ ) {
		const candidate = new Date( now.getTime() + ( d * 24 * 60 * 60 * 1000 ) );

		if ( candidate.getUTCDay() === targetWeekday ) {
			return d;
		}
	}

	throw new Error( 'No matching weekday found within a week of the minimum.' );
}

/**
 * Find the first date on or after `from` whose day-of-week is in `weekdays`.
 *
 * Mirrors `Expander::next_scanned_date()` for an INTERVAL=1 weekly rule: the
 * scan starts at the anchor date itself (inclusive), not the day after.
 *
 * @since 0.36.0
 *
 * @param {Date}     from     Date to scan forward from (inclusive).
 * @param {number[]} weekdays Day-of-week numbers, 0 (Sunday) through 6 (Saturday).
 *
 * @return {Date} The first matching date.
 */
function nextMatchingWeekday( from, weekdays ) {
	const date = dateOnly( from );

	for ( let step = 0; 14 >= step; step++ ) {
		if ( weekdays.includes( date.getDay() ) ) {
			return date;
		}
		date.setDate( date.getDate() + 1 );
	}

	throw new Error( 'No matching weekday found within two weeks.' );
}

/**
 * Find the last date in a given month whose day-of-week matches `weekday`.
 *
 * Mirrors `Expander::nth_weekday_of_month()` with `ordinal === -1`.
 *
 * @since 0.36.0
 *
 * @param {number} year    Four-digit year.
 * @param {number} month   Zero-based month (0 = January), matching `Date`.
 * @param {number} weekday Day-of-week number, 0 (Sunday) through 6 (Saturday).
 *
 * @return {Date} The last matching date in that month.
 */
function lastWeekdayOfMonth( year, month, weekday ) {
	const date = new Date( year, month + 1, 0 ); // Last day of the month.

	while ( date.getDay() !== weekday ) {
		date.setDate( date.getDate() - 1 );
	}

	return date;
}

/**
 * Resolve the first occurrence of a monthly "last <weekday>" rule on or after an anchor.
 *
 * Mirrors `Expander::next_monthly_date()`: the anchor's own month is tried
 * first, and only rolls to the next month when that month's last-weekday date
 * falls before the anchor.
 *
 * @since 0.36.0
 *
 * @param {Date}   anchor  Series anchor date (date-only).
 * @param {number} weekday Day-of-week number, 0 (Sunday) through 6 (Saturday).
 *
 * @return {Date} The first matching occurrence date.
 */
function firstLastWeekdayOnOrAfter( anchor, weekday ) {
	const candidate = lastWeekdayOfMonth(
		anchor.getFullYear(),
		anchor.getMonth(),
		weekday
	);

	if ( candidate >= dateOnly( anchor ) ) {
		return candidate;
	}

	return lastWeekdayOfMonth( anchor.getFullYear(), anchor.getMonth() + 1, weekday );
}

/**
 * Build the `Ymd\THis` recurrence ID for a date at a fixed wall-clock time.
 *
 * `Occurrences::recurrence_id()` formats the occurrence's own local start
 * datetime, and every occurrence in a GatherPress rule shares the anchor's
 * time-of-day (the panel has no per-occurrence time control), so pairing a
 * date-only value with the fixed `hhmmss` this suite always seeds with
 * reproduces the server's own identifier.
 *
 * @since 0.36.0
 *
 * @param {Date}   date   Date-only value.
 * @param {string} hhmmss Wall-clock time as `HHmmss`, matching the event's start time.
 *
 * @return {string} The recurrence ID.
 */
function toRecurrenceId( date, hhmmss ) {
	const year = String( date.getFullYear() ).padStart( 4, '0' );
	const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( date.getDate() ).padStart( 2, '0' );

	return `${ year }${ month }${ day }T${ hhmmss }`;
}

module.exports = {
	dismissEditorModals,
	openEventSettingsPanel,
	seedEventWithDatetime,
	openEventEditor,
	setEventTimezone,
	enableRecurrence,
	setFrequency,
	setWeekdays,
	setMonthly,
	setEndCondition,
	saveEvent,
	getEventMeta,
	createUpcomingEventsListPage,
	getUpcomingRows,
	deletePost,
	setSiteTimezone,
	setManualUtcOffsetViaAdmin,
	dateOnly,
	daysUntilUtcWeekday,
	nextMatchingWeekday,
	lastWeekdayOfMonth,
	firstLastWeekdayOnOrAfter,
	toRecurrenceId,
};
