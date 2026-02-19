<?php
// Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function add_x_hour_cron_schedule( $schedules ) {
    $schedules['every_x_hour'] = [
        'interval' => 14400, // 3600 seconds = 1Hour
        'display'  => __( 'Every X Hour' ),
    ];
    return $schedules;
}
add_filter( 'cron_schedules', 'add_x_hour_cron_schedule' );

function add_hourly_cron_schedule( $schedules ) {
    $schedules['every_1_hour'] = [
        'interval' => 3600, // 3600 seconds = 1Hour
        'display'  => __( 'Every 1 Hour' ),
    ];
    return $schedules;
}
add_filter( 'cron_schedules', 'add_hourly_cron_schedule' );


// Render the settings page.
function ls_render_settings_page() {
    // Check if the user has permissions to access this page.
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle form submission for settings.
    if ( isset( $_POST['ls_save_settings'] ) ) {
        check_admin_referer( 'ls_save_settings_action', 'ls_save_settings_nonce' );

        // Update options in the database.
        update_option( 'ls_api_url', sanitize_text_field( $_POST['ls_api_url'] ) );
        update_option( 'ls_username', sanitize_text_field( $_POST['ls_username'] ) );
        update_option( 'ls_password', sanitize_text_field( $_POST['ls_password'] ) );
        update_option( 'ls_api_docs_url', esc_url_raw( $_POST['ls_api_docs_url'] ) );

        // Update cron enable/disable option.
        $cron_enabled = isset( $_POST['ls_cron_enabled'] ) ? 1 : 0;
        update_option( 'ls_cron_enabled', $cron_enabled );

        // API log retention (days). Logs older than this are deleted automatically.
        $retention_days = isset( $_POST['ls_log_retention_days'] ) ? max( 1, (int) $_POST['ls_log_retention_days'] ) : 30;
        update_option( 'ls_log_retention_days', $retention_days );

        // Handle cron scheduling based on the checkbox value.
        if ( $cron_enabled ) {
            if ( ! wp_next_scheduled( 'ls_auto_sync_cron' ) ) {
                wp_schedule_event( time(), 'every_x_hour', 'ls_auto_sync_cron' );
            }
            // if ( ! wp_next_scheduled( 'ls_update_motors_status_cron' ) ) {
            //     wp_schedule_event( time(), 'every_1_hour', 'ls_update_motors_status_cron' );
            // }
            if ( ! wp_next_scheduled( 'ls_auto_sync_images_cron' ) ) {
                wp_schedule_event( time(), 'every_1_hour', 'ls_auto_sync_images_cron' );
            }

            if ( ! wp_next_scheduled( 'ls_auto_sync_images_cron_2nd_batch' ) ) {
                wp_schedule_event( time(), 'every_x_hour', 'ls_auto_sync_images_cron_2nd_batch' );
            }

            if ( ! wp_next_scheduled( 'ls_auto_sync_images_cron_check_no_image' ) ) {
                wp_schedule_event( time(), 'every_1_hour', 'ls_auto_sync_images_cron_check_no_image' );
            }
            
        } else {
            $timestamp = wp_next_scheduled( 'ls_auto_sync_cron' );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, 'ls_auto_sync_cron' );
            }
            // $timestamp2 = wp_next_scheduled( 'ls_update_motors_status_cron' );
            // if ( $timestamp2 ) {
            //     wp_unschedule_event( $timestamp2, 'ls_update_motors_status_cron' );
            // }
            $timestamp3 = wp_next_scheduled( 'ls_auto_sync_images_cron' );
            if ( $timestamp3 ) {
                wp_unschedule_event( $timestamp3, 'ls_auto_sync_images_cron' );
            }
            $timestamp4 = wp_next_scheduled( 'ls_auto_sync_images_cron_2nd_batch' );
            if ( $timestamp4 ) {
                wp_unschedule_event( $timestamp4, 'ls_auto_sync_images_cron_2nd_batch' );
            }
            $timestamp5 = wp_next_scheduled( 'ls_auto_sync_images_cron_check_no_image' );
            if ( $timestamp5) {
                wp_unschedule_event( $timestamp5, 'ls_auto_sync_images_cron_check_no_image' );
            }
        }

        // Schedule daily API log cleanup if not already scheduled.
        if ( ! wp_next_scheduled( 'ls_cleanup_api_logs' ) ) {
            wp_schedule_event( time(), 'daily', 'ls_cleanup_api_logs' );
        }

        echo '<div class="updated"><p>Settings saved!</p></div>';
    }

    // Retrieve the saved values AFTER the update to ensure they are reflected in the form.
    $api_url = get_option( 'ls_api_url', '' );
    $username = get_option( 'ls_username', '' );
    $password = get_option( 'ls_password', '' );
    $api_docs_url = get_option( 'ls_api_docs_url', '' );
    $cron_enabled = get_option( 'ls_cron_enabled', 0 ); // Default is disabled.
    $log_retention_days = (int) get_option( 'ls_log_retention_days', 30 );
    if ( $log_retention_days < 1 ) {
        $log_retention_days = 30;
    }

    // Display the form.
    ?>
    <div class="wrap">
        <h1>API Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'ls_save_settings_action', 'ls_save_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="ls_api_url">API URL</label>
                    </th>
                    <td>
                        <input type="url" name="ls_api_url" id="ls_api_url" value="<?php echo esc_attr( $api_url ); ?>" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ls_username">Username</label>
                    </th>
                    <td>
                        <input type="text" name="ls_username" id="ls_username" value="<?php echo esc_attr( $username ); ?>" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ls_password">Password</label>
                    </th>
                    <td>
                        <input type="password" name="ls_password" id="ls_password" value="<?php echo esc_attr( $password ); ?>" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ls_api_docs_url">API Documentation URL</label>
                    </th>
                    <td>
                        <input type="url" name="ls_api_docs_url" id="ls_api_docs_url" value="<?php echo esc_attr( $api_docs_url ); ?>" class="large-text">
                        <p class="description">URL for the API Documentation submenu link (opens in redirect).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ls_cron_enabled">Enable Auto Sync Cron</label>
                    </th>
                    <td>
                        <input type="checkbox" name="ls_cron_enabled" id="ls_cron_enabled" value="1" <?php checked( $cron_enabled, 1 ); ?>>
                        <label for="ls_cron_enabled">Enable auto-sync every 1 day</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ls_log_retention_days">API log retention (days)</label>
                    </th>
                    <td>
                        <input type="number" name="ls_log_retention_days" id="ls_log_retention_days" value="<?php echo esc_attr( $log_retention_days ); ?>" min="1" max="365" class="small-text">
                        <p class="description">API call logs older than this many days are automatically deleted. Only API request/response logs are stored.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="ls_save_settings" id="submit" class="button button-primary" value="Save Changes">
            </p>
        </form>

        <!-- Cron Job Status Section -->
        <h2>Cron Job Status</h2>
        <p>
            <?php
            $timestamp = wp_next_scheduled( 'ls_auto_sync_cron' );
            if ( $timestamp ) {
                echo 'Sync Data - Next scheduled run: ' . date( 'Y-m-d H:i:s', $timestamp );
            } else {
                echo 'Cron job is not currently scheduled.';
            }
            ?>
        </p>
    </div>
    <?php
}

