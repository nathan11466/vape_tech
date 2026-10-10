<?php
/**
 * Contributions: anything that can earn a reward.
 *
 * Lifecycle:
 *
 *   held      new account or negative reputation; only moderators can see it
 *   voting    members can see it, reveal it and vote
 *   accepted  enough weighted votes (or a moderator); author reward pending
 *   rejected  voted down or rejected by a moderator; penalties applied
 *   withdrawn accepted, then voted down inside the holding period; pending
 *             reward clawed back, no penalty (a code that died early)
 *
 * Integrations (coupons today) listen to 'vc_rewards_contribution_state' to
 * publish or unpublish their own records.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Salted hash of the caller's IP. Never the bare address; only used to spot
 * the same machine on both sides of a vote.
 */
function vc_rewards_ip_hash() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $ip = apply_filters('vc_rewards_client_ip', $ip);
    return $ip === '' ? '' : wp_hash('vc_rewards_ip|' . $ip);
}

/**
 * $args:
 *   state        force a starting state ('held' when the source system is
 *                already holding the post for moderation); otherwise the
 *                author's standing decides between 'held' and 'voting'
 *   fingerprint  normalised-content hash, for spotting reposted text
 */
function vc_rewards_create_contribution($object_type, $object_id, $contrib_type, $author_id, array $args = array()) {
    global $wpdb;
    if (!vc_rewards_type($contrib_type)) {
        return 0;
    }
    $state = isset($args['state']) ? $args['state'] : (vc_rewards_under_review($author_id) ? 'held' : 'voting');
    $wpdb->insert(vc_rewards_table('contributions'), array(
        'object_type'    => $object_type,
        'object_id'      => (int) $object_id,
        'contrib_type'   => $contrib_type,
        'author_id'      => (int) $author_id,
        'author_ip_hash' => vc_rewards_ip_hash(),
        'state'          => $state,
        'fingerprint'    => isset($args['fingerprint']) ? (string) $args['fingerprint'] : '',
        'created_at'     => vc_rewards_now(),
    ));
    $id = (int) $wpdb->insert_id;
    if ($id) {
        do_action('vc_rewards_contribution_state', $id, $state, '');
    }
    return $id;
}

/**
 * Hash of a text with formatting, case and punctuation stripped, so a
 * reposted guide matches even after light reformatting.
 */
function vc_rewards_fingerprint($text) {
    $text = strtolower(wp_strip_all_tags((string) $text));
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    $text = trim(preg_replace('/\s+/', ' ', $text));
    return $text === '' ? '' : md5($text);
}

/** An earlier contribution, by someone else, with the same fingerprint. */
function vc_rewards_fingerprint_taken($fingerprint, $author_id) {
    global $wpdb;
    if ($fingerprint === '') {
        return 0;
    }
    $table = vc_rewards_table('contributions');
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE fingerprint = %s AND author_id <> %d AND state <> 'rejected' LIMIT 1",
        $fingerprint,
        (int) $author_id
    ));
}

function vc_rewards_get_contribution($id) {
    global $wpdb;
    $table = vc_rewards_table('contributions');
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id));
}

function vc_rewards_contribution_for($object_type, $object_id) {
    global $wpdb;
    $table = vc_rewards_table('contributions');
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE object_type = %s AND object_id = %d",
        $object_type,
        (int) $object_id
    ));
}

function vc_rewards_set_state($contribution, $state, array $extra = array()) {
    global $wpdb;
    $old = $contribution->state;
    $data = array_merge(array('state' => $state), $extra);
    if (in_array($state, array('accepted', 'rejected', 'withdrawn'), true)) {
        $data['resolved_at'] = vc_rewards_now();
    }
    $wpdb->update(vc_rewards_table('contributions'), $data, array('id' => (int) $contribution->id));
    do_action('vc_rewards_contribution_state', (int) $contribution->id, $state, $old);
}

function vc_rewards_has_accepted_contribution($user_id) {
    global $wpdb;
    $table = vc_rewards_table('contributions');
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM $table WHERE author_id = %d AND state = 'accepted' LIMIT 1",
        (int) $user_id
    ));
}

/** The author's pending reward row still exists, so a clawback is possible. */
function vc_rewards_in_holding($contribution) {
    global $wpdb;
    $table = vc_rewards_table('ledger');
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM $table WHERE contribution_id = %d AND user_id = %d AND kind = 'contribution' AND status = 'pending' LIMIT 1",
        (int) $contribution->id,
        (int) $contribution->author_id
    ));
}

/**
 * Totals of the weighted votes on a contribution.
 */
