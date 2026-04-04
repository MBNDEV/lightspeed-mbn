<?php
/**
 * Sync v2 — WooCommerce: single-item processor, all_items/updated_today handlers.
 * Calls v1 sync_part_to_woocommerce from includes. Do not modify includes/.
 * Same pattern as v2 sync-motors: DB-stored skip (resume after error/rate limit), pass part object to avoid API.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
    return;
}

/** Product meta key for stored part JSON. */
define( 'LS_V2_META_PART_JSON', '_ls_part_json' );

/** Option key: skip cursor for "updated today" per CMF (resume after error/rate limit). */
define( 'LS_V2_OPTION_WOO_SKIP_TODAY', 'ls_sync_v2_woo_skip_today' );
/** Option key: skip cursor for "rest" (daily) per CMF. */
define( 'LS_V2_OPTION_WOO_SKIP_REST', 'ls_sync_v2_woo_skip_rest' );

/**
 * Normalize Part API response to a list of part arrays (handles value, 0-indexed, or single object).
 *
 * @param array|mixed $data Raw decoded API response.
 * @return array List of part arrays.
 */
function ls_v2_woo_normalize_parts_response( $data ) {
    if ( ! is_array( $data ) ) {
        return [];
    }
    if ( isset( $data['value'] ) && is_array( $data['value'] ) ) {
        return $data['value'];
    }
    if ( isset( $data[0] ) && is_array( $data[0] ) ) {
        return $data;
    }
    if ( isset( $data['PartNumber'] ) ) {
        return [ $data ];
    }
    return [];
}

/**
 * Get stored skip for a CMF (updated-today or rest phase). Returns 0 if not set.
 *
 * @param string $option_key LS_V2_OPTION_WOO_SKIP_TODAY or LS_V2_OPTION_WOO_SKIP_REST.
 * @param string $cmf        CMF identifier.
 * @return int
 */
function ls_v2_woo_get_skip( $option_key, $cmf ) {
    $arr = get_option( $option_key, [] );
    return isset( $arr[ $cmf ] ) ? max( 0, (int) $arr[ $cmf ] ) : 0;
}

/**
 * Save skip for a CMF. If empty results, pass 0 to reset.
 *
 * @param string $option_key LS_V2_OPTION_WOO_SKIP_TODAY or LS_V2_OPTION_WOO_SKIP_REST.
 * @param string $cmf        CMF identifier.
 * @param int    $skip       Value to store.
 */
function ls_v2_woo_set_skip( $option_key, $cmf, $skip ) {
    $arr  = get_option( $option_key, [] );
    $arr  = is_array( $arr ) ? $arr : [];
    $skip = max( 0, (int) $skip );
    if ( $skip === 0 ) {
        unset( $arr[ $cmf ] );
    } else {
        $arr[ $cmf ] = $skip;
    }
    update_option( $option_key, $arr );
}

/**
 * Ensure a product exists for the part (by SKU); create minimal one if not. Store part JSON in meta.
 *
 * @param array $part Part object (must have PartNumber).
 * @return int Product ID or 0 on failure.
 */
function ls_v2_ensure_product_for_part( $part ) {
    $part_number = isset( $part['PartNumber'] ) ? $part['PartNumber'] : '';
    if ( $part_number === '' ) {
        return 0;
    }
    $product_id = wc_get_product_id_by_sku( $part_number );
    if ( $product_id ) {
        update_post_meta( $product_id, LS_V2_META_PART_JSON, wp_json_encode( $part ) );
        ls_v2_redirection_delete_source_for_post( $product_id );
        return $product_id;
    }
    if ( ! class_exists( 'WC_Product_Simple' ) ) {
        return 0;
    }
    $product = new WC_Product_Simple();
    $product->set_name( $part['Description'] ?? '' );
    $product->set_sku( $part_number );
    $product->set_status( 'publish' );
    $product->save();
    $product_id = $product->get_id();
    if ( ! $product_id ) {
        return 0;
    }
    update_post_meta( $product_id, LS_V2_META_PART_JSON, wp_json_encode( $part ) );
    ls_v2_redirection_delete_source_for_post( $product_id );
    return $product_id;
}

