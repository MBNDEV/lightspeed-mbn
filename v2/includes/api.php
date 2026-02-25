<?php
/**
 * Sync v2 API: fetch units/parts updated since a date. Do not modify includes/api.php.
 * Uses existing ls_3pa_validate_before_request, ls_motors_log, and options from includes.
 * All 3PA requests in this file MUST pass the rate limit check before wp_remote_get.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ensure we are under the per-minute rate limit before calling 3PA API. Call once immediately before each wp_remote_get.
 *
 * @return bool True if request is allowed; false if at cap (caller should not send request).
 */
function ls_v2_3pa_rate_limit_ok() {
    return function_exists( 'ls_3pa_validate_before_request' ) && ls_3pa_validate_before_request();
}

/**
 * Shared: fetch resource (Unit or Part) updated since datetime. OData filter: lastupdatedate ge datetime'...'.
 *
 * @param string     $resource  'Unit' or 'Part'.
 * @param string     $orderby   'StockNumber' or 'PartNumber'.
 * @param string     $cmf       CMF identifier.
 * @param string     $datetime_odata Date in OData format e.g. '2026-02-25T00:00:00'.
 * @param int        $top       Number to fetch.
 * @param int        $skip      Number of records to skip.
 * @param string|null $run_id   Run ID for API logging.
 * @return array|string Decoded data array or error string.
 */
function ls_fetch_updated_since( $resource, $orderby, $cmf, $datetime_odata, $top = 15, $skip = 0, $run_id = null ) {
    $top  = max( 1, min( (int) $top, defined( 'LS_SYNC_PAGE_SIZE_MAX' ) ? LS_SYNC_PAGE_SIZE_MAX : 500 ) );
    $skip = max( 0, (int) $skip );
    $api_url   = get_option( 'ls_api_url', '' );
    $username  = get_option( 'ls_username', '' );
    $password  = get_option( 'ls_password', '' );
    $safe_date = str_replace( "'", "''", $datetime_odata );
    $endpoint  = rtrim( $api_url ) . '/' . $resource . '/' . rawurlencode( $cmf ) . '?$top=' . $top . '&$skip=' . $skip . '&$filter=lastupdatedate ge datetime\'' . $safe_date . '\'&$orderby=' . $orderby;

    if ( empty( $api_url ) || empty( $username ) || empty( $password ) ) {
        if ( $run_id !== null && $run_id !== '' && function_exists( 'ls_motors_log' ) ) {
            ls_motors_log( [ 'StockNumber' => $cmf, 'endpoint' => $endpoint, 'response_body' => '', 'headers' => [], 'status_code' => 0 ], $run_id );
        }
        return 'Missing API credentials.';
    }

    $headers = [
        'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
        'Content-Type'  => 'application/json',
    ];

    if ( ! ls_v2_3pa_rate_limit_ok() ) {
        if ( function_exists( 'ls_motors_log' ) ) {
            $run_id_log = ( $run_id !== null && $run_id !== '' ) ? $run_id : date( 'Y-m-d_H-i-s' ) . '_v2';
            ls_motors_log( [ 'rate_limit' => true, 'StockNumber' => $cmf, 'endpoint' => $endpoint, 'response_body' => '', 'headers' => [], 'status_code' => 429 ], $run_id_log );
        }
        return function_exists( 'LS_3PA_RATE_LIMIT_MESSAGE' ) ? LS_3PA_RATE_LIMIT_MESSAGE : 'Rate limit.';
    }

    $response         = wp_remote_get( $endpoint, [ 'headers' => $headers, 'timeout' => 30 ] );
    $status_code      = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
    $response_body    = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
    $response_headers = is_wp_error( $response ) ? [] : wp_remote_retrieve_headers( $response );

    if ( $run_id !== null && $run_id !== '' && function_exists( 'ls_motors_log' ) ) {
        ls_motors_log( [ 'StockNumber' => $cmf, 'endpoint' => $endpoint, 'response_body' => $response_body, 'headers' => $response_headers, 'status_code' => $status_code ], $run_id );
    }

    if ( is_wp_error( $response ) ) {
        return 'Error: ' . $response->get_error_message();
    }

    $data = json_decode( $response_body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return 'Error decoding JSON response: ' . json_last_error_msg();
    }
    return $data;
}

