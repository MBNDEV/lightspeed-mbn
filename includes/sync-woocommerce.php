<?php
// Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function ls_render_sync_woocommerce_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Fetch dealer data from the API.
    $dealer_data = ls_api_request_dealer();
    $part_data = isset( $_POST['sync_cmf'] ) ? ls_sync_parts_inventory( sanitize_text_field( $_POST['sync_cmf'] ) ) : null;
    $review = isset( $_POST['sync_review'] ) ? ls_sync_parts_inventory( sanitize_text_field( $_POST['sync_review'] ) ) : null;
    $newData = isset( $_POST['sync_new'] ) ? ls_sync_parts_inventory( sanitize_text_field( $_POST['sync_new'] ) ) : null;

    ?>
    <div class="wrap">
        <h1>Sync for WooCommerce</h1>

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
                        <!-- <th>Direct Connect</th>
                        <th>Direct Connect Date</th>
                        <th>Program Consent Date</th>
                        <th>Go Live Date</th> -->
                        <th>Actions</th>
                        <th>Auto Sync</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Retrieve the list of CMFs with auto sync enabled.
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
                                <!-- <td><?php echo esc_html( $dealer['DirectConnect'] ?? 'N/A' ); ?></td>
                                <td><?php echo esc_html( $dealer['DirectConnectDate'] ?? 'N/A' ); ?></td>
                                <td><?php echo esc_html( $dealer['ProgramConsentDate'] ?? 'N/A' ); ?></td>
                                <td><?php echo esc_html( $dealer['GoLiveDate'] ?? 'N/A' ); ?></td> -->
                                <td style="display: flex; gap: 10px;">
                                    <form method="post" action="">
                                        <input type="hidden" name="sync_cmf" value="<?php echo esc_attr( $cmf ); ?>">
                                        <button type="submit" name="sync_button" class="button">Sync</button>
                                    </form>
                                    <form method="post" action="">
                                        <input type="hidden" name="sync_review" value="<?php echo esc_attr( $cmf ); ?>">
                                        <button type="submit" name="review_button" class="button">Review</button>
                                    </form>
                                    
                                    <form method="post" action="">
                                        <input type="hidden" name="sync_new" value="<?php echo esc_attr( $cmf ); ?>">
                                        <button type="submit" name="new_button" class="button">Sync New</button>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" action="">
                                        <input type="hidden" name="auto_sync_cmf" value="<?php echo esc_attr( $cmf ); ?>">
                                        <label>
                                            <input type="checkbox" name="enable_auto_sync" value="1" <?php checked( $auto_sync_enabled, true ); ?>>
                                            Enable
                                        </label>
                                        <button type="submit" class="button">Save</button>
                                    </form>
                                </td>
                            </tr>
                    <?php
                        endforeach;
                    else:
                    ?>
                        <tr>
                            <td colspan="9">No data available.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>


        <?php 
            if ( $part_data && is_array( $part_data ) ): 
            $run_id = date( 'Y-m-d_H-i-s' );
            $synced_data = process_sync_woocommerce($part_data, $run_id);
        ?>
            <h2>CMF: <?php echo esc_html( sanitize_text_field( $_POST['sync_cmf'] ) ); ?></h2>
            <h3>Synced: <?php echo $synced_data['count']; ?></h3>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <td>#</td>
                        <td>WooCommerce ID</td>
                        <th>Part Number / SKU</th>
                        <th>Description / Prod Name</th>
                        <th>Category</th>
                        <!-- <th>On Hand</th> -->
                        <th>Avail</th>
                        <!-- <th>Cost</th> -->
                        <th>ActivePriceType</th>
                        <th>Retail</th>
                        <th>Current Active Price</th>
                        <!-- <th>Last Sold Date</th>
                        <th>Last Received Date</th> -->
                    </tr>
                </thead>
                <tbody>
                    <?php 
                     echo $synced_data['rows'];
                    ?>
                </tbody>
            </table>
        <?php elseif ( isset( $_POST['sync_cmf'] ) ): ?>
            <div class="notice notice-error">
                <p>Failed to fetch parts data for CMF: <?php echo esc_html( sanitize_text_field( $_POST['sync_cmf'] ) ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( $newData && is_array( $newData ) ):
            $run_id = date( 'Y-m-d_H-i-s' );
            $synced_data = process_sync_new_woocommerce($newData, $run_id);
        ?>
            <h2>CMF: <?php echo esc_html( sanitize_text_field( $_POST['sync_new'] ) ); ?></h2>
            <h3>Synced: <?php echo $synced_data['count']; ?></h3>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <td>#</td>
                        <td>WooCommerce ID</td>
                        <th>Part Number / SKU</th>
                        <th>Description / Prod Name</th>
                        <th>Category</th>
                        <!-- <th>On Hand</th> -->
                        <th>Avail</th>
                        <!-- <th>Cost</th> -->
                        <th>ActivePriceType</th>
                        <th>Retail</th>
                        <th>Current Active Price</th>
                        <!-- <th>Last Sold Date</th> -->
                        <!-- <th>Last Received Date</th> -->
                    </tr>
                </thead>
                <tbody>
                    <?php 
                     echo $synced_data['rows'];
                    ?>
                </tbody>
            </table>
        <?php elseif ( isset( $_POST['sync_new'] ) ): ?>
            <div class="notice notice-error">
                <p>Failed to fetch parts data for CMF: <?php echo esc_html( sanitize_text_field( $_POST['sync_new'] ) ); ?></p>
            </div>
        <?php endif; ?>


        <?php 
            if ( $review && is_array( $review ) ): 
            $reviewData = sync_review_woocommerce($review);
        ?>
            <h2>CMF: <?php echo esc_html( sanitize_text_field( $_POST['sync_review'] ) ); ?></h2>
            <h3>Synced <?php echo $reviewData['count']; ?></h3>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <td>#</td>
                        <td>WooCommerce ID</td>
                        <th>Part Number / SKU</th>
                        <th>Description / Name</th>
                        <th>Category</th>
                        <th>On Hand</th>
                        <th>Avail</th>
                        <th>ActivePriceType</th>
                        <th>Retail</th>
                        <th>Current Active Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    echo $reviewData['rows'];
                    ?>
                </tbody>
            </table>
        <?php elseif ( isset( $_POST['sync_review'] ) ): ?>
            <div class="notice notice-error">
                <p>Failed to fetch parts data for CMF: <?php echo esc_html( sanitize_text_field( $_POST['sync_cmf'] ) ); ?></p>
            </div>
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

    add_action( 'admin_notices', function() use ( $cmf, $enable_auto_sync ) {
        echo '<div class="notice notice-success"><p>Auto Sync ' . ( $enable_auto_sync ? 'enabled' : 'disabled' ) . ' for CMF: ' . esc_html( $cmf ) . '</p></div>';
    } );
}
