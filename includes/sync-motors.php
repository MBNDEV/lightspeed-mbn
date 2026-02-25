<?php
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("motors_process_image: PHP Error [$errno] $errstr in $errfile on line $errline");
    return false;
});

// Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sync for Motor Listings: chunked by request (LS_SYNC_CHUNK_SIZE), time-capped (LS_SYNC_MAX_SEC_PER_REQUEST),
 * state in ls_sync_motors_state. Same pattern as WooCommerce sync. process_sync_data / process_sync_new_data /
 * process_sync_review_data remain for backward compatibility and accept full arrays.
 */
function ls_render_sync_motors_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $dealer_data = ls_api_request_dealer();
    $chunk_size = LS_SYNC_CHUNK_SIZE;
    $max_sec    = LS_SYNC_MAX_SEC_PER_REQUEST;
    $state_key  = 'ls_sync_motors_state';

    $motors_sync_disabled = empty( get_option( 'ls_auto_sync_motors', [] ) );
    if ( $motors_sync_disabled ) {
        delete_option( $state_key );
    }

    $motors_synced_data   = null;
    $motors_result_cmf    = null;
    $motors_result_action = null;
    $motors_show_next     = false;
    $motors_run_id        = null;
    $motors_total         = 0;
    $motors_error         = null;
    $motors_sync_disabled_notice = false;

    if ( isset( $_POST['sync_new'] ) ) {
        delete_option( $state_key );
    }

    $is_next = ! empty( $_POST['sync_next_page'] );
    $cmf = null;
    $action = null;
    $sync_by_stock_result = null;
    if ( isset( $_POST['sync_cmf'] ) ) {
        $cmf = sanitize_text_field( $_POST['sync_cmf'] );
        $action = 'sync';
    } elseif ( isset( $_POST['sync_review'] ) ) {
        $cmf = sanitize_text_field( $_POST['sync_review'] );
        $action = 'review';
    } elseif ( isset( $_POST['sync_new'] ) ) {
        $cmf = sanitize_text_field( $_POST['sync_new'] );
        $action = 'sync_new';
    } elseif ( isset( $_POST['sync_by_stock_number'] ) && ! empty( $_POST['sync_by_stock_number_cmf'] ) && ! empty( $_POST['sync_by_stock_number_input'] ) ) {
        $sync_cmf     = sanitize_text_field( $_POST['sync_by_stock_number_cmf'] );
        $stock_number = sanitize_text_field( $_POST['sync_by_stock_number_input'] );
        if ( function_exists( 'ls_process_item_sync_stock_number' ) ) {
            $result = ls_process_item_sync_stock_number( $stock_number, $sync_cmf );
            $sync_by_stock_result = ! empty( $result['success'] )
                ? [ 'success' => true, 'post_id' => (int) ( $result['post_id'] ?? 0 ), 'stock_number' => $stock_number, 'cmf' => $sync_cmf ]
                : [ 'success' => false, 'message' => $result['message'] ?? 'Sync failed.' ];
        } else {
            $run_id         = date( 'Y-m-d_H-i-s' );
            $unit_response  = get_single_unit( $stock_number, $run_id, $sync_cmf );
            if ( $unit_response['status_code'] === 200 ) {
                $data   = $unit_response['data'];
                $units  = is_array( $data ) ? $data : ( isset( $data['value'] ) && is_array( $data['value'] ) ? $data['value'] : [] );
                if ( ! empty( $units ) ) {
                    $part   = $units[0];
                    $synced = sync_part_to_motors( $part, $run_id );
                    $sync_by_stock_result = $synced ? [ 'success' => true, 'post_id' => $synced, 'stock_number' => $stock_number, 'cmf' => $sync_cmf ] : [ 'success' => false, 'message' => 'Sync failed.' ];
                } else {
                    $sync_by_stock_result = [ 'success' => false, 'message' => 'Unit not found for stock number: ' . $stock_number . '.' ];
                }
            } else {
                $sync_by_stock_result = [ 'success' => false, 'message' => isset( $unit_response['message'] ) ? $unit_response['message'] : 'API error (HTTP ' . ( $unit_response['status_code'] ?? 0 ) . ').' ];
            }
        }
    }

    if ( $motors_sync_disabled && ( $cmf !== null || $is_next ) ) {
        $cmf = null;
        $motors_sync_disabled_notice = true;
        delete_option( $state_key );
    }

    if ( $cmf !== null ) {
        @set_time_limit( (int) ( LS_SYNC_MAX_SEC_PER_REQUEST + 15 ) );
        $run_id = $is_next ? null : date( 'Y-m-d_H-i-s' );
        $cursor = 0;
        $total_processed = 0;
        if ( $is_next ) {
            $state = get_option( $state_key, [] );
            if ( ! empty( $state ) && isset( $state['cmf'], $state['action'], $state['cursor'], $state['run_id'] ) && $state['cmf'] === $cmf && $state['action'] === $action ) {
                $run_id = $state['run_id'];
                $cursor = (int) $state['cursor'];
                $total_processed = (int) ( $state['total_processed'] ?? 0 );
            } else {
                $run_id = date( 'Y-m-d_H-i-s' );
            }
        }

        $data = ls_sync_major_unit_page( $cmf, $cursor, $chunk_size, $run_id );

        if ( is_string( $data ) ) {
            $motors_error = $data;
            delete_option( $state_key );
        } else {
            $units = is_array( $data ) ? $data : ( isset( $data['value'] ) && is_array( $data['value'] ) ? $data['value'] : [] );
            $start_time = time();
            $rows = '';
            $count = 0;
            $processed_this_chunk = 0;

            foreach ( $units as $part ) {
                if ( ( time() - $start_time ) >= $max_sec ) {
                    break;
                }
                if ( $action === 'sync' ) {
                    $synced = sync_part_to_motors( $part, $run_id );
                } elseif ( $action === 'sync_new' ) {
                    $synced = sync_part_to_motors_new( $part, $run_id );
                } else {
                    $synced = sync_part_to_motors_review( $part, $run_id );
                }
                if ( $synced != 0 ) {
                    $count++;
                }
                $processed_this_chunk++;
                $rows .= '<tr><td>' . (int) $synced . '</td><td>' . esc_html( $part['StockNumber'] ?? '' ) . '</td><td>' . esc_html( $part['Dealerlistprice'] ?? '' ) . '</td>';
                $rows .= '<td>' . esc_html( $part['CodeName'] ?? '' ) . '</td><td>' . esc_html( $part['ExteriorColor'] ?? '' ) . '</td><td>' . esc_html( $part['InteriorColor'] ?? '' ) . '</td>';
                $rows .= '<td>' . esc_html( $part['Condition'] ?? '' ) . '</td><td>' . esc_html( $part['ModelYear'] ?? '' ) . '</td><td>' . esc_html( $part['Make'] ?? '' ) . '</td>';
                $rows .= '<td>' . esc_html( $part['Model'] ?? '' ) . '</td><td>' . esc_html( $part['Class'] ?? '' ) . '</td><td>' . esc_html( $part['FuelType'] ?? '' ) . '</td>';
                $rows .= '<td>' . esc_html( $part['UnitType'] ?? '' ) . '</td><td>' . esc_html( $part['UnitStatus'] ?? '' ) . '</td></tr>';
            }

            $total_processed += $processed_this_chunk;
            if ( function_exists( 'ls_sync_progress_log' ) ) {
                ls_sync_progress_log( $run_id, 'motors', $cmf, $cursor + $processed_this_chunk, $processed_this_chunk, $action, $total_processed );
            }

            $motors_synced_data = [ 'count' => $count . ' / ' . $processed_this_chunk, 'rows' => $rows ];
            $motors_result_cmf = $cmf;
            $motors_result_action = $action;
            $motors_run_id = $run_id;
            $motors_total = $total_processed;

            $motors_show_next = ( count( $units ) >= $chunk_size ) || ( $processed_this_chunk < count( $units ) );
            if ( $motors_show_next && $processed_this_chunk > 0 ) {
                update_option( $state_key, [ 'cmf' => $cmf, 'action' => $action, 'run_id' => $run_id, 'cursor' => $cursor + $processed_this_chunk, 'total_processed' => $total_processed ] );
            } else {
                delete_option( $state_key );
                if ( $total_processed > 0 ) {
                    set_transient( 'ls_sync_motors_complete', [
                        'cmf'    => $motors_result_cmf,
                        'action' => $motors_result_action,
                        'total'  => $motors_total,
                    ], 300 );
                    if ( function_exists( 'nitropack_sdk_purge' ) ) {
                        nitropack_sdk_purge( null, null, null );
                    }
                }
            }
        }
    }

    $woo_complete    = get_transient( 'ls_sync_woo_complete' );
    $motors_complete = get_transient( 'ls_sync_motors_complete' );
    if ( $woo_complete !== false ) {
        delete_transient( 'ls_sync_woo_complete' );
    }
    if ( $motors_complete !== false ) {
        delete_transient( 'ls_sync_motors_complete' );
    }

    ?>
    <div class="wrap">
        <h1>Sync for Motor Listings</h1>

        <?php if ( $woo_complete !== false && is_array( $woo_complete ) ): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>WooCommerce sync complete.</strong> CMF <?php echo esc_html( $woo_complete['cmf'] ?? '' ); ?>: <?php echo esc_html( $woo_complete['action'] ?? 'sync' ); ?> finished — <?php echo (int) ( $woo_complete['total'] ?? 0 ); ?> items synced.</p>
            </div>
        <?php endif; ?>
        <?php if ( $motors_complete !== false && is_array( $motors_complete ) ): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Motor Listings sync complete.</strong> CMF <?php echo esc_html( $motors_complete['cmf'] ?? '' ); ?>: <?php echo esc_html( $motors_complete['action'] ?? 'sync' ); ?> finished — <?php echo (int) ( $motors_complete['total'] ?? 0 ); ?> items synced.</p>
            </div>
        <?php endif; ?>

        <?php if ( $motors_sync_disabled ): ?>
            <div class="notice notice-warning">
                <p>Sync has been disabled. Enable Auto Sync for at least one CMF below to run sync.</p>
            </div>
        <?php endif; ?>
        <?php if ( $motors_sync_disabled_notice ): ?>
            <div class="notice notice-warning">
                <p>Sync has been disabled.</p>
            </div>
        <?php endif; ?>
        <?php if ( $sync_by_stock_result !== null ): ?>
            <div class="notice notice-<?php echo $sync_by_stock_result['success'] ? 'success' : 'error'; ?> is-dismissible">
                <p><?php echo $sync_by_stock_result['success']
                    ? 'Synced by stock number: ' . esc_html( $sync_by_stock_result['stock_number'] ) . ' (CMF: ' . esc_html( $sync_by_stock_result['cmf'] ) . ') → Motor Listing ID: ' . (int) $sync_by_stock_result['post_id']
                    : esc_html( $sync_by_stock_result['message'] ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( is_string( $dealer_data ) ): ?>
            <div class="notice notice-error">
                <p><?php echo esc_html( $dealer_data ); ?></p>
            </div>
        <?php else: ?>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <th>Cmf</th>
                        <th>Dealership Name</th>
                        <th>Dealer Number</th>
                        <th>Actions</th>
                        <th>Auto Sync</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $auto_sync_list = get_option( 'ls_auto_sync_motors', [] );
                    if ( ! empty( $dealer_data ) ):
                        foreach ( $dealer_data as $dealer ):
                            $cmf = $dealer['Cmf'];
                            $auto_sync_enabled = in_array( $cmf, $auto_sync_list, true );
                    ?>
                            <tr>
                                <td><?php echo esc_html( $dealer['Cmf'] ?? 'N/A' ); ?></td>
                                <td><?php echo esc_html( $dealer['DealershipName'] ?? 'N/A' ); ?></td>
                                <td><?php echo esc_html( $dealer['DealerNumber'] ?? 'N/A' ); ?></td>
                                <td style="display: flex; gap: 10px;">
                                    <?php if ( $motors_sync_disabled ): ?>
                                        <span class="ls-sync-disabled-buttons" onclick="alert('Sync has been disabled');" style="cursor: not-allowed; display: inline-flex; gap: 10px;">
                                            <button type="button" class="button" disabled>Sync</button>
                                            <button type="button" class="button" disabled>Review</button>
                                            <button type="button" class="button" disabled>Sync New</button>
                                        </span>
                                    <?php else: ?>
                                        <form method="post" action=""><input type="hidden" name="sync_cmf" value="<?php echo esc_attr( $cmf ); ?>"><button type="submit" name="sync_button" class="button">Sync</button></form>
                                        <form method="post" action=""><input type="hidden" name="sync_review" value="<?php echo esc_attr( $cmf ); ?>"><button type="submit" name="review_button" class="button">Review</button></form>
                                        <form method="post" action=""><input type="hidden" name="sync_new" value="<?php echo esc_attr( $cmf ); ?>"><button type="submit" name="new_button" class="button">Sync New</button></form>
                                    <?php endif; ?>
                                    <form method="post" action="" style="display: inline-flex; align-items: center; gap: 4px;">
                                        <input type="hidden" name="sync_by_stock_number_cmf" value="<?php echo esc_attr( $cmf ); ?>">
                                        <input type="text" name="sync_by_stock_number_input" placeholder="Stock #" size="10" maxlength="32" style="max-width: 90px;">
                                        <button type="submit" name="sync_by_stock_number" class="button">Sync by Stock #</button>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" action="">
                                        <input type="hidden" name="auto_sync_cmf_motors" value="<?php echo esc_attr( $cmf ); ?>">
                                        <label><input type="checkbox" name="enable_auto_sync_motors" value="1" <?php checked( $auto_sync_enabled, true ); ?>> Enable</label>
                                        <button type="submit" class="button">Save</button>
                                    </form>
                                </td>
                            </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9">No data available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( $motors_error ): ?>
            <div class="notice notice-error"><p><?php echo esc_html( $motors_error ); ?></p></div>
        <?php elseif ( $motors_synced_data !== null && $motors_result_cmf !== null ): ?>
            <h2>CMF: <?php echo esc_html( $motors_result_cmf ); ?></h2>
            <h3><?php echo esc_html( $motors_result_action === 'sync_new' ? 'Sync New' : ( $motors_result_action === 'review' ? 'Review' : 'Sync' ) ); ?>: <?php echo esc_html( $motors_synced_data['count'] ); ?>
                <?php if ( $motors_show_next ): ?> (<?php echo (int) $motors_total; ?> so far — click Next to continue)<?php else: ?> (complete, <?php echo (int) $motors_total; ?> total)<?php endif; ?>
            </h3>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <td>Motor Listing ID</td>
                        <th>StockNumber</th>
                        <th>Dealerlistprice</th>
                        <th>CodeName</th>
                        <th>Color</th>
                        <th>Interior Color</th>
                        <th>Condition</th>
                        <th>ModelYear</th>
                        <th>Make</th>
                        <th>Model</th>
                        <th>Class</th>
                        <th>EngineType</th>
                        <th>Category</th>
                        <th>Unit Status</th>
                    </tr>
                </thead>
                <tbody><?php echo $motors_synced_data['rows']; ?></tbody>
            </table>
            <?php if ( $motors_show_next ): ?>
                <p><form method="post" action="" style="display:inline;">
                    <input type="hidden" name="<?php echo $motors_result_action === 'sync' ? 'sync_cmf' : ( $motors_result_action === 'review' ? 'sync_review' : 'sync_new' ); ?>" value="<?php echo esc_attr( $motors_result_cmf ); ?>">
                    <input type="hidden" name="sync_next_page" value="1">
                    <button type="submit" class="button button-primary">Next page</button>
                </form></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

if ( isset( $_POST['auto_sync_cmf_motors'] ) ) {
    $cmf = sanitize_text_field( $_POST['auto_sync_cmf_motors'] );
    $enable_auto_sync = isset( $_POST['enable_auto_sync_motors'] ) ? true : false;

    // Retrieve the current list of auto-sync CMFs.
    $auto_sync_list = get_option( 'ls_auto_sync_motors', [] );

    if ( $enable_auto_sync ) {
        // Add the CMF to the list if not already present.
        if ( ! in_array( $cmf, $auto_sync_list, true ) ) {
            $auto_sync_list[] = $cmf;
        }
    } else {
        // Remove the CMF from the list if it exists.
        $auto_sync_list = array_filter( $auto_sync_list, function( $value ) use ( $cmf ) {
            return $value !== $cmf;
        } );
    }

    // Save the updated list back to the database.
    update_option( 'ls_auto_sync_motors', $auto_sync_list );
    if ( function_exists( 'ls_maybe_schedule_auto_sync_cron' ) ) {
        ls_maybe_schedule_auto_sync_cron();
    }

    add_action( 'admin_notices', function() use ( $cmf, $enable_auto_sync ) {
        echo '<div class="notice notice-success"><p>Auto Sync ' . ( $enable_auto_sync ? 'enabled' : 'disabled' ) . ' for CMF: ' . esc_html( $cmf ) . '</p></div>';
    } );
}

function process_sync_data( $part_data, $run_id ) {
    $rows = '';
    $count = 0;
    foreach ( $part_data as $part ) {
            $synced = sync_part_to_motors( $part, $run_id );
            if($synced != 0){
                $count++;
            }
            $rows .= "<tr>
                <td>{$synced}</td>
                <td>{$part['StockNumber']}</td>
                <td>{$part['Dealerlistprice']}</td>
                <td>{$part['CodeName']}</td>
                <td>{$part['ExteriorColor']}</td>
                <td>{$part['InteriorColor']}</td>
                <td>{$part['Condition']}</td>
                <td>{$part['ModelYear']}</td>
                <td>{$part['Make']}</td>
                <td>{$part['Model']}</td>
                <td>{$part['Class']}</td>
                <td>{$part['FuelType']}</td>
                <td>{$part['UnitType']}</td>
                <td>{$part['UnitStatus']}</td>
            </tr>";
    }

     if(function_exists("nitropack_sdk_purge")) {
          ls_motors_log([
        'message' => "Nitropack clear cache."
    ], $run_id);
        nitropack_sdk_purge( NULL, NULL, NULL);
    }
    return ['rows' => $rows, 'count' => $count." / ".($part_data!=NULL?count($part_data):0)];
}

function sync_part_to_motors( $part, $run_id ) {
    global $wpdb;
     // Check if the post already exists based on stock_number in wp_postmeta
    $existing_post_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'stock_number' AND meta_value = %s",
        $part['StockNumber']
    ) );
    $description = $part['WebDescription'];
    $wpbakery_content = '[vc_row el_class="listing-details-main" el_id="listing-detail-layout"][vc_column width="1/4" el_class="list-details-sidebar listing-details-main-right"][stm_single_car_price][stm_single_car_data][vc_tta_accordion title_tag="h3" active_section="2" collapsible_all="true" el_id="side-accordion"][vc_tta_section i_icon_fontawesome="stm-icon-calculator" add_icon="true" title="Financing Calculator" tab_id="financing-calculator"][stm_single_car_calculator][/vc_tta_section][vc_tta_section add_icon="true" title="Reserve This Cart" tab_id="reserve-cart"][stm_contact_form form="6329" css=".vc_custom_1725586290642{padding-top: 20px !important;padding-right: 20px !important;padding-bottom: 20px !important;padding-left: 20px !important;background-color: #243136 !important;}"][/vc_tta_section][/vc_tta_accordion][/vc_column][vc_column width="3/4" el_class="listing-details-main-left"][stm_single_car_gallery][vc_row_inner el_class="cols_reverse"][vc_column_inner el_class="mob-order-3 custom-col50" width="1/3"][vc_column_text css=""]'.$description.'[/vc_column_text][/vc_column_inner][vc_column_inner el_class="mob-order-1 custom-col50" width="1/3"][vc_btn title="Call to Check Availability" style="custom" custom_background="#d11717" custom_text="#ffffff" align="right" css="" el_class="mob-btn-full" link="url:tel%3A%2B14802728418"][/vc_column_inner][vc_column_inner el_class="mob-order-2 custom-col100" width="1/3"][stm_single_car_actions][/vc_column_inner][/vc_row_inner][stm_social_buttons][/vc_column][/vc_row][vc_row][vc_column][/vc_column][/vc_row]';

    if ( $existing_post_id ) {
        // Update the existing post
        $post_data = array(
            'ID'         => $existing_post_id,
            'post_title' => $part['ModelYear'].' '.$part['Make'].' '.$part['Model'],
            'post_type'  => 'listings',
            'post_status'=> 'publish',
            'post_content'=> $wpbakery_content,
        );
        wp_update_post( $post_data );
        $post_id = $existing_post_id;
        $action = 'update';
    } else {
        // Insert a new post
        $post_data = array(
            'post_title'  => $part['ModelYear'].' '.$part['Make'].' '.$part['Model'],
            'post_type'   => 'listings',
            'post_status' => 'publish',
            'post_content'=> $wpbakery_content,
        );
        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        // Insert the stock_number into wp_postmeta
        add_post_meta( $post_id, 'stock_number', $part['StockNumber'] );

        $action = 'create';
    }
    
    if($part['UnitStatus'] === ''){
        post_meta_logs($post_id, $part, $run_id, $action);
    }

    return $post_id;
}