function vc_rewards_tally($contribution) {
    global $wpdb;
    $type     = vc_rewards_type($contribution->contrib_type);
    $verdicts = vc_rewards_verdicts($type['kind']);
    $table    = vc_rewards_table('votes');
    $rows     = $wpdb->get_results($wpdb->prepare(
        "SELECT voter_id, verdict, voter_rank, weight FROM $table WHERE contribution_id = %d",
        (int) $contribution->id
    ));

    $t = array(
        'positive' => 0.0, 'negative' => 0.0,
        'positive_voters' => 0, 'negative_voters' => 0,
        'moderator_positive' => false, 'moderator_negative' => false,
        'expired' => 0.0, 'spam' => 0.0,
    );
    foreach ($rows as $row) {
        $side   = isset($verdicts[$row->verdict]) ? $verdicts[$row->verdict]['side'] : 0;
        $weight = (float) $row->weight;
        if ($side > 0) {
            $t['positive'] += $weight;
            if ($weight > 0) {
                $t['positive_voters']++;
                $t['moderator_positive'] = $t['moderator_positive'] || $row->voter_rank === 'moderator';
            }
        } elseif ($side < 0) {
            $t['negative'] += $weight;
            if ($weight > 0) {
                $t['negative_voters']++;
                $t['moderator_negative'] = $t['moderator_negative'] || $row->voter_rank === 'moderator';
            }
            if ($row->verdict === 'expired') {
                $t['expired'] += $weight;
            }
            if ($row->verdict === 'spam') {
                $t['spam'] += $weight;
            }
        }
    }
    $t['score'] = $t['positive'] - $t['negative'];
    return $t;
}

/**
 * Re-check a contribution after a vote and move it on if the votes say so.
 */
function vc_rewards_evaluate($contribution_id) {
    global $wpdb;
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c) {
        return null;
    }
    $type  = vc_rewards_type($c->contrib_type);
    $t     = vc_rewards_tally($c);
    $alone = (bool) vc_rewards_setting('moderator_can_accept_alone');

    $wpdb->update(vc_rewards_table('contributions'), array('score' => $t['score']), array('id' => (int) $c->id));

    if ($c->state === 'voting') {
        $accept = ($t['score'] >= $type['threshold'] && $t['positive_voters'] >= $type['min_voters'])
            || ($alone && $t['moderator_positive'] && $t['score'] > 0);

        if ($type['kind'] === 'factual') {
            $reject = ($t['score'] <= -$type['threshold'] && $t['negative_voters'] >= $type['min_voters'])
                || ($alone && $t['moderator_negative'] && $t['score'] < 0);
            // A code most voters call expired was never the author's lie.
            $reason = $t['expired'] * 2 > $t['negative'] ? 'expired' : 'invalid';
        } else {
            // "Not helpful" never counts against anyone; only spam votes do.
            $reject = $t['spam'] - $t['positive'] >= (float) vc_rewards_setting('spam_threshold');
            $reason = 'spam';
        }

        if ($accept) {
            vc_rewards_accept($c->id);
        } elseif ($reject) {
            vc_rewards_reject($c->id, $reason);
        }
    } elseif ($c->state === 'accepted' && $t['score'] <= -$type['threshold'] && vc_rewards_in_holding($c)) {
        vc_rewards_withdraw($c->id);
    }

    return vc_rewards_get_contribution($contribution_id);
}

function vc_rewards_author_reward($contribution, array $tally) {
    $type  = vc_rewards_type($contribution->contrib_type);
    $bonus = min(
        (int) round($tally['positive'] * vc_rewards_setting('points_per_weight')),
        (int) vc_rewards_setting('vote_bonus_cap')
    );
    return (int) round(($type['base_points'] + $bonus) * (float) $contribution->multiplier);
}

function vc_rewards_accept($contribution_id) {
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || !in_array($c->state, array('voting', 'held', 'rejected'), true)) {
        return false;
    }
    $type  = vc_rewards_type($c->contrib_type);
    $tally = vc_rewards_tally($c);

    vc_rewards_set_state($c, 'accepted', array('reject_reason' => ''));

    vc_rewards_ledger_add($c->author_id, vc_rewards_author_reward($c, $tally), 'contribution', array(
        'settle_days'     => $type['holding_days'],
        'contribution_id' => $c->id,
        /* translators: %s: contribution type, e.g. Coupon */
        'note'            => sprintf(__('%s accepted', 'vc-rewards'), $type['label']),
    ));
    vc_rewards_add_reputation($c->author_id, vc_rewards_setting('rep_accepted'), 'accepted', $c->id);

    // Voters on an overturned rejection were already graded once; the
    // flaggers are charged separately by vc_rewards_overturn().
    if ($c->state !== 'rejected') {
        vc_rewards_grade_voters($c, 1);
    }
    return true;
}

/**
 * $reason: invalid (didn't work when posted), expired, fake, spam, copied
 * (someone else's text), removed (deleted at the source; no penalty).
 */
