<?php

namespace AK_Set\Models;


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
        return $this->post !== null && !empty($this->post->post_type) && $this->post->post_type === 'ak_set';
    }

    /**
     * Check if t-shirt collection is enabled for this set
     *
     * @return bool
     */
    public function has_tshirt() {
        return (bool)get_field('set_has_tshirt', $this->set_id);
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

    /**
     * Get assigned weekend product IDs.
     *
     * @return int[]
     */
    public function get_weekends() {
        $products = $this->get_weekend_products();
        return array_map(function($w) {
            return $w->get_id();
        }, $products);
    }

    /**
     * Get unit naming configuration for this set (presets or custom).
     *
     * @return array{singular: string, plural_few: string, plural_many: string}
     */
    public function get_unit_names(): array {
        $presets = [
            'weekend'  => ['singular' => 'weekend',  'plural_few' => 'weekendy',  'plural_many' => 'weekendów'],
            'zajecia'  => ['singular' => 'zajęcia',  'plural_few' => 'zajęcia',  'plural_many' => 'zajęć'],
            'modul'    => ['singular' => 'moduł',    'plural_few' => 'moduły',    'plural_many' => 'modułów'],
            'warsztat' => ['singular' => 'warsztat', 'plural_few' => 'warsztaty', 'plural_many' => 'warsztatów'],
            'zjazd'    => ['singular' => 'zjazd',    'plural_few' => 'zjazdy',    'plural_many' => 'zjazdów'],
            'trening'  => ['singular' => 'trening',  'plural_few' => 'treningi',  'plural_many' => 'treningów'],
            'dzien'    => ['singular' => 'dzień',    'plural_few' => 'dni',       'plural_many' => 'dni'],
            'termin'   => ['singular' => 'termin',   'plural_few' => 'terminy',   'plural_many' => 'terminów'],
        ];

        $unit_type = '';
        if (function_exists('get_field')) {
            try {
                $raw_type = get_field('set_unit_type', $this->set_id);
                $unit_type = is_scalar($raw_type) ? (string) $raw_type : '';
            } catch (\Throwable) {}
        }
        if (!$unit_type && function_exists('get_post_meta')) {
            try {
                $raw_type = get_post_meta($this->set_id, 'set_unit_type', true);
                $unit_type = is_scalar($raw_type) ? (string) $raw_type : '';
            } catch (\Throwable) {}
        }
        $clean_type = function_exists('sanitize_key') ? sanitize_key($unit_type ?: 'weekend') : strtolower(trim($unit_type ?: 'weekend'));

        if ($clean_type === 'custom') {
            $sing = '';
            $few  = '';
            $many = '';
            if (function_exists('get_field')) {
                try {
                    $raw_s = get_field('set_unit_singular', $this->set_id);
                    $raw_f = get_field('set_unit_plural_few', $this->set_id);
                    $raw_m = get_field('set_unit_plural_many', $this->set_id);
                    $sing = is_scalar($raw_s) ? trim((string) $raw_s) : '';
                    $few  = is_scalar($raw_f) ? trim((string) $raw_f) : '';
                    $many = is_scalar($raw_m) ? trim((string) $raw_m) : '';
                } catch (\Throwable) {}
            }
            if (!$sing && function_exists('get_post_meta')) {
                try {
                    $raw_s = get_post_meta($this->set_id, 'set_unit_singular', true);
                    $raw_f = get_post_meta($this->set_id, 'set_unit_plural_few', true);
                    $raw_m = get_post_meta($this->set_id, 'set_unit_plural_many', true);
                    $sing = is_scalar($raw_s) ? trim((string) $raw_s) : '';
                    $few  = is_scalar($raw_f) ? trim((string) $raw_f) : '';
                    $many = is_scalar($raw_m) ? trim((string) $raw_m) : '';
                } catch (\Throwable) {}
            }

            if ($sing !== '' || $few !== '' || $many !== '') {
                $default_sing = $sing ?: ($few ?: ($many ?: 'weekend'));
                return [
                    'singular'    => $sing ?: $default_sing,
                    'plural_few'  => $few  ?: $default_sing,
                    'plural_many' => $many ?: $default_sing,
                ];
            }
        }

        if (isset($presets[$clean_type])) {
            return $presets[$clean_type];
        }

        return $presets['weekend'];
    }

    /**
     * Get singular unit name (e.g. "weekend", "zajęcia", "moduł")
     */
    public function get_unit_name_singular(): string {
        return $this->get_unit_names()['singular'];
    }

    /**
     * Get plural-few unit name for 2-4 (e.g. "weekendy", "zajęcia", "moduły")
     */
    public function get_unit_name_plural_few(): string {
        return $this->get_unit_names()['plural_few'];
    }

    /**
     * Get plural-many unit name for 5+ / genitive (e.g. "weekendów", "zajęć", "modułów")
     */
    public function get_unit_name_plural_many(): string {
        return $this->get_unit_names()['plural_many'];
    }

    /**
     * Format package size count with proper grammatical unit declension.
     *
     * @param int $count Number of units (1..10)
     * @return string e.g. "1 weekend", "2 weekendy", "5 weekendów" OR "1 zajęcia", "2 zajęcia", "5 zajęć"
     */
    public function format_package_size(int $count): string {
        $units = $this->get_unit_names();
        if ($count === 1) {
            return sprintf('%d %s', $count, $units['singular']);
        }
        $rem10 = $count % 10;
        $rem100 = $count % 100;
        if ($rem10 >= 2 && $rem10 <= 4 && ($rem100 < 12 || $rem100 > 14)) {
            return sprintf('%d %s', $count, $units['plural_few']);
        }
        return sprintf('%d %s', $count, $units['plural_many']);
    }
}

