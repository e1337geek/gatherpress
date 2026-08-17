<?php
/**
 * Forward splits — REQ-13's "apply going forward".
 *
 * An organizer editing a series from occurrence 3 of 6 chooses whether the edit
 * rewrites the whole series (retroactive, the default) or applies only from that
 * occurrence onward. The second choice splits the series here: the original post
 * is capped at the occurrences before the split point, a second event post
 * carries the rest, and the organizer's edit lands on the second post.
 *
 * Three properties of this class are the point of the whole design, not
 * implementation details:
 *
 * - **Occurrence rows are moved, never deleted and regenerated.** PRD C-1 makes
 *   identity `(series_post_id, recurrence_id)` with `recurrence_id` derived from
 *   the occurrence's own local start, so moving a row changes which post owns it
 *   and nothing else. Permalinks, cancellation state and RSVP mappings survive
 *   because none of them was recreated.
 * - **RSVP terms are renamed, never re-tagged.** `wp_update_term()` leaves
 *   `term_taxonomy_id` alone, so every `term_relationships` row survives however
 *   many RSVPs an occurrence carries.
 * - **The scope degrades automatically.** "Forward" from the first occurrence is
 *   retroactive and produces no split at all; a side left holding exactly one
 *   occurrence is a plain non-recurring event rather than a series of one.
 *
 * @package GatherPress\Core\Event\Recurrence
 * @since 0.36.0
 */

namespace GatherPress\Core\Event\Recurrence;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

use GatherPress\Core\Event\Setup as Event_Setup;
use GatherPress\Core\Traits\Singleton;
use WP_Error;
use WP_Post;

/**
 * Class Splitter.
 *
 * Singleton owning REQ-13's forward split and REQ-13's RSVP-impact reporting.
 *
 * @since 0.36.0
 */
final class Splitter {

	/**
	 * Enforces a single instance of this class.
	 */
	use Singleton;

	/**
	 * Post meta this class never copies onto the forward post.
	 *
	 * The recurrence blob and the datetime blob are rewritten from the split
	 * point, so copying them would seed the forward post with the origin's
	 * anchor. The edit-lock pair is per-user session state.
	 *
	 * @since 0.36.0
	 * @var string[]
	 */
	const UNCOPIED_META_KEYS = array(
		'_edit_last',
		'_edit_lock',
		'gatherpress_datetime',
		'gatherpress_datetime_end',
		'gatherpress_datetime_start',
		'gatherpress_timezone',
		Meta::META_KEY,
	);

	/**
	 * Class constructor.
	 *
	 * @since 0.36.0
	 */
	protected function __construct() {
	}

	/**
	 * Split a series forward at one of its occurrences.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $post_id       Post the organizer is editing.
	 * @param string $recurrence_id Occurrence the edit is being made from.
	 *
	 * @return array|WP_Error The split result, or an error when the occurrence or rule is missing.
	 */
	public function split_forward( int $post_id, string $recurrence_id ) {
		$row = Occurrences::get_instance()->find_in_series(
			Series::get_instance()->resolve_post_ids( $post_id ),
			$recurrence_id
		);

		if ( null === $row ) {
			return new WP_Error(
				'gatherpress_occurrence_not_found',
				__( 'No occurrence matches the given post and recurrence ID.', 'gatherpress' ),
				array( 'status' => 404 )
			);
		}

		// The occurrence's own post, not the post the request named: a series
		// already split once holds occurrence 5 on a sibling post, and the split
		// has to cap the rule that actually produces it (PRD C-2).
		$origin_post_id = (int) $row['series_post_id'];
		$rule           = Rule::from_post( $origin_post_id );
		$origin_post    = get_post( $origin_post_id );

		if ( ! $rule instanceof Rule || ! $origin_post instanceof WP_Post ) {
			return new WP_Error(
				'gatherpress_not_recurring',
				__( 'This event does not carry a recurrence rule to split.', 'gatherpress' ),
				array( 'status' => 400 )
			);
		}

		return $this->split_owned_series( $origin_post, $rule, $row );
	}

