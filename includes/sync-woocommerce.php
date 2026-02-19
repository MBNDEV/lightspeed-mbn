<?php
// Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sync for WooCommerce: chunked by request (LS_SYNC_CHUNK_SIZE), time-capped (LS_SYNC_MAX_SEC_PER_REQUEST),
 * state in ls_sync_woo_state. Same pattern as Motor Listings sync. Process functions (process_sync_woocommerce, etc.)
 * remain for backward compatibility and accept full arrays.
 */
function ls_render_sync_woocommerce_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $dealer_data = ls_api_request_dealer();
    $chunk_size = LS_SYNC_CHUNK_SIZE;
    $max_sec    = LS_SYNC_MAX_SEC_PER_REQUEST;
    $state_key  = 'ls_sync_woo_state';

    $woo_sync_disabled = empty( get_option( 'ls_auto_sync', [] ) );
    if ( $woo_sync_disabled ) {
        delete_option( $state_key );
    }

    $woo_synced_data   = null;
    $woo_result_cmf    = null;
    $woo_result_action = null;
    $woo_show_next     = false;
    $woo_run_id        = null;
    $woo_total         = 0;
    $woo_error         = null;
    $woo_sync_disabled_notice = false;

    if ( isset( $_POST['sync_new'] ) ) {
        delete_option( $state_key );
    }

    $is_next = ! empty( $_POST['sync_next_page'] );
    $cmf = null;
    $action = null;
    if ( isset( $_POST['sync_cmf'] ) ) {
        $cmf = sanitize_text_field( $_POST['sync_cmf'] );
        $action = 'sync';
    } elseif ( isset( $_POST['sync_review'] ) ) {
        $cmf = sanitize_text_field( $_POST['sync_review'] );
        $action = 'review';
    } elseif ( isset( $_POST['sync_new'] ) ) {
        $cmf = sanitize_text_field( $_POST['sync_new'] );
        $action = 'sync_new';
    }

    if ( $woo_sync_disabled && ( $cmf !== null || $is_next ) ) {
        $cmf = null;
        $woo_sync_disabled_notice = true;
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

        $data = ls_sync_parts_inventory_page( $cmf, $cursor, $chunk_size, $run_id );

        if ( is_string( $data ) ) {
            $woo_error = $data;
            delete_option( $state_key );
        } else {
            $parts = is_array( $data ) ? $data : ( isset( $data['value'] ) && is_array( $data['value'] ) ? $data['value'] : [] );
            $start_time = time();
            $row_html = '';
            $count = 0;
            $item_num = 0;
            $processed_this_chunk = 0;

            foreach ( $parts as $part ) {
                if ( ( time() - $start_time ) >= $max_sec ) {
                    break;
                }
                if ( $action === 'sync' ) {
                    $product_id = sync_part_to_woocommerce( $part, $run_id );
                } elseif ( $action === 'sync_new' ) {
                    $product_id = sync_new_part_to_woocommerce( $part, $run_id );
                } else {
                    $product_id = wc_get_product_id_by_sku( $part['PartNumber'] ?? '' );
                }
                if ( $product_id > 0 ) {
                    $count++;
                }
                $item_num++;
                $processed_this_chunk++;
                $row_html .= '<tr><td>' . $item_num . '</td><td>' . ( $product_id ?: 0 ) . '</td>';
                $row_html .= '<td>' . esc_html( $part['PartNumber'] ?? 'N/A' ) . '</td><td>' . esc_html( $part['Description'] ?? 'N/A' ) . '</td>';
                $row_html .= '<td>' . esc_html( $part['category'] ?? 'N/A' ) . '</td><td>' . esc_html( $part['Avail'] ?? 'N/A' ) . '</td>';
                $row_html .= '<td>' . esc_html( $part['activepricetype'] ?? 'N/A' ) . '</td><td>' . esc_html( $part['Retail'] ?? 'N/A' ) . '</td>';
                $row_html .= '<td>' . esc_html( $part['CurrentActivePrice'] ?? 'N/A' ) . '</td></tr>';
            }

            $total_processed += $processed_this_chunk;
            if ( function_exists( 'ls_sync_progress_log' ) ) {
                ls_sync_progress_log( $run_id, 'woo', $cmf, $cursor + $processed_this_chunk, $processed_this_chunk, $action, $total_processed );
            }

            $woo_synced_data = [ 'count' => $count . ' / ' . $processed_this_chunk, 'rows' => $row_html ];
            $woo_result_cmf = $cmf;
            $woo_result_action = $action;
            $woo_run_id = $run_id;
            $woo_total = $total_processed;

            $woo_show_next = ( count( $parts ) >= $chunk_size ) || ( $processed_this_chunk < count( $parts ) );
            if ( $woo_show_next && $processed_this_chunk > 0 ) {
                update_option( $state_key, [ 'cmf' => $cmf, 'action' => $action, 'run_id' => $run_id, 'cursor' => $cursor + $processed_this_chunk, 'total_processed' => $total_processed ] );
            } else {
                delete_option( $state_key );
                if ( $total_processed > 0 ) {
                    set_transient( 'ls_sync_woo_complete', [
                        'cmf'    => $woo_result_cmf,
                        'action' => $woo_result_action,
                        'total'  => $woo_total,
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
        <h1>Sync for WooCommerce</h1>

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

        <?php if ( $woo_sync_disabled ): ?>
            <div class="notice notice-warning">
                <p>Sync has been disabled. Enable Auto Sync for at least one CMF below to run sync.</p>
            </div>
        <?php endif; ?>
        <?php if ( $woo_sync_disabled_notice ): ?>
            <div class="notice notice-warning">
                <p>Sync has been disabled.</p>
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
                    $auto_sync_list = get_option( 'ls_auto_sync', [] );
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
                                    <?php if ( $woo_sync_disabled ): ?>
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
                                </td>
                                <td>
                                    <form method="post" action="">
                                        <input type="hidden" name="auto_sync_cmf" value="<?php echo esc_attr( $cmf ); ?>">
                                        <label><input type="checkbox" name="enable_auto_sync" value="1" <?php checked( $auto_sync_enabled, true ); ?>> Enable</label>
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

        <?php if ( $woo_error ): ?>
            <div class="notice notice-error"><p><?php echo esc_html( $woo_error ); ?></p></div>
        <?php elseif ( $woo_synced_data !== null && $woo_result_cmf !== null ): ?>
            <h2>CMF: <?php echo esc_html( $woo_result_cmf ); ?></h2>
            <h3><?php echo esc_html( $woo_result_action === 'sync_new' ? 'Sync New' : ( $woo_result_action === 'review' ? 'Review' : 'Sync' ) ); ?>: <?php echo esc_html( $woo_synced_data['count'] ); ?>
                <?php if ( $woo_show_next ): ?> (<?php echo (int) $woo_total; ?> so far — click Next to continue)<?php else: ?> (complete, <?php echo (int) $woo_total; ?> total)<?php endif; ?>
            </h3>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <td>#</td><td>WooCommerce ID</td><th>Part Number / SKU</th><th>Description / Prod Name</th><th>Category</th><th>Avail</th><th>ActivePriceType</th><th>Retail</th><th>Current Active Price</th>
                    </tr>
                </thead>
                <tbody><?php echo $woo_synced_data['rows']; ?></tbody>
            </table>
            <?php if ( $woo_show_next ): ?>
                <p><form method="post" action="" style="display:inline;">
                    <input type="hidden" name="<?php echo $woo_result_action === 'sync' ? 'sync_cmf' : ( $woo_result_action === 'review' ? 'sync_review' : 'sync_new' ); ?>" value="<?php echo esc_attr( $woo_result_cmf ); ?>">
                    <input type="hidden" name="sync_next_page" value="1">
                    <button type="submit" class="button button-primary">Next page</button>
                </form></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

function process_sync_woocommerce( $parts, $run_id ) {

    $count = 0;
    $row_html = '';
    $itemNum = 0;
    foreach ( $parts as $part ): 
        $product_id = sync_part_to_woocommerce($part, $run_id);
        if($product_id > 0) {
            $count += 1;
        }
        $itemNum += 1;
        $row_html .= '<tr>';
        $row_html .= '<td>' . $itemNum . '</td>';
        $row_html .= '<td>' . ( $product_id ?: 0 ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['PartNumber'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['Description'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['category'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['Avail'] ?? 'N/A' ) . '</td>';
        // $row_html .= '<td>' . esc_html( $part['Cost'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['activepricetype'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['Retail'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['CurrentActivePrice'] ?? 'N/A' ) . '</td>';
        // $row_html .= '<td>' . esc_html( $part['LastSoldDate'] ?? 'N/A' ) . '</td>';
        // $row_html .= '<td>' . esc_html( $part['LastReceivedDate'] ?? 'N/A' ) . '</td>';
        $row_html .= '</tr>';
    endforeach;


     if(function_exists("nitropack_sdk_purge")) {
          ls_motors_log([
        'message' => "Nitropack clear cache."
    ], $run_id);
        nitropack_sdk_purge( NULL, NULL, NULL);
    }
    
    return [
        'count' => $count." / ".count($parts),
        'rows'   => $row_html,
    ];

}

function process_sync_new_woocommerce( $parts, $run_id ) {

    $count = 0;
    $row_html = '';
    $itemNum = 0;
    foreach ( $parts as $part ): 
        $product_id = sync_new_part_to_woocommerce($part, $run_id);
        if($product_id > 0) {
            $count += 1;
        }
        $itemNum += 1;
        $row_html .= '<tr>';
        $row_html .= '<td>' . $itemNum . '</td>';
        $row_html .= '<td>' . ( $product_id ?: 0 ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['PartNumber'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['Description'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['category'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['Avail'] ?? 'N/A' ) . '</td>';
        // $row_html .= '<td>' . esc_html( $part['Cost'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['activepricetype'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['Retail'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['CurrentActivePrice'] ?? 'N/A' ) . '</td>';
        // $row_html .= '<td>' . esc_html( $part['LastSoldDate'] ?? 'N/A' ) . '</td>';
        // $row_html .= '<td>' . esc_html( $part['LastReceivedDate'] ?? 'N/A' ) . '</td>';
        $row_html .= '</tr>';
    endforeach;


     if(function_exists("nitropack_sdk_purge")) {
          ls_motors_log([
        'message' => "Nitropack clear cache."
    ], $run_id);
        nitropack_sdk_purge( NULL, NULL, NULL);
    }
    
    return [
        'count' => $count,
        'rows'   => $row_html,
    ];

}

function sync_part_to_woocommerce( $part, $run_id ) {
    // Check if the category exists, create it if not.
    $category_id = sync_get_or_create_category( $part['category'] );

    // Check if product exists by SKU.
    $product_id = wc_get_product_id_by_sku( $part['PartNumber'] );

    $name = $part['Description'] ?? '';
    $regular_price = $part['Retail'] ?? 0;
    $sale_price = 0;
    $stock = $part['Avail'] ?? 0;

    if ( $product_id ) {
        // Update existing product.
        $product = wc_get_product( $product_id );
        $product->set_name( $part['Description'] );
        $product->set_regular_price( $part['Retail'] );

        if($part['Retail'] != $part['CurrentActivePrice']) {
            if($part['activepricetype'] == "Sale") {
                $sale_price = $part['saleprice'];
                $product->set_sale_price( $part['saleprice'] );
            } else if($part['activepricetype'] == "Special1") {
                $sale_price = $part['specialprice1'];
                $product->set_sale_price( $part['specialprice1'] );
            } else if($part['activepricetype'] == "Special2") {
                $sale_price = $part['specialprice2'];
                $product->set_sale_price( $part['specialprice2'] );
            } else if($part['activepricetype'] == "Special3") {
                $sale_price = $part['specialprice3'];
                $product->set_sale_price( $part['specialprice3'] );
            }
        }
        $product->set_stock_quantity( $part['Avail'] );

        // Assign category.
        $product->set_category_ids( [ $category_id ] );

        $product->save();

        ls_log( [
            'action'       => 'update',
            'product_id'   => $product_id,
            'sku'          => $part['PartNumber'],
            'name'         => $name,
            'regular_price'=> $regular_price,
            'sale_price'   => $sale_price,
            'stock'        => $stock,
        ], $run_id );
    } else {
        // Create a new product.
        $product = new WC_Product_Simple();
        $product->set_name( $part['Description'] );
        $product->set_sku( $part['PartNumber'] );
        $product->set_regular_price( $part['Retail'] );

        if($part['Retail'] != $part['CurrentActivePrice']) {
            if($part['activepricetype'] == "Sale") {
                $sale_price = $part['saleprice'];
                $product->set_sale_price( $part['saleprice'] );
            } else if($part['activepricetype'] == "Special1") {
                $sale_price = $part['specialprice1'];
                $product->set_sale_price( $part['specialprice1'] );
            } else if($part['activepricetype'] == "Special2") {
                $sale_price = $part['specialprice2'];
                $product->set_sale_price( $part['specialprice2'] );
            } else if($part['activepricetype'] == "Special3") {
                $sale_price = $part['specialprice3'];
                $product->set_sale_price( $part['specialprice3'] );
            }
        }
        
        $product->set_stock_quantity( $part['Avail'] );
        $product->set_status( 'publish' );

        // Assign category.
        $product->set_category_ids( [ $category_id ] );

        $product->save();

        ls_log( [
            'action'       => 'create',
            'product_id'   => $product->get_id(),
            'sku'          => $part['PartNumber'],
            'name'         => $name,
            'regular_price'=> $regular_price,
            'sale_price'   => $sale_price,
            'stock'        => $stock,
        ], $run_id );
    }

    return $product->get_id();
}

function sync_new_part_to_woocommerce( $part, $run_id ) {
    // Check if the category exists, create it if not.
    $category_id = sync_get_or_create_category( $part['category'] );

    // Check if product exists by SKU.
    $product_id = wc_get_product_id_by_sku( $part['PartNumber'] );

    $name = $part['Description'] ?? '';
    $regular_price = $part['Retail'] ?? 0;
    $sale_price = 0;
    $stock = $part['Avail'] ?? 0;

    if ( $product_id ) {
        return 0;
    } else {
        // Create a new product.
        $product = new WC_Product_Simple();
        $product->set_name( $part['Description'] );
        $product->set_sku( $part['PartNumber'] );
        $product->set_regular_price( $part['Retail'] );

        if($part['Retail'] != $part['CurrentActivePrice']) {
            if($part['activepricetype'] == "Sale") {
                $sale_price = $part['saleprice'];
                $product->set_sale_price( $part['saleprice'] );
            } else if($part['activepricetype'] == "Special1") {
                $sale_price = $part['specialprice1'];
                $product->set_sale_price( $part['specialprice1'] );
            } else if($part['activepricetype'] == "Special2") {
                $sale_price = $part['specialprice2'];
                $product->set_sale_price( $part['specialprice2'] );
            } else if($part['activepricetype'] == "Special3") {
                $sale_price = $part['specialprice3'];
                $product->set_sale_price( $part['specialprice3'] );
            }
        }
        
        $product->set_stock_quantity( $part['Avail'] );
        $product->set_status( 'publish' );

        // Assign category.
        $product->set_category_ids( [ $category_id ] );

        $product->save();

        ls_log( [
            'action'       => 'create',
            'product_id'   => $product->get_id(),
            'sku'          => $part['PartNumber'],
            'name'         => $name,
            'regular_price'=> $regular_price,
            'sale_price'   => $sale_price,
            'stock'        => $stock,
        ], $run_id );
    }

    return $product->get_id();
}

function sync_review_woocommerce( $parts ) {

    $count = 0;
    $row_html = '';
    $itemNum = 0;
    foreach ( $parts as $part ): 
        $product_id = wc_get_product_id_by_sku( $part['PartNumber'] );
        if($product_id > 0) {
            $count += 1;
        }
        $itemNum += 1;
        $row_html .= '<tr>';
        $row_html .= '<td>' . $itemNum . '</td>';
        $row_html .= '<td>' . ( $product_id ?: 0 ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['PartNumber'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['Description'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['category'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['OnHand'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['Avail'] ?? 'N/A' ) . '</td>';
        // $row_html .= '<td>' . esc_html( $part['Cost'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['activepricetype'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['Retail'] ?? 'N/A' ) . '</td>';
        $row_html .= '<td>' . esc_html( $part['CurrentActivePrice'] ?? 'N/A' ) . '</td>';
        // $row_html .= '<td>' . esc_html( $part['LastSoldDate'] ?? 'N/A' ) . '</td>';
        // $row_html .= '<td>' . esc_html( $part['LastReceivedDate'] ?? 'N/A' ) . '</td>';
        $row_html .= '</tr>';
    endforeach;
    return [
        'count' => $count." / ".count($parts),
        'rows'   => $row_html,
    ];

}

// Helper function to get or create a WooCommerce category.
function sync_get_or_create_category( $category_name ) {
    if ( empty( $category_name ) ) {
        return 0; // Return 0 if no category is provided.
    }

    // Check if the category exists.
    $existing_category = get_term_by( 'name', $category_name, 'product_cat' );

    if ( $existing_category ) {
        return $existing_category->term_id;
    }

    // Create a new category if it doesn't exist.
    $new_category = wp_insert_term(
        $category_name,
        'product_cat', // Taxonomy for WooCommerce categories.
        [
            'slug' => sanitize_title( $category_name ),
        ]
    ); 

    if ( is_wp_error( $new_category ) ) {
        // Handle error during category creation.
        error_log( 'Error creating category: ' . $new_category->get_error_message() );
        return 0; // Return 0 if there was an error.
    }

    return $new_category['term_id'];
}

if ( isset( $_POST['auto_sync_cmf'] ) ) {
    $cmf = sanitize_text_field( $_POST['auto_sync_cmf'] );
    $enable_auto_sync = isset( $_POST['enable_auto_sync'] ) ? true : false;

    // Retrieve the current list of auto-sync CMFs.
    $auto_sync_list = get_option( 'ls_auto_sync', [] );

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
    update_option( 'ls_auto_sync', $auto_sync_list );
    if ( function_exists( 'ls_maybe_schedule_auto_sync_cron' ) ) {
        ls_maybe_schedule_auto_sync_cron();
    }

    add_action( 'admin_notices', function() use ( $cmf, $enable_auto_sync ) {
        echo '<div class="notice notice-success"><p>Auto Sync ' . ( $enable_auto_sync ? 'enabled' : 'disabled' ) . ' for CMF: ' . esc_html( $cmf ) . '</p></div>';
    } );
}
