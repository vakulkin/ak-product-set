<?php

namespace AK_Set\Models;

if (!defined('ABSPATH')) {
    exit;
}

class Weekend_Model {
    /** @var int */
    private $product_id;

    /** @var \WC_Product|null */
    private $product;

    /**
     * @param int|\WC_Product $product
     */
    public function __construct($product) {
        if (is_numeric($product)) {
            $this->product_id = (int)$product;
            $this->product = wc_get_product($this->product_id);
        } elseif ($product instanceof \WC_Product) {
            $this->product = $product;
            $this->product_id = $product->get_id();
        }
    }

    public function get_id() {
        return $this->product_id;
    }

    public function get_title() {
        return $this->product ? $this->product->get_title() : '';
    }

    public function get_wc_product() {
        return $this->product;
    }

    /**
     * Get product image URL (square thumbnail)
     *
     * @param string $size Image size, default 'woocommerce_thumbnail'
     * @return string
     */
    public function get_image_url($size = 'woocommerce_thumbnail')
    {
        if (!$this->product) {
            return '';
        }

        $image_id = $this->product->get_image_id();

        return $image_id
            ? (string) (wp_get_attachment_image_url($image_id, $size) ?: '')
            : '';
    }

    /**
     * Get main product description
     *
     * @return string
     */
    public function get_description() {
        return $this->product ? $this->product->get_description() : '';
    }

    /**
     * Check if product manages stock
     *
     * @return bool
     */
    public function managing_stock() {
        return $this->product ? $this->product->managing_stock() : false;
    }

    /**
     * Get stock quantity available for this weekend product.
     * Returns null if stock management is disabled (unlimited).
     * Returns int if stock management is enabled.
     *
     * @return int|null
     */
    public function get_stock_quantity() {
        if (!$this->product) {
            return 0;
        }

        if ($this->product->managing_stock()) {
            $stock = $this->product->get_stock_quantity();
            return is_null($stock) ? null : (int)$stock;
        }

        return null;
    }

    public function get_event_start_datetime() {
        return get_field('ak_event_start_datetime', $this->product_id);
    }

    public function get_event_end_datetime() {
        return get_field('ak_event_end_datetime', $this->product_id);
    }

    public function get_recruitment_start_datetime() {
        return get_field('ak_recruitment_start_datetime', $this->product_id);
    }

    public function get_recruitment_end_datetime() {
        return get_field('ak_recruitment_end_datetime', $this->product_id);
    }

    public function get_event_location() {
        return get_field('ak_event_location', $this->product_id);
    }

    public function get_round_1_end_datetime() {
        return get_field('ak_round_1_end_datetime', $this->product_id);
    }

    public function get_round_2_end_datetime() {
        return get_field('ak_round_2_end_datetime', $this->product_id);
    }

    public function get_round_3_end_datetime() {
        return get_field('ak_round_3_end_datetime', $this->product_id);
    }

    /**
     * Resolve active round (1, 2, or 3) for this weekend product.
     *
     * @param int|null $current_timestamp
     * @return int (1, 2, or 3)
     */
    public function get_current_round($current_timestamp = null) {
        if ($current_timestamp === null) {
            $current_timestamp = current_time('timestamp');
        }

        $r1_end = $this->get_round_1_end_datetime();
        $r2_end = $this->get_round_2_end_datetime();

        // If Round 1 end date is not set, Round 1 lasts forever.
        if (empty($r1_end)) {
            return 1;
        }

        $r1_ts = strtotime($r1_end);
        if ($r1_ts === false || $current_timestamp <= $r1_ts) {
            return 1;
        }

        // Round 1 has expired.
        // If Round 2 end date is not set, Round 2 lasts forever.
        if (empty($r2_end)) {
            return 2;
        }

        $r2_ts = strtotime($r2_end);
        if ($r2_ts === false || $current_timestamp <= $r2_ts) {
            return 2;
        }

        // Round 2 has expired -> Round 3.
        return 3;
    }

    /**
     * Check if sales/recruitment is expired for this weekend.
     *
     * @return bool
     */
    public function is_expired() {
        $end_datetime = $this->get_recruitment_end_datetime();
        if (empty($end_datetime)) {
            return false;
        }

        $end_ts = strtotime($end_datetime);
        return $end_ts !== false && current_time('timestamp') > $end_ts;
    }
}
