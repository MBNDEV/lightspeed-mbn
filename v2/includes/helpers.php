<?php
/**
 * Sync v2 helpers. Do not modify includes/; v2 uses these for listings lookup.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get listing post ID by stock_number (efficient single query).
 * Used by v2 for "already in DB" and change detection. Does not modify get_all_listings_posts_with_stocknumber() in includes.
 *
 * @param string $stock_number Stock number (meta_value for meta_key 'stock_number').
 * @return int|null Post ID or null if not found.
 */
function ls_get_listing_post_id_by_stock_number( $stock_number ) {
    global $wpdb;
    if ( empty( $stock_number ) ) {
        return null;
    }
    $post_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT p.ID FROM $wpdb->posts p
        INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = 'stock_number' AND pm.meta_value = %s
        WHERE p.post_type = 'listings'
        LIMIT 1",
        $stock_number
    ) );
    return $post_id ? (int) $post_id : null;
}