function post_meta_logs($post_id, $part, $run_id, $action, $noimage = false){
    $make = $part['Make'];
    $condition = '';
    if(strtolower($part['NewUsed']) == 'n') {
        $condition = 'new-cars';
    } else if(strtolower($part['NewUsed']) == 'u') {
        $condition = 'used-cars';
    } else if(strtolower($part['NewUsed']) == 'c') {
        $condition = 'consigned-cars';
    }

    $unittype = $part['UnitType'];
    $model = $part['Model'];
    $engine = $part['FuelType'];
    $icolor = $part['InteriorColor'];
    $ecolor = $part['ExteriorColor'];
    $modelYear = $part['ModelYear'];
    // Insert or update the part details into the wp_postmeta table
    $sold = '';
    update_post_meta($post_id, 'car_mark_as_sold', $sold);
    update_post_meta( $post_id, 'price', $part['Dealerlistprice'] );
    $stock_number = $part['StockNumber'];
    error_log("Updating price to " . $part['Dealerlistprice'] . " for stock number " . $stock_number);
    update_post_meta( $post_id, 'stm_genuine_price', $part['Dealerlistprice'] );
    update_post_meta( $post_id, '_wpb_vc_js_status', "true");
    // Check if the 'make' taxonomy has a term value 'Honda'
    if ( ! term_exists( $make, 'make' ) ) {
        // Add the term to the 'make' taxonomy
        wp_insert_term( $make, 'make', array(
            'slug' => sanitize_title( $make )
        ));
    }
    // Get the term object by name and taxonomy
    $term_obj = get_term_by( 'name', $make, 'make' );
    if ( $term_obj && ! is_wp_error( $term_obj ) ) {
        wp_set_post_terms($post_id, [$term_obj->term_id], 'make', false);
        update_post_meta($post_id, 'make', $term_obj->slug);
    }

    if ( ! term_exists( $unittype, 'category_type' ) ) {
        // Add the term to the 'make' taxonomy
        wp_insert_term( $unittype, 'category_type', array(
            'slug' => sanitize_title( sanitize_title( $unittype ) )
        ));
    }
    $term_obj = get_term_by( 'name', $unittype, 'category_type' );
    if ( $term_obj && ! is_wp_error( $term_obj ) ) {
        wp_set_post_terms($post_id, [$term_obj->term_id], 'category_type', false);
        update_post_meta($post_id, 'category_type', $term_obj->slug);
    }

  
    $term_obj = get_term_by( 'slug', $condition, 'condition' );
    if ( $term_obj && ! is_wp_error( $term_obj ) ) {
        wp_set_post_terms($post_id, [$term_obj->term_id], 'condition', false);
        update_post_meta($post_id, 'condition', $term_obj->slug);
    }

    if ( ! term_exists( $modelYear, 'ca-year' ) ) {
        // Add the term to the 'make' taxonomy
        wp_insert_term( $modelYear, 'ca-year', array(
            'slug' => sanitize_title( sanitize_title( $modelYear ) )
        ));
    }
    $term_obj = get_term_by( 'name', $part['ModelYear'], 'ca-year' );
    if ( $term_obj && ! is_wp_error( $term_obj ) ) {
        wp_set_post_terms($post_id, [$term_obj->term_id], 'ca-year', false);
        update_post_meta($post_id, 'ca-year', $term_obj->slug);
    }

    if ( ! term_exists( $model, 'serie' ) ) {
        // Add the term to the 'make' taxonomy
        wp_insert_term( $model, 'serie', array(
            'slug' => sanitize_title( $model )
        ));
    }
    $term_obj = get_term_by( 'name', $model, 'serie' );
    if ( $term_obj && ! is_wp_error( $term_obj ) ) {
        wp_set_post_terms($post_id, [$term_obj->term_id], 'serie', false);
        update_post_meta($post_id, 'serie', $term_obj->slug);
    }

    if ( ! term_exists( $engine, 'engine-type' ) ) {
        // Add the term to the 'make' taxonomy
        wp_insert_term( $engine, 'engine-type', array(
            'slug' => sanitize_title( $engine )
        ));
    }
    $term_obj = get_term_by( 'name', $engine, 'engine-type' );
    if ( $term_obj && ! is_wp_error( $term_obj ) ) {
        wp_set_post_terms($post_id, [$term_obj->term_id], 'engine-type', false);
        update_post_meta($post_id, 'engine-type', $term_obj->slug);
    }

    if ( ! term_exists( $ecolor, 'exterior-color' ) ) {
        // Add the term to the 'make' taxonomy
        wp_insert_term( $ecolor, 'exterior-color', array(
            'slug' => sanitize_title( $ecolor )
        ));
    }
    $term_obj = get_term_by( 'name', $ecolor, 'exterior-color' );
    if ( $term_obj && ! is_wp_error( $term_obj ) ) {
        wp_set_post_terms($post_id, [$term_obj->term_id], 'exterior-color', false);
        update_post_meta($post_id, 'exterior-color', $term_obj->slug);
    }

    if ( ! term_exists( $icolor, 'interior-color' ) ) {
        // Add the term to the 'make' taxonomy
        wp_insert_term( $icolor, 'interior-color', array(
            'slug' => sanitize_title( $icolor )
        ));
    }
    if ( $term_obj && ! is_wp_error( $term_obj ) ) {
        wp_set_post_terms($post_id, [$term_obj->term_id], 'interior-color', false);
        update_post_meta($post_id, 'interior-color', $term_obj->slug);
    }
    update_post_meta( $post_id, 'vin_number', $part['VIN'] );
    // $serialized_gallery = '';
    // Upload images and set the primary image
    // if (!empty($part['Images'])) {
    //     $gallery_ids = [];
    //     $primary_attachment_id = null;
    //     $first_attachment_id = null;

    //     foreach ($part['Images'] as $index => $image) {
    //         if (empty($image['ImageUrl'])) continue;
    //         $image_url = $image['ImageUrl'];

    //         // Check if the image already exists in the media library
    //         $attachment_id = attachment_url_to_postid($image_url);
    //         if (!$attachment_id) {
    //             // Use a timeout for file_get_contents
    //             $context = stream_context_create(['http' => ['timeout' => 10]]);
    //             $image_data = @file_get_contents($image_url, false, $context);
    //             if ($image_data === false) {
    //                 error_log("Failed to download image: $image_url");
    //                 continue;
    //             }

    //             $finfo = finfo_open(FILEINFO_MIME_TYPE);
    //             $mime_type = finfo_buffer($finfo, $image_data);
    //             finfo_close($finfo);

    //             $mime_to_ext = [
    //                 'image/jpeg' => 'jpg',
    //                 'image/png' => 'png',
    //                 'image/gif' => 'gif',
    //                 'image/webp' => 'webp'
    //             ];
    //             $ext = isset($mime_to_ext[$mime_type]) ? $mime_to_ext[$mime_type] : 'jpg';

    //             $basename = basename(parse_url($image_url, PHP_URL_PATH));
    //             $filename = $basename . '.' . $ext;

    //             $upload_dir = wp_upload_dir();
    //             $file_path = $upload_dir['path'] . '/' . $filename;
    //             file_put_contents($file_path, $image_data);

    //             $file_type = wp_check_filetype($filename, null);
    //             $attachment = [
    //                 'post_mime_type' => $file_type['type'],
    //                 'post_title'     => sanitize_file_name($filename),
    //                 'post_content'   => '',
    //                 'post_status'    => 'inherit',
    //             ];

    //             $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);

    //             require_once(ABSPATH . 'wp-admin/includes/image.php');
    //             $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
    //             wp_update_attachment_metadata($attachment_id, $attachment_data);
    //         }

    //         // Save the first image's attachment ID
    //         if ($first_attachment_id === null) {
    //             $first_attachment_id = $attachment_id;
    //         }

    //         // Track the primary image's attachment ID
    //         if (!empty($image['PrimaryImage']) && $image['PrimaryImage'] === true) {
    //             $primary_attachment_id = $attachment_id;
    //         }
    //     }

    //     // If no PrimaryImage was found, set the first image as featured
    //     if ($primary_attachment_id === null && $first_attachment_id !== null) {
    //         $primary_attachment_id = $first_attachment_id;
    //     }

    //     // Set the featured image
    //     if ($primary_attachment_id) {
    //         set_post_thumbnail($post_id, $primary_attachment_id);
    //         update_post_meta($post_id, '_thumbnail_id', $primary_attachment_id);
    //     }

    //     // Build gallery_ids, excluding the primary image
    //     foreach ($part['Images'] as $image) {
    //         if (empty($image['ImageUrl'])) continue;
    //         $image_url = $image['ImageUrl'];
    //         $attachment_id = attachment_url_to_postid($image_url);
    //         if (!$attachment_id) continue;
    //         if ($attachment_id != $primary_attachment_id) {
    //             $gallery_ids[] = $attachment_id;
    //         }
    //     }

    //     if (!empty($gallery_ids)) {
    //         $gallery_ids = array_unique($gallery_ids); // Remove duplicates
    //         update_post_meta($post_id, 'gallery', $gallery_ids);
    //         ls_motors_log([
    //             'gallery_ids' => $gallery_ids,
    //             'post_id' => $post_id
    //         ], $run_id);
    //     }
    // }

    ls_motors_log( [
        'action'   => $action,
        'listing_id'   => $post_id,
        'post_title'   => $part['CodeName'],
        'StockNumber'   => $part['StockNumber'],
        'price'        => $part['Dealerlistprice'],
        'category_type'        => $part['UnitType'],
        'condition'        => $part['Condition'],
        'Year Model'        => $part['ModelYear'],
        'Model'        => $part['Model'],
        'Engine Type'        => $part['FuelType'],
        'Color'        => $part['ExteriorColor'],
        'Interior Color'        => $part['InteriorColor'],
        'VIN'        => $part['VIN'],
        'Make'        => $part['Make'],
    ], $run_id );
}
  
