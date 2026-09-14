<?php

namespace AK_Set\Pricing;

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
}
