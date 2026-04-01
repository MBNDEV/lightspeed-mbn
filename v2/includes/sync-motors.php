<?php
/**
 * Sync v2 — Motors: single-item processor, change detection, all_items/updated_today handlers.
 * Calls v1 sync_part_to_motors, sync_motors_image from includes. Do not modify includes/.
 * Images are always processed immediately per item (no separate image cron in v2).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Post meta key for stored unit JSON (change detection). */
define( 'LS_V2_META_UNIT_JSON', '_ls_unit_json' );

/** Option key: skip cursor for "updated today" per CMF (resume after error/rate limit). */
define( 'LS_V2_OPTION_MOTORS_SKIP_TODAY', 'ls_sync_v2_motors_skip_today' );
/** Option key: skip cursor for "rest" (daily) per CMF. */
define( 'LS_V2_OPTION_MOTORS_SKIP_REST', 'ls_sync_v2_motors_skip_rest' );

/**
 * Normalize Unit API response to a list of unit arrays (handles value, 0-indexed, or single object).
 *
 * @param array|mixed $data Raw decoded API response.
 * @return array List of unit arrays.
 */
function ls_v2_motors_normalize_units_response( $data ) {
    if ( ! is_array( $data ) ) {
        return [];
    }
    if ( isset( $data['value'] ) && is_array( $data['value'] ) ) {
        return $data['value'];
    }
    if ( isset( $data[0] ) && is_array( $data[0] ) ) {
        return $data;
    }
    if ( isset( $data['StockNumber'] ) ) {
        return [ $data ];
    }
    return [];
}

/**
 * Get stored skip for a CMF (updated-today or rest phase). Returns 0 if not set.
 *
 * @param string $option_key LS_V2_OPTION_MOTORS_SKIP_TODAY or LS_V2_OPTION_MOTORS_SKIP_REST.
 * @param string $cmf CMF identifier.
 * @return int
 */
function ls_v2_motors_get_skip( $option_key, $cmf ) {
    $arr = get_option( $option_key, [] );
    return isset( $arr[ $cmf ] ) ? max( 0, (int) $arr[ $cmf ] ) : 0;
}

/**
 * Save skip for a CMF. If empty results, pass 0 to reset.
 *
 * @param string $option_key LS_V2_OPTION_MOTORS_SKIP_TODAY or LS_V2_OPTION_MOTORS_SKIP_REST.
 * @param string $cmf CMF identifier.
 * @param int    $skip Value to store.
 */
function ls_v2_motors_set_skip( $option_key, $cmf, $skip ) {
    $arr   = get_option( $option_key, [] );
    $arr   = is_array( $arr ) ? $arr : [];
    $skip  = max( 0, (int) $skip );
    if ( $skip === 0 ) {
        unset( $arr[ $cmf ] );
    } else {
        $arr[ $cmf ] = $skip;
    }
    update_option( $option_key, $arr );
}

/**
 * Ensure a listing post exists for the given unit; create minimal one if not. Store unit JSON in meta.
 *
 * @param array $unit Unit object (must have StockNumber).
 * @return int Post ID.
 */
function ls_v2_ensure_listing_for_unit( $unit ) {
    $stock_number = isset( $unit['StockNumber'] ) ? $unit['StockNumber'] : '';
    if ( $stock_number === '' ) {
        return 0;
    }
    $post_id = ls_get_listing_post_id_by_stock_number( $stock_number );
    if ( $post_id ) {
        update_post_meta( $post_id, LS_V2_META_UNIT_JSON, wp_json_encode( $unit ) );
        return $post_id;
    }
    $status = get_motors_status( $unit );
    $post_data = [
        'post_title'   => ( $unit['ModelYear'] ?? '' ) . ' ' . ( $unit['Make'] ?? '' ) . ' ' . ( $unit['Model'] ?? '' ),
        'post_type'    => 'listings',
        'post_status'  => $status,
        'post_content' => '',
    ];
    $post_id = wp_insert_post( $post_data );
    if ( is_wp_error( $post_id ) || ! $post_id ) {
        return 0;
    }
    add_post_meta( $post_id, 'stock_number', $stock_number );
    update_post_meta( $post_id, LS_V2_META_UNIT_JSON, wp_json_encode( $unit ) );
    return $post_id;
}

