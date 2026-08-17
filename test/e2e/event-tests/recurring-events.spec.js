const { test, expect } = require( '@playwright/test' );
const {
	openEventEditor,
	setEventTimezone,
	enableRecurrence,
	setFrequency,
	setWeekdays,
	setMonthly,
	setEndCondition,
	saveEvent,
	getEventMeta,
	seedEventWithDatetime,
	createUpcomingEventsListPage,
	getUpcomingRows,
	deletePost,
	setSiteTimezone,
	nextMatchingWeekday,
	toRecurrenceId,
	daysUntilUtcWeekday,
} = require( '../helpers/recurrence' );

/**
 * End-to-end coverage for the recurring-events feature (#80).
 *
 * Covers beats 1-4 of the authoring/browsing journey (authoring a rule and
 * publishing, switching the rule and watching dates change, seeing multiple
 * occurrences in the upcoming list, and following a series link to see a
 * real occurrence's date) plus two specs the build has earned: REQ-16 (a
 * site with no recurring events behaves exactly as it does today) and REQ-3
 * (a fixed-offset timezone visibly refuses recurrence).
 *
 * RSVP-per-occurrence and cancel-an-occurrence (beats 5-6) are deliberately
 * out of scope — other lanes are still building that surface. See this
 * file's companion report for what a follow-up needs to add there.
 *
 * Every seeded post is deleted in a `finally` block so repeated local runs
 * don't accumulate orphans, and every date is derived from "now" rather than
 * pinned to a literal, so the suite does not rot into a time bomb.
 */