function process_sync_new_data( $part_data, $run_id ) {
    $rows = '';
    $count = 0;
    foreach ( $part_data as $part ) {
        $synced = sync_part_to_motors_new( $part, $run_id );
        if($synced != 0){
            $count++;
        }
        $rows .= "<tr>
            <td>{$synced}</td>
            <td>{$part['StockNumber']}</td>
            <td>{$part['Dealerlistprice']}</td>
            <td>{$part['CodeName']}</td>
            <td>{$part['ExteriorColor']}</td>
            <td>{$part['InteriorColor']}</td>
            <td>{$part['Condition']}</td>
            <td>{$part['ModelYear']}</td>
            <td>{$part['Make']}</td>
            <td>{$part['Model']}</td>
            <td>{$part['Class']}</td>
            <td>{$part['FuelType']}</td>
            <td>{$part['UnitType']}</td>
            <td>{$part['UnitStatus']}</td>
        </tr>";
    }
    return ['rows' => $rows, 'count' => $count];
}

function sync_part_to_motors_new($part, $run_id) {
    global $wpdb;
    $existing_post_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'stock_number' AND meta_value = %s",
        $part['StockNumber']
    ) );

    if ( $existing_post_id ) {
        return 0;
    } else {
        // Insert a new post
        $post_data = array(
            'post_title'  => $part['CodeName'],
            'post_type'   => 'listings',
            'post_status' => 'publish',
        );
        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        // Insert the stock_number into wp_postmeta
        add_post_meta( $post_id, 'stock_number', $part['StockNumber'] );
        post_meta_logs($post_id, $part, $run_id, 'create');

        return $post_id;
    }
}

