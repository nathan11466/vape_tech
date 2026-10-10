<?php
/**
 * Every tunable number in one place.
 *
 * Defaults match the design doc (25 points = 1 cent). Anything saved on the
 * Rewards > Settings screen overrides them; anything not saved falls back
 * here, so a new setting added in an update gets a sane value immediately.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VC_REWARDS_OPTION = 'vc_rewards_settings';

function vc_rewards_defaults() {
    return array(
        // Ranks, lowest first. Weight is how much one vote counts.
        'rank_weights' => array(
            'newcomer'  => 0,
            'member'    => 1,
            'trusted'   => 2,
            'expert'    => 3,
            'moderator' => 5,
        ),
        'member_min_account_days'   => 7,
        'member_min_accurate_votes' => 3,
        'trusted_min_reputation'    => 100,
        'trusted_min_accuracy'      => 0.85,
        'expert_min_reputation'     => 500,
        'expert_min_accuracy'       => 0.90,
        'rankup_bonus' => array(
            'member'  => 1250,
            'trusted' => 5000,
            'expert'  => 12500,
        ),

        // Payout formula.
        'points_per_weight'   => 50,   // added to the author's reward per weight point of positive votes
        'vote_bonus_cap'      => 500,  // ceiling on that addition
        'voter_points_per_weight' => 50,
        'daily_earn_cap'      => 5000, // excludes rank-up bonuses and login points

        // Reputation.
        'rep_accepted'        => 10,
        'rep_vote_matched'    => 1,
        'rep_vote_missed'     => -2,
        'rep_rejected_invalid' => -10,
        'rep_rejected_fake'   => -20,

        // Penalties, as multiples of the type's base points.
        'penalty_invalid_mult' => 1,
        'penalty_fake_mult'    => 2,
        'balance_floor'        => -5000,
        'spam_threshold'       => 4,    // net weight of "Inaccurate or spam" votes that rejects a helpful-type post
        'flag_cleared_points'  => -250, // flagged something a moderator then cleared
        'flag_cleared_rep'     => -5,

        // Showing up.
        'login_points'        => 25,
        'streak_days'         => 7,
        'streak_bonus'        => 250,
        'onetime_bonus'       => 250, // age check, first login (email confirmed)

        // New accounts.
        'hold_first_posts'    => 3,
        'hold_ban_after_rejections' => 2,

        // Anti-abuse.
        'pair_window_days'    => 30,
        'reciprocal_min_votes' => 3,
        'same_ip_window_days' => 30,
        'daily_vote_limit'    => 40,
        'daily_post_limit'    => array(
            'newcomer'  => 2,
            'member'    => 3,
            'trusted'   => 6,
            'expert'    => 10,
            'moderator' => 0, // 0 = unlimited
        ),

        // Early days: a moderator's approval alone is enough to accept.
        'moderator_can_accept_alone' => 1,

        // Redemptions.
        'min_redemption'      => 25000,
        'reward_options'      => "Member-only merchant code\nPartner store credit\nMonthly prize draw entries",

        'min_age'             => 21,

        // Stage 3: more ways to earn.
        'first_post_bonus'    => 200,   // first member to post a deal that gets verified
        'award_points'        => array('proof' => 150, 'spam_report' => 100),
        'award_max'           => 1000,
        'referral_bonus'      => 2500,
        'referral_monthly_cap' => 10,
        'cashback_percent'    => 3,     // of order value, paid from your commission
        'answer_pair_days'    => 30,
        'follow_channels'     => '',    // "YouTube | https://youtube.com/@vapingcheap" per line
        'subid_param'         => '',
        'awin_publisher_id'   => '1961155',
        'awin_source_tag'     => 'rewd',  // clickref6 on every Awin link, as in the site's own links
        'awin_stores'         => "ecigmafia.com = 67004\nsourcemore.com = 90119",

        // wpForo. Forum IDs (comma separated) whose new topics earn as each type.
        'forum_types'         => array('deal' => '', 'review' => '', 'guide' => '', 'store_report' => ''),
        'forum_min_words'     => array('deal' => 0, 'review' => 150, 'guide' => 150, 'store_report' => 100),
        'forum_max_links'     => 3, // more outbound links than this and a moderator checks it first
        'forum_copy_min_words' => 30, // shorter posts are never treated as copies

        'types' => vc_rewards_type_defaults(),
    );
}

/**
 * Contribution types. 'coupon' comes from WP Coupon & Deals; deal, review
 * and guide come from wpForo topics in the forums mapped on the Settings screen.
 *
 * kind: factual votes have a right answer and grade the voter's accuracy;
 * helpful votes are opinion and never cost the author anything on their own.
 */