/**
 * Process one WooCommerce product: ensure product, then v1 sync.
 * Call with a part object (from updated_today/all_items) to avoid API; or with part number string (e.g. CLI) to fetch part.
 *
 * @param array|string $part_or_part_number Part array (must have PartNumber) or part number (SKU) string.
 * @param string|null  $cmf                 CMF (default 76251145).
 * @return array{success: bool, product_id?: int, skipped?: bool, message?: string}
 */
function ls_process_part_sync_part_number( $part_or_part_number, $cmf = null ) {
    $cmf    = ( $cmf === null || $cmf === '' ) ? '76251145' : $cmf;
    $run_id = date( 'Y-m-d_H-i-s' ) . '_v2';

    if ( is_array( $part_or_part_number ) && ! empty( $part_or_part_number['PartNumber'] ) ) {
        $part = $part_or_part_number;
    } else {
        $part_number = is_string( $part_or_part_number ) ? trim( $part_or_part_number ) : '';
        if ( $part_number === '' ) {
            return [ 'success' => false, 'message' => 'Empty part number.' ];
        }
        $result = get_single_part( $part_number, $cmf, $run_id );
        if ( isset( $result['message'] ) ) {
            return [ 'success' => false, 'message' => $result['message'] ];
        }
        $part = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : null;
        if ( ! is_array( $part ) || empty( $part['PartNumber'] ) ) {
            return [ 'success' => false, 'message' => 'Part not found or invalid response.' ];
        }
    }

    $product_id = ls_v2_ensure_product_for_part( $part );
    if ( ! $product_id ) {
        if ( function_exists( 'ls_motors_log' ) ) {
            ls_motors_log( [ 'error' => 'Could not ensure product', 'PartNumber' => $part['PartNumber'] ?? '', 'part' => $part ], $run_id );
        }
        return [ 'success' => false, 'message' => 'Could not ensure product.' ];
    }

    if ( ! function_exists( 'sync_part_to_woocommerce' ) ) {
        if ( function_exists( 'ls_motors_log' ) ) {
            ls_motors_log( [ 'error' => 'v1 sync function not available', 'PartNumber' => $part['PartNumber'] ?? '' ], $run_id );
        }
        return [ 'success' => false, 'message' => 'v1 sync function not available.' ];
    }

    sync_part_to_woocommerce( $part, $run_id );
    update_post_meta( $product_id, LS_V2_META_PART_JSON, wp_json_encode( $part ) );

    return [ 'success' => true, 'product_id' => $product_id ];
}

/**
 * Run daily WooCommerce sync for one CMF: fetch updated today first, then remaining; process each immediately.
 * Skip for both phases is stored in DB (resume after error/rate limit). Empty results reset skip to 0.
 *
 * @param string $cmf         CMF identifier.
 * @param string $run_id      Run ID for logging.
 * @param int    $max_seconds Optional cap; 0 = no cap.
 * @param int    $max_items   Optional cap for "rest" run; 0 = no cap.
 */
