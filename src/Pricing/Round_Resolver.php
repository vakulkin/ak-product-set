<?php

namespace AK_Set\Pricing;

use AK_Set\Models\Set_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Round_Resolver {
    /**
     * Resolve active round (1, 2, or 3) for a given Set_Model or timestamp
     *
     * @param Set_Model $set
     * @param int|null $current_timestamp
     * @return int (1, 2, or 3)
     */
    public static function resolve_round(Set_Model $set, $current_timestamp = null) {
        if ($current_timestamp === null) {
            $current_timestamp = current_time('timestamp');
        }

        $r1_end = $set->get_round_1_end_date();
        $r2_end = $set->get_round_2_end_date();

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
