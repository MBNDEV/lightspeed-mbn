<?php
// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 3PA API rate limit (Lightspeed docs: "Limit your requests to no more than 200 requests per minute").
 * We only issue calls when under a per-minute cap so we never wait for the API to return rate limit.
 */
const LS_3PA_RATE_LIMIT_DOCS_MAX   = 200;  // Docs: max 200 requests per minute.
const LS_3PA_RATE_LIMIT_CAP        = 180;  // Our cap: 200 with margin to avoid hitting API limit.
const LS_3PA_RATE_LIMIT_WINDOW_SEC = 60;
const LS_3PA_RATE_LIMIT_OPTION     = 'ls_3pa_request_timestamps';
const LS_3PA_RATE_LIMIT_MESSAGE    = 'Rate limit: too many requests per minute. Try again later.';

/** Sync: small chunk per request to avoid gateway timeout (docs: max 500 per page). */
const LS_SYNC_CHUNK_SIZE           = 15;
const LS_SYNC_PAGE_SIZE_MAX        = 500;
/** Max seconds per HTTP request before bailing and showing "Next page". */
const LS_SYNC_MAX_SEC_PER_REQUEST  = 20;

/**
 * Record that a 3PA request was made (for sliding-window rate limit).
 */
function ls_3pa_record_request() {
    $timestamps = (array) get_option( LS_3PA_RATE_LIMIT_OPTION, [] );
    $now        = microtime( true );
    $cutoff     = $now - LS_3PA_RATE_LIMIT_WINDOW_SEC;
    $timestamps = array_values( array_filter( $timestamps, function ( $t ) use ( $cutoff ) {
        return $t >= $cutoff;
    } ) );
    $timestamps[] = $now;
    update_option( LS_3PA_RATE_LIMIT_OPTION, $timestamps );
}

/**
 * Validate before 3PA API call: ensure we are under 200/min with margin (180/min).
 * Returns true if request is allowed (and records it). Returns false if at cap — do not call API; cut/return.
 * Call this immediately before every wp_remote_get to 3PA; do not change wp_remote_get.
 *
 * @return bool True if allowed to send request, false if still waiting / at rate limit (skip request).
 */
function ls_3pa_validate_before_request() {
    $timestamps = (array) get_option( LS_3PA_RATE_LIMIT_OPTION, [] );
    $now        = microtime( true );
    $cutoff     = $now - LS_3PA_RATE_LIMIT_WINDOW_SEC;
    $timestamps = array_values( array_filter( $timestamps, function ( $t ) use ( $cutoff ) {
        return $t >= $cutoff;
    } ) );
    if ( count( $timestamps ) >= LS_3PA_RATE_LIMIT_CAP ) {
        return false;
    }
    ls_3pa_record_request();
    return true;
}

// Function to handle the API request.
function ls_api_request_dealer() {
    // Get the API credentials from the wp_options table.
    $api_url = get_option( 'ls_api_url', '' );
    $username = get_option( 'ls_username', '' );
    $password = get_option( 'ls_password', '' );

    // Check if all required options are set.
    if ( empty( $api_url ) || empty( $username ) || empty( $password ) ) {
        return 'Missing API credentials. Please set ls_api_url, ls_username, and ls_password in the settings.';
    }

    // Full endpoint URL.
    $endpoint = rtrim( $api_url ) . '/Dealer';

    // Prepare the request headers.
    $headers = [
        'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
        'Content-Type'  => 'application/json',
    ];

    if ( ! ls_3pa_validate_before_request() ) {
        return LS_3PA_RATE_LIMIT_MESSAGE;
    }
    $response = wp_remote_get( $endpoint, [
        'headers' => $headers,
    ] );

    // $status_code = wp_remote_retrieve_response_code( $response );
    // if ( $status_code !== 200 ) {
    //     return 'API returned an error: HTTP ' . $status_code;
    // }

    // Check for errors in the response.
    if ( is_wp_error( $response ) ) {
        return 'Error: ' . $response->get_error_message();
    }

    // Get the response body.
    $response_body = wp_remote_retrieve_body( $response );
    // Decode the JSON response.
    $data = json_decode( $response_body, true );

    // Handle invalid JSON response.
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return 'Error decoding JSON response: ' . json_last_error_msg();
    }

    // Return the data.
    return $data;
}

/**
 * Fetch one page of parts (TOP/SKIP/ORDERBY per docs).
 *
 * @param string     $cmf      CMF identifier.
 * @param int        $skip     Number of records to skip.
 * @param int        $top      Number to fetch (capped at LS_SYNC_PAGE_SIZE_MAX).
 * @param string|null $run_id  Run ID for API logging.
 * @return array|string Decoded data array or error string.
 */
