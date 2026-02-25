<?php
/**
 * Sync v2 cron: daily and 15-min "updated today". Do not modify includes/settings.php.
 * Schedules when ls_auto_sync or ls_auto_sync_motors is non-empty; unschedules when both empty.
 *
 * Cron scheduling is commented out — sync is run via WP-CLI (e.g. wp cron + wp ls_sync_mbn updated_today / all_items).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Cron hook: full daily sync (prioritize today, then rest). */
define( 'LS_SYNC_V2_DAILY_CRON', 'ls_sync_v2_daily_cron' );
/** Cron hook: updated-today only (every 15 min). */
define( 'LS_SYNC_V2_UPDATED_TODAY_CRON', 'ls_sync_v2_updated_today_cron' );

/**
 * v1 cron hooks (defined in includes/settings.php). Included for reference when using wp cron + CLI.
 * v1 schedule names and intervals (from includes/settings.php):
 *   ls_auto_sync_cron                    → every_x_hour  (14400s / 4h)
 *   ls_auto_sync_images_cron             → every_1_hour  (3600s)
 *   ls_auto_sync_images_cron_2nd_batch   → every_x_hour  (14400s / 4h)
 *   ls_auto_sync_images_cron_check_no_image → every_1_hour (3600s)
 * These v1 schedules are left in place when v2 scheduling is commented out.
 */
define( 'LS_V2_V1_CRON_HOOKS', 'ls_auto_sync_cron,ls_auto_sync_images_cron,ls_auto_sync_images_cron_2nd_batch,ls_auto_sync_images_cron_check_no_image' );

/**
 * Unschedule all v1 sync/image crons so only v2 runs.
 */
function ls_v2_unschedule_v1_crons() {
    $hooks = explode( ',', LS_V2_V1_CRON_HOOKS );
    foreach ( $hooks as $hook ) {
        $hook = trim( $hook );
        while ( true ) {
            $t = wp_next_scheduled( $hook );
            if ( ! $t ) {
                break;
            }
            wp_unschedule_event( $t, $hook );
        }
    }
}

/**
 * Add 15-minute schedule if not present.
 */
function ls_v2_cron_schedules( $schedules ) {
    if ( ! isset( $schedules['ls_v2_every_15_min'] ) ) {
        $schedules['ls_v2_every_15_min'] = [
            'interval' => 900,
            'display'  => __( 'Every 15 minutes (Lightspeed v2)' ),
        ];
    }
    return $schedules;
}
// add_filter( 'cron_schedules', 'ls_v2_cron_schedules' );

/**
 * Schedule or unschedule v2 crons based on ls_auto_sync / ls_auto_sync_motors.
 */
function ls_v2_maybe_schedule_crons() {
    $woo   = get_option( 'ls_auto_sync', [] );
    $motors = get_option( 'ls_auto_sync_motors', [] );
    $any    = ! empty( $woo ) || ! empty( $motors );

    if ( $any ) {
        ls_v2_unschedule_v1_crons();
        if ( ! wp_next_scheduled( LS_SYNC_V2_DAILY_CRON ) ) {
            wp_schedule_event( time(), 'daily', LS_SYNC_V2_DAILY_CRON );
        }
        if ( ! wp_next_scheduled( LS_SYNC_V2_UPDATED_TODAY_CRON ) ) {
            wp_schedule_event( time(), 'ls_v2_every_15_min', LS_SYNC_V2_UPDATED_TODAY_CRON );
        }
    } else {
        $t = wp_next_scheduled( LS_SYNC_V2_DAILY_CRON );
        if ( $t ) {
            wp_unschedule_event( $t, LS_SYNC_V2_DAILY_CRON );
        }
        $t2 = wp_next_scheduled( LS_SYNC_V2_UPDATED_TODAY_CRON );
        if ( $t2 ) {
            wp_unschedule_event( $t2, LS_SYNC_V2_UPDATED_TODAY_CRON );
        }
    }
}

// add_action( 'init', 'ls_v2_maybe_schedule_crons', 20 );
// add_action( 'update_option_ls_auto_sync', 'ls_v2_maybe_schedule_crons' );
// add_action( 'update_option_ls_auto_sync_motors', 'ls_v2_maybe_schedule_crons' );

/**
 * At end of request, ensure v1 crons are unscheduled when v2 is active (runs after settings form may have re-scheduled v1).
 */
// function ls_v2_shutdown_unschedule_v1() {
//     $woo    = get_option( 'ls_auto_sync', [] );
//     $motors = get_option( 'ls_auto_sync_motors', [] );
//     if ( ! empty( $woo ) || ! empty( $motors ) ) {
//         ls_v2_unschedule_v1_crons();
//     }
// }
// add_action( 'shutdown', 'ls_v2_shutdown_unschedule_v1', 999 );

/**
 * Handler: daily sync — same logic as WP-CLI all_items (Woo + Motors when enabled).
 */
function ls_v2_handle_daily_cron() {
    $run_id = date( 'Y-m-d_H-i-s' ) . '_v2';
    $max_seconds = defined( 'LS_SYNC_MAX_SEC_PER_REQUEST' ) ? time() + LS_SYNC_MAX_SEC_PER_REQUEST : 0;

    $woo = get_option( 'ls_auto_sync', [] );
    if ( ! empty( $woo ) && function_exists( 'ls_v2_run_all_items_woo' ) ) {
        foreach ( $woo as $cmf ) {
            ls_v2_run_all_items_woo( $cmf, $run_id, $max_seconds, 0 );
        }
    }

    $motors = get_option( 'ls_auto_sync_motors', [] );
    if ( ! empty( $motors ) && function_exists( 'ls_v2_run_all_items_motors' ) ) {
        foreach ( $motors as $cmf ) {
            ls_v2_run_all_items_motors( $cmf, $run_id, $max_seconds, 0 );
        }
    }
}

add_action( LS_SYNC_V2_DAILY_CRON, 'ls_v2_handle_daily_cron' );

/**
 * Handler: updated today — same logic as WP-CLI updated_today (Woo + Motors when enabled).
 */
function ls_v2_handle_updated_today_cron() {
    $run_id = date( 'Y-m-d_H-i-s' ) . '_v2';

    $woo = get_option( 'ls_auto_sync', [] );
    if ( ! empty( $woo ) && function_exists( 'ls_v2_run_updated_today_woo' ) ) {
        foreach ( $woo as $cmf ) {
            ls_v2_run_updated_today_woo( $cmf, $run_id );
        }
    }

    $motors = get_option( 'ls_auto_sync_motors', [] );
    if ( ! empty( $motors ) && function_exists( 'ls_v2_run_updated_today_motors' ) ) {
        foreach ( $motors as $cmf ) {
            ls_v2_run_updated_today_motors( $cmf, $run_id );
        }
    }
}

add_action( LS_SYNC_V2_UPDATED_TODAY_CRON, 'ls_v2_handle_updated_today_cron' );
