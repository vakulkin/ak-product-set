<?php

namespace AK_Set\Models;

use AK_Set\Support\Helper;

if (!defined('ABSPATH')) {
    exit;
}

class Set_Model {
    /** @var int */
    private $set_id;

    /** @var \WP_Post|null */
    private $post;

    public function __construct($set_post) {
        if (is_numeric($set_post)) {
            $this->set_id = (int)$set_post;
            $this->post = get_post($this->set_id);
        } elseif ($set_post instanceof \WP_Post) {
            $this->post = $set_post;
            $this->set_id = $set_post->ID;
        }
    }

    public function get_id() {
        return $this->set_id;
    }

    public function get_title() {
        return $this->post ? $this->post->post_title : '';
    }

    /**
     * Check if post is a valid ak_set
     *
     * @return bool
     */
    public function exists() {
        return $this->post !== null && $this->post->post_type === 'ak_set';
    }

    /**
     * Check if t-shirt collection is enabled for this set
     *
     * @return bool
     */
    public function has_tshirt() {
        return (bool)get_field('set_has_tshirt', $this->set_id);
    }

    public function get_round_1_end_date() {
        return get_field('round_1_end_date', $this->set_id);
    }

    public function get_round_2_end_date() {
        return get_field('round_2_end_date', $this->set_id);
    }

    /**
     * Get 3D pricing field value from ACF matrix on ak_set post
     *
     * @param int $package_size (1..10)
     * @param int $round (1..3)
     * @param string $tier ('ind', 'g5', 'g10')
     * @return float
     */
    public function get_price_by_matrix($package_size, $round, $tier) {
        $n = (int)$package_size;
        $t = \sanitize_key($tier);

        // Rounds to try in order: requested round, then fallback to round 1 if requested round has no price set
        $rounds_to_try = [(int)$round];
        if ((int)$round !== 1) {
            if ((int)$round === 3) {
                $rounds_to_try[] = 2;
            }
            $rounds_to_try[] = 1;
        }

        foreach ($rounds_to_try as $r) {
            $candidates = [
                sprintf('price_%dw_round%d_%s',   $n, $r, $t),
                sprintf('price_%dw_round_%d_%s',  $n, $r, $t),
                sprintf('price_%del_round%d_%s',  $n, $r, $t),
                sprintf('price_%del_round_%d_%s', $n, $r, $t),
                sprintf('price_%d_round%d_%s',    $n, $r, $t),
                sprintf('price_%d_round_%d_%s',   $n, $r, $t),
            ];

            foreach ($candidates as $key) {
                $val = get_post_meta($this->set_id, $key, true);
                if ($val !== null && $val !== false && $val !== '') {
                    $float_val = (float)str_replace(',', '.', $val);
                    if ($float_val > 0) {
                        return $float_val;
                    }
                }
            }
        }

        return 0.0;
    }

    /**
     * Get assigned weekend products as Weekend_Model instances
     *
     * @return Weekend_Model[]
     */
    public function get_weekend_products() {
        $product_ids = get_field('set_products', $this->set_id);

        if (empty($product_ids) || !is_array($product_ids)) {
            return [];
        }

        $weekends = [];
        foreach ($product_ids as $p) {
            $id = is_object($p) ? $p->ID : (int)$p;
            $model = new Weekend_Model($id);
            if ($model->get_wc_product()) {
                $weekends[] = $model;
            }
        }

        return $weekends;
    }
}
