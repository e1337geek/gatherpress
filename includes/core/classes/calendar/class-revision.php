<?php
/**
 * Monotonic calendar revision for a logical series.
 *
 * This file defines the `Revision` class, the single source of the number
 * `SEQUENCE` reports for a series' components. RFC 5545 section 3.8.7.4 makes
 * that number the only signal by which a client decides whether an incoming
 * component supersedes one it already holds, so anything that changes published
 * calendar content has to move it, including changes that never touch the
 * post row, and including two changes that land in the same second.
 *
 * @package GatherPress\Core\Calendar
 * @since 0.36.0
 */

namespace GatherPress\Core\Calendar;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Event\Recurrence\Series;
use GatherPress\Core\Traits\Singleton;

/**
 * Monotonic calendar revision for a logical series.
 *
 * The revision is stored per post and read across the whole logical series, so
 * a subscription held against any one fragment sees a change made to any other.
 * It is seeded from `post_modified_gmt` and never falls below it, which is what
 * keeps an ordinary post edit visible to a client without a stored value ever
 * having been written.
 *
 * Two properties matter and neither is free:
 *
 * 1. **Monotonic.** A sequence that moves backwards is one a client may ignore
 *    forever, so the stored value only ever rises.
 * 2. **Strictly increasing per change.** `time()` has one-second resolution and
 *    a split caps a rule, moves rows and rewrites terms well inside one second.
 *    `advance()` therefore takes the greater of "one past what is already
 *    published" and "seconds since the epoch", so same-second changes still
 *    separate.
 *
 * @since 0.36.0
 */
final class Revision {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Post meta key holding a series fragment's stored revision.
	 *
	 * @since 0.36.0
	 *
	 * @var string
	 */
	const META_KEY = 'gatherpress_calendar_revision';

	/**
	 * Epoch the revision counts from, as a Unix timestamp.
	 *
	 * 2020-01-01 00:00:00 UTC. A raw timestamp is monotonic but reaches the RFC
	 * 5545 INTEGER ceiling in January 2038; the epoch buys another sixty years
	 * at the same one-second resolution.
	 *
	 * @since 0.36.0
	 *
	 * @var int
	 */
	const EPOCH = 1577836800;

	/**
	 * Largest value RFC 5545 allows an INTEGER property to carry.
	 *
	 * The clamp guards against corrupt data, not ordinary growth: a saturated
	 * revision freezes the series in subscribers' calendars, because every later
	 * change repeats the ceiling and is ignored. Emitting an out-of-range
	 * INTEGER instead risks clients rejecting the whole component.
	 *
	 * @since 0.36.0
	 *
	 * @var int
	 */
	const CEILING = 2147483647;

	/**
	 * The highest revision stored anywhere in a post's logical series.
	 *
	 * Read across the series rather than off one post, so a client subscribed to
	 * the origin fragment of a split series still sees a change made to the
	 * fragment that carries the forward dates.
	 *
	 * Zero when nothing has ever advanced the series, which is the state every
	 * event is in until an occurrence row or a rule is written. Callers combine
	 * it with their own `post_modified_gmt` reading rather than treating zero as
	 * "no revision".
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Any post ID belonging to the series.
	 *
	 * @return int The stored revision, or 0 when none has been written.
	 */
	public function stored( int $post_id ): int {
		$highest = 0;

		foreach ( Series::get_instance()->resolve_post_ids( $post_id ) as $sibling_id ) {
			$highest = max( $highest, (int) get_post_meta( (int) $sibling_id, self::META_KEY, true ) );
		}

		return min( max( 0, $highest ), self::CEILING );
	}

	/**
	 * The revision a client currently sees for a post's logical series.
	 *
	 * The greater of the stored revision and the post's own modification time,
	 * so an event whose revision has never been advanced still reports a value
	 * that rises when it is edited.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Any post ID belonging to the series.
	 *
	 * @return int The current revision.
	 */
	public function current( int $post_id ): int {
		return max( $this->stored( $post_id ), $this->from_post_modified( $post_id ) );
	}

	/**
	 * Advance a logical series' revision past everything already published.
	 *
	 * **This is the seam a split calls.** Capping the origin's `RRULE`, moving
	 * occurrence rows to a sibling and renaming the RSVP terms are all direct
	 * storage writes that leave `post_modified_gmt` alone, so without this the
	 * origin's already-published `UID` acquires a shorter rule while reporting
	 * the same `SEQUENCE`, and a subscriber is entitled to keep the dates the
	 * split just moved away, then accept them again under the sibling's
	 * identifier.
	 *
	 * The new value is written to every post in the series so the series answers
	 * with it whichever fragment is asked, and it is at least one past the
	 * current value, so a second call in the same second still separates.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Any post ID belonging to the series.
	 *
	 * @return int The new revision.
	 */
	public function advance( int $post_id ): int {
		$next = min(
			max( $this->current( $post_id ) + 1, time() - self::EPOCH ),
			self::CEILING
		);

		foreach ( Series::get_instance()->resolve_post_ids( $post_id ) as $sibling_id ) {
			update_post_meta( (int) $sibling_id, self::META_KEY, $next );
		}

		return $next;
	}

	/**
	 * A post's modification time as an offset from the revision epoch.
	 *
	 * @since 0.36.0
	 *
	 * @param int $post_id Post ID to read.
	 *
	 * @return int Seconds since the epoch, floored at zero.
	 */
	private function from_post_modified( int $post_id ): int {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		// `strtotime()` answers `false` for a date nothing can parse, which casts
		// to zero and floors below. That is one expression rather than a guard
		// whose only reachable input is corrupt storage.
		$modified = (int) strtotime( (string) $post->post_modified_gmt );

		return min( max( 0, $modified - self::EPOCH ), self::CEILING );
	}
}
