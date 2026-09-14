<?php

namespace AK_Set\Pricing;

use AK_Set\Models\Set_Model;
use AK_Set\Models\Weekend_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Round_Resolver {
    /**
     * Resolve active round (1, 2, or 3) for an individual Weekend_Model
     *
     * @param Weekend_Model|int $weekend
     * @param int|null $current_timestamp
     * @return int (1, 2, or 3)
     */
    public static function resolve_weekend_round($weekend, $current_timestamp = null) {
        if (is_numeric($weekend)) {
            $weekend = new Weekend_Model((int)$weekend);
        }

        if (!($weekend instanceof Weekend_Model)) {
            return 1;
        }

        return $weekend->get_current_round($current_timestamp);
    }

    /**
     * Resolve active round for a selection of weekends in a set (Variant A: Max Round)
     * If multiple weekends are selected, the package is priced according to the most
     * restrictive (maximum) round among all selected weekends.
     *
     * @param array $selected_weekend_ids
     * @param int|null $current_timestamp
     * @return int (1, 2, or 3)
     */
    public static function resolve_package_round(array $selected_weekend_ids, $current_timestamp = null) {
        if (empty($selected_weekend_ids)) {
            return 1;
        }

        $rounds = [];
        foreach ($selected_weekend_ids as $wid) {
            $weekend = new Weekend_Model((int)$wid);
            $rounds[] = self::resolve_weekend_round($weekend, $current_timestamp);
        }

        // Variant A: Highest/most advanced round dictates package price
        return !empty($rounds) ? max($rounds) : 1;
    }

    /**
     * Resolve active round (1, 2, or 3) for a given Set_Model or timestamp (legacy fallback)
     *
     * @param Set_Model $set
     * @param int|null $current_timestamp
     * @return int (1, 2, or 3)
     */
    public static function resolve_round(Set_Model $set, $current_timestamp = null) {
        if ($current_timestamp === null) {
            $current_timestamp = current_time('timestamp');
        }

        $r1_end = method_exists($set, 'get_round_1_end_date') ? $set->get_round_1_end_date() : null;
        $r2_end = method_exists($set, 'get_round_2_end_date') ? $set->get_round_2_end_date() : null;

        // If Round 1 end date is not set, Round 1 lasts forever.
        if (empty($r1_end)) {
            return 1;
        }

        $r1_ts = strtotime($r1_end);
        if ($r1_ts === false || $current_timestamp <= $r1_ts) {
            return 1;
        }

        // If we reach here, Round 1 has expired.
        // If Round 2 end date is not set, Round 2 lasts forever.
        if (empty($r2_end)) {
            return 2;
        }

        $r2_ts = strtotime($r2_end);
        if ($r2_ts === false || $current_timestamp <= $r2_ts) {
            return 2;
        }

        // If Round 2 has also expired, we are in Round 3.
        return 3;
    }
}