	/**
	 * Perform the split once the owning post, its rule and the split row are known.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_Post $origin_post Post whose rule produces the split occurrence.
	 * @param Rule    $rule        That post's rule.
	 * @param array   $row         The occurrence row the split happens at.
	 *
	 * @return array The split result.
	 */
	protected function split_owned_series( WP_Post $origin_post, Rule $rule, array $row ): array {
		$origin_post_id = (int) $origin_post->ID;
		$rows           = Occurrences::get_instance()->select_for_series( array( $origin_post_id ) );
		$identifiers    = wp_list_pluck( $rows, 'recurrence_id' );
		$index          = (int) array_search( (string) $row['recurrence_id'], $identifiers, true );

		// "Forward" from the first occurrence is retroactive: every occurrence
		// the series has is at or after it, so a split would leave the original
		// holding nothing and the forward post holding the whole series.
		if ( 0 === $index ) {
			return $this->result( false, 'first_occurrence', $origin_post_id );
		}

		$forward_rows = array_slice( $rows, $index );
		$forward_ids  = array_map( 'strval', array_slice( $identifiers, $index ) );

		$forward_post_id = $this->create_forward_post( $origin_post, $row );

		$moved   = Occurrences::get_instance()->move_to_post( $origin_post_id, $forward_post_id, $forward_ids );
		$renamed = Rsvp_Occurrence::get_instance()->rename_series( $origin_post_id, $forward_post_id, $forward_ids );

		$origin_recurring  = $this->apply_capped_rule( $origin_post_id, $rule, $index, $rows[0] );
		$forward_recurring = $this->apply_forward_rule( $forward_post_id, $rule, $index, $forward_rows );

		Series::get_instance()->join( $origin_post_id, $forward_post_id );
		Context::flush_resolved();

		return array(
			'split'              => true,
			'reason'             => '',
			'origin_post_id'     => $origin_post_id,
			'forward_post_id'    => $forward_post_id,
			'moved'              => $moved,
			'renamed_rsvp_terms' => $renamed,
			'origin_recurring'   => $origin_recurring,
			'forward_recurring'  => $forward_recurring,
		);
	}

	/**
	 * Build the result payload a split reports back.
	 *
	 * @since 0.36.0
	 *
	 * @param bool   $split          Whether a split actually happened.
	 * @param string $reason         Why it did not, when it did not.
	 * @param int    $origin_post_id Post the series was split from.
	 *
	 * @return array The result payload.
	 */
	protected function result( bool $split, string $reason, int $origin_post_id ): array {
		return array(
			'split'              => $split,
			'reason'             => $reason,
			'origin_post_id'     => $origin_post_id,
			'forward_post_id'    => 0,
			'moved'              => 0,
			'renamed_rsvp_terms' => 0,
			'origin_recurring'   => true,
			'forward_recurring'  => false,
		);
	}

	/**
	 * Create the event post the forward half of the series lives on.
	 *
	 * Inserted **without** a recurrence blob deliberately. `Occurrences` projects
	 * on `wp_after_insert_post`, and a forward post that arrived already
	 * recurring would have its own rows generated before the origin's rows could
	 * be moved onto it — the moved rows would then collide with freshly generated
	 * ones on the composite primary key, and the recycling REQ-13 requires would
	 * turn into a delete-and-regenerate after all. The rule is written afterwards,
	 * once the rows are already there for the upsert to find.
	 *
	 * @since 0.36.0
	 *
	 * @param WP_Post $origin_post Post being split.
	 * @param array   $row         Occurrence row the forward post is anchored at.
	 *
	 * @return int The new post ID.
	 */
	protected function create_forward_post( WP_Post $origin_post, array $row ): int {
		$forward_post_id = (int) wp_insert_post(
			array(
				'post_author'  => (int) $origin_post->post_author,
				'post_content' => $origin_post->post_content,
				'post_excerpt' => $origin_post->post_excerpt,
				'post_status'  => $origin_post->post_status,
				'post_title'   => $origin_post->post_title,
				'post_type'    => $origin_post->post_type,
				'meta_input'   => $this->forward_meta_input( (int) $origin_post->ID, $row ),
			)
		);

		$this->copy_terms( (int) $origin_post->ID, $forward_post_id, (string) $origin_post->post_type );

		// The datetime blob arrived through `meta_input`, which lands before
		// `wp_after_insert_post`, so the events-table row is already written by
		// the time this returns. Writing it again here would be a second,
		// identical write -- see `Event\Setup::set_datetimes()`.
		return $forward_post_id;
	}

