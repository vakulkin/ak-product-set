<?php

namespace AK_Set\Pricing;

use AK_Set\Models\Set_Model;
use AK_Set\Models\Weekend_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Pricing_Engine {

    /**
     * Determine group headcount tier based on headcount
     *
     * @param int $headcount
     * @return string 'ind', 'g5', or 'g10'
     */
    public static function resolve_group_tier($headcount) {
        $headcount = max(1, (int)$headcount);

        if ($headcount < 5) {
            return 'ind';
        }
        if ($headcount <= 9) {
            return 'g5';
        }
        return 'g10';
    }

    /**
     * Compute dynamic maximum headcount limit for a selection of weekend products
     * Formula: MIN(stock(p)) across all managed stock products in selection.
     * Unmanaged products (stock null) are ignored.
     *
     * @param array $selected_weekend_ids
     * @return int|null null if unmanaged stock (unlimited), integer if stock managed
     */
    public static function get_max_headcount_limit(array $selected_weekend_ids) {
        if (empty($selected_weekend_ids)) {
            return null;
        }

        $min_stock = null;

        foreach ($selected_weekend_ids as $p_id) {
            $w = new Weekend_Model((int)$p_id);

            // Check if product is managed and has stock quantity set
            if ($w->managing_stock()) {
                $stock = $w->get_stock_quantity();
                if ($stock !== null) {
                    if ($min_stock === null || $stock < $min_stock) {
                        $min_stock = (int)$stock;
                    }
                }
            }
        }

        return $min_stock !== null ? max(0, $min_stock) : null;
    }

    /**
     * Calculate 3D dynamic set price purely on the server side
     *
     * @param int $set_id
     * @param array $selected_weekend_ids
     * @param int $requested_headcount
     * @return array Calculation result payload
     */
    public static function calculate($set_id, array $selected_weekend_ids, $requested_headcount) {
        $set = new Set_Model((int)$set_id);

        if (!$set->exists()) {
            return [
                'valid' => false,
                'error' => __('Zestaw nie istnieje.', 'ak-product-set'),
            ];
        }

        // Security: Ensure selected weekends actually belong to this set
        $allowed_weekend_ids = array_map(function($w) { return $w->get_id(); }, $set->get_weekend_products());
        $selected_weekend_ids = array_intersect($selected_weekend_ids, $allowed_weekend_ids);

        $package_size = count($selected_weekend_ids);
        if ($package_size < 1 || $package_size > 10) {
            return [
                'valid' => false,
                'error' => __('Liczba wybranych weekendów musi wynosić od 1 do 10. Nieprawidłowe weekendy mogły zostać odrzucone.', 'ak-product-set'),
            ];
        }

        $headcount = max(1, (int)$requested_headcount);

        // Server-side Round & Group Tier Resolution
        $round = Round_Resolver::resolve_round($set);
        $tier = self::resolve_group_tier($headcount);

        // Look up per-person unit price from 3D Set Matrix
        $per_person_price = (float)$set->get_price_by_matrix($package_size, $round, $tier);
        $total_price = $per_person_price * $headcount;

        if ($per_person_price <= 0 || $total_price <= 0) {
            return [
                'valid' => false,
                'error' => __('Cena dla wybranego pakietu i liczby osób nie została ustalona lub wynosi 0 zł. Zestaw nie może zostać dodany do koszyka.', 'ak-product-set'),
            ];
        }

        $max_headcount = self::get_max_headcount_limit($selected_weekend_ids);
        $stock_clamped = false;
        $stock_warning = '';

        if ($max_headcount !== null && $headcount > $max_headcount) {
            $stock_clamped = true;
            $stock_warning = sprintf(
                __('Dostępność miejsc uległa zmianie. Liczba dostępnych miejsc: %d os.', 'ak-product-set'),
                $max_headcount
            );
        }

        return [
            'valid'               => true,
            'set_id'              => $set->get_id(),
            'package_size'        => $package_size,
            'round'               => $round,
            'tier'                => $tier,
            'headcount'           => $headcount,
            'requested_headcount' => $headcount,
            'max_headcount'       => $max_headcount,
            'stock_clamped'       => $stock_clamped,
            'stock_warning'       => $stock_warning,
            'per_person_price'    => (float)$per_person_price,
            'total_price'         => (float)$total_price,
        ];
    }
}