function vc_rewards_reject($contribution_id, $reason) {
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || in_array($c->state, array('rejected'), true)) {
        return false;
    }
    $type = vc_rewards_type($c->contrib_type);
    $was  = $c->state;

    vc_rewards_void_for_contribution($c->id, array('contribution'), $c->author_id);
    vc_rewards_set_state($c, 'rejected', array('reject_reason' => $reason));

    if ($reason === 'fake' || $reason === 'spam' || $reason === 'copied') {
        $mult = vc_rewards_setting('penalty_fake_mult');
        $rep  = vc_rewards_setting('rep_rejected_fake');
    } elseif ($reason === 'invalid') {
        $mult = vc_rewards_setting('penalty_invalid_mult');
        $rep  = vc_rewards_setting('rep_rejected_invalid');
    } else {
        $mult = 0;
        $rep  = 0;
    }

    if ($mult) {
        vc_rewards_ledger_add($c->author_id, -1 * (int) round($type['base_points'] * $mult), 'penalty', array(
            'status'          => 'settled',
            'contribution_id' => $c->id,
            /* translators: 1: contribution type, 2: reason */
            'note'            => sprintf(__('%1$s rejected (%2$s)', 'vc-rewards'), $type['label'], $reason),
        ));
    }
    if ($rep) {
        vc_rewards_add_reputation($c->author_id, $rep, 'rejected_' . $reason, $c->id);
    }

    // A spam post during a new account's hold restarts the hold, and repeat
    // offenders are locked out until a moderator looks.
    if (in_array($reason, array('fake', 'spam', 'copied'), true) && vc_rewards_under_review($c->author_id)) {
        update_user_meta($c->author_id, '_vc_hold_approved', 0);
        $strikes = (int) get_user_meta($c->author_id, '_vc_hold_rejections', true) + 1;
        update_user_meta($c->author_id, '_vc_hold_rejections', $strikes);
        if ($strikes >= (int) vc_rewards_setting('hold_ban_after_rejections')) {
            update_user_meta($c->author_id, '_vc_banned', 1);
        }
    }

    if ($was === 'voting') {
        vc_rewards_grade_voters($c, -1);
    }
    return true;
}

/** Accepted, then voted down while the reward was still pending. */
function vc_rewards_withdraw($contribution_id) {
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || $c->state !== 'accepted') {
        return false;
    }
    vc_rewards_void_for_contribution($c->id, array('contribution'), $c->author_id);
    vc_rewards_set_state($c, 'withdrawn');
    return true;
}

/**
 * Pay and grade everyone who voted, once the outcome is known.
 *
 * Factual votes: a vote on the winning side earns points and reputation; a
 * vote on the losing side costs a little reputation. Newcomers (weight 0)
 * earn nothing but are still graded -- that is how they reach Member.
 *
 * Helpful votes have no right answer, so every weighted voter gets the same
 * small amount whatever they said, and nobody is graded.
 */
function vc_rewards_grade_voters($contribution, $outcome_side) {
    global $wpdb;
    $type     = vc_rewards_type($contribution->contrib_type);
    $verdicts = vc_rewards_verdicts($type['kind']);
    $per      = (int) vc_rewards_setting('voter_points_per_weight');
    $table    = vc_rewards_table('votes');
    $rows     = $wpdb->get_results($wpdb->prepare(
        "SELECT voter_id, verdict, weight FROM $table WHERE contribution_id = %d",
        (int) $contribution->id
    ));

    foreach ($rows as $row) {
        $side   = isset($verdicts[$row->verdict]) ? $verdicts[$row->verdict]['side'] : 0;
        $weight = (float) $row->weight;
        $uid    = (int) $row->voter_id;

        if ($type['kind'] === 'helpful') {
            if ($weight > 0) {
                vc_rewards_ledger_add($uid, $per, 'vote', array(
                    'settle_days'     => $type['holding_days'],
                    'contribution_id' => $contribution->id,
                    'note'            => __('Voted on a post', 'vc-rewards'),
                ));
            }
            continue;
        }

        $matched = ($side === $outcome_side);
        update_user_meta($uid, '_vc_votes_resolved', (int) get_user_meta($uid, '_vc_votes_resolved', true) + 1);
        if ($matched) {
            update_user_meta($uid, '_vc_votes_matched', (int) get_user_meta($uid, '_vc_votes_matched', true) + 1);
            if ($weight > 0) {
                vc_rewards_ledger_add($uid, (int) round($per * $weight), 'vote', array(
                    'settle_days'     => $type['holding_days'],
                    'contribution_id' => $contribution->id,
                    'note'            => __('Accurate vote', 'vc-rewards'),
                ));
            }
            vc_rewards_add_reputation($uid, vc_rewards_setting('rep_vote_matched'), 'vote_matched', $contribution->id);
        } else {
            vc_rewards_add_reputation($uid, vc_rewards_setting('rep_vote_missed'), 'vote_missed', $contribution->id);
        }
        vc_rewards_check_rankup($uid);
    }
}