	/**
	 * Build the forward post's `meta_input`, carrying the origin's meta forward.
	 *
	 * Everything the origin carries comes across — venue overrides, online-event
	 * links, attendance limits, anything a companion plugin stored — except the
	 * keys this class rewrites and the per-session edit lock.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $origin_post_id Post being split.
	 * @param array $row            Occurrence row the forward post is anchored at.
	 *
	 * @return array<string, mixed> Meta to insert with the forward post.
	 */
	protected function forward_meta_input( int $origin_post_id, array $row ): array {
		$meta   = array();
		$stored = get_post_meta( $origin_post_id );

		foreach ( (array) $stored as $meta_key => $values ) {
			if ( in_array( $meta_key, self::UNCOPIED_META_KEYS, true )
				|| in_array( $meta_key, Meta::DERIVED_META_KEYS, true )
			) {
				continue;
			}

			$meta[ $meta_key ] = maybe_unserialize( $values[0] );
		}

		$meta['gatherpress_datetime'] = wp_json_encode(
			array(
				'dateTimeStart' => (string) $row['datetime_start'],
				'dateTimeEnd'   => (string) $row['datetime_end'],
				'timezone'      => (string) $row['timezone'],
			)
		);

		return $meta;
	}

	/**
	 * Copy the origin's taxonomy terms onto the forward post.
	 *
	 * The venue association and any topics come across, so REQ-15's "the venue
	 * appears on every occurrence" holds across a split. The series taxonomy is
	 * excluded: `Series::join()` owns it, and copying it here would put the
	 * forward post in the series before the origin had a term of its own.
	 *
	 * @since 0.36.0
	 *
	 * @param int    $origin_post_id  Post being split.
	 * @param int    $forward_post_id Post the split created.
	 * @param string $post_type       Post type both share.
	 *
	 * @return void
	 */
	protected function copy_terms( int $origin_post_id, int $forward_post_id, string $post_type ): void {
		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			if ( Series::TAXONOMY === $taxonomy ) {
				continue;
			}

			$term_ids = wp_get_object_terms( $origin_post_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $term_ids ) || array() === $term_ids ) {
				continue;
			}

