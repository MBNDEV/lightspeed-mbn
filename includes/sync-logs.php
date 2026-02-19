<?php
// Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function ls_display_log_files() {
    $log_dir = plugin_dir_path( __FILE__ ) . 'logs/';
    
    // Check if logs directory exists.
    if ( ! file_exists( $log_dir ) || ! is_dir( $log_dir ) ) {
        echo '<p>No log files found.</p>';
        return;
    }

    // Get all log files.
    $log_files = glob( $log_dir . 'ls_log_*.json' );

    if ( empty( $log_files ) ) {
        echo '<p>No log files available.</p>';
        return;
    }

    // If a specific log file is selected, display its contents.
    if ( isset( $_GET['log_file'] ) ) {
        $selected_file = sanitize_text_field( $_GET['log_file'] );
        $log_file_path = $log_dir . $selected_file;

        if ( file_exists( $log_file_path ) ) {
            $log_data = json_decode( file_get_contents( $log_file_path ), true );
            if ( ! empty( $log_data ) ) {
                echo '<h2>Viewing Log File: ' . esc_html( $selected_file ) . '</h2>';
                ls_display_log_table( $log_data );
                echo '<a href="' . admin_url( 'admin.php?page=ls-sync-logs' ) . '" class="button">Back to Logs List</a>';
                return;
            } else {
                echo '<p>No data found in the log file.</p>';
            }
        } else {
            echo '<p>Invalid log file selected.</p>';
        }
    }

    // List all log files as links.
    echo '<h2>Available Log Files</h2>';
    echo '<ul>';
    foreach ( $log_files as $file ) {
        $file_name = basename( $file );
        $view_url = admin_url( 'admin.php?page=ls-sync-logs&log_file=' . $file_name );
        echo '<li><a href="' . esc_url( $view_url ) . '">' . esc_html( $file_name ) . '</a></li>';
    }
    echo '</ul>';
}

function ls_display_log_table( $log_data ) {
    ?>
    <table class="widefat fixed" cellspacing="0">
        <thead>
            <tr>
                <th>Action</th>
                <th>Product ID</th>
                <th>SKU</th>
                <th>Name</th>
                <th>Regular Price</th>
                <th>Sale Price</th>
                <th>Stock</th>
                <th>Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $log_data as $entry ) : ?>
                <tr>
                    <td><?php echo esc_html( $entry['action'] ); ?></td>
                    <td><?php echo esc_html( $entry['product_id'] ); ?></td>
                    <td><?php echo esc_html( $entry['sku'] ); ?></td>
                    <td><?php echo esc_html( $entry['name'] ); ?></td>
                    <td><?php echo esc_html( $entry['regular_price'] ); ?></td>
                    <td><?php echo esc_html( $entry['sale_price'] ); ?></td>
                    <td><?php echo esc_html( $entry['stock'] ); ?></td>
                    <td><?php echo esc_html( $entry['timestamp'] ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function ls_log( $sync_data, $run_id ) {
    // Get the current date for the log file name.
    $current_date = date( 'Y-m-d' );
    $log_file = plugin_dir_path( __FILE__ ) . "logs/ls_log_{$run_id}.json";

    // Ensure the logs directory exists.
    if ( ! file_exists( dirname( $log_file ) ) ) {
        mkdir( dirname( $log_file ), 0755, true );
    }

    // Prepare the data to append to the log file.
    $existing_data = [];
    if ( file_exists( $log_file ) ) {
        $existing_data = json_decode( file_get_contents( $log_file ), true ) ?: [];
    }

    // Append new sync data.
    $existing_data[] = array_merge( $sync_data, [ 'timestamp' => date( 'Y-m-d H:i:s' ) ] );

    // Save the updated log data back to the file.
    file_put_contents( $log_file, json_encode( $existing_data, JSON_PRETTY_PRINT ) );
}

function ls_cleanup_old_logs( $days_to_keep = 30 ) {
    $log_dir = plugin_dir_path( __FILE__ ) . 'logs/';
    if ( ! is_dir( $log_dir ) ) {
        return;
    }

    $files = glob( $log_dir . 'ls_log_*.txt' );
    $expiry_time = time() - ( $days_to_keep * DAY_IN_SECONDS );

    foreach ( $files as $file ) {
        if ( filemtime( $file ) < $expiry_time ) {
            unlink( $file ); // Delete the file if it's older than the retention period.
        }
    }

    $log_dir = plugin_dir_path( __FILE__ ) . 'm_sync_logs/';
    if ( ! is_dir( $log_dir ) ) {
        return;
    }

    $files = glob( $log_dir . 'ls_sync_log_*.txt' );
    $expiry_time = time() - ( $days_to_keep * DAY_IN_SECONDS );

    foreach ( $files as $file ) {
        if ( filemtime( $file ) < $expiry_time ) {
            unlink( $file ); // Delete the file if it's older than the retention period.
        }
    }
}
add_action( 'wp_scheduled_cleanup_logs', 'ls_cleanup_old_logs' );

function ls_sync_log( $message ) {
    // Get the current date for the log file name.
    $current_date = date( 'Y-m-d' );
    $log_file = plugin_dir_path( __FILE__ ) . "m_sync_logs/ls_sync_log_{$current_date}.txt";

    // Ensure the logs directory exists.
    if ( ! file_exists( dirname( $log_file ) ) ) {
        mkdir( dirname( $log_file ), 0755, true );
    }

    // Prepare the log message with a timestamp.
    $timestamp = date( 'Y-m-d H:i:s' );
    $log_message = "[{$timestamp}] {$message}" . PHP_EOL;

    // Write the log message to the daily log file.
    file_put_contents( $log_file, $log_message, FILE_APPEND );
}

function ls_motors_log( $sync_data, $run_id ) {
    // Get the current date for the log file name.
    $current_date = date( 'Y-m-d' );
    $log_file = plugin_dir_path( __FILE__ ) . "logs/ls_motors_log_{$run_id}.json";

    // Ensure the logs directory exists.
    if ( ! file_exists( dirname( $log_file ) ) ) {
        mkdir( dirname( $log_file ), 0755, true );
    }

    // Prepare the data to append to the log file.
    $existing_data = [];
    if ( file_exists( $log_file ) ) {
        $existing_data = json_decode( file_get_contents( $log_file ), true ) ?: [];
    }

    // Append new sync data.
    $existing_data[] = array_merge( $sync_data, [ 'timestamp' => date( 'Y-m-d H:i:s' ) ] );

    // Save the updated log data back to the file.
    file_put_contents( $log_file, json_encode( $existing_data, JSON_PRETTY_PRINT ) );
}