/**
 * Fetch units updated since a given datetime (OData filter: lastupdatedate ge datetime'...').
 *
 * @param string     $cmf       CMF identifier.
 * @param string     $datetime_odata Date in OData format e.g. '2026-02-25T00:00:00'.
 * @param int        $top       Number to fetch (capped at LS_SYNC_PAGE_SIZE_MAX from includes).
 * @param int        $skip      Number of records to skip.
 * @param string|null $run_id   Run ID for API logging.
 * @return array|string Decoded data array or error string.
 */
function ls_fetch_units_updated_since( $cmf, $datetime_odata, $top = 15, $skip = 0, $run_id = null ) {
    return ls_fetch_updated_since( 'Unit', 'StockNumber', $cmf, $datetime_odata, $top, $skip, $run_id );
}

/**
 * Fetch parts updated since a given datetime (OData filter: lastupdatedate ge datetime'...').
 *
 * @param string     $cmf       CMF identifier.
 * @param string     $datetime_odata Date in OData format e.g. '2026-02-25T00:00:00'.
 * @param int        $top       Number to fetch.
 * @param int        $skip      Number of records to skip.
 * @param string|null $run_id   Run ID for API logging.
 * @return array|string Decoded data array or error string.
 */
function ls_fetch_parts_updated_since( $cmf, $datetime_odata, $top = 15, $skip = 0, $run_id = null ) {
    return ls_fetch_updated_since( 'Part', 'PartNumber', $cmf, $datetime_odata, $top, $skip, $run_id );
}

/**
 * Fetch a single part by PartNumber (for sync by part number). Part API with $filter=PartNumber eq '...'.
 *
 * @param string      $part_number Part number (SKU).
 * @param string|null $cmf        CMF (dealer code). Default 76251145.
 * @param string|null $run_id     Run ID for API logging.
 * @return array{data: array|null, status_code: int, message?: string}
 */
function get_single_part( $part_number, $cmf = null, $run_id = null ) {
    if ( $cmf === null || $cmf === '' ) {
        $cmf = '76251145';
    }
    $api_url  = get_option( 'ls_api_url', '' );
    $username = get_option( 'ls_username', '' );
    $password = get_option( 'ls_password', '' );

    if ( empty( $api_url ) || empty( $username ) || empty( $password ) ) {
        return [ 'data' => null, 'status_code' => 500, 'message' => 'Missing API credentials.' ];
    }

    $safe_part = str_replace( "'", "''", $part_number );
    $endpoint  = rtrim( $api_url ) . '/Part/' . rawurlencode( $cmf ) . '?$top=1&$filter=PartNumber eq \'' . $safe_part . '\'';

    $headers = [
        'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
        'Content-Type'  => 'application/json',
    ];

    if ( ! ls_v2_3pa_rate_limit_ok() ) {
        if ( function_exists( 'ls_motors_log' ) ) {
            $run_id_log = ( $run_id !== null && $run_id !== '' ) ? $run_id : date( 'Y-m-d_H-i-s' ) . '_v2';
            ls_motors_log( [ 'rate_limit' => true, 'StockNumber' => $part_number, 'endpoint' => $endpoint, 'response_body' => '', 'headers' => [], 'status_code' => 429 ], $run_id_log );
        }
        return [
            'message'     => function_exists( 'LS_3PA_RATE_LIMIT_MESSAGE' ) ? LS_3PA_RATE_LIMIT_MESSAGE : 'Rate limit.',
            'status_code' => 429,
        ];
    }

    $response = wp_remote_get( $endpoint, [ 'headers' => $headers, 'timeout' => 15 ] );

    if ( is_wp_error( $response ) ) {
        return [ 'message' => 'Error: ' . $response->get_error_message(), 'status_code' => 500 ];
    }

    $response_body = wp_remote_retrieve_body( $response );
    $data         = json_decode( $response_body, true );
    $status_code  = wp_remote_retrieve_response_code( $response );

    if ( $run_id !== null && $run_id !== '' && function_exists( 'ls_motors_log' ) ) {
        ls_motors_log( [
            'StockNumber'   => $part_number,
            'endpoint'      => $endpoint,
            'response_body' => $response_body,
            'headers'       => wp_remote_retrieve_headers( $response ),
            'status_code'   => $status_code,
        ], $run_id );
    }

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return [ 'message' => 'Error decoding JSON: ' . json_last_error_msg(), 'status_code' => 500 ];
    }

    $parts = is_array( $data ) ? $data : ( isset( $data['value'] ) && is_array( $data['value'] ) ? $data['value'] : [] );
    $first = ! empty( $parts ) ? $parts[0] : null;

    return [ 'data' => $first, 'status_code' => $status_code ];
}
