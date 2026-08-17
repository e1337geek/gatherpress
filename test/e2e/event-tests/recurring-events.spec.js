const { test, expect } = require( '@playwright/test' );
const {
	gotoAdmin,
	restRequest,
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
	getSiteTimezone,
	nextMatchingWeekday,
	firstLastWeekdayOnOrAfter,
	toRecurrenceId,
	daysUntilUtcWeekday,
} = require( '../helpers/recurrence' );

/**
 * End-to-end coverage for the recurring-events feature (#80).
 *
 * Covers beats 1-4 of the authoring/browsing journey (authoring a rule and
 * publishing, switching the rule and watching dates change, seeing multiple
 * occurrences in the upcoming list, and following a series link to see a
 * real occurrence's date) plus two specs the build has earned: the
 * recurrence-aware `posts_clauses` leaving an ordinary event alone, and REQ-3
 * (a fixed-offset timezone visibly refuses recurrence).
 *
 * RSVP-per-occurrence and cancel-an-occurrence (beats 5-6) are deliberately
 * out of scope — other lanes are still building that surface.
 *
 * Known follow-up, deliberately not written here: occurrence identity is
 * stamped onto the cloned `WP_Post` an occurrence row is rendered from, but
 * nothing reads it back, so every row in the upcoming list renders the
 * series' anchor date and links to the bare series URL. That is being fixed
 * in another lane. Once it lands, two assertions here get to tighten:
 *
 * 1. "Beats 3-4" asserts only `matching.length > 1` for the list. It should
 *    assert the exact projected occurrence dates, in order, against
 *    `nextMatchingWeekday()` — the rows carry `dateText` for exactly that.
 * 2. "Beats 3-4" reaches the occurrence URL by constructing it. It should
 *    instead follow a row's own `href` (also already collected) and land on
 *    that occurrence, which is the journey a reader actually performs.
 *
 * Every seeded post — events *and* the list pages — is deleted in a `finally`
 * block so repeated local runs don't accumulate orphans, the site timezone is
 * restored in `afterAll`, and every date is derived from "now" rather than
 * pinned to a literal, so the suite does not rot into a time bomb.
 */
