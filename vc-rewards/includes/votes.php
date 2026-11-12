<?php
/**
 * Member votes.
 *
 * A vote's weight is fixed when it is cast: the voter's rank weight, then
 * reduced by the anti-collusion rules below. The reasons are kept in the
 * `flags` column so the admin queue can show why a vote counted for less.
 *
 *   same_ip     voter shares an IP hash with the author (recently) -> 0
 *   reciprocal  voter and author keep voting for each other     -> 0
 *   repeat      diminishing returns per voter/author pair: weight / (1 + n)
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_record_reveal($contribution_id, $user_id) {
    global $wpdb;
    $table = vc_rewards_table('reveals');
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $table (contribution_id, user_id, created_at) VALUES (%d, %d, %s)",
        (int) $contribution_id,
        (int) $user_id,
        vc_rewards_now()
    ));
}

function vc_rewards_has_revealed($contribution_id, $user_id) {
    global $wpdb;
    $table = vc_rewards_table('reveals');
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM $table WHERE contribution_id = %d AND user_id = %d",
        (int) $contribution_id,
        (int) $user_id
    ));
}

function vc_rewards_user_vote($contribution_id, $user_id) {
    global $wpdb;
    $table = vc_rewards_table('votes');
    return $wpdb->get_var($wpdb->prepare(
        "SELECT verdict FROM $table WHERE contribution_id = %d AND voter_id = %d",
        (int) $contribution_id,
        (int) $user_id
    ));
}

/**
 * Whether this user may act on rewards at all. Returns an error message, or
 * '' when they may.
 */
function vc_rewards_participation_error($user_id) {
    if (!$user_id) {
        return __('Log in to take part.', 'vc-rewards');
    }
    if (vc_rewards_is_banned($user_id)) {
        return __('Your account is paused while a moderator reviews it.', 'vc-rewards');
    }
    if (!vc_rewards_age_verified($user_id)) {
        return __('Confirm your date of birth on your rewards page first.', 'vc-rewards');
    }
    return '';
}

/**
 * Cast a vote. Returns array('ok' => bool, 'message' => string, 'weight' => float).
 */
function vc_rewards_cast_vote($contribution_id, $voter_id, $verdict) {
    global $wpdb;
    $voter_id = (int) $voter_id;

    $error = vc_rewards_participation_error($voter_id);
    if ($error) {
        return array('ok' => false, 'message' => $error);
    }

    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c) {
        return array('ok' => false, 'message' => __('That post no longer exists.', 'vc-rewards'));
    }
    $type = vc_rewards_type($c->contrib_type);

    $open = $c->state === 'voting' || ($c->state === 'accepted' && vc_rewards_in_holding($c));
    if (!$open) {
        return array('ok' => false, 'message' => __('Voting on this post has closed.', 'vc-rewards'));
    }
    if ((int) $c->author_id === $voter_id) {
        return array('ok' => false, 'message' => __("You can't vote on your own post.", 'vc-rewards'));
    }
    $verdicts = vc_rewards_verdicts($type['kind']);
    if (!isset($verdicts[$verdict])) {
        return array('ok' => false, 'message' => __('Unknown vote.', 'vc-rewards'));
    }
    if (vc_rewards_kind_is_factual($type['kind']) && !vc_rewards_has_revealed($c->id, $voter_id)) {
        return array('ok' => false, 'message' => __('Reveal the code and try it before voting.', 'vc-rewards'));
    }
    if (vc_rewards_user_vote($c->id, $voter_id) !== null) {
        return array('ok' => false, 'message' => __('You already voted on this.', 'vc-rewards'));
    }

    $votes = vc_rewards_table('votes');
    $limit = (int) vc_rewards_setting('daily_vote_limit');
    if ($limit > 0) {
        $today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $votes WHERE voter_id = %d AND created_at >= %s",
            $voter_id,
            gmdate('Y-m-d 00:00:00')
        ));
        if ($today >= $limit) {
            return array('ok' => false, 'message' => __("That's the vote limit for today. Thanks for helping!", 'vc-rewards'));
        }
    }

    $rank    = vc_rewards_rank($voter_id);
    $base    = vc_rewards_rank_weight($rank);
    $ip_hash = vc_rewards_ip_hash();
    list($weight, $flags) = vc_rewards_vote_weight($c, $voter_id, $rank, $base, $ip_hash);

    $inserted = $wpdb->insert($votes, array(
        'contribution_id' => (int) $c->id,
        'author_id'       => (int) $c->author_id,
        'voter_id'        => $voter_id,
        'verdict'         => $verdict,
        'voter_rank'      => $rank,
        'base_weight'     => $base,
        'weight'          => $weight,
        'flags'           => implode(',', $flags),
        'ip_hash'         => $ip_hash,
        'created_at'      => vc_rewards_now(),
    ));
    if (!$inserted) {
        // The unique key caught a double submit.
        return array('ok' => false, 'message' => __('You already voted on this.', 'vc-rewards'));
    }

    do_action('vc_rewards_vote_cast', (int) $c->id, $voter_id, $verdict, $weight, $flags);
    vc_rewards_evaluate($c->id);

    return array('ok' => true, 'message' => __('Thanks, your vote is in.', 'vc-rewards'), 'weight' => $weight);
}

/**
 * Apply the anti-collusion rules. Returns array(weight, flags).
 */
function vc_rewards_vote_weight($contribution, $voter_id, $rank, $base, $ip_hash) {
    global $wpdb;
    $flags     = array();
    $author_id = (int) $contribution->author_id;
    $votes     = vc_rewards_table('votes');
    $contribs  = vc_rewards_table('contributions');

    if ($base <= 0) {
        return array(0.0, $flags);
    }

    if ($ip_hash !== '') {
        $since  = vc_rewards_now(-1 * (int) vc_rewards_setting('same_ip_window_days') * DAY_IN_SECONDS);
        $shared = $ip_hash === $contribution->author_ip_hash
            || $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM $votes WHERE voter_id = %d AND ip_hash = %s AND created_at >= %s LIMIT 1",
                $author_id, $ip_hash, $since
            ))
            || $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM $contribs WHERE author_id = %d AND author_ip_hash = %s AND created_at >= %s LIMIT 1",
                $author_id, $ip_hash, $since
            ));
        if ($shared) {
            $flags[] = 'same_ip';
            return array(0.0, $flags);
        }
    }

    // Moderators are appointed; pair limits exist to catch members gaming
    // each other, and would only slow a moderator clearing a backlog.
    if ($rank === 'moderator') {
        return array((float) $base, $flags);
    }

    $since = vc_rewards_now(-1 * (int) vc_rewards_setting('pair_window_days') * DAY_IN_SECONDS);
    $given = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $votes WHERE voter_id = %d AND author_id = %d AND created_at >= %s",
        $voter_id, $author_id, $since
    ));
    $received = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $votes WHERE voter_id = %d AND author_id = %d AND created_at >= %s",
        $author_id, $voter_id, $since
    ));

    $min = (int) vc_rewards_setting('reciprocal_min_votes');
    if ($given >= $min && $received >= $min) {
        $flags[] = 'reciprocal';
        return array(0.0, $flags);
    }

    $weight = (float) $base;
    if ($given > 0) {
        $flags[] = 'repeat';
        $weight  = $weight / (1 + $given);
    }
    return array(round($weight, 3), $flags);
}

function vc_rewards_flagged_votes($limit = 50) {
    global $wpdb;
    $table = vc_rewards_table('votes');
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE flags LIKE %s OR flags LIKE %s ORDER BY id DESC LIMIT %d",
        '%same_ip%',
        '%reciprocal%',
        (int) $limit
    ));
}
