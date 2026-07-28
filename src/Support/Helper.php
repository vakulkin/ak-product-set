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
     * Get T-Shirt size options
     *
     * @return array
     */
    public static function get_tshirt_sizes()
    {
        return [
            'XS'  => 'XS',
            'S'   => 'S',
            'M'   => 'M',
            'L'   => 'L',
            'XL'  => 'XL',
            'XXL' => 'XXL',
        ];
    }

    /**
     * Get T-Shirt cut options
     *
     * @return array
     */
    public static function get_tshirt_cuts()
    {
        return [
            'men'   => __('Męska', 'ak-product-set'),
            'women' => __('Damska', 'ak-product-set'),
        ];
    }

    /**
     * Format participant array for order item meta storage
     *
     * @param array $participants
     * @param \WC_Order_Item_Product $item
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
            $details = $p['name'];
            $contact = [];
            if (!empty($p['email'])) {
                $contact[] = $p['email'];
            }
            if (!empty($p['phone'])) {
                $contact[] = $p['phone'];
            }
            if (!empty($contact)) {
                $details .= ' (' . implode(', ', $contact) . ')';
            }
            if (!empty($p['tshirt_size'])) {
                $cut = (!empty($p['tshirt_cut']) && $p['tshirt_cut'] === 'women') ? __('Damska', 'ak-product-set') : __('Męska', 'ak-product-set');
                $details .= ' — Koszulka: ' . $p['tshirt_size'] . ' (' . $cut . ')';
            }
            $item->add_meta_data(sprintf(__('Uczestnik %d', 'ak-product-set'), $i), $details, true);
            $i++;
        }
    }
}
