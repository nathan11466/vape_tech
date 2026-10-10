# VapingCheap Community Rewards

Members submit coupons and post deal finds, reviews and guides in the wpForo forum. Other members verify them, and the submitter earns points weighted by the rank of the members who verified. Reputation, ranks, penalties, new-account review and hand-approved redemptions keep it hard to farm.

All three stages of the design: **coupons** (WP Coupon & Deals), **forum posts** (wpForo), and the **other ways to earn** (dead-coupon reports, store corrections, accepted answers, challenges, referrals, cashback and small bonuses).

## Install

1. Upload `vc-rewards/` to `/wp-content/plugins/vc-rewards/` and activate **VapingCheap Community Rewards**.
2. WP Coupon & Deals must be active. Submissions are stored as its `wcd_coupon` posts with its own meta keys (`_wcd_code`, `_wcd_discount`, `_wcd_destination_url`, `_wcd_expiration`, `_wcd_type`) and `wcd_brand` terms.
3. Turn on **Settings → General → Anyone can register** so people can join. The signup form gets a date-of-birth field; under 21 is refused.
4. Create three pages and put one shortcode on each:

| Page | Shortcode |
| --- | --- |
| Submit a coupon | `[vc_submit_coupon]` |
| Verify coupons | `[vc_verify_queue]` |
| My rewards | `[vc_rewards_account]` |

`[vc_rewards_leaderboard limit="10"]` shows the top members by reputation anywhere you like.

Optional pages for the other ways to earn:

| Page | Shortcode |
| --- | --- |
| Report a dead coupon | `[vc_report_coupon]` (or `[vc_report_coupon id="123"]` for a one-button report next to a coupon) |
| Correct store info | `[vc_suggest_correction]` (link to it with `?merchant=ID` to preselect the store) |
| Challenges | `[vc_rewards_challenges]` (they also show on the My rewards page) |

5. Tick **Moderator** on the profile of anyone (besides admins) who should moderate. Moderators get a **Rewards** menu in wp-admin.

6. For the forum (optional, wpForo 3.x): in **Rewards → Settings**, enter the wpForo forum IDs whose new topics earn as a *deal*, *review* or *guide*. Leave a type blank to switch it off. In wpForo's own settings, turn off its reputation points and like rewards so members see one points system.

## How it works

1. A member submits a coupon. It's saved as a **pending** `wcd_coupon`, so it stays out of every public list.
2. A new account's first 3 posts wait in **Rewards → Queue** until a moderator releases them.
3. Members open **Verify coupons**, reveal the code, try it, and vote *Works*, *Doesn't work* or *Expired*. Revealing first is required.
4. Each vote counts by rank: Newcomer 0, Member 1, Trusted 2, Expert 3, Moderator 5.
5. At a weighted score of 4 from at least 2 voters, the coupon is **accepted** and published. The author earns 500 + 50 per weight point of "works" votes (max +500), times a moderator-set multiplier. Accurate voters earn 50 per weight point.
6. Rewards stay **pending for 7 days**. If the code is voted down in that time, the pending reward is cancelled and the coupon gets yesterday's expiry date. Nothing is penalised for a code that died early.
7. Voted down before acceptance as *Doesn't work*: the author loses 500 points and 10 reputation. Mostly *Expired*: no penalty. A moderator marking it fake or spam: −1,000 points, −20 reputation.

25 points = 1¢. Every number above is editable in **Rewards → Settings**.

### Ranks and reputation

Reputation is never spent. It comes from accepted posts (+10) and votes that match the outcome (+1), and goes down for missed votes (−2), rejected posts and cleared flags.

| Rank | Needs |
| --- | --- |
| Newcomer | Joined |
| Member | Age and email confirmed, account 7+ days old, 3 accurate votes |
| Trusted | Reputation 100+, 85%+ accuracy |
| Expert | Reputation 500+, 90%+ accuracy |
| Moderator | Ticked on their profile, or an admin |

Rank-up bonuses (1,250 / 5,000 / 12,500) are paid once per account, ever, and sit pending for 14 days. Below-zero reputation drops a member to Newcomer and sends their posts back to review.

