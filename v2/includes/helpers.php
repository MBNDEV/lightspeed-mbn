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

/**
 * Delete Redirection rules whose source matches this post’s permalink path (`url` or `match_url`, non-regex only).
 * Uses {@see Red_Item::delete()} so {@see Red_Module::flush()} runs per group like the REST/UI delete flow.
 *
 * @param int $post_id Post ID (listings, product, etc.).
 */
function ls_v2_redirection_delete_source_for_post( $post_id ) {
    $post_id = (int) $post_id;
    if ( $post_id <= 0 || ! class_exists( 'Red_Item' ) ) {
        return;
    }
    $permalink = get_permalink( $post_id );
    if ( ! $permalink ) {
        return;
    }
    $path = wp_parse_url( $permalink, PHP_URL_PATH );
    if ( ! is_string( $path ) || $path === '' || $path === '/' ) {
        return;
    }
    $base     = untrailingslashit( $path );
    $variants = array_unique(
        array_filter(
            [
                $path,
                $base,
                trailingslashit( $base ),
            ]
        )
    );
    global $wpdb;
    $table = $wpdb->prefix . 'redirection_items';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return;
    }
    $ids = [];
    foreach ( $variants as $v ) {
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE ( url = %s OR match_url = %s ) AND regex = 0",
                $v,
                $v
            )
        );
        foreach ( (array) $rows as $rid ) {
            $ids[] = (int) $rid;
        }
    }
    $ids = array_unique( array_filter( $ids ) );
    foreach ( $ids as $id ) {
        $item = Red_Item::get_by_id( $id );
        if ( $item ) {
            $item->delete();
        }
    }
}
