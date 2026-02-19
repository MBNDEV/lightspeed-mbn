<?php
// Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the API logs database table name (with prefix).
 *
 * @return string
 */
function ls_api_logs_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'ls_api_logs';
}

/**
 * Detect which schema the API logs table uses.
 * Returns 'legacy' for tables with log_date, cmf, params, method, http_code, response_summary.
 * Returns 'default' for tables with created_at, stock_number, status_code, response_body, headers.
 *
 * @return string 'legacy'|'default'
 */
function ls_api_logs_table_schema() {
    global $wpdb;
    $table = ls_api_logs_table_name();
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
        return 'default';
    }
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM $table", 0 );
    return in_array( 'log_date', $cols, true ) ? 'legacy' : 'default';
}

/**
 * Create the API logs table if it does not exist.
 */
function ls_create_api_logs_table() {
    global $wpdb;
    $table = ls_api_logs_table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id varchar(32) NOT NULL DEFAULT '',
        stock_number varchar(64) NOT NULL DEFAULT '',
        endpoint varchar(2048) NOT NULL DEFAULT '',
        response_body longtext,
        headers longtext,
        status_code int(11) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        KEY run_id (run_id),
        KEY created_at (created_at),
        KEY status_code (status_code)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Log only API call-related data to the database.
 * Only writes when the payload contains 'endpoint' (API request/response).
 * All other ls_motors_log() calls are no-ops.
 *
 * Table: {wpdb->prefix}ls_api_logs (e.g. tzt_ls_api_logs).
 *
 * @param array  $sync_data Data containing endpoint, status_code, response_body, headers, StockNumber.
 * @param string $run_id    Run identifier (e.g. date-based).
 */
function ls_motors_log( $sync_data, $run_id ) {
    if ( ! isset( $sync_data['endpoint'] ) ) {
        return;
    }

    global $wpdb;
    $table = ls_api_logs_table_name();

    // Ensure table exists (e.g. first run after upgrade).
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
        ls_create_api_logs_table();
    }

    // If table has legacy schema (old columns), recreate with current schema so insert succeeds.
    if ( ls_api_logs_table_schema() === 'legacy' ) {
        $wpdb->query( "DROP TABLE IF EXISTS `$table`" );
        ls_create_api_logs_table();
    }

    $run_id       = is_scalar( $run_id ) ? (string) $run_id : '';
    $stock_number = isset( $sync_data['StockNumber'] ) ? sanitize_text_field( (string) $sync_data['StockNumber'] ) : '';
    $endpoint     = isset( $sync_data['endpoint'] ) ? sanitize_text_field( $sync_data['endpoint'] ) : '';
    $response_body = isset( $sync_data['response_body'] ) ? wp_unslash( $sync_data['response_body'] ) : '';
    $headers      = isset( $sync_data['headers'] ) ? wp_json_encode( is_array( $sync_data['headers'] ) ? $sync_data['headers'] : (array) $sync_data['headers'] ) : '';
    $status_code  = isset( $sync_data['status_code'] ) ? (int) $sync_data['status_code'] : 0;
    $timestamp    = date( 'Y-m-d H:i:s' );

    $result = $wpdb->insert(
        $table,
        [
            'run_id'        => $run_id,
            'stock_number'  => $stock_number,
            'endpoint'      => $endpoint,
            'response_body' => $response_body,
            'headers'       => $headers,
            'status_code'   => $status_code,
            'created_at'    => $timestamp,
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
    );
    if ( $result === false ) {
        error_log( 'ls_motors_log insert failed. Table: ' . $table . ', wpdb->last_error: ' . $wpdb->last_error );
    }
}

/**
 * No-op: file-based sync log removed; only API logs are stored in DB.
 *
 * @param mixed $sync_data Unused.
 * @param mixed $run_id    Unused.
 */
function ls_log( $sync_data, $run_id ) {
    // Logging moved to database; only API calls are logged. No-op for sync data.
}

/**
 * No-op: file-based sync message log removed.
 *
 * @param string $message Unused.
 */
function ls_sync_log( $message ) {
    // File-based sync logs removed. No-op.
}

/**
 * Delete API log rows older than the configured retention period.
 * Uses option ls_log_retention_days; runs on cron and can be called directly.
 *
 * @param int|null $days_to_keep Optional. Override retention days; otherwise uses option.
 */
function ls_cleanup_old_logs( $days_to_keep = null ) {
    if ( $days_to_keep === null ) {
        $days_to_keep = (int) get_option( 'ls_log_retention_days', 30 );
    }
    if ( $days_to_keep <= 0 ) {
        return;
    }

    global $wpdb;
    $table  = ls_api_logs_table_name();
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
        return;
    }

    $date_col = ( ls_api_logs_table_schema() === 'legacy' ) ? 'log_date' : 'created_at';
    $cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $days_to_keep * DAY_IN_SECONDS ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE $date_col < %s", $cutoff ) );
}
add_action( 'ls_cleanup_api_logs', 'ls_cleanup_old_logs' );