test.describe( 'Recurring events', () => {
	/**
	 * The site timezone as found, restored in `afterAll`.
	 *
	 * Three specs below need a named timezone (REQ-3) and set one; other e2e
	 * files share this WordPress install, so leaving it changed is a
	 * cross-file side effect.
	 *
	 * @type {string}
	 */
	let originalTimezone;

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();

		await gotoAdmin( page );
		originalTimezone = await getSiteTimezone( page );
		await page.close();
	} );

	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();

		await gotoAdmin( page );
		await setSiteTimezone( page, originalTimezone );
		await page.close();
	} );

	test( 'the recurrence-aware event query neither drops nor duplicates an ordinary, non-recurring event', async ( {
		page,
	} ) => {
		// Named for what it proves. REQ-16's actual criterion — that a site
		// with `gatherpress_has_recurring_events` set to '0' runs byte-identical
		// SQL and performs no extra writes — is a query-level guarantee, and it
		// is proven by the PHP suite (a `$wpdb->queries` capture across the real
		// entry points), not here: an e2e run cannot establish a genuinely
		// flag-off site while other specs in the same run are creating recurring
		// series, and a spec whose stated precondition is false is a spec that
		// cannot fail for the reason its name gives.
		//
		// What *is* worth proving from the browser is the other half: the
		// `posts_clauses` filters this feature adds to every event query must
		// leave a non-recurring event exactly where it was — one row, its own
		// date, its own plain permalink. The LEFT JOIN, the COALESCE ordering
		// and the NULL branch all have to be right for that to hold.
		await gotoAdmin( page );

		const title = `Ordinary event ${ Date.now() }`;
		const { eventId, link, anchor } = await seedEventWithDatetime( page, {
			title,
			daysFromNow: 5,
		} );

		expect( eventId, 'ordinary event seeded via REST' ).toBeTruthy();

		let listPageId;

		try {
			// The permalink itself must render, with the right title.
			await page.goto( link );
			await page.waitForLoadState( 'load' );
			await expect( page.locator( 'body' ) ).toContainText( title );

			// Same event, seen through the upcoming-events list: exactly one
			// row, not duplicated by an occurrence join and not dropped by an
			// inner one.
			await gotoAdmin( page );

			const { pageId, link: listUrl } = await createUpcomingEventsListPage(
				page,
				`Ordinary event upcoming list ${ Date.now() }`
			);

			listPageId = pageId;

			const rows = await getUpcomingRows( page, listUrl );
			const matching = rows.filter( ( row ) => row.title === title );

			expect(
				matching,
				'an ordinary (non-recurring) event appears exactly once in the upcoming list'
			).toHaveLength( 1 );

			// And it is still *itself*: its own anchor date, and its own plain
			// permalink rather than an occurrence URL. A non-recurring event has
			// no occurrence row, so `COALESCE( o.datetime_start_gmt, ... )` must
			// fall through to the events table for both.
			const expectedDateText = new Date(
				anchor.year,
				anchor.month,
				anchor.day
			).toLocaleDateString( 'en-US', {
				month: 'long',
				day: 'numeric',
				year: 'numeric',
			} );

			expect(
				matching[ 0 ].dateText,
				"the row shows the ordinary event's own anchor date"
			).toContain( expectedDateText );

			expect(
				matching[ 0 ].href,
				"the row links to the ordinary event's own plain permalink, with no occurrence segment"
			).toBe( link );
		} finally {
			await gotoAdmin( page );
			await deletePost( page, eventId );

			if ( listPageId ) {
				await deletePost( page, listPageId, 'pages' );
			}
		}
	} );

	test( 'REQ-3: a fixed-offset timezone visibly refuses recurrence', async ( { page } ) => {
		await gotoAdmin( page );

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
			await gotoAdmin( page );
			await deletePost( page, eventId );
		}
	} );

	test( 'Beat 1: authoring a bi-weekly Tuesday/Thursday rule persists and publishes', async ( {
		page,
	} ) => {
		await gotoAdmin( page );
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
			const { status } = await restRequest( page, {
				path: `/wp/v2/gatherpress_events/${ eventId }`,
			} );

			expect( status ).toBe( 'publish' );
		} finally {
			await gotoAdmin( page );
			await deletePost( page, eventId );
		}
	} );

	test( 'Beat 2: switching to monthly last-Wednesday changes the projected occurrence dates', async ( {
		page,
	} ) => {
		await gotoAdmin( page );
		await setSiteTimezone( page, 'America/New_York' );

		const title = `Monthly switch ${ Date.now() }`;
		// Anchored on a Monday, deliberately never a Wednesday. A `daysFromNow`
		// literal would put the anchor on whatever weekday the suite happens to
		// run — and since a broken bare-series resolution renders the anchor
		// date, a spec asserting only "the shown date is a Wednesday" would pass
		// with the resolver entirely dead on one run in seven. Pinning the
		// anchor off a rule weekday, and asserting the exact resolved date
		// below rather than its day-of-week, closes both halves of that.
		const { eventId, anchor } = await seedEventWithDatetime( page, {
			title,
			daysFromNow: daysUntilUtcWeekday( 1 ),
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

			// The dates actually changed, and changed to the *right* dates.
			// The expected value is computed independently in JS
			// (`firstLastWeekdayOnOrAfter` mirrors `Expander::next_monthly_date()`
			// composed with `nth_weekday_of_month()` at ordinal -1), never read
			// back from anything the app produced. Asserting the exact date
			// rather than "it is a Wednesday" is what makes "last" load-bearing:
			// a server resolving `-1` as the *first* Wednesday of the month also
			// produces a Wednesday.
			const { link } = await restRequest( page, {
				path: `/wp/v2/gatherpress_events/${ eventId }`,
			} );

			await page.goto( link );
			await page.waitForLoadState( 'load' );

			const anchorDate = new Date( anchor.year, anchor.month, anchor.day );
			const expectedOccurrence = firstLastWeekdayOnOrAfter( anchorDate, 3 );
			const expectedDateText = expectedOccurrence.toLocaleDateString( 'en-US', {
				month: 'long',
				day: 'numeric',
				year: 'numeric',
			} );

			await expect(
				page.locator( 'body' ),
				'the series permalink resolves to the last Wednesday on or after the anchor'
			).toContainText( expectedDateText );
		} finally {
			await gotoAdmin( page );
			await deletePost( page, eventId );
		}
	} );

	test( 'Beats 3-4: a recurring series expands into multiple upcoming entries, and following one shows a real occurrence date', async ( {
		page,
	} ) => {
		await gotoAdmin( page );
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

		let listPageId;

		try {
			await openEventEditor( page, eventId );
			await enableRecurrence( page );
			await setFrequency( page, { frequency: 'weekly', interval: 2 } );
			await setWeekdays( page, [ 'Tuesday', 'Thursday' ] );
			await setEndCondition( page, { endType: 'count', count: 12 } );
			await saveEvent( page );

			const { link: seriesLink } = await restRequest( page, {
				path: `/wp/v2/gatherpress_events/${ eventId }`,
			} );

			const { pageId, link: listUrl } = await createUpcomingEventsListPage(
				page,
				`Beats 3-4 upcoming list ${ Date.now() }`
			);

			listPageId = pageId;

			const rows = await getUpcomingRows( page, listUrl );
			const matching = rows.filter( ( row ) => row.title === title );

			// Beat 3: the series shows up as several individual entries in
			// the upcoming list, not collapsed to a single row at its
			// anchor date — a 12-occurrence, twice-a-week series easily
			// clears "more than one" inside the list's default window.
			//
			// Deliberately only a count, for now: see the file docblock's
			// follow-up note. Per-row dates and hrefs are collected but not
			// asserted because occurrence identity is not yet read back off
			// the stamped post clone, so every row currently renders the
			// anchor date. Tightening this before that fix lands would be
			// writing against a moving target.
			expect(
				matching.length,
				'a recurring series produces more than one entry in the upcoming list'
			).toBeGreaterThan( 1 );

			// The expected occurrence dates are computed independently in JS
			// (mirroring `Expander::next_scanned_date()` for a weekly rule —
			// interval only affects which *week* a later occurrence falls in,
			// not which weekday it lands on, and both dates below sit in the
			// anchor's own week, at interval offset 0), never read back from
			// anything the app produced.
			const anchorDate = new Date( anchor.year, anchor.month, anchor.day );
			const firstOccurrence = nextMatchingWeekday( anchorDate, [ 2, 4 ] );
			const secondOccurrence = nextMatchingWeekday(
				new Date( firstOccurrence.getTime() + ( 24 * 60 * 60 * 1000 ) ),
				[ 2, 4 ]
			);
			const seriesBase = seriesLink.replace( /\/$/, '' );
			const dateText = ( date ) =>
				date.toLocaleDateString( 'en-US', {
					month: 'long',
					day: 'numeric',
					year: 'numeric',
				} );

			// Beat 4, part one: the exact occurrence URL REQ-8 defines
			// (`{permalink}{Ymd}T{His}/`) resolves and shows that specific
			// occurrence's date.
			const firstUrl = `${ seriesBase }/${ toRecurrenceId( firstOccurrence, '180000' ) }/`;
			const firstResponse = await page.goto( firstUrl );
			await page.waitForLoadState( 'load' );

			expect(
				firstResponse.status(),
				'the occurrence URL resolves (pretty permalinks are in effect for it)'
			).toBe( 200 );

			await expect(
				page.locator( 'body' ),
				"the occurrence URL's page shows that exact occurrence's date"
			).toContainText( dateText( firstOccurrence ) );

			// Beat 4, part two, and the assertion that makes part one mean
			// anything: the *second* occurrence's URL must render the *second*
			// occurrence's date. The bare-series fallback
			// (`Rewrite::maybe_resolve_bare_series()`) always resolves to the
			// next upcoming occurrence, which is the first one — so it can
			// satisfy every assertion about the first occurrence's URL without
			// the occurrence segment being read at all. Only a later
			// occurrence's date distinguishes "the URL was parsed" from "the
			// URL was discarded and the series resolved from scratch."
			const secondUrl = `${ seriesBase }/${ toRecurrenceId( secondOccurrence, '180000' ) }/`;
			const secondResponse = await page.goto( secondUrl );
			await page.waitForLoadState( 'load' );

			expect(
				secondResponse.status(),
				"a later occurrence's URL also resolves"
			).toBe( 200 );

			await expect(
				page.locator( 'body' ),
				"a later occurrence's URL shows that later occurrence's date, not the series' next-upcoming one"
			).toContainText( dateText( secondOccurrence ) );

			// A well-formed but nonexistent recurrence ID must 404 rather than
			// quietly rendering the series. This drives `parse_request()`'s
			// `error=404` branch and the `redirect_canonical` suppression that
			// goes with it — without the latter, core finds the series by its
			// `name` query var and 301s to the bare series URL, turning a stale
			// or hand-typed link into "renders the series at its anchor date."
			const bogusDate = new Date( firstOccurrence.getTime() + ( 400 * 24 * 60 * 60 * 1000 ) );
			const bogusResponse = await page.goto(
				`${ seriesBase }/${ toRecurrenceId( bogusDate, '180000' ) }/`
			);

			expect(
				bogusResponse.status(),
				'a well-formed recurrence ID with no matching occurrence row 404s'
			).toBe( 404 );

			// Beat 4, part three: following the series' own (bare) link — the
			// one every row in the list actually points to — lands on the
			// next-upcoming occurrence via `Rewrite::maybe_resolve_bare_series()`
			// (PRD D-4), rather than the series' stale anchor date. The anchor
			// is a Monday and the rule is Tuesday/Thursday, so "the first
			// occurrence" and "the anchor" are never the same date.
			await page.goto( seriesLink );
			await page.waitForLoadState( 'load' );

			await expect(
				page.locator( 'body' ),
				'the bare series URL resolves to the next-upcoming occurrence'
			).toContainText( dateText( firstOccurrence ) );

			await expect(
				page.locator( 'body' ),
				"the bare series URL does not render the series' own anchor date"
			).not.toContainText( dateText( anchorDate ) );
		} finally {
			await gotoAdmin( page );
			await deletePost( page, eventId );

			if ( listPageId ) {
				await deletePost( page, listPageId, 'pages' );
			}
		}
	} );
} );
