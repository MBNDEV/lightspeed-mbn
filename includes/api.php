<?php
// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
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

    // Use wp_remote_get to make the request.
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

// Function to sync data for a specific CMF.
function ls_sync_parts_inventory( $cmf ) {
    // Retrieve API credentials from wp_options.
    $api_url = get_option( 'ls_api_url', '' );
    $username = get_option( 'ls_username', '' );
    $password = get_option( 'ls_password', '' );

    // Check if credentials are available.
    if ( empty( $api_url ) || empty( $username ) || empty( $password ) ) {
        return 'Missing API credentials. Please set ls_api_url, ls_username, and ls_password in the settings.';
    }

    // Prepare the API endpoint.
    $endpoint = rtrim( $api_url ) . "/Part/".$cmf."?\$top=5";

    // Prepare the headers for authentication.
    $headers = [
        'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
        'Content-Type'  => 'application/json',
    ];

    // Make the API request.
    $response = wp_remote_get( $endpoint, [
        'headers' => $headers,
        'timeout' => 15,
    ] );

    // Check for errors.
    if ( is_wp_error( $response ) ) {
        return 'Error: ' . $response->get_error_message();
    }

    // Retrieve the response body and decode it.
    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );

    // Check for JSON decoding errors.
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return 'Error decoding JSON response: ' . json_last_error_msg();
    }

    return $data;
}

function ls_sync_major_unit( $cmf ) {
    // Retrieve API credentials from wp_options.
    $api_url = get_option( 'ls_api_url', '' );
    $username = get_option( 'ls_username', '' );
    $password = get_option( 'ls_password', '' );

    // Check if credentials are available.
    if ( empty( $api_url ) || empty( $username ) || empty( $password ) ) {
        return 'Missing API credentials. Please set ls_api_url, ls_username, and ls_password in the settings.';
    }

    // Prepare the API endpoint.
    $endpoint = rtrim( $api_url ) . "/Unit/".$cmf;

    // Prepare the headers for authentication.
    $headers = [
        'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
        'Content-Type'  => 'application/json',
    ];

    // Make the API request.
    $response = wp_remote_get( $endpoint, [
        'headers' => $headers,
        'timeout' => 15,
    ] );

    // Check for errors.
    if ( is_wp_error( $response ) ) {
        return 'Error: ' . $response->get_error_message();
    }

    // Retrieve the response body and decode it.
    $response_body = wp_remote_retrieve_body( $response );
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

    // Make the API request.
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