/**
 * Process one motor listing: ensure listing, change detection, then v1 sync + image.
 * Call with a unit object (from updated_today/all_items) to avoid API; or with stock number string (e.g. CLI) to fetch unit.
 *
 * @param array|string $unit_or_stock_number Unit array (must have StockNumber) or stock number string.
 * @param string|null  $cmf                  CMF (default from option or 76251145).
 * @return array{success: bool, post_id?: int, skipped?: bool, message?: string}
 */
function ls_process_item_sync_stock_number( $unit_or_stock_number, $cmf = null ) {
    $cmf     = ( $cmf === null || $cmf === '' ) ? '76251145' : $cmf;
    $run_id  = date( 'Y-m-d_H-i-s' ) . '_v2';

    if ( is_array( $unit_or_stock_number ) && ! empty( $unit_or_stock_number['StockNumber'] ) ) {
        $unit = $unit_or_stock_number;
    } else {
        $stock_number = is_string( $unit_or_stock_number ) ? trim( $unit_or_stock_number ) : '';
        if ( $stock_number === '' ) {
            return [ 'success' => false, 'message' => 'Empty stock number.' ];
        }
        $result = get_single_unit( $stock_number, $run_id, $cmf );
        if ( isset( $result['message'] ) ) {
            return [ 'success' => false, 'message' => $result['message'] ];
        }
        $rows = ls_v2_motors_normalize_units_response( isset( $result['data'] ) ? $result['data'] : [] );
        $unit = isset( $rows[0] ) ? $rows[0] : null;
        if ( ! is_array( $unit ) || empty( $unit['StockNumber'] ) ) {
            return [ 'success' => false, 'message' => 'Unit not found or invalid response.' ];
        }
    }

    $stock_number = $unit['StockNumber'];
    $post_id      = ls_v2_ensure_listing_for_unit( $unit );
    if ( ! $post_id ) {
        if ( function_exists( 'ls_motors_log' ) ) {
            ls_motors_log( [ 'error' => 'Could not ensure listing post', 'StockNumber' => $stock_number, 'unit' => $unit ], $run_id );
        }
        return [ 'success' => false, 'message' => 'Could not ensure listing post.' ];
    }

    if ( ! function_exists( 'sync_part_to_motors' ) || ! function_exists( 'sync_motors_image' ) ) {
        if ( function_exists( 'ls_motors_log' ) ) {
            ls_motors_log( [ 'error' => 'v1 sync functions not available', 'StockNumber' => $stock_number ], $run_id );
        }
        return [ 'success' => false, 'message' => 'v1 sync functions not available.' ];
    }

    sync_part_to_motors( $unit, $run_id );
    sync_motors_image( $unit, $run_id );
    update_post_meta( $post_id, LS_V2_META_UNIT_JSON, wp_json_encode( $unit ) );
    wp_update_post( [ 'ID' => $post_id, 'post_status' => get_motors_status( $unit ) ] );

    return [ 'success' => true, 'post_id' => $post_id ];
}

/**
 * Run daily Motors sync for one CMF: paginate all units (no date filter), process each immediately.
 * Skip is stored in DB (resume after error/rate limit). Empty results reset skip to 0.
 * Same listing/post meta as v1: sync_part_to_motors + sync_motors_image (post_meta_logs, taxonomies, gallery, etc.).
 * Images are processed immediately per unit — no separate cron for images.
 *
 * @param string $cmf CMF identifier.
 * @param string $run_id Run ID for logging.
 * @param int    $max_seconds Optional cap; 0 = no cap.
 * @param int    $max_items   Optional cap per run; 0 = no cap.
 */