function ls_sync_parts_inventory_page( $cmf, $skip, $top, $run_id = null ) {
    $top = (int) $top;
    if ( $top <= 0 || $top > LS_SYNC_PAGE_SIZE_MAX ) {
        $top = LS_SYNC_CHUNK_SIZE;
    }
    $skip = max( 0, (int) $skip );
    $api_url  = get_option( 'ls_api_url', '' );
    $username = get_option( 'ls_username', '' );
    $password = get_option( 'ls_password', '' );
    $endpoint = rtrim( $api_url ) . '/Part/' . $cmf . '?$top=' . $top . '&$skip=' . $skip . '&$orderby=PartNumber';
    if ( empty( $api_url ) || empty( $username ) || empty( $password ) ) {
        if ( $run_id !== null && $run_id !== '' ) {
            ls_motors_log( [ 'StockNumber' => $cmf, 'endpoint' => $endpoint, 'response_body' => '', 'headers' => [], 'status_code' => 0 ], $run_id );
        }
        return 'Missing API credentials.';
    }
    $headers = [ 'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ), 'Content-Type' => 'application/json' ];
    if ( ! ls_3pa_validate_before_request() ) {
        if ( $run_id !== null && $run_id !== '' ) {
            ls_motors_log( [ 'StockNumber' => $cmf, 'endpoint' => $endpoint, 'response_body' => '', 'headers' => [], 'status_code' => 429 ], $run_id );
        }
        return LS_3PA_RATE_LIMIT_MESSAGE;
    }
    $response = wp_remote_get( $endpoint, [ 'headers' => $headers, 'timeout' => 30 ] );
    $status_code = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
    $response_body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
    $response_headers = is_wp_error( $response ) ? [] : wp_remote_retrieve_headers( $response );
    if ( $run_id !== null && $run_id !== '' ) {
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
 * Fetch one page of units (TOP/SKIP/ORDERBY per docs).
 */
function ls_sync_major_unit_page( $cmf, $skip, $top, $run_id = null ) {
    $top = (int) $top;
    if ( $top <= 0 || $top > LS_SYNC_PAGE_SIZE_MAX ) {
        $top = LS_SYNC_CHUNK_SIZE;
    }
    $skip = max( 0, (int) $skip );
    $api_url  = get_option( 'ls_api_url', '' );
    $username = get_option( 'ls_username', '' );
    $password = get_option( 'ls_password', '' );
    $endpoint = rtrim( $api_url ) . '/Unit/' . $cmf . '?$top=' . $top . '&$skip=' . $skip . '&$orderby=StockNumber';
    if ( empty( $api_url ) || empty( $username ) || empty( $password ) ) {
        if ( $run_id !== null && $run_id !== '' ) {
            ls_motors_log( [ 'StockNumber' => $cmf, 'endpoint' => $endpoint, 'response_body' => '', 'headers' => [], 'status_code' => 0 ], $run_id );
        }
        return 'Missing API credentials.';
    }
    $headers = [ 'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ), 'Content-Type' => 'application/json' ];
    if ( ! ls_3pa_validate_before_request() ) {
        if ( $run_id !== null && $run_id !== '' ) {
            ls_motors_log( [ 'StockNumber' => $cmf, 'endpoint' => $endpoint, 'response_body' => '', 'headers' => [], 'status_code' => 429 ], $run_id );
        }
        return LS_3PA_RATE_LIMIT_MESSAGE;
    }
    $response = wp_remote_get( $endpoint, [ 'headers' => $headers, 'timeout' => 30 ] );
    $status_code = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
    $response_body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
    $response_headers = is_wp_error( $response ) ? [] : wp_remote_retrieve_headers( $response );
    if ( $run_id !== null && $run_id !== '' ) {
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
 * Parts inventory: first chunk only (backward compat). Admin paged sync uses ls_sync_parts_inventory_page directly.
 *
 * @param string     $cmf    CMF identifier.
 * @param string|null $run_id Optional. Run ID for API logging.
 * @return array|string Decoded data array or error string.
 */
function ls_sync_parts_inventory( $cmf, $run_id = null ) {
    return ls_sync_parts_inventory_page( $cmf, 0, LS_SYNC_CHUNK_SIZE, $run_id );
}

/**
 * Unit list: full fetch with no $top/$skip (used by settings.php cron). Admin paged sync uses ls_sync_major_unit_page.
 *
 * @param string     $cmf    CMF identifier.
 * @param string|null $run_id Optional. Run ID for API logging.
 * @return array|string Decoded data array or error string.
 */
function ls_sync_major_unit( $cmf, $run_id = null ) {
    // Retrieve API credentials from wp_options.
    $api_url = get_option( 'ls_api_url', '' );
    $username = get_option( 'ls_username', '' );
    $password = get_option( 'ls_password', '' );

    // Prepare the API endpoint (used for request and for logging).
    $endpoint = rtrim( $api_url ) . "/Unit/".$cmf;

    // Check if credentials are available.
    if ( empty( $api_url ) || empty( $username ) || empty( $password ) ) {
        if ( $run_id !== null && $run_id !== '' ) {
            ls_motors_log( [
                'StockNumber'   => $cmf,
                'endpoint'      => $endpoint,
                'response_body' => '',
                'headers'       => [],
                'status_code'   => 0,
            ], $run_id );
        }
        return 'Missing API credentials. Please set ls_api_url, ls_username, and ls_password in the settings.';
    }

    // Prepare the headers for authentication.
    $headers = [
        'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
        'Content-Type'  => 'application/json',
    ];

    if ( ! ls_3pa_validate_before_request() ) {
        if ( $run_id !== null && $run_id !== '' ) {
            ls_motors_log( [
                'StockNumber'   => $cmf,
                'endpoint'      => $endpoint,
                'response_body' => '',
                'headers'       => [],
                'status_code'   => 429,
            ], $run_id );
        }
        return LS_3PA_RATE_LIMIT_MESSAGE;
    }
    $response = wp_remote_get( $endpoint, [
        'headers' => $headers,
        'timeout' => 15,
    ] );

    $status_code = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
    $response_body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
    $response_headers = is_wp_error( $response ) ? [] : wp_remote_retrieve_headers( $response );

    // Log API call when run_id is provided (e.g. from Sync / Sync New buttons).
    if ( $run_id !== null && $run_id !== '' ) {
        ls_motors_log( [
            'StockNumber'   => $cmf,
            'endpoint'      => $endpoint,
            'response_body'  => $response_body,
            'headers'       => $response_headers,
            'status_code'   => $status_code,
        ], $run_id );
    }

    // Check for errors.
    if ( is_wp_error( $response ) ) {
        return 'Error: ' . $response->get_error_message();
    }

    // Retrieve the response body and decode it.
    $data = json_decode( $response_body, true );

    // Check for JSON decoding errors.
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return 'Error decoding JSON response: ' . json_last_error_msg();
    }

    return $data;
}

function get_single_unit($stock_number, $run_id) {
    // Retrieve API credentials from wp_options.
    $api_url = get_option( 'ls_api_url', '' );
    $username = get_option( 'ls_username', '' );
    $password = get_option( 'ls_password', '' );

    // Check if credentials are available.
    if ( empty( $api_url ) || empty( $username ) || empty( $password ) ) {
        return ['data' => 'Missing API credentials. Please set ls_api_url, ls_username, and ls_password in the settings.', 'status_code' => 500];
    }

    // Prepare the API endpoint.
    $endpoint = rtrim( $api_url ) . "/Unit/76251145/?\$filter=StockNumber eq '".$stock_number."'";

    // Prepare the headers for authentication.
    $headers = [
        'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
        'Content-Type'  => 'application/json',
    ];

    if ( ! ls_3pa_validate_before_request() ) {
        return [
            'message'     => LS_3PA_RATE_LIMIT_MESSAGE,
            'status_code' => 429,
        ];
    }
    $response = wp_remote_get( $endpoint, [
        'headers' => $headers,
        'timeout' => 15,
    ] );

    // Check for errors.
    if ( is_wp_error( $response ) ) {
         return ['message' => 'Error: ' . $response->get_error_message(), 'status_code' => 500];
    }

    // Retrieve the response body and decode it.
    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );
    $status_code = wp_remote_retrieve_response_code($response);
    ls_motors_log([
        'StockNumber' => $stock_number,
        'endpoint' => $endpoint,
        'response_body' => $response_body,
        'headers' => wp_remote_retrieve_headers($response),
        'status_code' => $status_code
    ], $run_id);
    // Check for JSON decoding errors.
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return ['message' => 'Error decoding JSON response: ' . json_last_error_msg(), 'status_code' => 500];
    }

    return [
        'data' => $data,
        'status_code' => $status_code
    ];
}