<?php

namespace AK_Set\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

class Catalog_Excluder {

    public function init() {
        add_action('woocommerce_product_query', [$this, 'exclude_set_products_from_catalog']);
        add_action('template_redirect', [$this, 'redirect_single_set_product_pages']);
    }

    /**
     * Collect all weekend product IDs assigned to any active ak_set CPT post
     *
     * @return array
     */
    private function get_all_assigned_weekend_ids() {
        static $assigned_ids = null;
        if ($assigned_ids !== null) {
            return $assigned_ids;
        }

        $assigned_ids = [];
        $sets = get_posts([
            'post_type'      => 'ak_set',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);

        foreach ($sets as $set_id) {
            $products = get_field('set_products', $set_id);
            if (!empty($products) && is_array($products)) {
                foreach ($products as $p) {
                    $id = is_object($p) ? $p->ID : (int)$p;
                    if ($id > 0) {
                        $assigned_ids[] = $id;
                    }
                }
            }
        }

        $assigned_ids = array_unique($assigned_ids);
        return $assigned_ids;
    }

    /**
     * Exclude weekend products from main shop catalog loop & search queries
     *
     * @param \WP_Query $query
     */
    public function exclude_set_products_from_catalog($query) {
        if (is_admin()) {
            return;
        }

        $assigned_ids = $this->get_all_assigned_weekend_ids();
        if (empty($assigned_ids)) {
            return;
        }

        $post__not_in = $query->get('post__not_in');
        if (!is_array($post__not_in)) {
            $post__not_in = [];
        }

        $query->set('post__not_in', array_merge($post__not_in, $assigned_ids));
    }

    /**
     * Redirect direct visits to single weekend product pages
     */
    public function redirect_single_set_product_pages() {
        if (!is_singular('product')) {
            return;
        }

        $product_id = get_queried_object_id();
        $assigned_ids = $this->get_all_assigned_weekend_ids();

        if (in_array($product_id, $assigned_ids, true)) {
            // Find which set contains this weekend product
            $sets = get_posts([
                'post_type'      => 'ak_set',
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'meta_query'     => [
                    [
                        'key'     => 'set_products',
                        'value'   => '"' . $product_id . '"',
                        'compare' => 'LIKE',
                    ],
                ],
            ]);

            if (!empty($sets)) {
                $target_url = get_permalink($sets[0]->ID);
                wp_safe_redirect($target_url, 301);
                exit;
            } else {
                wp_safe_redirect(wc_get_page_permalink('shop'), 302);
                exit;
            }
        }
    }
}
