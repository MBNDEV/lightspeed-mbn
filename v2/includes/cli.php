<?php
/**
 * Sync v2 WP-CLI: wp ls_sync_mbn <command>. Do not add CLI code to includes/.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

use WP_CLI\Formatter;

/**
 * WP-CLI command class for ls_sync_mbn (subcommands as methods).
 */
class LS_Sync_MBN_Command {

    /**
     * Run daily sync (prioritize today, then rest) for enabled sync types.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Sync type: all, woo, motors. Default: all.
     *
     * ## EXAMPLES
     *
     *     wp ls_sync_mbn all_items
     *     wp ls_sync_mbn all_items --type=motors
     */
    public function all_items( $args, $assoc_args ) {
        $type = isset( $assoc_args['type'] ) ? $assoc_args['type'] : 'all';
        if ( ! in_array( $type, [ 'all', 'woo', 'motors' ], true ) ) {
            WP_CLI::error( 'Invalid --type. Use all, woo, or motors.' );
        }

        $run_id = date( 'Y-m-d_H-i-s' ) . '_v2';

        if ( $type === 'all' || $type === 'woo' ) {
            $woo = get_option( 'ls_auto_sync', [] );
            if ( empty( $woo ) ) {
                WP_CLI::log( 'No WooCommerce CMFs enabled; skipping.' );
            } else {
                foreach ( $woo as $cmf ) {
                    WP_CLI::log( "WooCommerce daily sync CMF: $cmf" );
                    ls_v2_run_all_items_woo( $cmf, $run_id, 0, 0 );
                }
            }
        }

        if ( $type === 'all' || $type === 'motors' ) {
            $motors = get_option( 'ls_auto_sync_motors', [] );
            if ( empty( $motors ) ) {
                WP_CLI::log( 'No Motors CMFs enabled; skipping.' );
            } else {
                foreach ( $motors as $cmf ) {
                    WP_CLI::log( "Motors daily sync CMF: $cmf" );
                    ls_v2_run_all_items_motors( $cmf, $run_id, 0, 0 );
                }
            }
        }

        WP_CLI::success( 'all_items completed.' );
    }

    /**
     * Run "updated today" sync only (fetch today's parts/units, process immediately).
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Sync type: all, woo, motors. Default: all.
     *
     * ## EXAMPLES
     *
     *     wp ls_sync_mbn updated_today
     *     wp ls_sync_mbn updated_today --type=woo
     */
    public function updated_today( $args, $assoc_args ) {
        $type = isset( $assoc_args['type'] ) ? $assoc_args['type'] : 'all';
        if ( ! in_array( $type, [ 'all', 'woo', 'motors' ], true ) ) {
            WP_CLI::error( 'Invalid --type. Use all, woo, or motors.' );
        }

        $run_id = date( 'Y-m-d_H-i-s' ) . '_v2';

        if ( $type === 'all' || $type === 'woo' ) {
            $woo = get_option( 'ls_auto_sync', [] );
            if ( ! empty( $woo ) ) {
                foreach ( $woo as $cmf ) {
                    ls_v2_run_updated_today_woo( $cmf, $run_id );
                }
            }
        }

        if ( $type === 'all' || $type === 'motors' ) {
            $motors = get_option( 'ls_auto_sync_motors', [] );
            if ( ! empty( $motors ) ) {
                foreach ( $motors as $cmf ) {
                    ls_v2_run_updated_today_motors( $cmf, $run_id );
                }
            }
        }

        WP_CLI::success( 'updated_today completed.' );
    }

    /**
     * Process a single motor listing by stock number.
     *
     * ## OPTIONS
     *
     * <stock_number>
     * : Stock number of the unit.
     *
     * [--cmf=<cmf>]
     * : CMF (dealer code). Optional.
     *
     * ## EXAMPLES
     *
     *     wp ls_sync_mbn sync_stock 12345
     *     wp ls_sync_mbn sync_stock 12345 --cmf=76251145
     */
    public function sync_stock( $args, $assoc_args ) {
        $stock_number = isset( $args[0] ) ? $args[0] : '';
        $cmf          = isset( $assoc_args['cmf'] ) ? $assoc_args['cmf'] : null;

        $result = ls_process_item_sync_stock_number( $stock_number, $cmf );
        if ( ! empty( $result['success'] ) ) {
            if ( ! empty( $result['skipped'] ) ) {
                WP_CLI::success( "Skipped (no change). Listing ID: {$result['post_id']}" );
            } else {
                WP_CLI::success( "Synced. Listing ID: {$result['post_id']}" );
            }
        } else {
            WP_CLI::error( $result['message'] ?? 'Unknown error.' );
        }
    }

    /**
     * Process a single WooCommerce product by part number (SKU).
     *
     * ## OPTIONS
     *
     * <part_number>
     * : Part number (SKU).
     *
     * [--cmf=<cmf>]
     * : CMF (dealer code). Optional.
     *
     * ## EXAMPLES
     *
     *     wp ls_sync_mbn sync_part ABC123
     *     wp ls_sync_mbn sync_part ABC123 --cmf=76251145
     */
    public function sync_part( $args, $assoc_args ) {
        $part_number = isset( $args[0] ) ? $args[0] : '';
        $cmf         = isset( $assoc_args['cmf'] ) ? $assoc_args['cmf'] : null;

        $result = ls_process_part_sync_part_number( $part_number, $cmf );
        if ( ! empty( $result['success'] ) ) {
            WP_CLI::success( "Synced. Product ID: {$result['product_id']}" );
        } else {
            WP_CLI::error( $result['message'] ?? 'Unknown error.' );
        }
    }