			wp_set_object_terms( $forward_post_id, array_map( 'intval', $term_ids ), $taxonomy );
		}
	}

	/**
	 * Cap the origin's rule at the occurrences before the split point.
	 *
	 * Capped by `COUNT`, never by `UNTIL`: the count is exactly the number of
	 * rows staying behind, so re-projection reproduces precisely the rows that
	 * were already there and `delete_stale_rows()` has nothing to delete. An
	 * `UNTIL` bound would have to be derived from a date and would depend on the
	 * rule landing on it.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $post_id    Origin post ID.
	 * @param Rule  $rule       The rule being capped.
	 * @param int   $index      Index of the split occurrence, and so the number of rows staying.
	 * @param array $first_row  The origin's first occurrence row, for the demotion path.
	 *
	 * @return bool True when the origin remains a recurring series.
	 */
	protected function apply_capped_rule( int $post_id, Rule $rule, int $index, array $first_row ): bool {
		$values             = $rule->to_array();
		$values['end_type'] = Rule::END_TYPE_COUNT;
		$values['count']    = $index;
		$values['until']    = '';

		// One occurrence left behind is not a series: REQ-13 says that side is a
		// plain non-recurring event.
		if ( 1 === $index ) {
			return ! $this->demote_to_plain_event( $post_id, $first_row, $values );
		}

		$this->write_rule( $post_id, $values );

		return true;
	}

	/**
	 * Write the forward post's rule, anchored at the split occurrence.
	 *
	 * A `COUNT` rule's count is reduced by the occurrences left behind; `UNTIL`
	 * and `never` bounds carry across unchanged, because both are absolute and
	 * the forward post's own anchor is what moved.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $post_id      Forward post ID.
	 * @param Rule  $rule         The origin's rule.
	 * @param int   $index        Index of the split occurrence.
	 * @param array $forward_rows Occurrence rows that moved to the forward post.
	 *
	 * @return bool True when the forward post is a recurring series.
	 */
	protected function apply_forward_rule( int $post_id, Rule $rule, int $index, array $forward_rows ): bool {
		$values = $rule->to_array();

		if ( Rule::END_TYPE_COUNT === $rule->end_type() ) {
			$values['count'] = $rule->count() - $index;
		}

		// Exactly one occurrence forward is a single-occurrence edit, not a
		// series -- but only a `COUNT` rule can be *known* to produce exactly
		// one. An `UNTIL` or `never` rule projected to a single row may simply
		// have run into the projection horizon, and demoting it would silently
		// discard every date beyond it.
		if ( 1 === count( $forward_rows ) && Rule::END_TYPE_COUNT === $rule->end_type() ) {
			return ! $this->demote_to_plain_event( $post_id, $forward_rows[0], $values );
		}

		$this->write_rule( $post_id, $values );

		return true;
	}

	/**
	 * Write a rule blob and run the production derivation and projection for it.
	 *
	 * `Meta::set_recurrence()` then `Occurrences::project()`, in that order,
	 * because that is the order their `wp_after_insert_post` handlers run in
	 * (priority 10, then 20) and `project()` reads the mirrors the first writes.
	 * Calling them directly rather than through `wp_update_post()` keeps the
	 * split synchronous — the REST response reports the state it produced, not
	 * the state a `shutdown` handler will produce later — and leaves
	 * `post_modified` alone.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $post_id Post to write the rule on.
	 * @param array $values  Rule values, in `Rule::to_array()` shape.
	 *
	 * @return void
	 */
	protected function write_rule( int $post_id, array $values ): void {
		update_post_meta( $post_id, Meta::META_KEY, wp_json_encode( $values ) );

		Meta::get_instance()->set_recurrence( $post_id );
		Occurrences::get_instance()->project( $post_id );
	}

	/**
	 * Turn a post holding exactly one occurrence into a plain non-recurring event.
	 *
	 * The rule goes, the occurrence row goes, the event's own datetime becomes
	 * that occurrence's, and the occurrence's RSVP term is dropped so its RSVPs
	 * read series-wide again — which, on a single-date event, *is* that date. No
	 * RSVP is moved, deleted or re-attached.
	 *
	 * **A cancelled occurrence is never demoted.** PRD C-5 makes cancellation
	 * occurrence state, and a plain event has nowhere to record it: demoting
	 * would silently un-cancel a date the organizer cancelled. That side keeps a
	 * one-occurrence rule instead, which is the lesser of the two wrongs and the
	 * only one that loses no information.
	 *
	 * @since 0.36.0
	 *
	 * @param int   $post_id Post to demote.
	 * @param array $row     Its single remaining occurrence row.
	 * @param array $values  Rule values to fall back to when the occurrence is cancelled.
	 *
	 * @return bool True when the post was demoted.
	 */
	protected function demote_to_plain_event( int $post_id, array $row, array $values ): bool {
		if ( Occurrences::STATUS_SCHEDULED !== (string) $row['status'] ) {
			$values['end_type'] = Rule::END_TYPE_COUNT;
			$values['count']    = 1;
			$values['until']    = '';

			$this->write_rule( $post_id, $values );

			return false;
		}

		Meta::get_instance()->remove_recurrence( $post_id );
		Occurrences::get_instance()->delete_for_post( $post_id );
		Rsvp_Occurrence::get_instance()->detach_series( $post_id, array( (string) $row['recurrence_id'] ) );

		update_post_meta(
			$post_id,
			'gatherpress_datetime',
			wp_json_encode(
				array(
					'dateTimeStart' => (string) $row['datetime_start'],
					'dateTimeEnd'   => (string) $row['datetime_end'],
					'timezone'      => (string) $row['timezone'],
				)
			)
		);

		Event_Setup::get_instance()->set_datetimes( $post_id );

		return true;
	}

	/**
	 * Report which occurrences a candidate rule would strand, and how many RSVPs ride on them.
	 *
	 * REQ-13's last acceptance criterion, and brief §6 Q12's reasoning for it:
	 * migrating an RSVP to a date the attendee never agreed to is worse than
	 * leaving it where it is, so GatherPress leaves it — and tells the organizer
	 * how many are affected before they commit the change.
	 *
	 * @since 0.36.0
	 *
	 * @param int  $post_id Series post ID the candidate rule would be saved on.
	 * @param Rule $rule    Candidate rule.
	 *
	 * @return array{removed: string[], rsvp_count: int} The stranded occurrences and their RSVP count.
	 */
	public function rsvp_impact( int $post_id, Rule $rule ): array {
		$rows     = Occurrences::get_instance()->select_for_series( array( $post_id ) );
		$current  = wp_list_pluck( $rows, 'recurrence_id' );
		$proposed = Occurrences::get_instance()->preview_recurrence_ids( $post_id, $rule );
		$removed  = array_values( array_diff( array_map( 'strval', $current ), $proposed ) );

		return array(
			'removed'    => $removed,
			'rsvp_count' => Rsvp_Occurrence::get_instance()->count_rsvps( $post_id, $removed ),
		);
	}
}