//Add a function that runs when the ls_auto_sync_cron event is triggered.
add_action( 'ls_auto_sync_cron', 'handle_ls_auto_sync_cron' );

function handle_ls_auto_sync_cron() {
    error_log('handle_ls_auto_sync_cron triggered at ' . date('Y-m-d H:i:s'));
    $run_id = date( 'Y-m-d_H-i-s' );
     ls_motors_log([
        'message' => "Cron job triggered"
    ], $run_id);

    // Get the list of CMFs with auto-sync enabled.
    $auto_sync_list = get_option( 'ls_auto_sync', [] );
    $auto_sync_list2 = get_option( 'ls_auto_sync_motors', [] );

//     if ( empty( $auto_sync_list ) ) {
//         // ls_log( 'No CMFs available for auto-sync.' );
//         return; // No CMFs to sync.
//     }

    // foreach ( $auto_sync_list as $cmf ) {
    //     // ls_log( "Processing CMF: {$cmf}" );
    //     // Fetch data for the current CMF.
    //     $parts = ls_sync_parts_inventory( $cmf );

    //     // Sync each part to WooCommerce.
    //     if ( is_array( $parts ) ) {
    //         $run_id = date( 'Y-m-d_H-i-s' );
    //         foreach ( $parts as $part ) {
    //             sync_part_to_woocommerce( $part, $run_id );
    //             // ls_log( "Synced product ID & part #: {$product_id} - {$part['PartNumber']} for CMF: {$cmf}" );
    //         }
    //     } else {
    //         // Log error if the data fetch failed.
    //         error_log( "Failed to fetch data for CMF: $cmf" );
    //     }
    // }

    if ( empty( $auto_sync_list2 ) ) {
        ls_motors_log([
            'message' => "No CMFs available for auto-sync."
        ], $run_id);
        return; // No CMFs to sync.
    }

    // #region agent log
    $log_path = dirname( plugin_dir_path( __FILE__ ) ) . '/.cursor/debug-63247a.log';
    @file_put_contents( $log_path, json_encode( [ 'sessionId' => '63247a', 'hypothesisId' => 'B', 'location' => 'settings.php:handle_ls_auto_sync_cron', 'message' => 'Auto sync cron started', 'data' => [ 'cmf_count' => count( $auto_sync_list2 ) ], 'timestamp' => (int) ( microtime( true ) * 1000 ) ] ) . "\n", FILE_APPEND | LOCK_EX );
    // #endregion
    foreach ( $auto_sync_list2 as $cmf ) {
        // ls_log( "Processing CMF: {$cmf}" );
        // Fetch data for the current CMF.
        $motors = ls_sync_major_unit( $cmf );

        // Sync each part to WooCommerce.
        if ( is_array( $motors ) ) {
            $run_id = date( 'Y-m-d_H-i-s' );
            
            ls_motors_log([
                'Count' => count($motors),
            ], $run_id);
            foreach ( $motors as $part ) {
                ls_motors_log([
                    'message' => "Synced unit - {$part['StockNumber']}"
                ], $run_id);
                sync_part_to_motors( $part, $run_id );
            }
        } else {
            
            ls_motors_log([
                'message' => "Failed"
            ], $run_id);
            error_log( "Failed to fetch data for CMF: $cmf" );
        }
    }

     if(function_exists("nitropack_sdk_purge")) {
          ls_motors_log([
        'message' => "Nitropack clear cache."
    ], $run_id);
        nitropack_sdk_purge( NULL, NULL, NULL);
    }

    ls_motors_log([
        'message' => "Cron job execution completed."
    ], $run_id);
    error_log( "Failed to fetch data for CMF: $cmf" );
   
}