### Anti-abuse

- Authors can't vote on their own posts.
- A vote from an IP the author used in the last 30 days counts for 0. IPs are stored only as salted hashes.
- Each extra vote a member gives the same author in 30 days counts less: weight ÷ (1 + earlier votes).
- Two members who have each voted for the other 3+ times in 30 days: their votes on each other count for 0.
- Zero-weight votes are listed in the queue so you can spot accounts that keep showing up.
- Duplicate codes (same store, ignoring case and punctuation) are refused; the first poster keeps the credit.
- Daily limits: posts by rank (2 / 3 / 6 / 10), 40 votes, and 5,000 points earned from posts and votes.
- Penalties stop at a balance of −5,000. A below-zero member can't redeem.
- A spam rejection during the new-account review restarts it. A second one pauses the account.
- Flagging something a moderator then clears costs 250 points and 5 reputation.
- Authors can appeal a rejection once. Overturning refunds the penalty and reputation in full.

### Forum posts (wpForo)

- A new **topic** in a mapped forum becomes a contribution. Replies never earn.
- **Deal finds** are checked like coupons: open the link, then vote *Works*, *Doesn't work* or *Expired*. Base 400 points.
- **Reviews and guides** get *Helpful*, *Not helpful* or *Inaccurate or spam*. "Not helpful" never costs the author anything; enough net spam weight rejects it with the fake/spam penalty. Base 750 (review) and 600 (guide), and they need 150 words to earn.
- The vote widget appears under the topic's first post, and forum posts show in **Verify** and **Rewards → Queue** next to coupons.
- Held posts are held in wpForo too (status "unapproved"). Approving in wpForo's moderation screen and releasing in **Rewards → Queue** do the same thing.
- A new account's first 3 posts of any kind wait for a moderator, including replies and off-topic topics. So do topics from members with negative reputation, reviews and guides that link to a store, and posts with more than 3 outbound links. Members who registered before the plugin was activated skip the new-account review.
- A topic whose text (30+ words) matches another member's post is rejected as **copied**, with the fake/spam penalty.
- Voted *Doesn't work* or spam: the topic is unapproved (hidden). *Expired*, or withdrawn after acceptance: the topic stays visible but is closed to replies. Deleting a topic cancels its pending reward without a penalty.
- Paused accounts can't post in the forum.

### Other ways to earn

| Action | Points | How it's checked |
| --- | --- | --- |
| Reporting a coupon that stopped working | 150 | Members reveal the code and vote *Confirmed* or *Still works*. Confirmed gives the coupon yesterday's expiry date. A wrong report costs nothing. One open report per coupon, and one report per coupon per member per month. |
| Correcting store facts (free shipping, restricted states, support email, contact page) | 250 | A moderator checks the source link in **Rewards → Queue**. Approving writes the value into the merchant-tools field, with `fact_source_url` and `fact_last_verified`. |
| Store experience report (shipping speed, support) | 400 | A wpForo forum mapped as *Store experience report* in settings. Voted like reviews; 100 words minimum. |
| Accepted answer in a wpForo Q&A forum | 200 | The asker marks it best answer. The asker must be Member or above, and the same asker can reward the same member once every 30 days. Checked hourly; unmarking it while pending takes the points back. |
| First to post a deal that gets verified | +200 | The first verified forum deal for a store page. Reposts of the same page don't get it. |
| Proof photo, confirmed spam report | 150, 100 | A moderator awards them in **Rewards → Queue → Award points** (or a custom amount up to 1,000). |
| Weekly challenge | set per challenge | Moderators create them in **Rewards → Challenges**: a number of accurate votes or accepted posts, optionally for one type and one store, with an optional sponsor. Paid once. |
| Referral | 2,500 | Paid when the invited member reaches Member rank and has a post accepted or a purchase confirmed. Never from the inviter's own signup address; at most 10 a month. Links are on the My rewards page. |
| Cashback on a confirmed purchase | 3% of the order value | Set your affiliate network's sub-ID parameter in settings so store links carry the member's sub-ID. Import the network's confirmed sales under **Rewards → Purchases** (admins only). Reversals take the points back. |
| Completed profile, following a channel | 250 each, once | Automatic. Follow bonuses aren't checked, so like visit points they only become redeemable after a post is accepted. Channels are listed in settings. |