/* -------------------------------------------------------------------------
 * Moderator actions
 * ---------------------------------------------------------------------- */

/** Let a held post through to voting. Counts toward the author's hold. */
function vc_rewards_release($contribution_id) {
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || $c->state !== 'held') {
        return false;
    }
    vc_rewards_set_state($c, 'voting');
    update_user_meta($c->author_id, '_vc_hold_approved', (int) get_user_meta($c->author_id, '_vc_hold_approved', true) + 1);
    return true;
}

/**
 * A moderator accepts outright. Anyone who flagged it as invalid or spam
 * loses a little, which is what makes mass-flagging a rival expensive.
 */
function vc_rewards_moderator_accept($contribution_id) {
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c) {
        return false;
    }
    if ($c->state === 'held') {
        update_user_meta($c->author_id, '_vc_hold_approved', (int) get_user_meta($c->author_id, '_vc_hold_approved', true) + 1);
    }
    if (!vc_rewards_accept($c->id)) {
        return false;
    }
    vc_rewards_penalize_flaggers($c);
    return true;
}

function vc_rewards_penalize_flaggers($contribution) {
    global $wpdb;
    $table = vc_rewards_table('votes');
    $flaggers = $wpdb->get_col($wpdb->prepare(
        "SELECT voter_id FROM $table WHERE contribution_id = %d AND verdict IN ('invalid', 'spam') AND weight > 0",
        (int) $contribution->id
    ));
    foreach ($flaggers as $uid) {
        vc_rewards_ledger_add($uid, (int) vc_rewards_setting('flag_cleared_points'), 'penalty', array(
            'status'          => 'settled',
            'contribution_id' => $contribution->id,
            'note'            => __('Flagged a post a moderator cleared', 'vc-rewards'),
        ));
        vc_rewards_add_reputation($uid, vc_rewards_setting('flag_cleared_rep'), 'flag_cleared', $contribution->id);
    }
}

function vc_rewards_set_multiplier($contribution_id, $multiplier) {
    global $wpdb;
    $multiplier = max(0.5, min(3.0, (float) $multiplier));
    $wpdb->update(
        vc_rewards_table('contributions'),
        array('multiplier' => $multiplier),
        array('id' => (int) $contribution_id)
    );
}

/** The author asks for a second look, once per post. */
function vc_rewards_request_appeal($contribution_id, $user_id) {
    global $wpdb;
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || (int) $c->author_id !== (int) $user_id || $c->state !== 'rejected' || $c->appeal !== '') {
        return false;
    }
    $wpdb->update(vc_rewards_table('contributions'), array('appeal' => 'open'), array('id' => (int) $c->id));
    return true;
}

/**
 * Overturn a rejection: refund the penalty and reputation in full, accept
 * the post, and charge whoever flagged it.
 */
function vc_rewards_overturn($contribution_id) {
    global $wpdb;
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || $c->state !== 'rejected') {
        return false;
    }

    $ledger = vc_rewards_table('ledger');
    $refund = -1 * (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(points), 0) FROM $ledger WHERE contribution_id = %d AND user_id = %d AND kind = 'penalty' AND status = 'settled'",
        (int) $c->id,
        (int) $c->author_id
    ));
    if ($refund > 0) {
        vc_rewards_ledger_add($c->author_id, $refund, 'refund', array(
            'status'          => 'settled',
            'contribution_id' => $c->id,
            'note'            => __('Penalty reversed on appeal', 'vc-rewards'),
        ));
    }

    $rep = vc_rewards_table('reputation');
    $lost = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(delta), 0) FROM $rep WHERE contribution_id = %d AND user_id = %d AND reason LIKE %s",
        (int) $c->id,
        (int) $c->author_id,
        'rejected_%'
    ));
    if ($lost < 0) {
        vc_rewards_add_reputation($c->author_id, -$lost, 'appeal_overturned', $c->id);
    }

    $wpdb->update(vc_rewards_table('contributions'), array('appeal' => 'upheld'), array('id' => (int) $c->id));
    vc_rewards_accept($c->id);
    vc_rewards_penalize_flaggers($c);
    return true;
}

function vc_rewards_deny_appeal($contribution_id) {
    global $wpdb;
    return (bool) $wpdb->update(
        vc_rewards_table('contributions'),
        array('appeal' => 'denied'),
        array('id' => (int) $contribution_id, 'appeal' => 'open')
    );
}
