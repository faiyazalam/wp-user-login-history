<?php

/**
 * The class that saves user's login detail.
 *
 * @link       https://github.com/faiyazalam
 * @package    User_Login_History
 * @subpackage User_Login_History/includes
 * @author     Er Faiyaz Alam
 * @access private
 */
if (!class_exists('Faulh_User_Tracker')) {

    class Faulh_User_Tracker {

        /**
         * Login Status Constants.
         * 
         */
        const LOGIN_STATUS_LOGIN = 'login';
        const LOGIN_STATUS_FAIL = 'fail';
        const LOGIN_STATUS_LOGOUT = 'logout';

        /**
         * LOGIN_STATUS_BLOCK
         * This will be saved in db if user is not allowed to login on another blog.
         * This is for network enabled mode only.
         */
        const LOGIN_STATUS_BLOCK = 'block';

        /**
         * The unique identifier of this plugin.
         *
         * @access   protected
         * @var      string    $plugin_name    The string used to uniquely identify this plugin.
         */
        protected $plugin_name;

        /**
         * The version of this plugin.
         *
         * @access   private
         * @var      string    $version    The current version of this plugin.
         */
        private $version;

        /**
         * Stores user session token.
         * 
         * @access private
         * @var string $session_token The session token of user.
         */
        private $session_token;

        /**
         * Stores the status of login.
         * 
         * @access private
         * @var string|bool $login_status The login status of user.
         */
        private $login_status = false;

        /**
         * Stores instance of geo helper class.
         * 
         * @access   private
         * @var      string    $geo_object
         */
        private $geo_object;

        /**
         * Initialize the class and set its properties.
         *
         * @var      string    $plugin_name       The name of this plugin.
         */
        public function __construct($plugin_name, $version) {
            $this->plugin_name = $plugin_name;
            $this->version = $version;
        }

        /**
         * Blocks user if the user is not allowed to 
         * login on another blog on the network.
         * 
         * @access private
         */
        private function is_blocked_user_on_this_blog($user_id) {
            if (is_multisite() && !is_user_member_of_blog($user_id) && !is_super_admin($user_id)) {
                $Network_Admin_Setting = new Faulh_Network_Admin_Setting($this->plugin_name);
                if ($Network_Admin_Setting->get_settings('block_user')) {
                    $this->login_status = self::LOGIN_STATUS_BLOCK;
                    wp_logout();
                    wp_die($Network_Admin_Setting->get_settings('block_user_message'));
                }
            }
        }

        /**
         * Saves user login details.
         * 
         * @access private
         * @param string $user_login username
         * @param object $user WP_User object
         * @param string $status success, fail, logout, block etc.
         */
        private function save_login($user_login, $user, $status = '') {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-faulh-browser-helper.php';
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-faulh-geo-helper.php';
            global $wpdb;
            $unknown = 'unknown';
            $table = $wpdb->get_blog_prefix() . FAULH_TABLE_NAME;
            $current_date = Faulh_Date_Time_Helper::get_current_date_time();
            $user_id = !empty($user->ID) ? $user->ID : FALSE;
            $BrowserHelper = new Faulh_Browser_Helper();

            //now insert for new login
            $data = array(
                'user_id' => $user_id,
                'session_token' => $this->get_session_token(),
                'username' => $user_login,
                'time_login' => $current_date,
                'ip_address' => Faulh_Geo_Helper::get_ip(),
                'time_last_seen' => $current_date,
                'browser' => $BrowserHelper->getBrowser(),
                'browser_version' => $BrowserHelper->getVersion(),
                'operating_system' => $BrowserHelper->getPlatform(),
                'old_role' => !empty($user->roles) ? implode(",", $user->roles) : "",
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'login_status' => $status,
                'is_super_admin' => is_multisite() ? is_super_admin($user_id) : FALSE,
            );
            //this is used to modify data before saving in db.
            $filtered_data = apply_filters('faulh_before_save_login', $data);

            if (is_array($filtered_data) && !empty($filtered_data)) {
                $data = array_merge($data, $filtered_data);
            }

            $wpdb->insert($table, $data);

            if ($wpdb->last_error || !$wpdb->insert_id) {
                Faulh_Error_Handler::error_log("last error:" . $wpdb->last_error . " last query:" . $wpdb->last_query, __LINE__, __FILE__);
                return;
            }

            if (self::LOGIN_STATUS_FAIL == $status) {
                return;
            }

            Faulh_Session_Helper::set_last_insert_id($wpdb->insert_id);
            Faulh_Session_Helper::set_current_login_blog_id();

            $this->is_blocked_user_on_this_blog($user_id);
            do_action('faulh_after_save_login', $data);
        }

        /**
         * Fires if login success.
         * @access  public
         * @param string $user_login username
         * @param object $user wp user object
         */
        public function user_login($user_login, $user) {
            $this->save_login($user_login, $user, self::LOGIN_STATUS_LOGIN);
        }

        /**
         * Update last seen time for the current user.
         * 
         * @access  public
         * @global object $wpdb
         * @return bool|int The number of records updated.
         */
        public function update_time_last_seen() {
            global $wpdb;
            $current_user = wp_get_current_user();
            $table = $wpdb->get_blog_prefix(Faulh_Session_Helper::get_current_login_blog_id()) . FAULH_TABLE_NAME;
            $current_date = Faulh_Date_Time_Helper::get_current_date_time();
            $user_id = $current_user->ID;
            $last_id = Faulh_Session_Helper::get_last_insert_id();
           
            if (!$user_id || !$last_id) {
                return;
            }

            $sql = "update $table set time_last_seen='$current_date' where id = '$last_id' and user_id = '$user_id'";

            $status = $wpdb->query($sql);

            if ($wpdb->last_error) {
                Faulh_Error_Handler::error_log("last error:" . $wpdb->last_error . " last query:" . $wpdb->last_query, __LINE__, __FILE__);
            }

            return $status;
        }

        /**
         * Fires if login failed.
         * 
         * @access public
         * @param string $user_login username
         */
        public function user_login_failed($user_login) {
            $this->save_login($user_login, NULL, self::LOGIN_STATUS_FAIL);
        }

        /**
         * Fires on logout.
         * Save logout time of current user.
         * 
         * @access public
         */
        public function user_logout() {
            global $wpdb;
            $time_logout = Faulh_Date_Time_Helper::get_current_date_time();
            $last_id = Faulh_Session_Helper::get_last_insert_id();
            $login_status = $this->login_status ? $this->login_status : self::LOGIN_STATUS_LOGOUT;

            if (!$last_id) {
                return;
            }
            $table = $wpdb->get_blog_prefix(Faulh_Session_Helper::get_current_login_blog_id()) . FAULH_TABLE_NAME;
            ;
            $sql = "update $table  set time_logout='$time_logout', time_last_seen='$time_logout', login_status = '" . $login_status . "' where id = '$last_id' ";
            $wpdb->query($sql);

            if ($wpdb->last_error) {
                Faulh_Error_Handler::error_log("last error:" . $wpdb->last_error . " last query:" . $wpdb->last_query, __LINE__, __FILE__);
            }
            Faulh_Session_Helper::destroy();
        }

        /**
         * Sets session token.
         * 
         * @param string $logged_in_cookie
         * @param string $expire
         * @param string $expiration
         * @param string|int $user_id
         * @param string $logged_in_text
         * @param string $token The session token.
         */
        public function set_session_token($logged_in_cookie, $expire, $expiration, $user_id, $logged_in_text, $token) {
            $this->session_token = $token;
        }

        /**
         * Gets session token.
         * 
         * @return string The session token.
         */
        public function get_session_token() {
            return $this->session_token ? $this->session_token : "";
        }

    }

}