function process_sync_review_data( $part_data, $run_id ) {
    $rows = '';
    $count = 0;
    foreach ( $part_data as $part ) {
        $synced = sync_part_to_motors_review( $part, $run_id );
        if($synced != 0){
            $count++;
        }
        $rows .= "<tr>
            <td>{$synced}</td>
            <td>{$part['StockNumber']}</td>
            <td>{$part['Dealerlistprice']}</td>
            <td>{$part['CodeName']}</td>
            <td>{$part['ExteriorColor']}</td>
            <td>{$part['InteriorColor']}</td>
            <td>{$part['Condition']}</td>
            <td>{$part['ModelYear']}</td>
            <td>{$part['Make']}</td>
            <td>{$part['Model']}</td>
            <td>{$part['Class']}</td>
            <td>{$part['FuelType']}</td>
            <td>{$part['UnitType']}</td>
            <td>{$part['UnitStatus']}</td>
        </tr>";
    }
    return ['rows' => $rows, 'count' => $count." / ".($part_data!=NULL?count($part_data):0)];
}

function sync_part_to_motors_review($part, $run_id) {
    global $wpdb;
    $existing_post_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'stock_number' AND meta_value = %s",
        $part['StockNumber']
    ) );

    if ( $existing_post_id ) {
        return 0;
    }

    return $existing_post_id;
}