    /**
     * List v2 cron schedules (hooks and next run).
     *
     * ## EXAMPLES
     *
     *     wp ls_sync_mbn cron_list
     */
    public function cron_list( $args, $assoc_args ) {
        $hooks = [ LS_SYNC_V2_DAILY_CRON, LS_SYNC_V2_UPDATED_TODAY_CRON ];
        $items = [];
        foreach ( $hooks as $hook ) {
            $next = wp_next_scheduled( $hook ) ? wp_next_scheduled( $hook ) : null;
            $items[] = [
                'hook'     => $hook,
                'next_run' => $next ? date( 'Y-m-d H:i:s', $next ) : 'not scheduled',
            ];
        }
        WP_CLI::log( 'V2 cron hooks:' );
        ( new Formatter( $assoc_args, [ 'hook', 'next_run' ] ) )->display_items( $items );
        WP_CLI::log( 'Use `wp cron event list` to see all events.' );
    }

    /**
     * Cleanup listings: remove posts whose stock number is not in the LS Unit API.
     * Paginates listing posts (default 20 per page), checks each batch against LS API by stock number;
     * deletes any listing whose stock number is not returned by the API.
     *
     * ## OPTIONS
     *
     * [--cmf=<cmf>]
     * : CMF (dealer code). Default: first from ls_auto_sync_motors, or 76251145.
     *
     * [--per_page=<n>]
     * : Listings per batch (API filter batch size). Default: 20.
     *
     * [--dry-run]
     * : Only report what would be removed; do not delete.
     *
     * ## EXAMPLES
     *
     *     wp ls_sync_mbn cleanup_listings
     *     wp ls_sync_mbn cleanup_listings --per_page=20 --cmf=75231145
     *     wp ls_sync_mbn cleanup_listings --dry-run
     */
    public function cleanup_listings( $args, $assoc_args ) {
        $per_page = isset( $assoc_args['per_page'] ) ? max( 1, (int) $assoc_args['per_page'] ) : 20;
        $dry_run  = isset( $assoc_args['dry-run'] );
        $cmf      = isset( $assoc_args['cmf'] ) ? $assoc_args['cmf'] : null;
        if ( $cmf === null || $cmf === '' ) {
            $motors = get_option( 'ls_auto_sync_motors', [] );
            $cmf    = ! empty( $motors ) ? $motors[0] : '76251145';
        }
        if ( ! function_exists( 'ls_get_unit_stock_numbers_in_ls' ) ) {
            WP_CLI::error( 'ls_get_unit_stock_numbers_in_ls not found. Ensure includes/api.php is loaded.' );
        }
        $paged   = 1;
        $deleted = 0;
        $skipped = 0;
        WP_CLI::log( 'Cleanup listings: CMF=' . $cmf . ', per_page=' . $per_page . ( $dry_run ? ' (dry-run)' : '' ) );
        while ( true ) {
            $query = new WP_Query( [
                'post_type'      => 'listings',
                'post_status'    => 'any',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                'orderby'       => 'ID',
                'order'         => 'ASC',
                'fields'        => 'ids',
                'no_found_rows' => false,
            ] );
            $post_ids = $query->posts;
            if ( empty( $post_ids ) ) {
                break;
            }
            $stock_by_id = [];
            foreach ( $post_ids as $post_id ) {
                $sn = get_post_meta( $post_id, 'stock_number', true );
                $stock_by_id[ $post_id ] = $sn !== '' && $sn !== null ? (string) $sn : null;
            }
            $stock_numbers = array_values( array_filter( array_unique( array_values( $stock_by_id ) ) ) );
            if ( empty( $stock_numbers ) ) {
                foreach ( array_keys( $stock_by_id ) as $pid ) {
                    if ( $dry_run ) {
                        WP_CLI::log( "Would remove post $pid (no stock_number)." );
                        $skipped++;
                    } else {
                        wp_delete_post( $pid, true );
                        $deleted++;
                        WP_CLI::log( "Removed post $pid (no stock_number)." );
                    }
                }
                $paged++;
                continue;
            }

            $in_ls = ls_get_unit_stock_numbers_in_ls( $stock_numbers, $cmf );
            if ( $paged === 1 && empty( $in_ls ) && ! empty( $stock_numbers ) ) {
                WP_CLI::warning( 'API returned no units for first batch; check credentials and CMF.' );
            }
            $in_ls_set = array_flip( $in_ls );
            foreach ( $stock_by_id as $post_id => $stock_number ) {
                if ( $stock_number === null ) {
                    if ( $dry_run ) {
                        WP_CLI::log( "Would remove post $post_id (no stock_number)." );
                        $skipped++;
                    } else {
                        wp_delete_post( $post_id, true );
                        $deleted++;
                        WP_CLI::log( "Removed post $post_id (no stock_number)." );
                    }
                    continue;
                }
                if ( ! isset( $in_ls_set[ $stock_number ] ) ) {
                    if ( $dry_run ) {
                        WP_CLI::log( "Would remove post $post_id (stock $stock_number not in LS)." );
                        $skipped++;
                    } else {
                        wp_delete_post( $post_id, true );
                        $deleted++;
                        WP_CLI::log( "Removed post $post_id (stock $stock_number not in LS)." );
                    }
                }
            }
            $total = $query->found_posts;
            $from  = ( $paged - 1 ) * $per_page + 1;
            $to    = min( $paged * $per_page, $total );
            WP_CLI::log( "Batch $paged: checked posts $from–$to of $total." );
            if ( $paged >= $query->max_num_pages ) {
                break;
            }
            $paged++;
        }
        if ( $dry_run ) {
            WP_CLI::success( "Dry-run: would remove $skipped listing(s)." );
        } else {
            WP_CLI::success( "Cleanup done. Removed $deleted listing(s)." );
        }
    }
}

WP_CLI::add_command( 'ls_sync_mbn', 'LS_Sync_MBN_Command' );