//Add this logic to your plugin deactivation hook or a function.
function unschedule_auto_sync_cron() {
    $timestamp = wp_next_scheduled( 'ls_auto_sync_cron' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'ls_auto_sync_cron' );
    }
}
register_deactivation_hook( __FILE__, 'unschedule_auto_sync_cron' );

add_action( 'ls_update_motors_status_cron', 'handle_ls_update_sync_cron' );
function handle_ls_update_sync_cron() {
    $run_id = date( 'Y-m-d_H-i-s' );
     ls_motors_log([
        'message' => "Cron job triggered to update status of motors"
    ], $run_id);

    $listings = get_all_listings_posts_with_stocknumber();
    // #region agent log
    $log_path = dirname( plugin_dir_path( __FILE__ ) ) . '/.cursor/debug-63247a.log';
    @file_put_contents( $log_path, json_encode( [ 'sessionId' => '63247a', 'hypothesisId' => 'A', 'location' => 'settings.php:handle_ls_update_sync_cron', 'message' => 'Status cron started', 'data' => [ 'listing_count' => count( $listings ) ], 'timestamp' => (int) ( microtime( true ) * 1000 ) ] ) . "\n", FILE_APPEND | LOCK_EX );
    // #endregion
    foreach ($listings as $listing) {
        ls_motors_log([
            'ID' => $listing['ID'],
            'StockNumber' => $listing['stock_number'],
        ], $run_id);
        $unit = get_single_unit($listing['stock_number'], $run_id);
        if($unit['status_code'] === 500) {
            ls_motors_log([
                'message' => "Error fetching unit data for StockNumber: {$listing['stock_number']}"
            ], $run_id);
            continue; // Skip this listing if there's an error.
        } else {
            update_motors_status($listing['ID'], $unit['data'], $run_id);
        }
    }

     if(function_exists("nitropack_sdk_purge")) {
          ls_motors_log([
        'message' => "Nitropack clear cache."
    ], $run_id);
        nitropack_sdk_purge( NULL, NULL, NULL);
    }

    ls_motors_log([
        'message' => "Cron job execution to update status of motors completed."
    ], $run_id);
}

function get_all_listings_posts_with_stocknumber() {
    $args = [
        'post_type'      => 'listings',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids', // Only get post IDs for efficiency
    ];
    $query = new WP_Query($args);
    $results = [];
    foreach ($query->posts as $post_id) {
        $stock_number = get_post_meta($post_id, 'stock_number', true);
        $results[] = [
            'ID' => $post_id,
            'stock_number' => $stock_number,
        ];
    }
    return $results;
}

add_action( 'ls_auto_sync_images_cron', 'handle_ls_auto_sync_images' );