function update_motors_status( $post_id, $unit, $run_id ) {
    if ( empty($unit) ) {
        // No units, set to draft
        $post_data = array(
            'ID'         => $post_id,
            'post_status'=> 'draft',
        );
    } else {
        // Default to draft, publish if any unit has empty UnitStatus
        $status = 'draft';
        foreach($unit as $u) {
            if (empty($u['UnitStatus'])) {
                $status = 'publish';
                break;
            }
        }
        $post_data = array(
            'ID'         => $post_id,
            'post_status'=> $status,
        );
    }

    wp_update_post( $post_data );
    ls_motors_log( [
        'action'   => 'update_status',
        'listing_id'   => $post_id,
        'is_empty'   => empty($unit),
        'unit'   => json_encode($unit),
        'post_status' => $post_data['post_status'],
    ], $run_id );
}


function sync_motors_image( $part, $run_id ) {
    global $wpdb;
     // Check if the post already exists based on stock_number in wp_postmeta
    $post_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'stock_number' AND meta_value = %s",
        $part['StockNumber']
    ) );

    if ( $post_id ) {
        motors_process_image($post_id, $part, $run_id,);
    }

    return $post_id;
}

function sync_motors_no_image( $part, $run_id ) {
    global $wpdb;
     // Check if the post already exists based on stock_number in wp_postmeta
    $post_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'stock_number' AND meta_value = %s",
        $part['StockNumber']
    ) );

    if ( $post_id ) {
        motors_process_image_if_no_thumbnail($post_id, $part, $run_id,);
    }

    return $post_id;
}


// function motors_process_image($post_id, $part, $run_id){
//     $existing_thumbnail_id = get_post_thumbnail_id($post_id);
//     $existing_gallery = get_post_meta($post_id, 'gallery', true);
//     ls_motors_log([
//         'message' => 'Processing images for post.',
//         'post_id' => $post_id
//     ], $run_id);
    
//     if (!empty($part['Images'])) {
//         if ( $existing_thumbnail_id && !empty($existing_gallery) && (count($part['Images']) - 1) != (is_array($existing_gallery) ? count($existing_gallery) : 0)
//         ) {
//             ls_motors_log([
//                 'message' => 'Images already exist for post, skipping image sync.',
//                 'post_id' => $post_id
//             ], $run_id);
//             return;
//         }
//         ls_motors_log([
//             'Images' => $part['Images'],
//             'post_id' => $post_id
//         ], $run_id);
//         $gallery_ids = [];
//         $primary_attachment_id = null;
//         $first_attachment_id = null;

//         foreach ($part['Images'] as $index => $image) {
//             if (empty($image['ImageUrl'])) continue;
//             $image_url = $image['ImageUrl'];

//             // Check if the image already exists in the media library
//             $attachment_id = attachment_url_to_postid($image_url);
//             if (!$attachment_id) {
//                 $context = stream_context_create(['http' => ['timeout' => 10]]);
//                 $image_data = @file_get_contents($image_url, false, $context);
//                 if ($image_data === false) {
//                     error_log("Failed to download image: $image_url");
//                     continue;
//                 }

//                 $finfo = finfo_open(FILEINFO_MIME_TYPE);
//                 $mime_type = finfo_buffer($finfo, $image_data);
//                 finfo_close($finfo);

//                 $mime_to_ext = [
//                     'image/jpeg' => 'jpg',
//                     'image/png' => 'png',
//                     'image/gif' => 'gif',
//                     'image/webp' => 'webp'
//                 ];
//                 $ext = isset($mime_to_ext[$mime_type]) ? $mime_to_ext[$mime_type] : 'jpg';

//                 $basename = basename(parse_url($image_url, PHP_URL_PATH));
//                 $filename = $basename . '.' . $ext;

//                 $upload_dir = wp_upload_dir();
//                 $file_path = $upload_dir['path'] . '/' . $filename;
//                 file_put_contents($file_path, $image_data);

//                 $file_type = wp_check_filetype($filename, null);
//                 $attachment = [
//                     'post_mime_type' => $file_type['type'],
//                     'post_title'     => sanitize_file_name($filename),
//                     'post_content'   => '',
//                     'post_status'    => 'inherit',
//                 ];

//                 $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);

//                 require_once(ABSPATH . 'wp-admin/includes/image.php');
//                 $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
//                 wp_update_attachment_metadata($attachment_id, $attachment_data);
//             }

//             // Save the first image's attachment ID
//             if ($first_attachment_id === null) {
//                 $first_attachment_id = $attachment_id;
//             }

//             // Track the primary image's attachment ID
//             if (!empty($image['PrimaryImage']) && $image['PrimaryImage'] === true) {
//                 $primary_attachment_id = $attachment_id;
//             }

//             // Always add to gallery_ids (we'll remove the primary later)
//             $gallery_ids[] = $attachment_id;
//         }

//         // If no PrimaryImage was found, set the first image as featured
//         if ($primary_attachment_id === null && $first_attachment_id !== null) {
//             $primary_attachment_id = $first_attachment_id;
//         }

//         // Set the featured image
//         if ($primary_attachment_id) {
//             set_post_thumbnail($post_id, $primary_attachment_id);
//             update_post_meta($post_id, '_thumbnail_id', $primary_attachment_id);
//         }

