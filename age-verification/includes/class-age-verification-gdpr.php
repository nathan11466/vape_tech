<?php
/**
 * Age Verification GDPR & Privacy Compliance Class
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Age_Verification_GDPR {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get instance of this class
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_gdpr_menu'));
        add_action('admin_init', array($this, 'register_gdpr_settings'));
        add_action('init', array($this, 'handle_user_data_requests'));
        add_action('wp_ajax_av_export_user_data', array($this, 'export_user_data'));
        add_action('wp_ajax_nopriv_av_export_user_data', array($this, 'export_user_data'));
        add_action('wp_ajax_av_delete_user_data', array($this, 'delete_user_data'));
        add_action('wp_ajax_nopriv_av_delete_user_data', array($this, 'delete_user_data'));
        add_action('wp_ajax_av_update_consent', array($this, 'update_consent'));
        add_action('wp_ajax_nopriv_av_update_consent', array($this, 'update_consent'));

        // Create database table on activation
        register_activation_hook(AGE_VERIFICATION_PLUGIN_DIR . 'age-verification.php', array($this, 'create_consent_table'));
    }

    /**
     * Create consent logging table
     */
    public function create_consent_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'av_consent_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            user_agent text NOT NULL,
            consent_type varchar(50) NOT NULL,
            consent_value text NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY ip_address (ip_address),
            KEY consent_type (consent_type),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log consent
     */
    public function log_consent($consent_type, $consent_value) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'av_consent_log';

        $wpdb->insert(
            $table_name,
            array(
                'ip_address' => $this->get_user_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'consent_type' => sanitize_text_field($consent_type),
                'consent_value' => wp_json_encode($consent_value),
                'timestamp' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Get user IP address
     */
    private function get_user_ip() {
        $ip = '';

        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return sanitize_text_field($ip);
    }

    /**
     * Add GDPR admin menu
     */
    public function add_gdpr_menu() {
        add_submenu_page(
            'options-general.php',
            __('Privacy & GDPR', 'age-verification'),
            __('Privacy & GDPR', 'age-verification'),
            'manage_options',
            'age-verification-gdpr',
            array($this, 'render_gdpr_page')
        );
    }

    /**
     * Register GDPR settings
     */
    public function register_gdpr_settings() {
        // Cookie Consent Settings
        register_setting('age_verification_gdpr', 'av_gdpr_enabled');
        register_setting('age_verification_gdpr', 'av_cookie_consent_enabled');
        register_setting('age_verification_gdpr', 'av_cookie_banner_position');
        register_setting('age_verification_gdpr', 'av_cookie_banner_message');
        register_setting('age_verification_gdpr', 'av_privacy_policy_page');
        register_setting('age_verification_gdpr', 'av_cookie_policy_page');

        // Cookie Categories
        register_setting('age_verification_gdpr', 'av_cookies_necessary');
        register_setting('age_verification_gdpr', 'av_cookies_analytics');
        register_setting('age_verification_gdpr', 'av_cookies_marketing');
        register_setting('age_verification_gdpr', 'av_cookies_preferences');

        // GDPR Compliance
        register_setting('age_verification_gdpr', 'av_enable_consent_log');
        register_setting('age_verification_gdpr', 'av_consent_expiry');
        register_setting('age_verification_gdpr', 'av_show_privacy_widget');
        register_setting('age_verification_gdpr', 'av_enable_dnt');

        // Data Retention
        register_setting('age_verification_gdpr', 'av_data_retention_days');
        register_setting('age_verification_gdpr', 'av_auto_delete_logs');
    }

    /**
     * Render GDPR settings page
     */
    public function render_gdpr_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['subtab']) ? sanitize_text_field($_GET['subtab']) : 'cookie_consent';

        ?>
        <div class="wrap">
            <h1><?php _e('Privacy & GDPR Settings', 'age-verification'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=age-verification-gdpr&subtab=cookie_consent" class="nav-tab <?php echo $active_tab === 'cookie_consent' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Cookie Consent', 'age-verification'); ?>
                </a>
                <a href="?page=age-verification-gdpr&subtab=cookie_management" class="nav-tab <?php echo $active_tab === 'cookie_management' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Cookie Management', 'age-verification'); ?>
                </a>
                <a href="?page=age-verification-gdpr&subtab=compliance" class="nav-tab <?php echo $active_tab === 'compliance' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Compliance', 'age-verification'); ?>
                </a>
                <a href="?page=age-verification-gdpr&subtab=data_requests" class="nav-tab <?php echo $active_tab === 'data_requests' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Data Requests', 'age-verification'); ?>
                </a>
                <a href="?page=age-verification-gdpr&subtab=consent_logs" class="nav-tab <?php echo $active_tab === 'consent_logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Consent Logs', 'age-verification'); ?>
                </a>
            </h2>

            <form method="post" action="options.php">
                <?php
                settings_fields('age_verification_gdpr');

                if ($active_tab === 'cookie_consent') {
                    $this->render_cookie_consent_settings();
                } elseif ($active_tab === 'cookie_management') {
                    $this->render_cookie_management_settings();
                } elseif ($active_tab === 'compliance') {
                    $this->render_compliance_settings();
                } elseif ($active_tab === 'data_requests') {
                    $this->render_data_requests_tab();
                } elseif ($active_tab === 'consent_logs') {
                    $this->render_consent_logs_tab();
                }

                if ($active_tab !== 'data_requests' && $active_tab !== 'consent_logs') {
                    submit_button();
                }
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render cookie consent settings
     */
    private function render_cookie_consent_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="av_cookie_consent_enabled"><?php _e('Enable Cookie Consent Banner', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="av_cookie_consent_enabled" name="av_cookie_consent_enabled" value="1" <?php checked(1, get_option('av_cookie_consent_enabled', 0)); ?> />
                    <p class="description"><?php _e('Display a cookie consent banner to comply with GDPR and privacy regulations.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_cookie_banner_position"><?php _e('Banner Position', 'age-verification'); ?></label>
                </th>
                <td>
                    <select id="av_cookie_banner_position" name="av_cookie_banner_position">
                        <option value="bottom" <?php selected('bottom', get_option('av_cookie_banner_position', 'bottom')); ?>>
                            <?php _e('Bottom', 'age-verification'); ?>
                        </option>
                        <option value="top" <?php selected('top', get_option('av_cookie_banner_position', 'bottom')); ?>>
                            <?php _e('Top', 'age-verification'); ?>
                        </option>
                        <option value="modal" <?php selected('modal', get_option('av_cookie_banner_position', 'bottom')); ?>>
                            <?php _e('Modal (Center)', 'age-verification'); ?>
                        </option>
                    </select>
                    <p class="description"><?php _e('Where to display the cookie consent banner.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_cookie_banner_message"><?php _e('Banner Message', 'age-verification'); ?></label>
                </th>
                <td>
                    <textarea id="av_cookie_banner_message" name="av_cookie_banner_message" rows="4" class="large-text"><?php echo esc_textarea(get_option('av_cookie_banner_message', 'We use cookies to ensure you get the best experience on our website. By continuing to browse, you agree to our use of cookies.')); ?></textarea>
                    <p class="description"><?php _e('Message displayed in the cookie consent banner.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_privacy_policy_page"><?php _e('Privacy Policy Page', 'age-verification'); ?></label>
                </th>
                <td>
                    <?php
                    wp_dropdown_pages(array(
                        'name' => 'av_privacy_policy_page',
                        'id' => 'av_privacy_policy_page',
                        'selected' => get_option('av_privacy_policy_page', 0),
                        'show_option_none' => __('Select a page', 'age-verification'),
                    ));
                    ?>
                    <p class="description"><?php _e('Select your privacy policy page.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_cookie_policy_page"><?php _e('Cookie Policy Page', 'age-verification'); ?></label>
                </th>
                <td>
                    <?php
                    wp_dropdown_pages(array(
                        'name' => 'av_cookie_policy_page',
                        'id' => 'av_cookie_policy_page',
                        'selected' => get_option('av_cookie_policy_page', 0),
                        'show_option_none' => __('Select a page', 'age-verification'),
                    ));
                    ?>
                    <p class="description"><?php _e('Optional: Select your cookie policy page.', 'age-verification'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render cookie management settings
     */
    private function render_cookie_management_settings() {
        ?>
        <div class="age-verification-info-box">
            <p><strong><?php _e('Cookie Categories', 'age-verification'); ?></strong></p>
            <p><?php _e('Define which cookies fall into each category. Users can accept/reject categories individually.', 'age-verification'); ?></p>
        </div>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="av_cookies_necessary"><?php _e('Necessary Cookies', 'age-verification'); ?></label>
                </th>
                <td>
                    <textarea id="av_cookies_necessary" name="av_cookies_necessary" rows="3" class="large-text"><?php echo esc_textarea(get_option('av_cookies_necessary', 'age_verified, PHPSESSID, wp-settings-*, wordpress_logged_in_*')); ?></textarea>
                    <p class="description"><?php _e('Comma-separated list of necessary cookies (always enabled). Use * for wildcards.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_cookies_analytics"><?php _e('Analytics Cookies', 'age-verification'); ?></label>
                </th>
                <td>
                    <textarea id="av_cookies_analytics" name="av_cookies_analytics" rows="3" class="large-text"><?php echo esc_textarea(get_option('av_cookies_analytics', '_ga, _gid, _gat, _ga_*')); ?></textarea>
                    <p class="description"><?php _e('Analytics cookies (Google Analytics, etc.). Users can opt-out.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_cookies_marketing"><?php _e('Marketing Cookies', 'age-verification'); ?></label>
                </th>
                <td>
                    <textarea id="av_cookies_marketing" name="av_cookies_marketing" rows="3" class="large-text"><?php echo esc_textarea(get_option('av_cookies_marketing', '_fbp, fr, IDE, test_cookie')); ?></textarea>
                    <p class="description"><?php _e('Marketing and advertising cookies. Users can opt-out.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_cookies_preferences"><?php _e('Preference Cookies', 'age-verification'); ?></label>
                </th>
                <td>
                    <textarea id="av_cookies_preferences" name="av_cookies_preferences" rows="3" class="large-text"><?php echo esc_textarea(get_option('av_cookies_preferences', 'av_cookie_consent')); ?></textarea>
                    <p class="description"><?php _e('Preference cookies that remember user choices. Users can opt-out.', 'age-verification'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render compliance settings
     */
    private function render_compliance_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="av_enable_consent_log"><?php _e('Enable Consent Logging', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="av_enable_consent_log" name="av_enable_consent_log" value="1" <?php checked(1, get_option('av_enable_consent_log', 1)); ?> />
                    <p class="description"><?php _e('Log all consent actions for GDPR compliance (recommended).', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_consent_expiry"><?php _e('Consent Expiry (days)', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="number" id="av_consent_expiry" name="av_consent_expiry" value="<?php echo esc_attr(get_option('av_consent_expiry', 365)); ?>" min="1" max="730" />
                    <p class="description"><?php _e('How long consent is valid before asking again (GDPR recommends 12 months).', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_show_privacy_widget"><?php _e('Show Privacy Widget', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="av_show_privacy_widget" name="av_show_privacy_widget" value="1" <?php checked(1, get_option('av_show_privacy_widget', 1)); ?> />
                    <p class="description"><?php _e('Display a privacy preferences widget in the footer for users to manage their consent.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_enable_dnt"><?php _e('Honor Do Not Track', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="av_enable_dnt" name="av_enable_dnt" value="1" <?php checked(1, get_option('av_enable_dnt', 0)); ?> />
                    <p class="description"><?php _e('Automatically disable non-essential cookies for users with DNT enabled.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_data_retention_days"><?php _e('Data Retention Period (days)', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="number" id="av_data_retention_days" name="av_data_retention_days" value="<?php echo esc_attr(get_option('av_data_retention_days', 730)); ?>" min="30" max="3650" />
                    <p class="description"><?php _e('How long to keep consent logs before automatic deletion.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="av_auto_delete_logs"><?php _e('Auto-Delete Old Logs', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="av_auto_delete_logs" name="av_auto_delete_logs" value="1" <?php checked(1, get_option('av_auto_delete_logs', 0)); ?> />
                    <p class="description"><?php _e('Automatically delete consent logs older than the retention period.', 'age-verification'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render data requests tab
     */
    private function render_data_requests_tab() {
        ?>
        <div class="age-verification-info-box">
            <p><strong><?php _e('GDPR Data Subject Rights', 'age-verification'); ?></strong></p>
            <p><?php _e('Handle user data requests including access, export, and deletion requests.', 'age-verification'); ?></p>
        </div>

        <h2><?php _e('Export User Data', 'age-verification'); ?></h2>
        <p><?php _e('Enter an IP address to export all consent data for that user:', 'age-verification'); ?></p>
        <input type="text" id="export-ip" class="regular-text" placeholder="192.168.1.1" />
        <button type="button" class="button button-primary" id="export-user-data"><?php _e('Export Data', 'age-verification'); ?></button>

        <hr />

        <h2><?php _e('Delete User Data', 'age-verification'); ?></h2>
        <p><?php _e('Enter an IP address to delete all consent data for that user:', 'age-verification'); ?></p>
        <input type="text" id="delete-ip" class="regular-text" placeholder="192.168.1.1" />
        <button type="button" class="button button-danger" id="delete-user-data"><?php _e('Delete Data', 'age-verification'); ?></button>

        <div id="data-request-result" style="margin-top: 20px;"></div>
        <?php
    }

    /**
     * Render consent logs tab
     */
    private function render_consent_logs_tab() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'av_consent_log';

        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total / $per_page);

        // Get logs
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        ?>
        <div class="age-verification-info-box">
            <p><strong><?php _e('Consent Audit Trail', 'age-verification'); ?></strong></p>
            <p><?php _e('View all consent actions logged for GDPR compliance.', 'age-verification'); ?></p>
        </div>

        <p><?php echo sprintf(__('Total logs: %d', 'age-verification'), $total); ?></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('ID', 'age-verification'); ?></th>
                    <th><?php _e('IP Address', 'age-verification'); ?></th>
                    <th><?php _e('Consent Type', 'age-verification'); ?></th>
                    <th><?php _e('Consent Value', 'age-verification'); ?></th>
                    <th><?php _e('User Agent', 'age-verification'); ?></th>
                    <th><?php _e('Timestamp', 'age-verification'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)) : ?>
                    <?php foreach ($logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html($log->id); ?></td>
                            <td><?php echo esc_html($log->ip_address); ?></td>
                            <td><?php echo esc_html($log->consent_type); ?></td>
                            <td><code><?php echo esc_html(substr($log->consent_value, 0, 50)) . '...'; ?></code></td>
                            <td><?php echo esc_html(substr($log->user_agent, 0, 50)) . '...'; ?></td>
                            <td><?php echo esc_html($log->timestamp); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="6"><?php _e('No consent logs found.', 'age-verification'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1) : ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $page
                    ));
                    ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Export user data
     */
    public function export_user_data() {
        check_ajax_referer('av-gdpr-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $ip = isset($_POST['ip']) ? sanitize_text_field($_POST['ip']) : '';

        if (empty($ip)) {
            wp_send_json_error('Invalid IP address');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'av_consent_log';

        $data = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE ip_address = %s ORDER BY timestamp DESC",
            $ip
        ), ARRAY_A);

        if (empty($data)) {
            wp_send_json_error('No data found for this IP address');
        }

        wp_send_json_success($data);
    }

    /**
     * Delete user data
     */
    public function delete_user_data() {
        check_ajax_referer('av-gdpr-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $ip = isset($_POST['ip']) ? sanitize_text_field($_POST['ip']) : '';

        if (empty($ip)) {
            wp_send_json_error('Invalid IP address');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'av_consent_log';

        $deleted = $wpdb->delete($table_name, array('ip_address' => $ip), array('%s'));

        if ($deleted === false) {
            wp_send_json_error('Failed to delete data');
        }

        wp_send_json_success(sprintf(__('Deleted %d records', 'age-verification'), $deleted));
    }

    /**
     * Update user consent
     */
    public function update_consent() {
        check_ajax_referer('av-consent-nonce', 'nonce');

        $consent_data = isset($_POST['consent']) ? $_POST['consent'] : array();

        // Log the consent
        if (get_option('av_enable_consent_log', 1)) {
            $this->log_consent('cookie_consent', $consent_data);
        }

        // Set consent cookie
        $expiry = intval(get_option('av_consent_expiry', 365)) * DAY_IN_SECONDS;
        setcookie('av_cookie_consent', wp_json_encode($consent_data), time() + $expiry, '/');

        wp_send_json_success('Consent updated');
    }

    /**
     * Handle user data requests
     */
    public function handle_user_data_requests() {
        // Auto-delete old logs if enabled
        if (get_option('av_auto_delete_logs', 0)) {
            $this->auto_delete_old_logs();
        }
    }

    /**
     * Auto-delete old logs
     */
    private function auto_delete_old_logs() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'av_consent_log';
        $retention_days = intval(get_option('av_data_retention_days', 730));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }
}