function ls_v2_run_all_items_woo( $cmf, $run_id, $max_seconds = 0, $max_items = 0 ) {
    $today_start = gmdate( 'Y-m-d' ) . 'T00:00:00';
    $skip_today  = ls_v2_woo_get_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf );
    $chunk       = defined( 'LS_SYNC_CHUNK_SIZE' ) ? LS_SYNC_CHUNK_SIZE : 15;

    while ( true ) {
        $data = ls_fetch_parts_updated_since( $cmf, $today_start, $chunk, $skip_today, $run_id );
        if ( is_string( $data ) ) {
            if ( function_exists( 'ls_motors_log' ) ) {
                ls_motors_log( [ 'error' => $data, 'phase' => 'updated_today' ], $run_id );
            }
            ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf, $skip_today );
            break;
        }
        $rows = ls_v2_woo_normalize_parts_response( $data );
        if ( empty( $rows ) ) {
            ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf, 0 );
            if ( function_exists( 'ls_motors_log' ) ) {
                ls_motors_log( [ 'message' => 'updated_today: no parts with lastupdatedate >= today', 'cmf' => $cmf, 'filter' => $today_start ], $run_id );
            }
            break;
        }
        if ( function_exists( 'ls_motors_log' ) ) {
            ls_motors_log( [ 'message' => 'updated_today: processing batch', 'cmf' => $cmf, 'count' => count( $rows ), 'skip' => $skip_today ], $run_id );
        }
        foreach ( $rows as $part ) {
            if ( ! is_array( $part ) || empty( $part['PartNumber'] ) ) {
                continue;
            }
            ls_process_part_sync_part_number( $part, $cmf );
            if ( $max_seconds && time() >= $max_seconds ) {
                ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf, $skip_today + count( $rows ) );
                return;
            }
        }
        $skip_today += count( $rows );
        ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf, $skip_today );
        if ( count( $rows ) < $chunk ) {
            ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf, 0 );
            break;
        }
    }

    $skip_rest  = ls_v2_woo_get_skip( LS_V2_OPTION_WOO_SKIP_REST, $cmf );
    $page_size  = $chunk;
    while ( true ) {
        if ( ! function_exists( 'ls_sync_parts_inventory_page' ) ) {
            break;
        }
        $data = ls_sync_parts_inventory_page( $cmf, $skip_rest, $page_size, $run_id );
        if ( is_string( $data ) ) {
            ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_REST, $cmf, $skip_rest );
            break;
        }
        $rows = ls_v2_woo_normalize_parts_response( $data );
        if ( empty( $rows ) ) {
            ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_REST, $cmf, 0 );
            break;
        }
        foreach ( $rows as $part ) {
            if ( ! is_array( $part ) || empty( $part['PartNumber'] ) ) {
                continue;
            }
            ls_process_part_sync_part_number( $part, $cmf );
            if ( $max_seconds && time() >= $max_seconds ) {
                ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_REST, $cmf, $skip_rest + count( $rows ) );
                return;
            }
            if ( $max_items && $skip_rest >= $max_items ) {
                return;
            }
        }
        $skip_rest += count( $rows );
        ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_REST, $cmf, $skip_rest );
        if ( count( $rows ) < $page_size ) {
            ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_REST, $cmf, 0 );
            break;
        }
    }
}

/**
 * Run "updated today" WooCommerce sync for one CMF: fetch parts updated since start of today, process immediately.
 * Same $top/$skip logic and DB-stored skip as ls_v2_run_all_items_woo (updated-today phase).
 *
 * @param string $cmf    CMF identifier.
 * @param string $run_id Run ID for logging.
 */
function ls_v2_run_updated_today_woo( $cmf, $run_id ) {
    $today_start = gmdate( 'Y-m-d' ) . 'T00:00:00';
    $skip_today  = ls_v2_woo_get_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf );
    $chunk       = defined( 'LS_SYNC_CHUNK_SIZE' ) ? LS_SYNC_CHUNK_SIZE : 15;

    while ( true ) {
        $data = ls_fetch_parts_updated_since( $cmf, $today_start, $chunk, $skip_today, $run_id );
        if ( is_string( $data ) ) {
            if ( function_exists( 'ls_motors_log' ) ) {
                ls_motors_log( [ 'error' => $data, 'phase' => 'updated_today' ], $run_id );
            }
            ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf, $skip_today );
            break;
        }
        $rows = ls_v2_woo_normalize_parts_response( $data );
        if ( empty( $rows ) ) {
            ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf, 0 );
            if ( function_exists( 'ls_motors_log' ) ) {
                ls_motors_log( [ 'message' => 'updated_today: no parts with lastupdatedate >= today', 'cmf' => $cmf, 'filter' => $today_start ], $run_id );
            }
            break;
        }
        if ( function_exists( 'ls_motors_log' ) ) {
            ls_motors_log( [ 'message' => 'updated_today: processing batch', 'cmf' => $cmf, 'count' => count( $rows ), 'skip' => $skip_today ], $run_id );
        }
        foreach ( $rows as $part ) {
            if ( ! is_array( $part ) || empty( $part['PartNumber'] ) ) {
                continue;
            }
            ls_process_part_sync_part_number( $part, $cmf );
        }
        $skip_today += count( $rows );
        ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf, $skip_today );
        if ( count( $rows ) < $chunk ) {
            ls_v2_woo_set_skip( LS_V2_OPTION_WOO_SKIP_TODAY, $cmf, 0 );
            break;
        }
    }
}