//         // Remove the primary image from gallery_ids
//         $gallery_ids = array_diff($gallery_ids, [$primary_attachment_id]);
//         if (!empty($gallery_ids)) {
//             $gallery_ids = array_unique($gallery_ids); // Remove duplicates
//             update_post_meta($post_id, 'gallery', $gallery_ids);
//             ls_motors_log([
//                 'gallery_ids' => $gallery_ids,
//                 'post_id' => $post_id
//             ], $run_id);
//         }
//     }
// }

function motors_process_image($post_id, $part, $run_id){
    global $wpdb;
    $start_time = microtime(true);
    $stock_number = isset($part['StockNumber']) ? $part['StockNumber'] : 'UNKNOWN';
    $image_stats = [
        'total_images' => 0,
        'downloaded' => 0,
        'existing' => 0,
        'failed' => 0,
        'total_size' => 0
    ];
    
    try {
        // Skip if unit has a status (sold, etc.)
        if(isset($part['UnitStatus']) && $part['UnitStatus'] != ''){
            error_log("motors_process_image: Skipping post_id $post_id (stock: $stock_number) - Unit status is: {$part['UnitStatus']}");
            ls_motors_log([
                'message' => 'Skipped image processing - unit has status',
                'post_id' => $post_id,
                'stock_number' => $stock_number,
                'unit_status' => $part['UnitStatus']
            ], $run_id);
            return;
        }
        
        error_log("motors_process_image: Start processing images for post_id: $post_id, stock number: $stock_number, run_id: $run_id");
        
        // Remove old gallery and thumbnail
        delete_post_meta($post_id, 'gallery');
        delete_post_meta($post_id, '_thumbnail_id');
        delete_post_thumbnail($post_id);

        $gallery_ids = [];
        $primary_attachment_id = null;
        $first_attachment_id = null;

        if (empty($part['Images']) || !is_array($part['Images'])) {
            error_log("motors_process_image: No images found for stock number: $stock_number");
            ls_motors_log([
                'message' => 'No images available for processing',
                'post_id' => $post_id,
                'stock_number' => $stock_number
            ], $run_id);
            return;
        }
        
        $image_stats['total_images'] = count($part['Images']);
        error_log("motors_process_image: Found {$image_stats['total_images']} images for stock number: $stock_number");

        foreach ($part['Images'] as $index => $image) {
            error_log("motors_process_image: STARTING image #$index for stock number: $stock_number");
            
            if (empty($image['ImageUrl'])) {
                error_log("motors_process_image: Image #$index has empty URL for stock number: $stock_number");
                continue;
            }
            
            $image_url = $image['ImageUrl'];
            $is_primary = !empty($image['PrimaryImage']) && $image['PrimaryImage'] === true;
            
            error_log("motors_process_image: Processing image #$index" . ($is_primary ? " (PRIMARY)" : "") . ": $image_url for stock number: $stock_number");
            error_log("motors_process_image: Memory before image #$index: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB");

            // Check if an attachment already exists with this ImageUrl in metadata (skip re-download)
            $attachment_id = null;
            $existing_by_meta = $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM $wpdb->posts p
                INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_ls_image_url' AND pm.meta_value = %s
                WHERE p.post_type = 'attachment' LIMIT 1",
                $image_url
            ) );
            if ( $existing_by_meta ) {
                $attachment_id = (int) $existing_by_meta;
                error_log("motors_process_image: Found existing attachment by ImageUrl meta: $attachment_id for stock number: $stock_number");
            }
            if ( ! $attachment_id ) {
                error_log("motors_process_image: Checking if image exists in media library: $image_url");
                $attachment_id = attachment_url_to_postid($image_url);
                error_log("motors_process_image: attachment_url_to_postid returned: " . ($attachment_id ? $attachment_id : 'false'));
            }
            
            if ($attachment_id) {
                $image_stats['existing']++;
                error_log("motors_process_image: Image already exists with attachment_id: $attachment_id for stock number: $stock_number");
            } else {
                // Download and process new image
                try {
                    error_log("motors_process_image: DOWNLOADING image #$index from $image_url");
                    
                    // Increase timeout for large images and add retry logic
                    $context = stream_context_create([
                        'http' => [
                            'timeout' => 30, // Increased from 10 to 30 seconds
                            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                            'follow_location' => 1,
                            'max_redirects' => 3
                        ]
                    ]);
                    
                    error_log("motors_process_image: file_get_contents starting for image #$index");
                    $download_start = microtime(true);
                    $image_data = @file_get_contents($image_url, false, $context);
                    $download_time = round(microtime(true) - $download_start, 2);
                    error_log("motors_process_image: file_get_contents completed in {$download_time}s for image #$index");
                    
                    if ($image_data === false) {
                        $error = error_get_last();
                        $image_stats['failed']++;
                        error_log("motors_process_image: Failed to download image #$index: $image_url. Error: " . ($error ? $error['message'] : 'Unknown error'));
                        ls_motors_log([
                            'error' => 'Failed to download image',
                            'post_id' => $post_id,
                            'stock_number' => $stock_number,
                            'image_index' => $index,
                            'image_url' => $image_url,
                            'error_detail' => $error ? $error['message'] : 'Unknown error'
                        ], $run_id);
                        continue;
                    }
                    
                    $image_size = strlen($image_data);
                    $image_stats['total_size'] += $image_size;
                    error_log("motors_process_image: Downloaded image #$index, size: " . round($image_size / 1024, 2) . " KB");

                    // Validate image data
                    if ($image_size < 100) {
                        $image_stats['failed']++;
                        error_log("motors_process_image: Image data too small (possible error page), skipping: $image_url");
                        unset($image_data); // Free memory
                        continue;
                    }

                    // Detect MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_buffer($finfo, $image_data);
                    finfo_close($finfo);

                    $mime_to_ext = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        'image/jpg' => 'jpg'
                    ];
                    
                    if (!isset($mime_to_ext[$mime_type])) {
                        $image_stats['failed']++;
                        error_log("motors_process_image: Unsupported MIME type '$mime_type' for image: $image_url");
                        unset($image_data);
                        continue;
                    }
                    
                    $ext = $mime_to_ext[$mime_type];
                    $basename = basename(parse_url($image_url, PHP_URL_PATH));
                    // Remove existing extension if any
                    $basename = preg_replace('/\.[^.]+$/', '', $basename);
                    $filename = sanitize_file_name($basename) . '_' . $index . '_' . time() . '.' . $ext;

                    $upload_dir = wp_upload_dir();
                    
                    if (isset($upload_dir['error']) && $upload_dir['error']) {
                        $image_stats['failed']++;
                        error_log("motors_process_image: Upload directory error: {$upload_dir['error']}");
                        unset($image_data);
                        continue;
                    }
                    
                    $file_path = $upload_dir['path'] . '/' . $filename;
                    
                    $bytes_written = @file_put_contents($file_path, $image_data);
                    
                    if ($bytes_written === false) {
                        $image_stats['failed']++;
                        error_log("motors_process_image: Failed to save image to $file_path for stock number: $stock_number");
                        unset($image_data);
                        continue;
                    }
                    
                    // Free memory immediately after writing
                    unset($image_data);
                    
                    error_log("motors_process_image: Saved image to $file_path (" . round($bytes_written / 1024, 2) . " KB)");

                    $file_type = wp_check_filetype($filename, null);
                    $attachment = [
                        'post_mime_type' => $file_type['type'],
                        'post_title'     => sanitize_file_name($basename),
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                    ];

                    $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);
                    
                    if (is_wp_error($attachment_id)) {
                        $image_stats['failed']++;
                        error_log("motors_process_image: Failed to insert attachment: " . $attachment_id->get_error_message());
                        @unlink($file_path); // Clean up file
                        continue;
                    }

                    update_post_meta( $attachment_id, '_ls_image_url', $image_url );

                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    
                    error_log("motors_process_image: STARTING wp_generate_attachment_metadata for attachment_id: $attachment_id (this can be slow)");
                    $metadata_start = microtime(true);
                    $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
                    $metadata_time = round(microtime(true) - $metadata_start, 2);
                    error_log("motors_process_image: COMPLETED wp_generate_attachment_metadata in {$metadata_time}s for attachment_id: $attachment_id");
                    
                    if (is_wp_error($attachment_data)) {
                        $image_stats['failed']++;
                        error_log("motors_process_image: Failed to generate attachment metadata for attachment_id: $attachment_id, error: " . $attachment_data->get_error_message());
                        // Don't continue - we still have the attachment, just without metadata
                    } elseif (empty($attachment_data)) {
                        error_log("motors_process_image: Warning - attachment_data is empty for attachment_id: $attachment_id, file_path: $file_path (file may still be usable)");
                    } else {
                        error_log("motors_process_image: Updating attachment metadata for attachment_id: $attachment_id");
                        wp_update_attachment_metadata($attachment_id, $attachment_data);
                        error_log("motors_process_image: Metadata updated successfully for attachment_id: $attachment_id");
                    }

                    $image_stats['downloaded']++;
                    error_log("motors_process_image: Successfully created attachment_id: $attachment_id for stock number: $stock_number");
                    
                } catch (Throwable $e) {
                    $image_stats['failed']++;
                    error_log("motors_process_image: Exception processing image #$index URL $image_url for stock number: $stock_number - " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                    ls_motors_log([
                        'error' => 'Exception processing image',
                        'post_id' => $post_id,
                        'stock_number' => $stock_number,
                        'image_index' => $index,
                        'exception' => $e->getMessage()
                    ], $run_id);
                    continue;
                }
            }

            // Track attachment IDs
            if ($attachment_id) {
                if ($first_attachment_id === null) {
                    $first_attachment_id = $attachment_id;
                }
                if ($is_primary) {
                    $primary_attachment_id = $attachment_id;
                }
                $gallery_ids[] = $attachment_id;
            }
            
            // CRITICAL: Log completion of each image iteration
            error_log("motors_process_image: COMPLETED image #$index for stock number: $stock_number (attachment_id: " . ($attachment_id ? $attachment_id : 'none') . ")");
            error_log("motors_process_image: Memory after image #$index: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB");
        }
        
        error_log("motors_process_image: FINISHED all images loop for stock number: $stock_number - processed " . count($part['Images']) . " images");

        // If no PrimaryImage was found, set the first image as featured
        if ($primary_attachment_id === null && $first_attachment_id !== null) {
            $primary_attachment_id = $first_attachment_id;
            error_log("motors_process_image: No primary image designated, using first image as featured: $first_attachment_id");
        }

        // Set the featured image
        if ($primary_attachment_id) {
            set_post_thumbnail($post_id, $primary_attachment_id);
            update_post_meta($post_id, '_thumbnail_id', $primary_attachment_id);
            error_log("motors_process_image: Set featured image attachment_id: $primary_attachment_id for stock number: $stock_number");
        } else {
            error_log("motors_process_image: Warning - No featured image could be set for stock number: $stock_number");
        }

        // Remove the primary image from gallery_ids
        $gallery_ids = array_diff($gallery_ids, [$primary_attachment_id]);
        if (!empty($gallery_ids)) {
            $gallery_ids = array_unique($gallery_ids); // Remove duplicates
            update_post_meta($post_id, 'gallery', $gallery_ids);
            error_log("motors_process_image: Set gallery with " . count($gallery_ids) . " images for stock number $stock_number: " . implode(',', $gallery_ids));
        }
        
        $elapsed_time = round(microtime(true) - $start_time, 2);
        
        ls_motors_log([
            'message' => 'Completed image processing for post',
            'post_id' => $post_id,
            'stock_number' => $stock_number,
            'stats' => $image_stats,
            'primary_attachment_id' => $primary_attachment_id,
            'gallery_count' => count($gallery_ids),
            'processing_time' => $elapsed_time . 's',
            'total_size_mb' => round($image_stats['total_size'] / 1024 / 1024, 2)
        ], $run_id);

        error_log("motors_process_image: Finished processing images for stock number: $stock_number in {$elapsed_time}s - Total: {$image_stats['total_images']}, Downloaded: {$image_stats['downloaded']}, Existing: {$image_stats['existing']}, Failed: {$image_stats['failed']}");
        
    } catch (Throwable $e) {
        $elapsed_time = round(microtime(true) - $start_time, 2);
        error_log("motors_process_image: CRITICAL Exception in " . $e->getFile() . " on line " . $e->getLine() . 
            " for post_id $post_id, stock_number $stock_number, run_id $run_id: " . $e->getMessage());
        error_log("motors_process_image: Stack trace: " . $e->getTraceAsString());
        
        ls_motors_log([
            'error' => 'Critical exception in motors_process_image',
            'post_id' => $post_id,
            'stock_number' => $stock_number,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'processing_time' => $elapsed_time . 's',
            'stats' => $image_stats
        ], $run_id);
    }
}

