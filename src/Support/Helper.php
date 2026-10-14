<?php

namespace AK_Set\Support;

if (!defined('ABSPATH')) {
    exit;
}

class Helper
{
    /**
     * Generate a UUID v4 string
     *
     * @return string
     */
    public static function generate_uuid()
    {
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }

        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Generate ACF field key name for price grid
     * Format: price_{package_size}w_round{round}_{tier}
     * Example: price_3w_round1_g5
     *
     * @param int $package_size Number of weekends (1..10)
     * @param int $round Round number (1..3)
     * @param string $tier Tier ('ind', 'g5', 'g10')
     * @return string
     */
    public static function get_pricing_field_key($package_size, $round, $tier)
    {
        return sprintf('price_%dw_round%d_%s', (int)$package_size, (int)$round, \sanitize_key($tier));
    }

    /**
     * T-Shirt sizes available across frontend and admin
     */
    public const TSHIRT_SIZES = [
        'XS',
        'S',
        'M',
        'L',
        'XL',
        'XXL',
    ];

    /**
     * T-Shirt cuts available across frontend and admin
     */
    public const TSHIRT_CUT_MEN     = 'men';
    public const TSHIRT_CUT_WOMEN   = 'women';
    public const TSHIRT_CUT_DEFAULT = self::TSHIRT_CUT_MEN;

    /**
     * Get T-Shirt size options
     *
     * @return array<string, string> Map of size_value => size_label
     */
    public static function get_tshirt_sizes(): array
    {
        $sizes = [];
        foreach (self::TSHIRT_SIZES as $size) {
            $sizes[$size] = $size;
        }

        return (array) apply_filters('ak_set_tshirt_sizes', $sizes);
    }

    /**
     * Check if a given T-Shirt size is valid
     */
    public static function is_valid_tshirt_size(?string $size): bool
    {
        if ($size === null || $size === '') {
            return false;
        }
        return array_key_exists($size, self::get_tshirt_sizes());
    }

    /**
     * Get T-Shirt cut options
     *
     * @return array<string, string> Map of cut_value => cut_label
     */
    public static function get_tshirt_cuts(): array
    {
        $cuts = [
            self::TSHIRT_CUT_MEN   => __('Męska', 'ak-product-set'),
            self::TSHIRT_CUT_WOMEN => __('Damska', 'ak-product-set'),
        ];

        return (array) apply_filters('ak_set_tshirt_cuts', $cuts);
    }

    /**
     * Check if a given T-Shirt cut is valid
     */
    public static function is_valid_tshirt_cut(?string $cut): bool
    {
        if ($cut === null || $cut === '') {
            return false;
        }
        return array_key_exists($cut, self::get_tshirt_cuts());
    }

    /**
     * Get localized T-Shirt cut label
     *
     * @param bool $fallback_to_default If true and cut not found, falls back to default ('men')
     */
    public static function get_tshirt_cut_label(?string $cut, bool $fallback_to_default = true): string
    {
        $cuts = self::get_tshirt_cuts();

        if ($cut !== null && isset($cuts[$cut])) {
            return $cuts[$cut];
        }

        if ($fallback_to_default && isset($cuts[self::TSHIRT_CUT_DEFAULT])) {
            return $cuts[self::TSHIRT_CUT_DEFAULT];
        }

        return '';
    }

    /**
     * Format participant array for order item meta storage
     */
    public static function add_participant_meta_to_order_item(array $participants, \WC_Order_Item_Product $item)
    {
        if (empty($participants)) {
            return;
        }

        $item->add_meta_data('_ak_participants', $participants, true);

        $i = 1;
        foreach ($participants as $p) {
            if (empty($p['name'])) {
                continue;
            }
            $status_suffix = '';
            if (($p['status'] ?? 'active') === 'cancelled') {
                $status_suffix = ' [ANULOWANY]';
            } elseif (($p['status'] ?? 'active') === 'transferred') {
                $to_id = !empty($p['transferred_to_order']) ? ' #' . $p['transferred_to_order'] : '';
                $status_suffix = ' [PRZENIESIONY' . $to_id . ']';
            }
            $details = $p['name'] . $status_suffix;
            $contact = [];
            if (!empty($p['email'])) {
                $contact[] = $p['email'];
            }
            if (!empty($p['phone'])) {
                $contact[] = $p['phone'];
            }
            if (!empty($contact)) {
                $details .= ' [' . implode(', ', $contact) . ']';
            }
            if (!empty($p['tshirt_size'])) {
                $cut = self::get_tshirt_cut_label($p['tshirt_cut'] ?? '');
                $details .= ' — Koszulka: ' . $p['tshirt_size'] . ' [' . $cut . ']';
            }
            $item->add_meta_data(sprintf(__('Uczestnik %d', 'ak-product-set'), $i), $details, true);
            $i++;
        }
    }
}