function handle_ls_auto_sync_images() {
    // Increase time limit and memory for image processing
    @set_time_limit(0); // Unlimited execution time
    @ini_set('memory_limit', '512M'); // Increase memory limit
    @ini_set('max_execution_time', '0'); // Also try this way
    
    $start_time = time();
    $max_execution_time = 1800; // Reduced to 30 minutes for safety
    $batch_size = 20; // Process 10 items per run to avoid server limits
    
    error_log('handle_ls_auto_sync_images triggered at ' . date('Y-m-d H:i:s'));
    $run_id = date( 'Y-m-d_H-i-s' );
    
    // Get the last processed index from WordPress options
    $last_processed_index = (int) get_option('ls_auto_sync_images_last_index', -1);
    
    // Register shutdown function to catch fatal errors and timeouts
    $shutdown_handler = function() use ($run_id, $start_time) {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            error_log("handle_ls_auto_sync_images: FATAL ERROR - " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
            ls_motors_log([
                'error' => 'FATAL ERROR - Script terminated unexpectedly',
                'error_type' => $error['type'],
                'error_message' => $error['message'],
                'error_file' => $error['file'],
                'error_line' => $error['line'],
                'elapsed_time' => (time() - $start_time) . 's'
            ], $run_id);
        }
    };
    register_shutdown_function($shutdown_handler);
    
    ls_motors_log([
        'message' => "Cron job triggered - Image upload (1st batch)",
        'start_time' => date('Y-m-d H:i:s', $start_time),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => $max_execution_time . ' seconds',
        'php_max_execution_time' => ini_get('max_execution_time'),
        'php_memory_limit' => ini_get('memory_limit'),
        'batch_size' => $batch_size,
        'last_processed_index' => $last_processed_index,
        'resuming_from' => $last_processed_index + 1
    ], $run_id);

    // Get the list of CMFs with auto-sync enabled.
    $auto_sync_list2 = get_option( 'ls_auto_sync_motors', [] );

    if ( empty( $auto_sync_list2 ) ) {
        ls_motors_log([
            'message' => "No CMFs available for auto-sync."
        ], $run_id);
        error_log('handle_ls_auto_sync_images: No CMFs available for auto-sync');
        return; // No CMFs to sync.
    }
    
    $total_processed = 0;
    $total_success = 0;
    $total_failed = 0;
    $total_skipped = 0;
    $items_in_current_batch = 0;
    
    foreach ( $auto_sync_list2 as $cmf ) {
        // Check execution time before processing each CMF
        if ((time() - $start_time) > $max_execution_time) {
            ls_motors_log([
                'message' => "Execution time limit approaching, stopping gracefully",
                'elapsed_time' => (time() - $start_time) . ' seconds',
                'cmf' => $cmf
            ], $run_id);
            error_log("handle_ls_auto_sync_images: Time limit reached at CMF: $cmf");
            break;
        }
        
        ls_motors_log([
            'message' => "Processing CMF: $cmf",
            'elapsed_time' => (time() - $start_time) . ' seconds'
        ], $run_id);
        
        // Fetch data for the current CMF.
        $motors = ls_sync_major_unit( $cmf );

        // Sync each part to WooCommerce.
        if ( is_array( $motors ) ) {
            $motors_count = count($motors);
            ls_motors_log([
                'message' => "Fetched motors for CMF: $cmf",
                'count' => $motors_count,
            ], $run_id);
            
            foreach ( $motors as $index => $part ) {
                // Skip items already processed in previous runs
                if ($index <= $last_processed_index) {
                    continue;
                }
                
                // Check batch size limit - process only N items per run
                if ($items_in_current_batch >= $batch_size) {
                    ls_motors_log([
                        'message' => "Batch size limit reached, stopping to resume next run",
                        'batch_size' => $batch_size,
                        'items_processed_this_run' => $items_in_current_batch,
                        'last_index_processed' => $index - 1,
                        'total_processed' => $total_processed
                    ], $run_id);
                    error_log("handle_ls_auto_sync_images: Batch limit reached, saving progress at index: " . ($index - 1));
                    break 2; // Break out of both loops
                }
                
                // CRITICAL: Log before processing to track where it stops
                $stock_number = $part['StockNumber'] ?? 'UNKNOWN';
                error_log("handle_ls_auto_sync_images: BEFORE processing StockNumber: {$stock_number} (index: $index, total_processed: $total_processed)");
                
                // Check execution time before processing each motor
                if ((time() - $start_time) > $max_execution_time) {
                    ls_motors_log([
                        'message' => "Execution time limit approaching, stopping gracefully",
                        'elapsed_time' => (time() - $start_time) . ' seconds',
                        'processed_in_current_cmf' => $index,
                        'total_processed' => $total_processed
                    ], $run_id);
                    error_log("handle_ls_auto_sync_images: Time limit reached at motor index: $index");
                    break 2; // Break out of both loops
                }
                
                try {
                    ls_motors_log([
                        'message' => "STARTING Processing unit - {$stock_number}",
                        'index' => $index + 1,
                        'total' => $motors_count,
                        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                        'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
                        'elapsed_time' => (time() - $start_time) . 's'
                    ], $run_id);
                    
                    error_log("handle_ls_auto_sync_images: Calling sync_motors_image for {$stock_number}");
                    $result = sync_motors_image( $part, $run_id );
                    error_log("handle_ls_auto_sync_images: AFTER sync_motors_image for {$stock_number}, result: " . ($result ? $result : 'null'));
                    
                    if ( !$result ) {
                        $total_failed++;
                        error_log("handle_ls_auto_sync_images: Failed to sync image for StockNumber: {$stock_number}");
                        ls_motors_log([
                            'error' => "Failed to sync image for StockNumber: {$stock_number}",
                            'post_id' => null
                        ], $run_id);
                    } else {
                        $total_success++;
                        error_log("handle_ls_auto_sync_images: Successfully synced StockNumber: {$stock_number}");
                        ls_motors_log([
                            'message' => "COMPLETED Successfully synced unit - {$stock_number}",
                            'post_id' => $result
                        ], $run_id);
                    }
                    
                    $total_processed++;
                    $items_in_current_batch++;
                    
                    // Save progress after each successful item
                    update_option('ls_auto_sync_images_last_index', $index);
                    
                    // Log after EVERY item for debugging
                    error_log("handle_ls_auto_sync_images: Completed item $total_processed - {$stock_number}");
                    ls_motors_log([
                        'message' => "Item completed",
                        'stock_number' => $stock_number,
                        'index' => $index,
                        'batch_progress' => $items_in_current_batch . '/' . $batch_size,
                        'total_processed' => $total_processed,
                        'success' => $total_success,
                        'failed' => $total_failed,
                        'elapsed_time' => (time() - $start_time) . ' seconds',
                        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
                    ], $run_id);
                    
                    // Clear memory after each item
                    if (function_exists('wp_cache_flush')) {
                        wp_cache_flush();
                    }
                    
                    // Force garbage collection
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                    
                } catch (Exception $e) {
                    $total_failed++;
                    error_log("handle_ls_auto_sync_images: Exception for StockNumber: {$stock_number} - " . $e->getMessage());
                    ls_motors_log([
                        'error' => "Exception processing StockNumber: {$stock_number}",
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine()
                    ], $run_id);
                } catch (Throwable $t) {
                    $total_failed++;
                    error_log("handle_ls_auto_sync_images: CRITICAL Throwable for StockNumber: {$stock_number} - " . $t->getMessage());
                    ls_motors_log([
                        'error' => "CRITICAL Throwable processing StockNumber: {$stock_number}",
                        'exception_message' => $t->getMessage(),
                        'exception_file' => $t->getFile(),
                        'exception_line' => $t->getLine()
                    ], $run_id);
                }
            }
        } else {
            ls_motors_log([
                'message' => "Failed to fetch motors for CMF: $cmf",
                'error' => is_string($motors) ? $motors : 'Unknown error'
            ], $run_id);
            error_log( "handle_ls_auto_sync_images: Failed to fetch data for CMF: $cmf" );
        }
    }
    
    if(function_exists("nitropack_sdk_purge")) {
        ls_motors_log([
            'message' => "Clearing Nitropack cache."
        ], $run_id);
        try {
            nitropack_sdk_purge( NULL, NULL, NULL);
            ls_motors_log(['message' => "Nitropack cache cleared successfully."], $run_id);
        } catch (Exception $e) {
            error_log("handle_ls_auto_sync_images: Failed to clear Nitropack cache - " . $e->getMessage());
            ls_motors_log(['error' => "Failed to clear Nitropack cache: " . $e->getMessage()], $run_id);
        }
    }

    $end_time = time();
    $total_time = $end_time - $start_time;
    
    // Check if we've completed the full cycle
    $current_last_index = (int) get_option('ls_auto_sync_images_last_index', -1);
    
    // If we processed fewer items than batch size, we've reached the end
    if ($items_in_current_batch < $batch_size && $items_in_current_batch > 0) {
        // Reset the index to start from beginning next time
        update_option('ls_auto_sync_images_last_index', -1);
        ls_motors_log([
            'message' => "Full cycle completed! Resetting index to start from beginning next run.",
            'items_in_last_batch' => $items_in_current_batch
        ], $run_id);
        error_log("handle_ls_auto_sync_images: Full cycle completed, reset index");
    } elseif ($items_in_current_batch == 0) {
        // No items processed means we've already completed all items
        update_option('ls_auto_sync_images_last_index', -1);
        ls_motors_log([
            'message' => "No items to process. Resetting index for next cycle."
        ], $run_id);
    }
    
    ls_motors_log([
        'message' => "Cron job execution completed (1st batch)",
        'summary' => [
            'total_processed' => $total_processed,
            'total_success' => $total_success,
            'total_failed' => $total_failed,
            'total_skipped' => $total_skipped,
            'items_in_batch' => $items_in_current_batch,
            'batch_size' => $batch_size,
            'last_index_saved' => $current_last_index,
            'will_resume_from' => $current_last_index + 1,
            'execution_time' => $total_time . ' seconds',
            'end_time' => date('Y-m-d H:i:s', $end_time),
            'peak_memory_usage' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
        ]
    ], $run_id);
    
    error_log("handle_ls_auto_sync_images completed: Processed=$total_processed, Success=$total_success, Failed=$total_failed, BatchItems=$items_in_current_batch, Time={$total_time}s");
}

