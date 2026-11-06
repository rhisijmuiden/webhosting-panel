<?php
/**
 * Plugin Name: Webhosting Panel login
 * Description: One-click login from the Webhosting Panel. Installed and updated by the panel; links are single use and expire after two minutes.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', static function () {
    if (empty($_GET['whp_sso']) || !is_string($_GET['whp_sso'])) {
        return;
    }
    nocache_headers();
    $token = (string) $_GET['whp_sso'];
    if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
        wp_die(esc_html__('This login link is not valid.'), '', ['response' => 403]);
    }
    $key = 'whp_sso_' . substr(hash('sha256', $token), 0, 40);
    $data = get_transient($key);
    // Single use: remove it before anything else happens.
    delete_transient($key);
    if (!is_array($data) && !is_numeric($data)) {
        wp_die(esc_html__('This login link has expired. Open WordPress again from the control panel.'), '', ['response' => 403]);
    }
    $user_id = (int) (is_array($data) ? ($data['user'] ?? 0) : $data);
    $user = get_userdata($user_id);
    if (!$user) {
        wp_die(esc_html__('This user no longer exists.'), '', ['response' => 403]);
    }
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, false, is_ssl());
    do_action('wp_login', $user->user_login, $user);
    wp_safe_redirect(admin_url());
    exit;
}, 1);