function motors_process_image_if_no_thumbnail($post_id, $part, $run_id) {
    global $wpdb;
    $start_time = microtime(true);
    $stock_number = isset($part['StockNumber']) ? $part['StockNumber'] : 'UNKNOWN';
    $image_stats = [
        'total_images' => 0,
        'downloaded' => 0,
        'existing' => 0,
        'failed' => 0,
        'total_size' => 0
    ];
    
    try {
        // Skip if unit has a status (sold, etc.)
        if(isset($part['UnitStatus']) && $part['UnitStatus'] != ''){
            error_log("motors_process_image_if_no_thumbnail: Skipping post_id $post_id (stock: $stock_number) - Unit status is: {$part['UnitStatus']}");
            return;
        }
        
        // Check if the post already has a featured image (_thumbnail_id)
        $existing_thumbnail_id = get_post_meta($post_id, '_thumbnail_id', true);
        if ($existing_thumbnail_id) {
            error_log("motors_process_image_if_no_thumbnail: Post $post_id already has a _thumbnail_id ($existing_thumbnail_id), skipping image processing.");
            ls_motors_log([
                'message' => 'Skipped - post already has thumbnail',
                'post_id' => $post_id,
                'stock_number' => $stock_number,
                'existing_thumbnail_id' => $existing_thumbnail_id
            ], $run_id);
            return;
        }

        error_log("motors_process_image_if_no_thumbnail: Start processing images for post_id: $post_id, stock number: $stock_number, run_id: $run_id");

        // Remove old gallery just in case
        delete_post_meta($post_id, 'gallery');
        delete_post_meta($post_id, '_thumbnail_id');
        delete_post_thumbnail($post_id);

        $gallery_ids = [];
        $primary_attachment_id = null;
        $first_attachment_id = null;

        if (empty($part['Images']) || !is_array($part['Images'])) {
            error_log("motors_process_image_if_no_thumbnail: No images found for stock number: $stock_number");
            ls_motors_log([
                'message' => 'No images available for processing',
                'post_id' => $post_id,
                'stock_number' => $stock_number
            ], $run_id);
            return;
        }
        
        $image_stats['total_images'] = count($part['Images']);
        error_log("motors_process_image_if_no_thumbnail: Found {$image_stats['total_images']} images for stock number: $stock_number");

        foreach ($part['Images'] as $index => $image) {
            if (empty($image['ImageUrl'])) {
                error_log("motors_process_image_if_no_thumbnail: Image #$index has empty URL for stock number: $stock_number");
                continue;
            }
            
            $image_url = $image['ImageUrl'];
            $is_primary = !empty($image['PrimaryImage']) && $image['PrimaryImage'] === true;
            
            error_log("motors_process_image_if_no_thumbnail: Processing image #$index" . ($is_primary ? " (PRIMARY)" : "") . ": $image_url for stock number: $stock_number");

            $attachment_id = null;
            $existing_by_meta = $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM $wpdb->posts p
                INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_ls_image_url' AND pm.meta_value = %s
                WHERE p.post_type = 'attachment' LIMIT 1",
                $image_url
            ) );
            if ( $existing_by_meta ) {
                $attachment_id = (int) $existing_by_meta;
            }
            if ( ! $attachment_id ) {
                $attachment_id = attachment_url_to_postid($image_url);
            }
            
            if ($attachment_id) {
                $image_stats['existing']++;
                error_log("motors_process_image_if_no_thumbnail: Image already exists with attachment_id: $attachment_id");
            } else {
                // Download and process new image
                try {
                    $context = stream_context_create([
                        'http' => [
                            'timeout' => 30, // Increased timeout
                            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                            'follow_location' => 1,
                            'max_redirects' => 3
                        ]
                    ]);
                    
                    $image_data = @file_get_contents($image_url, false, $context);
                    
                    if ($image_data === false) {
                        $error = error_get_last();
                        $image_stats['failed']++;
                        error_log("motors_process_image_if_no_thumbnail: Failed to download image #$index: $image_url. Error: " . ($error ? $error['message'] : 'Unknown error'));
                        ls_motors_log([
                            'error' => 'Failed to download image',
                            'post_id' => $post_id,
                            'stock_number' => $stock_number,
                            'image_index' => $index,
                            'image_url' => $image_url,
                            'error_detail' => $error ? $error['message'] : 'Unknown error'
                        ], $run_id);
                        continue;
                    }
                    
                    $image_size = strlen($image_data);
                    $image_stats['total_size'] += $image_size;
                    error_log("motors_process_image_if_no_thumbnail: Downloaded image #$index, size: " . round($image_size / 1024, 2) . " KB");

                    // Validate image data
                    if ($image_size < 100) {
                        $image_stats['failed']++;
                        error_log("motors_process_image_if_no_thumbnail: Image data too small, skipping: $image_url");
                        unset($image_data);
                        continue;
                    }

                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_buffer($finfo, $image_data);
                    finfo_close($finfo);

                    $mime_to_ext = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        'image/jpg' => 'jpg'
                    ];
                    
                    if (!isset($mime_to_ext[$mime_type])) {
                        $image_stats['failed']++;
                        error_log("motors_process_image_if_no_thumbnail: Unsupported MIME type '$mime_type' for image: $image_url");
                        unset($image_data);
                        continue;
                    }
                    
                    $ext = $mime_to_ext[$mime_type];
                    $basename = basename(parse_url($image_url, PHP_URL_PATH));
                    $basename = preg_replace('/\.[^.]+$/', '', $basename);
                    $filename = sanitize_file_name($basename) . '_' . $index . '_' . time() . '.' . $ext;

                    $upload_dir = wp_upload_dir();
                    
                    if (isset($upload_dir['error']) && $upload_dir['error']) {
                        $image_stats['failed']++;
                        error_log("motors_process_image_if_no_thumbnail: Upload directory error: {$upload_dir['error']}");
                        unset($image_data);
                        continue;
                    }
                    
                    $file_path = $upload_dir['path'] . '/' . $filename;
                    
                    $bytes_written = @file_put_contents($file_path, $image_data);
                    
                    if ($bytes_written === false) {
                        $image_stats['failed']++;
                        error_log("motors_process_image_if_no_thumbnail: Failed to save image to $file_path");
                        unset($image_data);
                        continue;
                    }
                    
                    unset($image_data); // Free memory
                    
                    error_log("motors_process_image_if_no_thumbnail: Saved image to $file_path (" . round($bytes_written / 1024, 2) . " KB)");

                    $file_type = wp_check_filetype($filename, null);
                    $attachment = [
                        'post_mime_type' => $file_type['type'],
                        'post_title'     => sanitize_file_name($basename),
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                    ];

                    $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);
                    
                    if (is_wp_error($attachment_id)) {
                        $image_stats['failed']++;
                        error_log("motors_process_image_if_no_thumbnail: Failed to insert attachment: " . $attachment_id->get_error_message());
                        @unlink($file_path);
                        continue;
                    }

                    update_post_meta( $attachment_id, '_ls_image_url', $image_url );

                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
                    
                    if (is_wp_error($attachment_data)) {
                        error_log("motors_process_image_if_no_thumbnail: Failed to generate metadata for attachment_id: $attachment_id, error: " . $attachment_data->get_error_message());
                    } elseif (empty($attachment_data)) {
                        error_log("motors_process_image_if_no_thumbnail: Warning - metadata is empty for attachment_id: $attachment_id");
                    } else {
                        wp_update_attachment_metadata($attachment_id, $attachment_data);
                    }

                    $image_stats['downloaded']++;
                    error_log("motors_process_image_if_no_thumbnail: Successfully created attachment_id: $attachment_id for stock number: $stock_number");
                    
                } catch (Throwable $e) {
                    $image_stats['failed']++;
                    error_log("motors_process_image_if_no_thumbnail: Exception processing image #$index - " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                    ls_motors_log([
                        'error' => 'Exception processing image',
                        'post_id' => $post_id,
                        'stock_number' => $stock_number,
                        'image_index' => $index,
                        'exception' => $e->getMessage()
                    ], $run_id);
                    continue;
                }
            }

            // Track attachment IDs
            if ($attachment_id) {
                if ($first_attachment_id === null) {
                    $first_attachment_id = $attachment_id;
                }
                if ($is_primary) {
                    $primary_attachment_id = $attachment_id;
                }
                $gallery_ids[] = $attachment_id;
            }
        }

        if ($primary_attachment_id === null && $first_attachment_id !== null) {
            $primary_attachment_id = $first_attachment_id;
            error_log("motors_process_image_if_no_thumbnail: No primary image designated, using first image: $first_attachment_id");
        }

        if ($primary_attachment_id) {
            set_post_thumbnail($post_id, $primary_attachment_id);
            update_post_meta($post_id, '_thumbnail_id', $primary_attachment_id);
            error_log("motors_process_image_if_no_thumbnail: Set featured image attachment_id: $primary_attachment_id for stock number: $stock_number");
        } else {
            error_log("motors_process_image_if_no_thumbnail: Warning - No featured image could be set for stock number: $stock_number");
        }

        $gallery_ids = array_diff($gallery_ids, [$primary_attachment_id]);
        if (!empty($gallery_ids)) {
            $gallery_ids = array_unique($gallery_ids);
            update_post_meta($post_id, 'gallery', $gallery_ids);
            error_log("motors_process_image_if_no_thumbnail: Set gallery with " . count($gallery_ids) . " images for stock number $stock_number");
        }

        $elapsed_time = round(microtime(true) - $start_time, 2);
        
        ls_motors_log([
            'message' => 'Completed image processing for post (if no thumbnail)',
            'post_id' => $post_id,
            'stock_number' => $stock_number,
            'stats' => $image_stats,
            'primary_attachment_id' => $primary_attachment_id,
            'gallery_count' => count($gallery_ids),
            'processing_time' => $elapsed_time . 's',
            'total_size_mb' => round($image_stats['total_size'] / 1024 / 1024, 2)
        ], $run_id);

        error_log("motors_process_image_if_no_thumbnail: Finished processing images for stock number: $stock_number in {$elapsed_time}s - Total: {$image_stats['total_images']}, Downloaded: {$image_stats['downloaded']}, Existing: {$image_stats['existing']}, Failed: {$image_stats['failed']}");
        
    } catch (Throwable $e) {
        $elapsed_time = round(microtime(true) - $start_time, 2);
        error_log("motors_process_image_if_no_thumbnail: CRITICAL Exception in " . $e->getFile() . " on line " . $e->getLine() .
            " for post_id $post_id, stock_number $stock_number, run_id $run_id: " . $e->getMessage());
        error_log("motors_process_image_if_no_thumbnail: Stack trace: " . $e->getTraceAsString());
        
        ls_motors_log([
            'error' => 'Critical exception in motors_process_image_if_no_thumbnail',
            'post_id' => $post_id,
            'stock_number' => $stock_number,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'processing_time' => $elapsed_time . 's',
            'stats' => $image_stats
        ], $run_id);
    }
}