add_action( 'ls_auto_sync_images_cron_2nd_batch', 'handle_ls_auto_sync_images_2nd_batch' );

function handle_ls_auto_sync_images_2nd_batch() {
    // Increase time limit and memory for image processing
    @set_time_limit(0); // Unlimited execution time
    @ini_set('memory_limit', '512M'); // Increase memory limit
    
    $start_time = time();
    $max_execution_time = 3300; // 55 minutes (leave 5 min buffer for hourly cron)
    
    error_log('handle_ls_auto_sync_images_2nd_batch triggered at ' . date('Y-m-d H:i:s'));
    $run_id = date( 'Y-m-d_H-i-s' );
    
    ls_motors_log([
        'message' => "Cron job triggered - Image upload (2nd batch - items > 40)",
        'start_time' => date('Y-m-d H:i:s', $start_time),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => $max_execution_time . ' seconds'
    ], $run_id);

    // Get the list of CMFs with auto-sync enabled.
    $auto_sync_list2 = get_option( 'ls_auto_sync_motors', [] );

    if ( empty( $auto_sync_list2 ) ) {
        ls_motors_log([
            'message' => "No CMFs available for auto-sync."
        ], $run_id);
        error_log('handle_ls_auto_sync_images_2nd_batch: No CMFs available for auto-sync');
        return; // No CMFs to sync.
    }
    
    $total_processed = 0;
    $total_success = 0;
    $total_failed = 0;
    $total_skipped = 0;
    
    foreach ( $auto_sync_list2 as $cmf ) {
        // Check execution time before processing each CMF
        if ((time() - $start_time) > $max_execution_time) {
            ls_motors_log([
                'message' => "Execution time limit approaching, stopping gracefully",
                'elapsed_time' => (time() - $start_time) . ' seconds',
                'cmf' => $cmf
            ], $run_id);
            error_log("handle_ls_auto_sync_images_2nd_batch: Time limit reached at CMF: $cmf");
            break;
        }
        
        ls_motors_log([
            'message' => "Processing CMF: $cmf",
            'elapsed_time' => (time() - $start_time) . ' seconds'
        ], $run_id);
        
        // Fetch data for the current CMF.
        $motors = ls_sync_major_unit( $cmf );

        // Sync each part to WooCommerce.
        if ( is_array( $motors ) ) {
            $motors_count = count($motors);
            ls_motors_log([
                'message' => "Fetched motors for CMF: $cmf",
                'total_count' => $motors_count,
                'processing_from_index' => 41
            ], $run_id);
            
            foreach ( $motors as $index => $part ) {
                $item_number = $index + 1;
                
                // Skip first 40 items (processed in 1st batch)
                if ($item_number <= 40) {
                    $total_skipped++;
                    continue;
                }
                
                // Check execution time before processing each motor
                if ((time() - $start_time) > $max_execution_time) {
                    ls_motors_log([
                        'message' => "Execution time limit approaching, stopping gracefully",
                        'elapsed_time' => (time() - $start_time) . ' seconds',
                        'processed_in_current_cmf' => $total_processed - $total_skipped,
                        'total_processed' => $total_processed
                    ], $run_id);
                    error_log("handle_ls_auto_sync_images_2nd_batch: Time limit reached at motor index: $index");
                    break 2; // Break out of both loops
                }
                
                try {
                    $stock_number = $part['StockNumber'] ?? 'UNKNOWN';
                    
                    ls_motors_log([
                        'message' => "Processing unit - {$stock_number}",
                        'item_number' => $item_number,
                        'total' => $motors_count,
                        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
                    ], $run_id);
                    
                    $result = sync_motors_image( $part, $run_id );
                    
                    if ( !$result ) {
                        $total_failed++;
                        error_log("handle_ls_auto_sync_images_2nd_batch: Failed to sync image for StockNumber: {$stock_number}");
                        ls_motors_log([
                            'error' => "Failed to sync image for StockNumber: {$stock_number}",
                            'post_id' => null
                        ], $run_id);
                    } else {
                        $total_success++;
                        ls_motors_log([
                            'message' => "Successfully synced unit - {$stock_number}",
                            'post_id' => $result
                        ], $run_id);
                    }
                    
                    $total_processed++;
                    
                    // Periodic status update every 10 items
                    if ((($total_processed - $total_skipped) % 10) == 0) {
                        ls_motors_log([
                            'message' => "Progress update",
                            'total_processed' => $total_processed - $total_skipped,
                            'success' => $total_success,
                            'failed' => $total_failed,
                            'skipped' => $total_skipped,
                            'elapsed_time' => (time() - $start_time) . ' seconds',
                            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
                        ], $run_id);
                    }
                    
                    // Clear memory periodically
                    if ((($total_processed - $total_skipped) % 20) == 0) {
                        if (function_exists('wp_cache_flush')) {
                            wp_cache_flush();
                        }
                    }
                    
                } catch (Exception $e) {
                    $total_failed++;
                    error_log("handle_ls_auto_sync_images_2nd_batch: Exception for StockNumber: {$stock_number} - " . $e->getMessage());
                    ls_motors_log([
                        'error' => "Exception processing StockNumber: {$stock_number}",
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine()
                    ], $run_id);
                }
            }
        } else {
            ls_motors_log([
                'message' => "Failed to fetch motors for CMF: $cmf",
                'error' => is_string($motors) ? $motors : 'Unknown error'
            ], $run_id);
            error_log( "handle_ls_auto_sync_images_2nd_batch: Failed to fetch data for CMF: $cmf" );
        }
    }
    
    if(function_exists("nitropack_sdk_purge")) {
        ls_motors_log([
            'message' => "Clearing Nitropack cache."
        ], $run_id);
        try {
            nitropack_sdk_purge( NULL, NULL, NULL);
            ls_motors_log(['message' => "Nitropack cache cleared successfully."], $run_id);
        } catch (Exception $e) {
            error_log("handle_ls_auto_sync_images_2nd_batch: Failed to clear Nitropack cache - " . $e->getMessage());
            ls_motors_log(['error' => "Failed to clear Nitropack cache: " . $e->getMessage()], $run_id);
        }
    }

    $end_time = time();
    $total_time = $end_time - $start_time;
    
    ls_motors_log([
        'message' => "Cron job execution completed (2nd batch)",
        'summary' => [
            'total_processed' => $total_processed - $total_skipped,
            'total_success' => $total_success,
            'total_failed' => $total_failed,
            'total_skipped' => $total_skipped,
            'execution_time' => $total_time . ' seconds',
            'end_time' => date('Y-m-d H:i:s', $end_time),
            'peak_memory_usage' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
        ]
    ], $run_id);
    
    error_log("handle_ls_auto_sync_images_2nd_batch completed: Processed=" . ($total_processed - $total_skipped) . ", Success=$total_success, Failed=$total_failed, Skipped=$total_skipped, Time={$total_time}s");
}

