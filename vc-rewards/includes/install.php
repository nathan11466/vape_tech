<?php
/**
 * Database tables.
 *
 * Everything that has to add up -- points, reputation, votes -- lives in its
 * own table rather than post or user meta, so it can be summed, audited and
 * reversed row by row. User meta only ever holds cached totals.
 *
 * object_type + object_id identify what a row is about. Today that is always
 * ('wcd_coupon', post ID). wpForo posts live in wpForo's own tables, not
 * wp_posts, so they will arrive as ('wpforo_topic', topic ID) with no schema
 * change.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'vc_' . $name;
}

function vc_rewards_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    $contributions = vc_rewards_table('contributions');
    $votes         = vc_rewards_table('votes');
    $reveals       = vc_rewards_table('reveals');
    $ledger        = vc_rewards_table('ledger');
    $reputation    = vc_rewards_table('reputation');
    $redemptions   = vc_rewards_table('redemptions');

    // dbDelta is fussy: two spaces after PRIMARY KEY, one field per line.
    dbDelta("CREATE TABLE $contributions (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        object_type varchar(20) NOT NULL,
        object_id bigint(20) unsigned NOT NULL,
        contrib_type varchar(20) NOT NULL,
        author_id bigint(20) unsigned NOT NULL,
        author_ip_hash char(64) NOT NULL DEFAULT '',
        state varchar(20) NOT NULL DEFAULT 'voting',
        score decimal(8,3) NOT NULL DEFAULT 0,
        multiplier decimal(4,2) NOT NULL DEFAULT 1.00,
        reject_reason varchar(20) NOT NULL DEFAULT '',
        appeal varchar(10) NOT NULL DEFAULT '',
        fingerprint char(32) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        resolved_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY object (object_type,object_id),
        KEY author (author_id),
        KEY state (state),
        KEY fingerprint (fingerprint)
    ) $charset;");

    dbDelta("CREATE TABLE $votes (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        contribution_id bigint(20) unsigned NOT NULL,
        author_id bigint(20) unsigned NOT NULL,
        voter_id bigint(20) unsigned NOT NULL,
        verdict varchar(20) NOT NULL,
        voter_rank varchar(20) NOT NULL,
        base_weight decimal(6,3) NOT NULL DEFAULT 0,
        weight decimal(6,3) NOT NULL DEFAULT 0,
        flags varchar(255) NOT NULL DEFAULT '',
        ip_hash char(64) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY one_vote (contribution_id,voter_id),
        KEY pair (voter_id,author_id,created_at),
        KEY flagged (flags(20))
    ) $charset;");

    dbDelta("CREATE TABLE $reveals (
        contribution_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (contribution_id,user_id)
    ) $charset;");

    dbDelta("CREATE TABLE $ledger (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        points int(11) NOT NULL,
        kind varchar(20) NOT NULL,
        status varchar(10) NOT NULL DEFAULT 'pending',
        contribution_id bigint(20) unsigned NOT NULL DEFAULT 0,
        note varchar(255) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        settle_after datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY user_status (user_id,status),
        KEY due (status,settle_after),
        KEY contribution (contribution_id)
    ) $charset;");

    dbDelta("CREATE TABLE $reputation (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        delta int(11) NOT NULL,
        reason varchar(40) NOT NULL,
        contribution_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id)
    ) $charset;");

    dbDelta("CREATE TABLE $redemptions (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        points int(11) NOT NULL,
        reward varchar(191) NOT NULL,
        status varchar(10) NOT NULL DEFAULT 'requested',
        ledger_id bigint(20) unsigned NOT NULL DEFAULT 0,
        admin_note varchar(255) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        decided_at datetime DEFAULT NULL,
        decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY user_id (user_id)
    ) $charset;");

    // Members who joined before the plugin skip the new-account review.
    add_option('vc_rewards_installed_at', gmdate('Y-m-d H:i:s'));
    update_option('vc_rewards_db_version', VC_REWARDS_DB_VERSION);
}

/** UTC timestamp in MySQL format. All stored times are UTC. */
function vc_rewards_now($offset_seconds = 0) {
    return gmdate('Y-m-d H:i:s', time() + $offset_seconds);
}