function ls_v2_run_all_items_motors( $cmf, $run_id, $max_seconds = 0, $max_items = 0 ) {
    $chunk      = defined( 'LS_SYNC_CHUNK_SIZE' ) ? LS_SYNC_CHUNK_SIZE : 15;
    $skip_rest  = ls_v2_motors_get_skip( LS_V2_OPTION_MOTORS_SKIP_REST, $cmf );
    $page_size  = $chunk;
    $processed  = 0;

    while ( true ) {
        $data = ls_fetch_units_updated_since( $cmf, null, $page_size, $skip_rest, $run_id );
        if ( is_string( $data ) ) {
            if ( function_exists( 'ls_motors_log' ) ) {
                ls_motors_log( [ 'error' => $data, 'phase' => 'all_items' ], $run_id );
            }
            ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_REST, $cmf, $skip_rest );
            break;
        }
        $rows = ls_v2_motors_normalize_units_response( $data );
        if ( empty( $rows ) ) {
            ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_REST, $cmf, 0 );
            if ( function_exists( 'ls_motors_log' ) ) {
                ls_motors_log( [ 'message' => 'all_items: no more units', 'cmf' => $cmf, 'skip' => $skip_rest ], $run_id );
            }
            break;
        }
        if ( function_exists( 'ls_motors_log' ) ) {
            ls_motors_log( [ 'message' => 'all_items: processing batch', 'cmf' => $cmf, 'count' => count( $rows ), 'skip' => $skip_rest ], $run_id );
        }
        foreach ( $rows as $unit ) {
            if ( ! is_array( $unit ) || empty( $unit['StockNumber'] ) ) {
                continue;
            }
            ls_process_item_sync_stock_number( $unit, $cmf );
            $processed++;
            if ( $max_seconds && time() >= $max_seconds ) {
                ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_REST, $cmf, $skip_rest + count( $rows ) );
                return;
            }
            if ( $max_items && $processed >= $max_items ) {
                ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_REST, $cmf, $skip_rest + count( $rows ) );
                return;
            }
        }
        $skip_rest += count( $rows );
        ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_REST, $cmf, $skip_rest );
        if ( count( $rows ) < $page_size ) {
            ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_REST, $cmf, 0 );
            break;
        }
    }
}

/**
 * Run "updated today" Motors sync for one CMF: fetch units updated since start of today, store and process immediately.
 * Same $top/$skip logic and DB-stored skip as ls_v2_run_all_items_motors (updated-today phase). Empty results reset skip to 0.
 * Images are processed immediately per unit via sync_motors_image() inside ls_process_item_sync_stock_number — no cron for images.
 *
 * @param string $cmf CMF identifier.
 * @param string $run_id Run ID for logging.
 */
function ls_v2_run_updated_today_motors( $cmf, $run_id ) {
    $today_start = gmdate( 'Y-m-d' ) . 'T00:00:00';
    $skip_today  = ls_v2_motors_get_skip( LS_V2_OPTION_MOTORS_SKIP_TODAY, $cmf );
    $chunk       = defined( 'LS_SYNC_CHUNK_SIZE' ) ? LS_SYNC_CHUNK_SIZE : 15;

    while ( true ) {
        $data = ls_fetch_units_updated_since( $cmf, $today_start, $chunk, $skip_today, $run_id );
        if ( is_string( $data ) ) {
            if ( function_exists( 'ls_motors_log' ) ) {
                ls_motors_log( [ 'error' => $data, 'phase' => 'updated_today' ], $run_id );
            }
            ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_TODAY, $cmf, $skip_today );
            break;
        }
        $rows = ls_v2_motors_normalize_units_response( $data );
        if ( empty( $rows ) ) {
            ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_TODAY, $cmf, 0 );
            if ( function_exists( 'ls_motors_log' ) ) {
                ls_motors_log( [ 'message' => 'updated_today: no units with lastupdatedate >= today', 'cmf' => $cmf, 'filter' => $today_start ], $run_id );
            }
            break;
        }
        if ( function_exists( 'ls_motors_log' ) ) {
            ls_motors_log( [ 'message' => 'updated_today: processing batch', 'cmf' => $cmf, 'count' => count( $rows ), 'skip' => $skip_today ], $run_id );
        }
        foreach ( $rows as $unit ) {
            if ( ! is_array( $unit ) || empty( $unit['StockNumber'] ) ) {
                continue;
            }
            ls_process_item_sync_stock_number( $unit, $cmf );
        }
        $skip_today += count( $rows );
        ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_TODAY, $cmf, $skip_today );
        if ( count( $rows ) < $chunk ) {
            ls_v2_motors_set_skip( LS_V2_OPTION_MOTORS_SKIP_TODAY, $cmf, 0 );
            break;
        }
    }
}