add_action( 'ls_auto_sync_images_cron_check_no_image', 'handle_ls_auto_sync_images_check_no_image' );

function handle_ls_auto_sync_images_check_no_image() {
    // Increase time limit and memory for image processing
    @set_time_limit(0); // Unlimited execution time
    @ini_set('memory_limit', '512M'); // Increase memory limit
    
    $start_time = time();
    $max_execution_time = 3300; // 55 minutes (leave 5 min buffer for hourly cron)
    
    error_log('handle_ls_auto_sync_images_check_no_image triggered at ' . date('Y-m-d H:i:s'));
    $run_id = date( 'Y-m-d_H-i-s' );
    
    ls_motors_log([
        'message' => "Cron job triggered - Image upload (check no image)",
        'start_time' => date('Y-m-d H:i:s', $start_time),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => $max_execution_time . ' seconds'
    ], $run_id);

    // Get the list of CMFs with auto-sync enabled.
    $auto_sync_list2 = get_option( 'ls_auto_sync_motors', [] );

    if ( empty( $auto_sync_list2 ) ) {
        ls_motors_log([
            'message' => "No CMFs available for auto-sync."
        ], $run_id);
        error_log('handle_ls_auto_sync_images_check_no_image: No CMFs available for auto-sync');
        return; // No CMFs to sync.
    }
    
    $total_processed = 0;
    $total_success = 0;
    $total_failed = 0;
    $total_skipped = 0;
    
    foreach ( $auto_sync_list2 as $cmf ) {
        // Check execution time before processing each CMF
        if ((time() - $start_time) > $max_execution_time) {
            ls_motors_log([
                'message' => "Execution time limit approaching, stopping gracefully",
                'elapsed_time' => (time() - $start_time) . ' seconds',
                'cmf' => $cmf
            ], $run_id);
            error_log("handle_ls_auto_sync_images_check_no_image: Time limit reached at CMF: $cmf");
            break;
        }
        
        ls_motors_log([
            'message' => "Processing CMF: $cmf",
            'elapsed_time' => (time() - $start_time) . ' seconds'
        ], $run_id);
        
        // Fetch data for the current CMF.
        $motors = ls_sync_major_unit( $cmf );

        // Sync each part to WooCommerce.
        if ( is_array( $motors ) ) {
            $motors_count = count($motors);
            ls_motors_log([
                'message' => "Fetched motors for CMF: $cmf (checking for missing images)",
                'count' => $motors_count,
            ], $run_id);
            
            foreach ( $motors as $index => $part ) {
                // Check execution time before processing each motor
                if ((time() - $start_time) > $max_execution_time) {
                    ls_motors_log([
                        'message' => "Execution time limit approaching, stopping gracefully",
                        'elapsed_time' => (time() - $start_time) . ' seconds',
                        'processed_in_current_cmf' => $index,
                        'total_processed' => $total_processed
                    ], $run_id);
                    error_log("handle_ls_auto_sync_images_check_no_image: Time limit reached at motor index: $index");
                    break 2; // Break out of both loops
                }
                
                try {
                    $stock_number = $part['StockNumber'] ?? 'UNKNOWN';
                    
                    ls_motors_log([
                        'message' => "Checking unit for missing images - {$stock_number}",
                        'index' => $index + 1,
                        'total' => $motors_count,
                        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
                    ], $run_id);
                    
                    $result = sync_motors_no_image( $part, $run_id );
                    
                    if ( !$result ) {
                        $total_failed++;
                        error_log("handle_ls_auto_sync_images_check_no_image: Failed to sync image for StockNumber: {$stock_number}");
                        ls_motors_log([
                            'error' => "Failed to sync image for StockNumber: {$stock_number}",
                            'post_id' => null
                        ], $run_id);
                    } else {
                        $total_success++;
                        ls_motors_log([
                            'message' => "Successfully processed unit - {$stock_number}",
                            'post_id' => $result
                        ], $run_id);
                    }
                    
                    $total_processed++;
                    
                    // Periodic status update every 10 items
                    if (($total_processed % 10) == 0) {
                        ls_motors_log([
                            'message' => "Progress update",
                            'total_processed' => $total_processed,
                            'success' => $total_success,
                            'failed' => $total_failed,
                            'elapsed_time' => (time() - $start_time) . ' seconds',
                            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
                        ], $run_id);
                    }
                    
                    // Clear memory periodically
                    if (($total_processed % 20) == 0) {
                        if (function_exists('wp_cache_flush')) {
                            wp_cache_flush();
                        }
                    }
                    
                } catch (Exception $e) {
                    $total_failed++;
                    error_log("handle_ls_auto_sync_images_check_no_image: Exception for StockNumber: {$stock_number} - " . $e->getMessage());
                    ls_motors_log([
                        'error' => "Exception processing StockNumber: {$stock_number}",
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine()
                    ], $run_id);
                }
            }
        } else {
            ls_motors_log([
                'message' => "Failed to fetch motors for CMF: $cmf",
                'error' => is_string($motors) ? $motors : 'Unknown error'
            ], $run_id);
            error_log( "handle_ls_auto_sync_images_check_no_image: Failed to fetch data for CMF: $cmf" );
        }
    }
    
    if(function_exists("nitropack_sdk_purge")) {
        ls_motors_log([
            'message' => "Clearing Nitropack cache."
        ], $run_id);
        try {
            nitropack_sdk_purge( NULL, NULL, NULL);
            ls_motors_log(['message' => "Nitropack cache cleared successfully."], $run_id);
        } catch (Exception $e) {
            error_log("handle_ls_auto_sync_images_check_no_image: Failed to clear Nitropack cache - " . $e->getMessage());
            ls_motors_log(['error' => "Failed to clear Nitropack cache: " . $e->getMessage()], $run_id);
        }
    }

    $end_time = time();
    $total_time = $end_time - $start_time;
    
    ls_motors_log([
        'message' => "Cron job execution completed (check no image)",
        'summary' => [
            'total_processed' => $total_processed,
            'total_success' => $total_success,
            'total_failed' => $total_failed,
            'total_skipped' => $total_skipped,
            'execution_time' => $total_time . ' seconds',
            'end_time' => date('Y-m-d H:i:s', $end_time),
            'peak_memory_usage' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
        ]
    ], $run_id);
    
    error_log("handle_ls_auto_sync_images_check_no_image completed: Processed=$total_processed, Success=$total_success, Failed=$total_failed, Time={$total_time}s");
}