/**
 * Handle clear-all-logs action (must be called before any output on Sync Logs page).
 */
function ls_handle_clear_api_logs() {
    if ( ! isset( $_POST['ls_clear_api_logs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ls_clear_api_logs_nonce'] ) ), 'ls_clear_api_logs' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    global $wpdb;
    $table = ls_api_logs_table_name();
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table ) {
        $wpdb->query( "DELETE FROM $table" );
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All API logs have been cleared.', 'lightspeed-mbn' ) . '</p></div>';
        } );
    }
}

/**
 * Build query string for Sync Logs page (filters, paged).
 *
 * @param array $args Keys: run_id, date_from, date_to, paged.
 * @return string
 */
function ls_sync_logs_query_string( $args = [] ) {
    $q = [];
    if ( ! empty( $args['run_id'] ) ) {
        $q['run_id'] = $args['run_id'];
    }
    if ( ! empty( $args['date_from'] ) ) {
        $q['date_from'] = $args['date_from'];
    }
    if ( ! empty( $args['date_to'] ) ) {
        $q['date_to'] = $args['date_to'];
    }
    if ( isset( $args['per_page'] ) && $args['per_page'] !== '' ) {
        $q['per_page'] = $args['per_page'];
    }
    if ( ! empty( $args['paged'] ) ) {
        $q['paged'] = $args['paged'];
    }
    return $q ? '&' . build_query( $q ) : '';
}

/**
 * Display API logs from the database (Sync Logs admin page).
 */
