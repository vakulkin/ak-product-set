<?php

namespace AK_Set\Support;

if (!defined('ABSPATH')) {
    exit;
}

class NIP_Validator {

    /**
     * Clean NIP string from spaces, hyphens, and optional country code (PL).
     *
     * @return string 10-digit string or empty
     */
    public static function clean(string $nip): string {
        $nip = strtoupper(trim($nip));
        if (str_starts_with($nip, 'PL')) {
            $nip = substr($nip, 2);
        }
        return preg_replace('/[^0-9]/', '', $nip) ?? '';
    }

    /**
     * Validate Polish NIP checksum using modulo 11 algorithm.
     * Weights: 6, 5, 7, 2, 3, 4, 5, 6, 7.
     */
    public static function is_valid(string $nip): bool {
        $nip = self::clean($nip);

        if (strlen($nip) !== 10) {
            return false;
        }

        // Check if not all digits are identical (e.g. 0000000000)
        if (preg_match('/^(\d)\1{9}$/', $nip)) {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $nip[$i] * $weights[$i];
        }

        $control_digit = $sum % 11;
        if ($control_digit === 10) {
            return false;
        }

        return $control_digit === (int) $nip[9];
    }
}