test.describe( 'Recurring events', () => {
	test( 'REQ-16: a site with no recurring events renders the upcoming list and an ordinary permalink correctly', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' );
		await page.waitForLoadState( 'load' );

		const title = `REQ-16 ordinary event ${ Date.now() }`;
		const { eventId, link } = await seedEventWithDatetime( page, {
			title,
			daysFromNow: 5,
		} );

		expect( eventId, 'ordinary event seeded via REST' ).toBeTruthy();

		try {
			// The permalink itself must render, with the right title and no
			// stray recurrence-only markup.
			await page.goto( link );
			await page.waitForLoadState( 'load' );
			await expect( page.locator( 'body' ) ).toContainText( title );

			// Same event, seen through the upcoming-events list: exactly one
			// row, not duplicated or dropped by the recurrence-aware clauses
			// this feature adds to every event query.
			await page.goto( '/wp-admin/' );
			await page.waitForLoadState( 'load' );
			const { link: listUrl } = await createUpcomingEventsListPage(
				page,
				`REQ-16 upcoming list ${ Date.now() }`
			);
			const rows = await getUpcomingRows( page, listUrl );
			const matching = rows.filter( ( row ) => row.title === title );

			expect(
				matching,
				'ordinary (non-recurring) event appears exactly once in the upcoming list'
			).toHaveLength( 1 );
		} finally {
			await page.goto( '/wp-admin/' );
			await deletePost( page, eventId );
		}
	} );

	test( 'REQ-3: a fixed-offset timezone visibly refuses recurrence', async ( { page } ) => {
		await page.goto( '/wp-admin/' );
		await page.waitForLoadState( 'load' );

		// Seeded with a named zone so the only variable under test is the
		// in-editor timezone change below.
		const { eventId } = await seedEventWithDatetime( page, {
			title: `REQ-3 fixed offset ${ Date.now() }`,
			daysFromNow: 10,
			timezone: 'America/New_York',
		} );

		try {
			await openEventEditor( page, eventId );

			// The three-click organizer path: open Event settings (already
			// open), open the Time Zone dropdown, pick a manual UTC offset.
			await setEventTimezone( page, 'UTC-5' );

			await expect(
				page.getByText( /Recurring events require a named timezone/i )
			).toBeVisible();

			const repeatToggle = page.getByRole( 'checkbox', { name: 'Repeat' } );
			await expect( repeatToggle ).toBeDisabled();
			await expect( repeatToggle ).not.toBeChecked();
		} finally {
			await page.goto( '/wp-admin/' );
			await deletePost( page, eventId );
		}
	} );

	test( 'Beat 1: authoring a bi-weekly Tuesday/Thursday rule persists and publishes', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' );
		await page.waitForLoadState( 'load' );
		await setSiteTimezone( page, 'America/New_York' );

		const title = `Bi-weekly Tue/Thu ${ Date.now() }`;
		const { eventId } = await seedEventWithDatetime( page, {
			title,
			daysFromNow: 7,
			status: 'draft',
		} );

		try {
			await openEventEditor( page, eventId );
			await enableRecurrence( page );
			await setFrequency( page, { frequency: 'weekly', interval: 2 } );
			await setWeekdays( page, [ 'Tuesday', 'Thursday' ] );
			await saveEvent( page );

			const meta = await getEventMeta( page, eventId );
			const rule = JSON.parse( meta.gatherpress_recurrence );

			expect( rule.frequency ).toBe( 'weekly' );
			expect( rule.interval ).toBe( 2 );
			expect( rule.weekdays.sort() ).toEqual( [ 2, 4 ] );

			// The server-derived, read-only mirrors are what the expander
			// and the front end actually read — proving the panel's write
			// round-tripped through a real save, not just local React state.
			expect( meta.gatherpress_recurrence_frequency ).toBe( 'weekly' );
			expect( meta.gatherpress_recurrence_interval ).toBe( 2 );
			expect( meta.gatherpress_recurrence_byday ).toBe( 'TU,TH' );

			// The post is published, not left as a draft.
			const status = await page.evaluate( async ( id ) => {
				const res = await window.wp.apiFetch( {
					path: `/wp/v2/gatherpress_events/${ id }`,
				} );
				return res.status;
			}, eventId );

			expect( status ).toBe( 'publish' );
		} finally {
			await page.goto( '/wp-admin/' );
			await deletePost( page, eventId );
		}
	} );

	test( 'Beat 2: switching to monthly last-Wednesday changes the projected occurrence dates', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' );
		await page.waitForLoadState( 'load' );
		await setSiteTimezone( page, 'America/New_York' );

		const title = `Monthly switch ${ Date.now() }`;
		const { eventId } = await seedEventWithDatetime( page, {
			title,
			daysFromNow: 7,
		} );

		try {
			await openEventEditor( page, eventId );
			await enableRecurrence( page );
			await setFrequency( page, { frequency: 'weekly' } );
			await setWeekdays( page, [ 'Tuesday' ] );
			await saveEvent( page );

			const weeklyMeta = await getEventMeta( page, eventId );

			expect( weeklyMeta.gatherpress_recurrence_frequency ).toBe( 'weekly' );

			// Switch to monthly, last Wednesday of the month.
			await setFrequency( page, { frequency: 'monthly' } );
			await setMonthly( page, {
				mode: 'nth_weekday',
				ordinal: 'Last',
				weekday: 'Wednesday',
			} );
			await saveEvent( page );

			const monthlyMeta = await getEventMeta( page, eventId );

			expect( monthlyMeta.gatherpress_recurrence_frequency ).toBe( 'monthly' );
			expect( monthlyMeta.gatherpress_recurrence_monthly_mode ).toBe( 'nth_weekday' );
			expect( monthlyMeta.gatherpress_recurrence_monthly_ordinal ).toBe( -1 );
			expect( monthlyMeta.gatherpress_recurrence_monthly_weekday ).toBe( 3 );

			// The dates actually changed: every projected occurrence must
			// fall on a Wednesday now, which a weekly-Tuesday rule's
			// occurrences never would.
			const link = await page.evaluate( async ( id ) => {
				const res = await window.wp.apiFetch( { path: `/wp/v2/gatherpress_events/${ id }` } );
				return res.link;
			}, eventId );

			await page.goto( link );
			await page.waitForLoadState( 'load' );

			const bodyText = await page.locator( 'body' ).innerText();
			const dateMatch = bodyText.match(
				/(January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4}/
			);

			expect( dateMatch, 'the series permalink shows a resolved occurrence date' ).toBeTruthy();

			const shownDate = new Date( `${ dateMatch[ 0 ] } 12:00:00` );

			expect(
				shownDate.getDay(),
				'the resolved occurrence date is a Wednesday, matching the new monthly rule'
			).toBe( 3 );
		} finally {
			await page.goto( '/wp-admin/' );
			await deletePost( page, eventId );
		}
	} );

	test( 'Beats 3-4: a recurring series expands into multiple upcoming entries, and following one shows a real occurrence date', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' );
		await page.waitForLoadState( 'load' );
		await setSiteTimezone( page, 'America/New_York' );

		const title = `List expansion ${ Date.now() }`;
		// Anchored on a Monday, deliberately outside the Tuesday/Thursday
		// rule below — the anchor date and the rule's first occurrence must
		// differ, or a broken occurrence-resolution path would coincidentally
		// pass on any run where the anchor already lands on a rule day.
		const { eventId, anchor } = await seedEventWithDatetime( page, {
			title,
			daysFromNow: daysUntilUtcWeekday( 1 ),
			hour: 18,
			status: 'draft',
		} );

		try {
			await openEventEditor( page, eventId );
			await enableRecurrence( page );
			await setFrequency( page, { frequency: 'weekly', interval: 2 } );
			await setWeekdays( page, [ 'Tuesday', 'Thursday' ] );
			await setEndCondition( page, { endType: 'count', count: 12 } );
			await saveEvent( page );

			const { link: seriesLink } = await page.evaluate( async ( id ) => {
				const res = await window.wp.apiFetch( { path: `/wp/v2/gatherpress_events/${ id }` } );
				return { link: res.link };
			}, eventId );

			const { link: listUrl } = await createUpcomingEventsListPage(
				page,
				`Beats 3-4 upcoming list ${ Date.now() }`
			);
			const rows = await getUpcomingRows( page, listUrl );
			const matching = rows.filter( ( row ) => row.title === title );

			// Beat 3: the series shows up as several individual entries in
			// the upcoming list, not collapsed to a single row at its
			// anchor date — a 12-occurrence, twice-a-week series easily
			// clears "more than one" inside the list's default window.
			expect(
				matching.length,
				'a recurring series produces more than one entry in the upcoming list'
			).toBeGreaterThan( 1 );

			// Beat 4, part one: the exact occurrence URL REQ-8 defines
			// (`{permalink}{Ymd}T{His}/`) resolves and shows that specific
			// occurrence's date. The expected first occurrence is computed
			// independently in JS (mirroring `Expander::next_scanned_date()`
			// for an INTERVAL=1 weekly rule — interval only affects which
			// *week* a later occurrence falls in, not which weekday the
			// first one lands on), not read back from anything the app
			// produced, so this proves the URL scheme rather than assuming it.
			const anchorDate = new Date( anchor.year, anchor.month, anchor.day );
			const firstOccurrence = nextMatchingWeekday( anchorDate, [ 2, 4 ] );
			const recurrenceId = toRecurrenceId( firstOccurrence, '180000' );
			const occurrenceUrl = `${ seriesLink.replace( /\/$/, '' ) }/${ recurrenceId }/`;

			const occurrenceResponse = await page.goto( occurrenceUrl );
			await page.waitForLoadState( 'load' );

			expect(
				occurrenceResponse.status(),
				'the occurrence URL resolves (pretty permalinks are in effect for it)'
			).toBe( 200 );

			const expectedDateText = firstOccurrence.toLocaleDateString( 'en-US', {
				month: 'long',
				day: 'numeric',
				year: 'numeric',
			} );

			await expect(
				page.locator( 'body' ),
				"the occurrence URL's page shows that exact occurrence's date"
			).toContainText( expectedDateText );

			// Beat 4, part two: following the series' own (bare) link — the
			// one every row in the list actually points to — also lands on
			// a page showing that same first occurrence, via
			// `Rewrite::maybe_resolve_bare_series()` (PRD D-4), rather than
			// the series' stale anchor date.
			await page.goto( seriesLink );
			await page.waitForLoadState( 'load' );

			await expect(
				page.locator( 'body' ),
				'the bare series URL resolves to the same next-upcoming occurrence'
			).toContainText( expectedDateText );
		} finally {
			await page.goto( '/wp-admin/' );
			await deletePost( page, eventId );
		}
	} );
} );