function ls_display_log_files() {
    ls_handle_clear_api_logs();

    global $wpdb;
    $table = ls_api_logs_table_name();

    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
        ls_create_api_logs_table();
    }

    $per_page_options = [ 50, 100, 250, 500 ];
    $per_page_request = isset( $_GET['per_page'] ) ? sanitize_text_field( $_GET['per_page'] ) : '50';
    if ( $per_page_request === 'all' || $per_page_request === '-1' ) {
        $per_page = -1;
    } else {
        $per_page = in_array( (int) $per_page_request, $per_page_options, true ) ? (int) $per_page_request : 50;
    }

    $page       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $offset     = $per_page > 0 ? ( $page - 1 ) * $per_page : 0;
    $run_id     = isset( $_GET['run_id'] ) ? sanitize_text_field( $_GET['run_id'] ) : '';
    $date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
    $date_to    = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';
    $log_id     = isset( $_GET['log_id'] ) ? (int) $_GET['log_id'] : 0;

    $base_args = array_filter( [
        'run_id'    => $run_id,
        'date_from' => $date_from,
        'date_to'   => $date_to,
        'per_page'  => $per_page > 0 ? (string) $per_page : 'all',
    ], static function ( $v ) { return $v !== '' && $v !== null; } );

    $schema = ls_api_logs_table_schema();
    $date_col = ( $schema === 'legacy' ) ? 'log_date' : 'created_at';

    // Single log entry view.
    if ( $log_id > 0 ) {
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $log_id ), ARRAY_A );
        if ( $row ) {
            $back_url = admin_url( 'admin.php?page=ls-sync-logs' . ls_sync_logs_query_string( array_merge( $base_args, [ 'paged' => $page ] ) ) );
            echo '<h2>API Log Entry</h2>';
            if ( $schema === 'legacy' ) {
                echo '<p><strong>Run ID:</strong> ' . esc_html( $row['run_id'] ?? '' ) . ' &nbsp; <strong>CMF:</strong> ' . esc_html( $row['cmf'] ?? '' ) . ' &nbsp; <strong>HTTP code:</strong> ' . esc_html( $row['http_code'] ?? '' ) . ' &nbsp; <strong>Time:</strong> ' . esc_html( $row['log_date'] ?? '' ) . '</p>';
                echo '<p><strong>Endpoint:</strong><br><code style="word-break:break-all;">' . esc_html( $row['endpoint'] ?? '' ) . '</code></p>';
                if ( ! empty( $row['params'] ) ) {
                    echo '<p><strong>Params:</strong> ' . esc_html( $row['params'] ) . '</p>';
                }
                echo '<p><strong>Method:</strong> ' . esc_html( $row['method'] ?? '' ) . '</p>';
                echo '<p><strong>Response summary:</strong> ' . esc_html( $row['response_summary'] ?? '' ) . '</p>';
            } else {
                echo '<p><strong>Run ID:</strong> ' . esc_html( $row['run_id'] ?? '' ) . ' &nbsp; <strong>Stock #:</strong> ' . esc_html( $row['stock_number'] ?? '' ) . ' &nbsp; <strong>Status:</strong> ' . esc_html( $row['status_code'] ?? '' ) . ' &nbsp; <strong>Time:</strong> ' . esc_html( $row['created_at'] ?? '' ) . '</p>';
                echo '<p><strong>Endpoint:</strong><br><code style="word-break:break-all;">' . esc_html( $row['endpoint'] ?? '' ) . '</code></p>';
                if ( ! empty( $row['headers'] ) ) {
                    $headers = json_decode( $row['headers'], true );
                    echo '<p><strong>Headers:</strong></p><pre style="background:#f5f5f5;padding:10px;overflow:auto;">' . esc_html( is_array( $headers ) ? wp_json_encode( $headers, JSON_PRETTY_PRINT ) : $row['headers'] ) . '</pre>';
                }
                echo '<p><strong>Response body:</strong></p><pre style="background:#f5f5f5;padding:10px;overflow:auto;max-height:400px;">' . esc_html( $row['response_body'] ?? '' ) . '</pre>';
            }
            echo '<a href="' . esc_url( $back_url ) . '" class="button">Back to API Logs</a>';
            return;
        }
    }

    $where  = '1=1';
    $params = [];
    if ( $run_id !== '' ) {
        $where   .= ' AND run_id = %s';
        $params[] = $run_id;
    }
    if ( $date_from !== '' ) {
        $where   .= ' AND DATE(' . $date_col . ') >= %s';
        $params[] = $date_from;
    }
    if ( $date_to !== '' ) {
        $where   .= ' AND DATE(' . $date_col . ') <= %s';
        $params[] = $date_to;
    }

    $count_sql   = "SELECT COUNT(*) FROM $table WHERE $where";
    $total       = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
    $total_in_db = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

    if ( $schema === 'legacy' ) {
        $select_cols = '*';
        $order_by    = 'log_date DESC';
    } else {
        $select_cols = 'id, run_id, stock_number, endpoint, status_code, created_at';
        $order_by    = 'created_at DESC';
    }

    if ( $per_page < 1 ) {
        $rows = $wpdb->get_results(
            $params ? $wpdb->prepare( "SELECT $select_cols FROM $table WHERE $where ORDER BY $order_by", $params ) : "SELECT $select_cols FROM $table WHERE $where ORDER BY $order_by",
            ARRAY_A
        );
    } else {
        $limit_params = array_merge( $params, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT $select_cols FROM $table WHERE $where ORDER BY $order_by LIMIT %d OFFSET %d", ...$limit_params ), ARRAY_A );
    }

    $used_fallback = false;
    if ( empty( $rows ) && $total_in_db > 0 ) {
        $limit = ( $per_page < 1 ) ? 999999 : $per_page;
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
        if ( ! empty( $rows ) ) {
            $total         = $total_in_db;
            $used_fallback = true;
        }
    }

    $base_url = admin_url( 'admin.php?page=ls-sync-logs' );

    echo '<h2>API Logs</h2>';
    echo '<p class="description" style="margin-bottom:14px;"><strong>Table:</strong> <code>' . esc_html( $table ) . '</code> &nbsp;|&nbsp; <strong>Total rows in DB:</strong> ' . (int) $total_in_db . ( $total !== $total_in_db ? ' &nbsp;|&nbsp; <strong>Matching filters:</strong> ' . (int) $total : '' ) . '</p>';

    // Filters, per-page, and Clear all.
    echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="ls-log-filters" style="margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="ls-sync-logs">';
    echo '<label for="ls_date_from">From date</label> <input type="date" name="date_from" id="ls_date_from" value="' . esc_attr( $date_from ) . '"> ';
    echo '<label for="ls_date_to">To date</label> <input type="date" name="date_to" id="ls_date_to" value="' . esc_attr( $date_to ) . '"> ';
    if ( $run_id !== '' ) {
        echo '<input type="hidden" name="run_id" value="' . esc_attr( $run_id ) . '">';
    }
    echo ' <label for="ls_per_page">Show</label> <select name="per_page" id="ls_per_page">';
    foreach ( $per_page_options as $n ) {
        echo '<option value="' . (int) $n . '" ' . selected( $per_page, $n, false ) . '>' . (int) $n . '</option>';
    }
    echo '<option value="all"' . selected( $per_page, -1, false ) . '>All</option>';
    echo '</select> per page ';
    echo ' <input type="submit" class="button" value="Apply"> ';
    echo '<a href="' . esc_url( $base_url ) . '" class="button">Reset</a>';
    echo '</form>';

    // Only show Clear all when there are rows (in the table, respecting filters).
    if ( $total > 0 ) {
        echo '<form method="post" action="' . esc_url( $base_url . ls_sync_logs_query_string( $base_args ) ) . '" style="margin-bottom:16px;" onsubmit="return confirm(\'' . esc_js( __( 'Permanently delete all API logs? This cannot be undone.', 'lightspeed-mbn' ) ) . '\');">';
        wp_nonce_field( 'ls_clear_api_logs', 'ls_clear_api_logs_nonce' );
        echo '<input type="submit" class="button button-secondary" name="ls_clear_api_logs_submit" value="' . esc_attr__( 'Clear all logs', 'lightspeed-mbn' ) . '">';
        echo '</form>';
    }

    if ( $run_id !== '' ) {
        echo '<p><a href="' . esc_url( $base_url . ( $date_from || $date_to ? '&' . build_query( array_filter( [ 'date_from' => $date_from, 'date_to' => $date_to ] ) ) : '' ) ) . '" class="button">All runs</a> <strong>Run:</strong> ' . esc_html( $run_id ) . '</p>';
    }

    if ( empty( $rows ) ) {
        echo '<div class="notice notice-info inline" style="margin:10px 0;"><p><strong>No API log rows to list.</strong>';
        if ( $total_in_db === 0 ) {
            echo ' Table <code>' . esc_html( $table ) . '</code> is empty. Logs are written only when an API request is made that includes an <code>endpoint</code>: (1) <strong>Sync</strong> / <strong>Sync New</strong> / <strong>Review</strong> on <strong>Lightspeed MBN → Sync for Motor Listings</strong> or <strong>Sync for WooCommerce</strong>, or (2) the status-update cron that fetches single units. Run one of those to create log entries.';
        } else {
            echo ' No rows match the current filters. <a href="' . esc_url( $base_url ) . '">Click Reset</a> to clear filters and show all logs.';
        }
        echo '</p></div>';
        return;
    }

    if ( $used_fallback ) {
        echo '<div class="notice notice-warning inline" style="margin:10px 0;"><p>Filters could not be applied to this table; showing all logs ordered by ID. <a href="' . esc_url( $base_url ) . '">Reset</a> to refresh.</p></div>';
    }

    if ( $schema === 'legacy' ) {
        echo '<table class="widefat fixed striped" cellspacing="0"><thead><tr><th>ID</th><th>Log date</th><th>Endpoint</th><th>CMF</th><th>Params</th><th>Method</th><th>HTTP code</th><th>Response summary</th><th>Run ID</th><th>View</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $view_url = admin_url( 'admin.php?page=ls-sync-logs&log_id=' . (int) $row['id'] . ls_sync_logs_query_string( array_merge( $base_args, [ 'paged' => $page ] ) ) );
            echo '<tr>';
            echo '<td>' . (int) $row['id'] . '</td>';
            echo '<td>' . esc_html( $row['log_date'] ?? '' ) . '</td>';
            echo '<td><code style="word-break:break-all;font-size:11px;">' . esc_html( $row['endpoint'] ?? '' ) . '</code></td>';
            echo '<td>' . esc_html( $row['cmf'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row['params'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row['method'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row['http_code'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row['response_summary'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row['run_id'] ?? '' ) . '</td>';
            echo '<td><a href="' . esc_url( $view_url ) . '">Details</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<table class="widefat fixed striped" cellspacing="0"><thead><tr><th>ID</th><th>Run ID</th><th>Stock #</th><th>Endpoint</th><th>Status</th><th>Date / Time</th><th>View</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $view_url = admin_url( 'admin.php?page=ls-sync-logs&log_id=' . (int) $row['id'] . ls_sync_logs_query_string( array_merge( $base_args, [ 'paged' => $page ] ) ) );
            echo '<tr>';
            echo '<td>' . (int) $row['id'] . '</td>';
            echo '<td>' . esc_html( $row['run_id'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row['stock_number'] ?? '' ) . '</td>';
            echo '<td><code style="word-break:break-all;font-size:11px;">' . esc_html( $row['endpoint'] ?? '' ) . '</code></td>';
            echo '<td>' . esc_html( $row['status_code'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row['created_at'] ?? '' ) . '</td>';
            echo '<td><a href="' . esc_url( $view_url ) . '">Details</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    echo '<p class="tablenav" style="margin-top:10px;"><span class="displaying-num">';
    if ( $per_page < 1 ) {
        echo (int) $total . ' items (all)';
    } else {
        $from = $offset + 1;
        $to   = min( $offset + $per_page, $total );
        echo $from . '–' . $to . ' of ' . (int) $total . ' items';
    }
    echo '</span>';
    if ( $per_page > 0 && $total > $per_page ) {
        $total_pages = (int) ceil( $total / $per_page );
        $base       = $base_url . ls_sync_logs_query_string( $base_args ) . '&paged=%#%';
        echo ' &nbsp; ' . paginate_links( [ 'base' => $base, 'format' => '', 'current' => $page, 'total' => $total_pages ] );
    }
    echo '</p>';
}

/**
 * Legacy table display for old ls_log format; kept for any backward compatibility.
 * API logs use ls_display_log_files() which reads from DB.
 *
 * @param array $log_data Array of log rows (e.g. action, product_id, sku, ...).
 */
function ls_display_log_table( $log_data ) {
    if ( empty( $log_data ) ) {
        return;
    }
    $first = reset( $log_data );
    if ( isset( $first['endpoint'], $first['status_code'] ) ) {
        echo '<table class="widefat fixed striped" cellspacing="0"><thead><tr><th>Stock #</th><th>Endpoint</th><th>Status</th><th>Timestamp</th></tr></thead><tbody>';
        foreach ( $log_data as $entry ) {
            echo '<tr><td>' . esc_html( isset( $entry['StockNumber'] ) ? $entry['StockNumber'] : '' ) . '</td><td><code>' . esc_html( $entry['endpoint'] ) . '</code></td><td>' . esc_html( $entry['status_code'] ) . '</td><td>' . esc_html( $entry['timestamp'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        return;
    }
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
                    <td><?php echo esc_html( $entry['action'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['product_id'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['sku'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['name'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['regular_price'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['sale_price'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['stock'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
