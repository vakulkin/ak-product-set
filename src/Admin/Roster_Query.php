<?php

namespace AK_Set\Admin;

use AK_Set\Models\Set_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Roster_Query {

    /** @var array<int, string>|null */
    private static ?array $weekends_cache = null;

    /** @var array<int, array> */
    private static array $participants_cache = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Get weekend products that have at least one decomposed order item.
     * Sorted alphabetically by product title.
     *
     * @return array<int, string>  Map of product_id => product title
     */
    public static function get_weekends_for_selector(): array {
        if (self::$weekends_cache !== null) {
            return self::$weekends_cache;
        }

        $product_ids = self::fetch_weekend_product_ids_with_orders();

        $result = [];
        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if ($product) {
                $result[$pid] = $product->get_name();
            }
        }

        asort($result);
        self::$weekends_cache = $result;

        return $result;
    }

    /**
     * Get all participants for a given weekend product.
     *
     * Deduplicates by (order_id, name, email) because Order_Decomposer copies
     * _ak_participants into every decomposed item in the same order — so for a
     * 3-weekend booking the same participant list appears in 3 items.
     * When querying by product_id we already select only the item matching that
     * weekend, so each participant appears at most once per order.
     * The dedup guard handles the edge-case of duplicate items.
     *
     * @param int $product_id  Weekend WC product ID
     * @return array[]  Sorted alphabetically by participant name
     */
    public static function get_participants_by_weekend(int $product_id): array {
        if (array_key_exists($product_id, self::$participants_cache)) {
            return self::$participants_cache[$product_id];
        }

        $raw_items = self::fetch_order_items_for_weekend($product_id);

        if (empty($raw_items)) {
            self::$participants_cache[$product_id] = [];
            return [];
        }

        $item_ids  = array_column($raw_items, 'order_item_id');
        $order_ids = array_unique(array_column($raw_items, 'order_id'));

        $order_statuses = self::fetch_order_statuses($order_ids);
        $meta_by_item   = self::fetch_item_meta($item_ids);

        $rows = [];
        $seen = []; // dedup key: "item_id|participant_uuid"

        foreach ($raw_items as $item) {
            $item_id  = (int) $item->order_item_id;
            $order_id = (int) $item->order_id;

            $item_meta      = $meta_by_item[$item_id] ?? [];
            $order_info     = $order_statuses[$order_id] ?? [];
            $order_status   = $order_info['status']         ?? '';
            $date_paid      = $order_info['date_paid']      ?? '';
            $payment_method = $order_info['payment_method'] ?? '';

            $set_id   = isset($item_meta['_ak_parent_set_id']) ? (int) $item_meta['_ak_parent_set_id'] : 0;
            $set      = $set_id > 0 ? new Set_Model($set_id) : null;
            $set_name = $set ? $set->get_title() : '';

            $participants_raw = isset($item_meta['_ak_participants'])
                ? maybe_unserialize($item_meta['_ak_participants'])
                : [];

            if (!is_array($participants_raw)) {
                continue;
            }

            foreach ($participants_raw as $p) {
                $name  = isset($p['name'])  ? (string) $p['name']  : '';
                $email = isset($p['email']) ? (string) $p['email'] : '';

                // Primary key: participant UUID stored by Participant_Model.
                // UUID is unique per booking slot so two people with identical
                // names or emails in the same order are correctly distinguished.
                // Fallback: hash of (item_id, name, email) for records that
                // pre-date the UUID field.
                if (!empty($p['id'])) {
                    $participant_uuid = (string) $p['id'];
                } else {
                    $participant_uuid = md5($item_id . '|' . $name . '|' . $email);
                }

                // Dedup by (order_item_id, uuid) — prevents duplicate rows if
                // the same item appears twice in the query result (edge-case).
                $dedup_key = $item_id . '|' . $participant_uuid;
                if (isset($seen[$dedup_key])) {
                    continue;
                }
                $seen[$dedup_key] = true;

                $rows[] = [
                    'participant_id' => $participant_uuid,
                    'order_item_id'  => $item_id,
                    'order_id'       => $order_id,
                    'order_status'   => $order_status,
                    'date_paid'      => $date_paid,
                    'payment_method' => $payment_method,
                    'set_name'       => $set_name,
                    'name'           => $name,
                    'email'          => $email,
                    'phone'          => isset($p['phone'])       ? (string) $p['phone']       : '',
                    'tshirt_size'    => isset($p['tshirt_size']) ? (string) $p['tshirt_size'] : '',
                    'tshirt_cut'     => isset($p['tshirt_cut'])  ? (string) $p['tshirt_cut']  : '',
                ];
            }
        }

        usort($rows, static fn($a, $b) => strcmp($a['name'], $b['name']));

        self::$participants_cache[$product_id] = $rows;
        return $rows;
    }


    /**
     * Get every weekend with its participant list (for ZIP export).
     *
     * @return array[]  Each element: ['weekend_id', 'weekend_title', 'participants']
     */
    public static function get_all_weekends_with_participants(): array {
        $weekends = self::get_weekends_for_selector();
        $result   = [];

        foreach ($weekends as $pid => $title) {
            $result[] = [
                'weekend_id'    => $pid,
                'weekend_title' => $title,
                'participants'  => self::get_participants_by_weekend($pid),
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private DB helpers
    // -------------------------------------------------------------------------

    /**
     * Return distinct weekend product IDs present in at least one decomposed
     * order item (identified by presence of _ak_parent_set_id meta).
     *
     * HPOS-safe: order items are always in woocommerce_order_items /
     * woocommerce_order_itemmeta regardless of the order storage backend.
     *
     * @return int[]
     */
    private static function fetch_weekend_product_ids_with_orders(): array {
        global $wpdb;

        $results = $wpdb->get_col(
            "SELECT DISTINCT oim_pid.meta_value
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_set
                 ON oi.order_item_id = oim_set.order_item_id
                AND oim_set.meta_key = '_ak_parent_set_id'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
                 ON oi.order_item_id = oim_pid.order_item_id
                AND oim_pid.meta_key = '_product_id'
             WHERE oi.order_item_type = 'line_item'"
        );

        return array_map('intval', $results ?: []);
    }

    /**
     * Return (order_item_id, order_id) rows for all decomposed items of a
     * given weekend product.
     *
     * @param int $product_id
     * @return object[]
     */
    private static function fetch_order_items_for_weekend(int $product_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT oi.order_item_id, oi.order_id
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
                 ON oi.order_item_id = oim_pid.order_item_id
                AND oim_pid.meta_key = '_product_id'
                AND oim_pid.meta_value = %s
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_set
                 ON oi.order_item_id = oim_set.order_item_id
                AND oim_set.meta_key = '_ak_parent_set_id'
             WHERE oi.order_item_type = 'line_item'",
            $product_id
        )) ?: [];
    }

    /**
     * Batch-load order statuses, payment dates, and payment methods via the WC API (HPOS-safe).
     * Returns an array keyed by order_id, each value being:
     *   [ 'status' => string, 'date_paid' => string, 'payment_method' => string ]
     *
     * @param int[]|string[] $order_ids
     * @return array<int, array{status: string, date_paid: string, payment_method: string}>
     */
    private static function fetch_order_statuses(array $order_ids): array {
        $result = [];
        foreach ($order_ids as $oid) {
            $order = wc_get_order((int) $oid);
            if (!$order) {
                continue;
            }
            $date_paid_obj = $order->get_date_paid();
            $result[(int) $oid] = [
                'status'         => $order->get_status(),
                'date_paid'      => $date_paid_obj ? $date_paid_obj->date('Y-m-d') : '',
                'payment_method' => $order->get_payment_method_title(),
            ];
        }
        return $result;
    }

    /**
     * Batch-load _ak_parent_set_id and _ak_participants for a set of item IDs.
     * Uses intval-cast IDs in the IN clause — no injection risk.
     *
     * @param int[]|string[] $item_ids
     * @return array<int, array<string, string>>  Keyed by item_id
     */
    private static function fetch_item_meta(array $item_ids): array {
        if (empty($item_ids)) {
            return [];
        }

        global $wpdb;

        $in   = implode(',', array_map('intval', $item_ids));
        $rows = $wpdb->get_results(
            "SELECT order_item_id, meta_key, meta_value
             FROM {$wpdb->prefix}woocommerce_order_itemmeta
             WHERE order_item_id IN ($in)
               AND meta_key IN ('_ak_parent_set_id', '_ak_participants')"
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->order_item_id][$row->meta_key] = $row->meta_value;
        }

        return $grouped;
    }
}