function vc_rewards_type_defaults() {
    return array(
        'coupon' => array(
            'label'        => 'Coupon',
            'kind'         => 'factual',
            'base_points'  => 500,
            'threshold'    => 4,
            'min_voters'   => 2,
            'holding_days' => 7,
        ),
        'deal' => array(
            'label'        => 'Deal find',
            'kind'         => 'factual',
            'base_points'  => 400,
            'threshold'    => 4,
            'min_voters'   => 2,
            'holding_days' => 7,
        ),
        'review' => array(
            'label'        => 'Product review',
            'kind'         => 'helpful',
            'base_points'  => 750,
            'threshold'    => 6,
            'min_voters'   => 3,
            'holding_days' => 14,
        ),
        'guide' => array(
            'label'        => 'Guide or useful info',
            'kind'         => 'helpful',
            'base_points'  => 600,
            'threshold'    => 6,
            'min_voters'   => 3,
            'holding_days' => 14,
        ),
        'store_report' => array(
            'label'        => 'Store experience report',
            'kind'         => 'helpful',
            'base_points'  => 400,
            'threshold'    => 6,
            'min_voters'   => 3,
            'holding_days' => 14,
        ),
        // A member reports that a published coupon has stopped working.
        // Voters check the code; "confirmed" pays the reporter.
        'report' => array(
            'label'        => 'Dead coupon report',
            'kind'         => 'report',
            'base_points'  => 150,
            'threshold'    => 4,
            'min_voters'   => 2,
            'holding_days' => 3,
        ),
        // Shipping threshold, restricted states and so on, decided by a moderator.
        'correction' => array(
            'label'        => 'Store fact correction',
            'kind'         => 'moderated',
            'base_points'  => 250,
            'threshold'    => 0,
            'min_voters'   => 0,
            'holding_days' => 7,
        ),
        // The asker marked it best answer in a wpForo Q&A forum.
        'answer' => array(
            'label'        => 'Accepted answer',
            'kind'         => 'auto',
            'base_points'  => 200,
            'threshold'    => 0,
            'min_voters'   => 0,
            'holding_days' => 7,
        ),
    );
}

/** Kinds members vote on with a right answer, after revealing the item. */
function vc_rewards_kind_is_factual($kind) {
    return $kind === 'factual' || $kind === 'report';
}

/** Kinds that appear in the members' verify queue. */
function vc_rewards_votable_kinds() {
    return array('factual', 'report', 'helpful');
}

/**
 * Verdicts members can give, per vote kind, and which side each counts for.
 */
function vc_rewards_verdicts($kind) {
    if ($kind === 'report') {
        return array(
            'confirmed'   => array('label' => __('Confirmed, it no longer works', 'vc-rewards'), 'side' => 1),
            'still_works' => array('label' => __('Still works', 'vc-rewards'), 'side' => -1),
        );
    }
    if ($kind === 'moderated' || $kind === 'auto') {
        return array();
    }
    if ($kind === 'helpful') {
        return array(
            'helpful'     => array('label' => __('Helpful', 'vc-rewards'), 'side' => 1),
            'not_helpful' => array('label' => __('Not helpful', 'vc-rewards'), 'side' => 0),
            'spam'        => array('label' => __('Inaccurate or spam', 'vc-rewards'), 'side' => -1),
        );
    }
    return array(
        'works'   => array('label' => __('Works', 'vc-rewards'), 'side' => 1),
        'invalid' => array('label' => __("Doesn't work", 'vc-rewards'), 'side' => -1),
        'expired' => array('label' => __('Expired', 'vc-rewards'), 'side' => -1),
    );
}

function vc_rewards_settings() {
    static $merged = null;
    if ($merged === null || defined('VC_REWARDS_TESTING')) {
        $saved  = get_option(VC_REWARDS_OPTION, array());
        $merged = vc_rewards_merge(vc_rewards_defaults(), is_array($saved) ? $saved : array());
        $merged = apply_filters('vc_rewards_settings', $merged);
    }
    return $merged;
}

/** Recursive merge where saved scalars win but missing keys keep defaults. */
function vc_rewards_merge(array $defaults, array $saved) {
    foreach ($saved as $key => $value) {
        if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
            $defaults[$key] = vc_rewards_merge($defaults[$key], $value);
        } else {
            $defaults[$key] = $value;
        }
    }
    return $defaults;
}

function vc_rewards_setting($key) {
    $s = vc_rewards_settings();
    return isset($s[$key]) ? $s[$key] : null;
}

function vc_rewards_type($type) {
    $types = vc_rewards_setting('types');
    return isset($types[$type]) ? $types[$type] : null;
}

/** Points to cents, for display: "1,250 points (50¢)". */
function vc_rewards_format_points($points) {
    $points = (int) $points;
    $cents  = $points / 25;
    if (abs($cents) >= 100) {
        $value = '$' . number_format($cents / 100, 2);
    } else {
        $value = rtrim(rtrim(number_format($cents, 1), '0'), '.') . '¢';
    }
    /* translators: 1: number of points, 2: money value */
    return sprintf(__('%1$s points (%2$s)', 'vc-rewards'), number_format_i18n($points), $value);
}
