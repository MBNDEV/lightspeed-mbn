<?php
/**
 * Plugin Name: Lightspeed MBN
 * Description: A plugin to sync lightspeed products
 * Version: 3.0.6
 * Author: MyBizNiche
 */

if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
 PucFactory::buildUpdateChecker(
  'https://github.com/MBNDEV/lightspeed-mbn',
  __FILE__,
  'lightspeed-mbn'
);

// Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include the settings file.
require_once plugin_dir_path( __FILE__ ) . 'includes/api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/sync-woocommerce.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/sync-motors.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/sync-logs.php';
require_once plugin_dir_path( __FILE__ ) . 'v2/includes/bootstrap.php';

// Ensure API logs table exists and daily cleanup is scheduled.
ls_create_api_logs_table();
if ( ! wp_next_scheduled( 'ls_cleanup_api_logs' ) ) {
    wp_schedule_event( time(), 'daily', 'ls_cleanup_api_logs' );
}

// Add a "Settings" link to the plugin on the Plugins page.
function ls_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( '?page=lightspeed-mbn' ) . '">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ls_add_settings_link' );

// Add the settings page and submenu under the "Lightspeed MBN" menu.
function ls_add_settings_pages() {
    // Add top-level menu.
    add_menu_page(
        'Lightspeed MBN',             // Page title
        'Lightspeed MBN',             // Menu title
        'manage_options',             // Capability
        'lightspeed-mbn',             // Menu slug
        'ls_render_settings_page',    // Callback function to display content
        'dashicons-admin-network',    // Icon URL or Dashicons class
        90                            // Position in the admin menu
    );

    // // Add submenu for settings under the same menu.
    // add_submenu_page(
    //     'lightspeed-mbn',             // Parent menu slug
    //     'Lightspeed MBN Settings',    // Page title
    //     'Settings',                   // Submenu title
    //     'manage_options',             // Capability
    //     'lightspeed-mbn-settings',    // Submenu slug
    //     'ls_render_settings_page'     // Callback function to render the settings page
    // );

    add_submenu_page(
        'lightspeed-mbn',             // Parent menu slug
        'Sync for Woocommerce',    // Page title
        'Sync for Woocommerce',                   // Submenu title
        'manage_options',             // Capability
        'lightspeed-mbn-woocommerce',    // Submenu slug
        'ls_render_sync_woocommerce_page'     // Callback function to render the settings page
    );

    add_submenu_page(
        'lightspeed-mbn',             // Parent menu slug
        'Sync for Motor Listings',    // Page title
        'Sync for Motor Listings',                   // Submenu title
        'manage_options',             // Capability
        'lightspeed-mbn-motors',    // Submenu slug
        'ls_render_sync_motors_page'     // Callback function to render the settings page
    );

    add_submenu_page(
        'lightspeed-mbn',             // Parent menu slug
        'Sync Logs',    // Page title
        'Sync Logs',                   // Submenu title
        'manage_options',             // Capability
        'ls-sync-logs',    // Submenu slug
        'ls_display_log_files'     // Callback function to render the settings page
    );

    add_submenu_page(
        'lightspeed-mbn',             // Parent menu slug
        'API Documentation',          // Page title
        'API Documentation',          // Submenu title
        'manage_options',             // Capability
        'ls-api-documentation',       // Submenu slug
        function() {
            $url = get_option( 'ls_api_docs_url', '' );
            if ( $url ) {
                wp_redirect( $url );
                exit;
            }
        }
    );

    add_submenu_page(
        'lightspeed-mbn',
        'CRON CLI',
        'CRON CLI',
        'manage_options',
        'ls-cron-cli',
        'ls_render_cron_cli_page'
    );
}
add_action( 'admin_menu', 'ls_add_settings_pages' );