Rewards from awards, challenges and referrals stay pending for 7 to 14 days. Every number is in **Rewards → Settings**.

### Daily visits

Logged-in members get 25 points on their first page view each day, plus 250 for every 7 days in a row. These points add no reputation and **can't be redeemed until the member has had a post accepted**.

### Redemptions

Members request a reward from the list in settings (minimum 25,000 points = $10). The points are reserved straight away. Every request waits in **Rewards → Redemptions** with a summary showing when they joined, accepted and rejected posts, the share of points from daily visits, and flagged votes. Approve after sending the reward by hand, or reject to return the points.

## Not in this stage

- Promoting a forum reply into a reward-earning post.
- Newsletter signup bonus. It needs your email provider's double opt-in to report back.
- Pulling sales automatically from an affiliate network's API. Reports are imported as CSV for now.
- Phone verification and ID-based age verification. The date of birth is self-declared.
- myCRED. This plugin keeps its own ledger, because myCRED has no pending points or clawbacks. To show settled points in myCRED's leaderboards and badges, add `add_filter('vc_rewards_mirror_to_mycred', '__return_true');`.

## Hooks

- `vc_rewards_settings`: filter the merged settings array.
- `vc_rewards_object_info` `($info, $contribution)` and `vc_rewards_reveal_payload` `($payload, $contribution)`: describe a new kind of object to the queue and vote widget. `includes/coupons.php` and `includes/wpforo.php` are the two examples.
- `vc_rewards_contribution_state` `($contribution_id, $new_state, $old_state)`: integrations listen here. The coupon integration uses it to publish and unpublish.
- `vc_rewards_vote_cast`, `vc_rewards_rank_changed`, `vc_rewards_ledger_added`, `vc_rewards_redemption_requested`, `vc_rewards_redemption_decided`.
- `vc_rewards_correction_fields`: add merchant-tools fields members may correct. `vc_rewards_correction_applied` fires after one is written.
- `vc_rewards_tag_link`: `apply_filters('vc_rewards_tag_link', $url)` adds the member's affiliate sub-ID to any store link in your templates.
- `vc_rewards_purchase_confirmed` `($user_id, $purchase_id)`.
- `vc_rewards_client_ip`: filter the IP before hashing, for sites behind a proxy or CDN that need to read a forwarded header.

## Running the tests

`tests/test_rewards.php` covers the core and coupons; `tests/test_extras.php` covers stage 3; `tests/test_wpforo.php` covers the forum against a stand-in `WPF()` that fires wpForo 3.2's hooks with the same arguments. The suite runs against a real WordPress on SQLite, so the SQL, hooks and post status changes are all the real thing. WP Coupon & Deals isn't open source, so the bootstrap registers its post type and taxonomy under the same names.

```bash
git clone --depth 1 https://github.com/WordPress/WordPress wp
git clone --depth 1 https://github.com/WordPress/sqlite-database-integration sqlite
cp -r sqlite/packages/plugin-sqlite-database-integration wp/wp-content/plugins/sqlite-database-integration
rm wp/wp-content/plugins/sqlite-database-integration/wp-includes/database
cp -r sqlite/packages/mysql-on-sqlite/src wp/wp-content/plugins/sqlite-database-integration/wp-includes/database
cp wp/wp-content/plugins/sqlite-database-integration/db.copy wp/wp-content/db.php
# edit wp/wp-content/db.php: replace {SQLITE_IMPLEMENTATION_FOLDER_PATH} and {SQLITE_PLUGIN}
# create wp/wp-config.php, run wp_install() once, then copy
# wp/wp-content/database/.ht.sqlite somewhere as the pristine database

VC_WP_DIR=$PWD/wp VC_WP_PRISTINE=/path/to/pristine.sqlite vc-rewards/tests/run.sh
```
