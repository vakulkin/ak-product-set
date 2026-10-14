<?php

namespace AK_Set\Order;

use AK_Set\Support\Helper;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Participant_Profile_Service {

    /**
     * Update participant's personal data (name, email, phone, t-shirt size/cut) across all line items in an order.
     */
    public static function update_participant_data(\WC_Order $order, string $participant_uuid, array $data): array {
        $found = false;
        $participant_name = '';
        $old_data = [];
        $new_data = [];

        $has_name_change  = isset($data['name']) && trim((string) $data['name']) !== '';
        $has_email_change = isset($data['email']);
        $has_phone_change = isset($data['phone']);
        $has_size_change  = isset($data['tshirt_size']);
        $has_cut_change   = isset($data['tshirt_cut']);

        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $participants = $item->get_meta('_ak_participants');
            if (!is_array($participants)) {
                continue;
            }

            $modified = false;
            foreach ($participants as &$p) {
                if (!empty($p['id']) && $p['id'] === $participant_uuid) {
                    $found = true;
                    if (empty($old_data)) {
                        $old_data = [
                            'name'        => $p['name'] ?? '',
                            'email'       => $p['email'] ?? '',
                            'phone'       => $p['phone'] ?? '',
                            'tshirt_size' => $p['tshirt_size'] ?? '',
                            'tshirt_cut'  => $p['tshirt_cut'] ?? 'men',
                        ];
                    }

                    if ($has_name_change) {
                        $p['name'] = sanitize_text_field($data['name']);
                    }
                    if ($has_email_change) {
                        $p['email'] = sanitize_email($data['email']);
                    }
                    if ($has_phone_change) {
                        $p['phone'] = sanitize_text_field($data['phone']);
                    }
                    if ($has_size_change) {
                        $p['tshirt_size'] = sanitize_text_field($data['tshirt_size']);
                    }
                    if ($has_cut_change) {
                        $p['tshirt_cut'] = sanitize_text_field($data['tshirt_cut']);
                    }

                    $new_data = [
                        'name'        => $p['name'] ?? '',
                        'email'       => $p['email'] ?? '',
                        'phone'       => $p['phone'] ?? '',
                        'tshirt_size' => $p['tshirt_size'] ?? '',
                        'tshirt_cut'  => $p['tshirt_cut'] ?? '',
                    ];
                    $participant_name = $p['name'] ?? '';
                    $modified = true;
                }
            }
            unset($p);

            if ($modified) {
                $item->update_meta_data('_ak_participants', $participants);
                Helper::add_participant_meta_to_order_item($participants, $item);
            }
        }

        if (!$found) {
            return [
                'success' => false,
                'error'   => __('Uczestnik nie został znaleziony w tym zamówieniu.', 'ak-product-set'),
            ];
        }

        $changes = [];
        if (($old_data['name'] ?? '') !== ($new_data['name'] ?? '')) {
            $changes[] = sprintf('Imię: "%s" → "%s"', $old_data['name'] ?: '—', $new_data['name']);
        }
        if (($old_data['email'] ?? '') !== ($new_data['email'] ?? '')) {
            $changes[] = sprintf('E-mail: "%s" → "%s"', $old_data['email'] ?: '—', $new_data['email'] ?: '—');
        }
        if (($old_data['phone'] ?? '') !== ($new_data['phone'] ?? '')) {
            $changes[] = sprintf('Telefon: "%s" → "%s"', $old_data['phone'] ?: '—', $new_data['phone'] ?: '—');
        }
        if (($old_data['tshirt_size'] ?? '') !== ($new_data['tshirt_size'] ?? '') || ($old_data['tshirt_cut'] ?? '') !== ($new_data['tshirt_cut'] ?? '')) {
            $old_cut_label = Helper::get_tshirt_cut_label($old_data['tshirt_cut'] ?? '');
            $new_cut_label = Helper::get_tshirt_cut_label($new_data['tshirt_cut'] ?? '');
            $changes[] = sprintf(
                'Koszulka: %s [%s] → %s [%s]',
                $old_data['tshirt_size'] ?: '—',
                $old_cut_label,
                $new_data['tshirt_size'] ?: '—',
                $new_cut_label
            );
        }

        $tshirt_produced = !empty($data['tshirt_produced']);
        $tshirt_cost = 0.0;
        if ($tshirt_produced) {
            $set_id = (int) $order->get_meta('_ak_parent_set_id');
            $tshirt_cost = Order_Recalculator::get_tshirt_cost($set_id);
            $changes[] = sprintf(__('Doliczono koszt wyprodukowanej koszulki: +%s zł', 'ak-product-set'), number_format_i18n($tshirt_cost, 2));

            // Add produced t-shirt record to the first matching slot item found for this participant
            $slot_incremented = false;
            foreach ($order->get_items('line_item') as $item) {
                if (!($item instanceof \WC_Order_Item_Product)) {
                    continue;
                }
                $participants = $item->get_meta('_ak_participants');
                if (!is_array($participants)) {
                    continue;
                }
                foreach ($participants as $p) {
                    if (!empty($p['id']) && $p['id'] === $participant_uuid) {
                        Order_Recalculator::add_item_produced_tshirt($item, [
                            'participant_uuid' => $participant_uuid,
                            'participant_name' => $p['name'] ?? '',
                            'cost'             => $tshirt_cost,
                            'size'             => $new_data['tshirt_size'] ?: ($p['tshirt_size'] ?? ''),
                            'cut'              => $new_data['tshirt_cut'] ?: ($p['tshirt_cut'] ?? ''),
                            'reason'           => 'profile_edit',
                        ]);
                        $slot_incremented = true;
                        break;
                    }
                }
                if ($slot_incremented) {
                    break;
                }
            }
        }

        if ($has_size_change && $has_cut_change && !$has_name_change && !$has_email_change && !$has_phone_change && !$tshirt_produced) {
            $old_cut_label = Helper::get_tshirt_cut_label($old_data['tshirt_cut'] ?? '');
            $new_cut_label = Helper::get_tshirt_cut_label($new_data['tshirt_cut'] ?? '');
            $log_entry = sprintf(
                "[%s] Zmiana koszulki dla uczestnika %s:\n• PRZED: Rozmiar %s [%s]\n• PO: Rozmiar %s [%s]",
                current_time('Y-m-d H:i'),
                $participant_name ?: $participant_uuid,
                $old_data['tshirt_size'] ?: '—',
                $old_cut_label,
                $new_data['tshirt_size'] ?: '—',
                $new_cut_label
            );
        } else {
            $log_entry = sprintf(
                "[%s] Edycja danych uczestnika %s:\n• %s",
                current_time('Y-m-d H:i'),
                $participant_name ?: $participant_uuid,
                !empty($changes) ? implode("\n• ", $changes) : __('Brak zmian', 'ak-product-set')
            );
        }

        Order_Recalculator::append_audit_log($order, $log_entry);
        $order->save();

        $recalc_result = null;
        if ($tshirt_produced) {
            $recalc_result = Order_Recalculator::recalculate($order, [
                'reason' => sprintf(
                    /* translators: 1: participant name, 2: cost */
                    __('Doliczono koszt wyprodukowanej koszulki (+%2$s zł) przy edycji danych uczestnika: %1$s', 'ak-product-set'),
                    $participant_name ?: $participant_uuid,
                    number_format_i18n($tshirt_cost, 2)
                ),
            ]);
        }

        return [
            'success'          => true,
            'participant_name' => $participant_name,
            'new_size'         => $new_data['tshirt_size'] ?? '',
            'new_cut'          => $new_data['tshirt_cut'] ?? '',
            'participant_data' => $new_data,
            'recalculation'    => $recalc_result,
        ];
    }

    /**
     * Update participant's T-shirt size and cut across all line items in an order without price recalculation.
     */
    public static function update_participant_tshirt(\WC_Order $order, string $participant_uuid, string $new_size, string $new_cut, bool $tshirt_produced = false): array {
        return self::update_participant_data($order, $participant_uuid, [
            'tshirt_size'     => $new_size,
            'tshirt_cut'      => $new_cut,
            'tshirt_produced' => $tshirt_produced,
        ]);
    }
}
