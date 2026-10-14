<?php

namespace AK_Set\Admin;

use AK_Set\Order\Order_Recalculator;
use AK_Set\Order\Participant_Transfer_Service;
use AK_Set\Order\Order_Participant_Slot_Manager;
use AK_Set\Order\Order_Participant_Profile_Service;
use AK_Set\Admin\Roster_Query;
use AK_Set\Models\Set_Model;
use AK_Set\Support\Helper;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Participant_Manager {

    const NONCE_ACTION = 'ak_order_participant_action';

    public function init(): void {
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Atomic Slot AJAX Handlers
        add_action('wp_ajax_ak_remove_weekend_participant',   [$this, 'ajax_remove_weekend_participant']);
        add_action('wp_ajax_ak_restore_weekend_participant',  [$this, 'ajax_restore_weekend_participant']);
        add_action('wp_ajax_ak_revert_tshirt_fee',             [$this, 'ajax_revert_tshirt_fee']);
        add_action('wp_ajax_ak_add_participant',               [$this, 'ajax_add_participant']);
        add_action('wp_ajax_ak_reschedule_participant',        [$this, 'ajax_reschedule_participant']);
        add_action('wp_ajax_ak_transfer_participant',          [$this, 'ajax_transfer_participant']);
        add_action('wp_ajax_ak_mark_balance_settled',          [$this, 'ajax_mark_balance_settled']);
        add_action('wp_ajax_ak_mark_balance_unsettled',        [$this, 'ajax_mark_balance_unsettled']);
        add_action('wp_ajax_ak_update_participant',            [$this, 'ajax_update_participant']);
        add_action('wp_ajax_ak_update_total_paid',             [$this, 'ajax_update_total_paid']);
    }

    /**
     * Register Order Meta Box on both HPOS and legacy order screens.
     */
    public function register_meta_box(): void {
        $screens = ['woocommerce_page_wc-orders', 'shop_order'];

        foreach ($screens as $screen) {
            add_meta_box(
                'ak_order_participant_manager_box',
                __('AK Zestawy: Zarządzanie Uczestnikami & Przeliczanie', 'ak-product-set'),
                [$this, 'render_meta_box'],
                $screen,
                'normal',
                'high'
            );
        }
    }

    /**
     * Enqueue admin styles / scripts for the order participant manager.
     */
    public function enqueue_admin_assets($hook): void {
        // Handled inline within the metabox
    }

    /**
     * Render the Meta Box UI on the order screen.
     *
     * @param \WC_Order|\WP_Post $post_or_order
     */
    public function render_meta_box($post_or_order): void {
        $order = $post_or_order instanceof \WC_Order
            ? $post_or_order
            : wc_get_order(is_object($post_or_order) ? $post_or_order->ID : $post_or_order);

        if (!$order) {
            return;
        }

        // Check if order has AK decomposed items
        $ak_items = [];
        $set_id = (int) $order->get_meta('_ak_parent_set_id');
        if ($set_id <= 0) {
            $set_id = (int) $order->get_meta('_ak_set_id');
        }
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $item_set_id = (int) $item->get_meta('_ak_parent_set_id');
                if ($item_set_id > 0) {
                    $ak_items[] = $item;
                    if (!$set_id) {
                        $set_id = $item_set_id;
                    }
                }
            }
        }

        $is_ak_order = !empty($ak_items)
            || (bool) $order->get_meta('_ak_order_decomposed')
            || (bool) $order->get_meta('_ak_original_total')
            || (bool) $order->get_meta('_ak_balance_status')
            || (bool) $order->get_meta('_ak_transferred_to_order')
            || (bool) $order->get_meta('_ak_transferred_from_order')
            || (bool) $order->get_meta('_ak_balance_history');

        if (!$is_ak_order) {
            echo '<p style="color: #666;">' . esc_html__('To zamówienie nie zawiera pozycji rozbitych pakietów AK Zestawów.', 'ak-product-set') . '</p>';
            return;
        }

        // If set_id is still unknown (e.g. empty/cancelled order), resolve from linked orders or published sets
        if ($set_id <= 0 && function_exists('wc_get_order')) {
            $from_id = (int) $order->get_meta('_ak_transferred_from_order');
            if ($from_id > 0) {
                $from_order = wc_get_order($from_id);
                if ($from_order) {
                    $set_id = (int) $from_order->get_meta('_ak_parent_set_id');
                    if ($set_id <= 0) {
                        foreach ($from_order->get_items('line_item') as $f_item) {
                            $f_sid = (int) $f_item->get_meta('_ak_parent_set_id');
                            if ($f_sid > 0) {
                                $set_id = $f_sid;
                                break;
                            }
                        }
                    }
                }
            }
            if ($set_id <= 0) {
                $to_ids = $order->get_meta('_ak_transferred_to_orders');
                if (!is_array($to_ids)) {
                    $to_single = (int) $order->get_meta('_ak_transferred_to_order');
                    $to_ids = $to_single > 0 ? [$to_single] : [];
                }
                foreach ($to_ids as $to_id) {
                    $to_order = wc_get_order((int) $to_id);
                    if ($to_order) {
                        $to_sid = (int) $to_order->get_meta('_ak_parent_set_id');
                        if ($to_sid > 0) {
                            $set_id = $to_sid;
                            break;
                        }
                        foreach ($to_order->get_items('line_item') as $t_item) {
                            $t_sid = (int) $t_item->get_meta('_ak_parent_set_id');
                            if ($t_sid > 0) {
                                $set_id = $t_sid;
                                break;
                            }
                        }
                        if ($set_id > 0) {
                            break;
                        }
                    }
                }
            }
        }
        if ($set_id <= 0 && function_exists('get_posts')) {
            $sets = get_posts([
                'post_type'      => 'ak_set',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
            if (!empty($sets)) {
                $set_id = (int) $sets[0];
            }
        }

        if ($set_id > 0 && !$order->get_meta('_ak_parent_set_id')) {
            $order->update_meta_data('_ak_parent_set_id', $set_id);
        }

        // Available weekends of this set / order for the "Add Participant" checklist
        $set_weekends = [];
        if ($set_id > 0) {
            $set = new Set_Model($set_id);
            if (method_exists($set, 'get_weekends')) {
                $set_wids = (array) $set->get_weekends();
                foreach ($set_wids as $wid) {
                    $wid = (int) $wid;
                    $p = wc_get_product($wid);
                    if ($p) {
                        $set_weekends[$wid] = $p->get_name();
                    }
                }
            } elseif (method_exists($set, 'get_weekend_products')) {
                foreach ($set->get_weekend_products() as $wm) {
                    $set_weekends[$wm->get_id()] = $wm->get_title();
                }
            }
        }
        if (empty($set_weekends)) {
            foreach ($ak_items as $item) {
                $wid = (int) $item->get_product_id();
                $set_weekends[$wid] = $item->get_name();
            }
        }
        if (empty($set_weekends)) {
            $set_weekends = Roster_Query::get_weekends_for_selector();
        }

        // Every row is a Slot: Participant × Weekend
        $slots = [];
        $participant_wids = []; // uuid => [wid1, wid2, ...]
        foreach ($ak_items as $item) {
            $wid = (int) $item->get_product_id();
            $wtitle = $item->get_name();
            $p_list = $item->get_meta('_ak_participants');

            if (is_array($p_list)) {
                foreach ($p_list as $p) {
                    $uuid = $p['id'] ?? '';
                    if (!$uuid) {
                        continue;
                    }
                    if (!isset($participant_wids[$uuid])) {
                        $participant_wids[$uuid] = [];
                    }
                    if (($p['status'] ?? 'active') === 'active') {
                        $participant_wids[$uuid][] = $wid;
                    }

                    $slots[] = [
                        'uuid'                  => $uuid,
                        'name'                  => !empty($p['name']) ? $p['name'] : $uuid,
                        'email'                 => $p['email'] ?? '',
                        'phone'                 => $p['phone'] ?? '',
                        'tshirt_size'           => $p['tshirt_size'] ?? '',
                        'tshirt_cut'            => $p['tshirt_cut'] ?? '',
                        'weekend_id'            => $wid,
                        'weekend_name'          => $wtitle,
                        'status'                => $p['status'] ?? 'active',
                        'cancelled_at'          => $p['cancelled_at'] ?? null,
                        'transferred_to_order'  => $p['transferred_to_order'] ?? null,
                        'tshirt_retained_count' => (int) ($p['tshirt_retained_count'] ?? 0),
                    ];
                }
            }
        }

        $raw_balance_status = (string) $order->get_meta('_ak_balance_status');
        $raw_balance_amount = $order->get_meta('_ak_balance_amount');
        $raw_total_paid     = $order->get_meta('_ak_total_paid');

        $balance_status = $raw_balance_status ?: Order_Recalculator::BALANCE_SETTLED;
        $balance_amount = $raw_balance_amount !== '' && $raw_balance_amount !== false ? (float) $raw_balance_amount : 0.0;
        $total_paid     = $raw_total_paid !== '' && $raw_total_paid !== false ? (float) $raw_total_paid : 0.0;

        // Strict normalization:
        // An order is only settled if balance amount is negligible and not explicitly underpaid or unpaid
        if ($balance_status === Order_Recalculator::BALANCE_UNPAID) {
            $balance_amount = abs($balance_amount);
        } elseif (abs($balance_amount) < 0.01 && $balance_status !== Order_Recalculator::BALANCE_UNDERPAID) {
            $balance_status = Order_Recalculator::BALANCE_SETTLED;
            $balance_amount = 0.0;
        } else {
            $balance_amount = abs($balance_amount);
        }

        $init_method = (string) $order->get_meta('_ak_initial_payment_method');
        $init_amount = $order->get_meta('_ak_initial_payment_amount');
        $init_date   = (string) $order->get_meta('_ak_initial_payment_date');
        $init_txn    = (string) $order->get_meta('_ak_initial_payment_txn_id');
        $init_recorded = $order->get_meta('_ak_initial_payment_recorded') === 'yes';

        // Resolve locked gateway payment and auto-heal corrupted values (e.g. 60 zł on #4508)
        $original_total = (float) $order->get_meta('_ak_original_total');
        $gateway_paid   = ($init_amount !== '' && $init_amount !== false) ? (float) $init_amount : 0.0;
        if ($original_total > 0.009 && ($init_recorded || Order_Recalculator::is_order_paid($order) || in_array($order->get_status(), ['processing', 'completed'], true))) {
            if ($gateway_paid <= 0.009 || abs($gateway_paid - $original_total) > 0.009) {
                $gateway_paid = $original_total;
                $init_amount  = $original_total;
                $order->update_meta_data('_ak_initial_payment_amount', $original_total);
                $order->update_meta_data('_ak_initial_payment_recorded', 'yes');
                $order->save();
            }
        }

        $raw_additional = $order->get_meta('_ak_additional_paid');
        if ($raw_additional !== '' && $raw_additional !== false) {
            $additional_paid = (float) $raw_additional;
            $total_paid = round($gateway_paid + $additional_paid, 2);
        } else {
            if ($gateway_paid > 0.009 && $total_paid < $gateway_paid && abs($total_paid - 60.0) < 0.01) {
                // Auto-heal corrupted 60 zł on #4508
                $total_paid = $gateway_paid;
                $additional_paid = 0.0;
                $order->update_meta_data('_ak_total_paid', $total_paid);
                $order->update_meta_data('_ak_additional_paid', 0.0);
                $order->save();
            } else {
                $additional_paid = round($total_paid - $gateway_paid, 2);
            }
        }

        // Fallback for orders that were paid before tracker metadata was recorded
        if (!$init_recorded && ($total_paid > 0.009 || Order_Recalculator::is_order_paid($order))) {
            $init_method = '';
            try {
                if (is_callable([$order, 'get_payment_method_title'])) {
                    $init_method = (string) $order->get_payment_method_title();
                }
            } catch (\Throwable $e) {}
            if (!$init_method) {
                try {
                    if (is_callable([$order, 'get_payment_method'])) {
                        $init_method = (string) $order->get_payment_method();
                    }
                } catch (\Throwable $e) {}
            }
            if (!$init_method) {
                $init_method = __('Bramka płatności', 'ak-product-set');
            }
            $init_amount = $gateway_paid > 0.009 ? $gateway_paid : ($total_paid > 0.009 ? $total_paid : (float) $order->get_total());
            $gateway_paid = $init_amount;
            if (method_exists($order, 'get_date_paid') && $order->get_date_paid()) {
                $init_date = $order->get_date_paid()->date('Y-m-d H:i');
            } elseif ($order->get_date_created()) {
                $init_date = $order->get_date_created()->date('Y-m-d H:i');
            }
            $init_txn = '';
            try {
                if (is_callable([$order, 'get_transaction_id'])) {
                    $init_txn = (string) $order->get_transaction_id();
                }
            } catch (\Throwable $e) {}
            $init_recorded = true;
        }

        $initial_payment_subtitle = '';
        if ($init_recorded && $init_method !== '') {
            $formatted_amt = number_format_i18n((float) $init_amount, 2);
            $initial_payment_subtitle = sprintf(
                __('Płatność początkowa: %s, kwota: %s zł (%s)', 'ak-product-set'),
                $init_method,
                $formatted_amt,
                $init_date ?: '—'
            );
            if ($init_txn !== '') {
                $initial_payment_subtitle .= ' • ' . sprintf(__('ID: %s', 'ak-product-set'), $init_txn);
            }
        }

        $breakdown        = (string) $order->get_meta('_ak_pricing_breakdown');
        $history          = (string) $order->get_meta('_ak_balance_history');
        $tshirt_cost      = Order_Recalculator::get_tshirt_cost($set_id > 0 ? $set_id : null);
        $tshirt_fee_total = Order_Recalculator::get_order_tshirt_fee_total($order);
        $all_weekends     = Roster_Query::get_weekends_for_selector();
        $nonce            = wp_create_nonce(self::NONCE_ACTION);

        $template = defined('AK_SET_PATH')
            ? AK_SET_PATH . 'templates/admin/order-participants-metabox.php'
            : dirname(__DIR__, 2) . '/templates/admin/order-participants-metabox.php';

        if (file_exists($template)) {
            include $template;
        }
    }

    // -------------------------------------------------------------------------
    // AJAX Actions
    // -------------------------------------------------------------------------

    public function ajax_remove_weekend_participant(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id      = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $uuid          = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
        $weekend_id    = isset($_POST['weekend_id']) ? (int) $_POST['weekend_id'] : 0;
        $retain_tshirt = !empty($_POST['retain_tshirt']);

        $order = wc_get_order($order_id);
        if (!$order || !$uuid || !$weekend_id) {
            wp_send_json_error(__('Nieprawidłowe parametry anulowania terminu.', 'ak-product-set'));
        }

        $result = Order_Participant_Slot_Manager::remove_weekend_for_participant($order, $uuid, $weekend_id, $retain_tshirt);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? 'Błąd');
        }
    }



    public function ajax_add_participant(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id    = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $set_id      = isset($_POST['set_id']) ? (int) $_POST['set_id'] : 0;
        $name        = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $weekend_ids = isset($_POST['weekend_ids']) ? array_map('intval', (array) $_POST['weekend_ids']) : [];

        $order = wc_get_order($order_id);
        if (!$order || !$name) {
            wp_send_json_error(__('Nieprawidłowe dane wejściowe.', 'ak-product-set'));
        }

        if ($set_id <= 0) {
            $set_id = (int) $order->get_meta('_ak_parent_set_id');
            if ($set_id <= 0) {
                $set_id = (int) $order->get_meta('_ak_set_id');
            }
            if ($set_id <= 0) {
                $from_id = (int) $order->get_meta('_ak_transferred_from_order');
                if ($from_id > 0 && function_exists('wc_get_order')) {
                    $from_o = wc_get_order($from_id);
                    if ($from_o) {
                        $set_id = (int) $from_o->get_meta('_ak_parent_set_id');
                    }
                }
            }
            if ($set_id <= 0 && function_exists('get_posts')) {
                $sets = get_posts([
                    'post_type'      => 'ak_set',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                ]);
                if (!empty($sets)) {
                    $set_id = (int) $sets[0];
                }
            }
        }

        if ($set_id <= 0) {
            wp_send_json_error(__('Nie udało się ustalić pakietu AK dla zamówienia.', 'ak-product-set'));
        }

        $raw_size = isset($_POST['tshirt_size']) ? sanitize_text_field($_POST['tshirt_size']) : '';
        $raw_cut  = isset($_POST['tshirt_cut']) ? sanitize_text_field($_POST['tshirt_cut']) : '';
        $tshirt_size = Helper::is_valid_tshirt_size($raw_size) ? $raw_size : '';
        $tshirt_cut  = Helper::is_valid_tshirt_cut($raw_cut) ? $raw_cut : ($raw_cut !== '' ? Helper::TSHIRT_CUT_DEFAULT : '');

        $p_data = [
            'name'        => $name,
            'email'       => isset($_POST['email']) ? sanitize_email($_POST['email']) : '',
            'phone'       => isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '',
            'tshirt_size' => $tshirt_size,
            'tshirt_cut'  => $tshirt_cut,
        ];

        $result = Order_Participant_Slot_Manager::add_participant($order, $set_id, $p_data, $weekend_ids);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? 'Błąd');
        }
    }

    public function ajax_reschedule_participant(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $uuid     = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
        $from_id  = isset($_POST['from_id']) ? (int) $_POST['from_id'] : 0;
        $to_id    = isset($_POST['to_id']) ? (int) $_POST['to_id'] : 0;

        $order = wc_get_order($order_id);
        if (!$order || !$uuid || !$from_id || !$to_id) {
            wp_send_json_error(__('Nieprawidłowe parametry.', 'ak-product-set'));
        }

        $result = Order_Participant_Slot_Manager::reschedule_weekend_for_participant($order, $uuid, $from_id, $to_id);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? 'Błąd');
        }
    }

    public function ajax_transfer_participant(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id   = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $uuid       = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
        $type       = isset($_POST['transfer_type']) ? sanitize_key($_POST['transfer_type']) : Participant_Transfer_Service::TRANSFER_TYPE_NEW;
        $target_id  = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
        $weekend_id = !empty($_POST['weekend_id']) ? (int) $_POST['weekend_id'] : null;

        $order = wc_get_order($order_id);
        if (!$order || !$uuid) {
            wp_send_json_error(__('Nieprawidłowe dane zamówienia.', 'ak-product-set'));
        }

        if ($type === Participant_Transfer_Service::TRANSFER_TYPE_NEW) {
            $result = Participant_Transfer_Service::transfer_to_new_order($order, $uuid, $weekend_id);
        } else {
            $target_order = wc_get_order($target_id);
            if (!$target_order) {
                wp_send_json_error(__('Zamówienie docelowe nie istnieje.', 'ak-product-set'));
            }
            $result = Participant_Transfer_Service::transfer_to_existing_order($order, $target_order, $uuid, $weekend_id);
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? 'Błąd');
        }
    }

    public function ajax_update_participant(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $uuid     = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
        $name     = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';

        $order = wc_get_order($order_id);
        if (!$order || !$uuid) {
            wp_send_json_error(__('Nieprawidłowe dane zamówienia.', 'ak-product-set'));
        }

        if (!$name) {
            foreach ($order->get_items('line_item') as $item) {
                if ($item instanceof \WC_Order_Item_Product) {
                    $participants = $item->get_meta('_ak_participants');
                    if (is_array($participants)) {
                        foreach ($participants as $p) {
                            if (!empty($p['id']) && $p['id'] === $uuid && !empty($p['name'])) {
                                $name = (string) $p['name'];
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        if (!$name) {
            wp_send_json_error(__('Imię i nazwisko jest wymagane.', 'ak-product-set'));
        }

        $raw_size    = isset($_POST['tshirt_size']) ? sanitize_text_field($_POST['tshirt_size']) : '';
        $raw_cut     = isset($_POST['tshirt_cut']) ? sanitize_text_field($_POST['tshirt_cut']) : '';
        $tshirt_size = Helper::is_valid_tshirt_size($raw_size) ? $raw_size : '';
        $tshirt_cut  = Helper::is_valid_tshirt_cut($raw_cut) ? $raw_cut : Helper::TSHIRT_CUT_DEFAULT;

        $data = [
            'name'            => $name,
            'email'           => isset($_POST['email']) ? sanitize_email($_POST['email']) : '',
            'phone'           => isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '',
            'tshirt_size'     => $tshirt_size,
            'tshirt_cut'      => $tshirt_cut,
            'tshirt_produced' => !empty($_POST['tshirt_produced']),
        ];

        $result = Order_Participant_Profile_Service::update_participant_data($order, $uuid, $data);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? 'Błąd');
        }
    }



    public function ajax_restore_weekend_participant(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id   = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $uuid       = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
        $weekend_id = isset($_POST['weekend_id']) ? (int) $_POST['weekend_id'] : 0;

        $order = wc_get_order($order_id);
        if (!$order || !$uuid || !$weekend_id) {
            wp_send_json_error(__('Nieprawidłowe parametry przywracania terminu.', 'ak-product-set'));
        }

        $result = Order_Participant_Slot_Manager::restore_weekend_for_participant($order, $uuid, $weekend_id);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? 'Błąd');
        }
    }

    public function ajax_revert_tshirt_fee(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id   = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $uuid       = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : null;
        $weekend_id = isset($_POST['weekend_id']) ? (int) $_POST['weekend_id'] : null;

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Nieprawidłowe dane zamówienia.', 'ak-product-set'));
        }

        $result = Order_Participant_Slot_Manager::revert_tshirt_fee($order, $uuid, $weekend_id);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? 'Błąd');
        }
    }

    public function ajax_mark_balance_settled(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Nie znaleziono zamówienia.', 'ak-product-set'));
        }

        Order_Recalculator::mark_settled($order);
        wp_send_json_success();
    }

    public function ajax_mark_balance_unsettled(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Nie znaleziono zamówienia.', 'ak-product-set'));
        }

        $result = Order_Recalculator::mark_unsettled($order);
        wp_send_json_success($result);
    }

    public function ajax_update_total_paid(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Nie znaleziono zamówienia.', 'ak-product-set'));
        }

        $new_paid = isset($_POST['total_paid']) ? round((float) $_POST['total_paid'], 2) : 0.0;
        $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';

        $before_snapshot = Order_Recalculator::get_order_state_snapshot($order);

        $gateway_paid = (float) $order->get_meta('_ak_initial_payment_amount');
        $orig_total   = (float) $order->get_meta('_ak_original_total');
        if ($orig_total > 0.009 && ($order->get_meta('_ak_initial_payment_recorded') === 'yes' || Order_Recalculator::is_order_paid($order))) {
            if ($gateway_paid <= 0.009 || abs($gateway_paid - $orig_total) > 0.009) {
                $gateway_paid = $orig_total;
                $order->update_meta_data('_ak_initial_payment_amount', $orig_total);
            }
        }

        $order->update_meta_data('_ak_total_paid', $new_paid);

        if ($new_paid <= 0.009) {
            $order->update_meta_data('_ak_initial_payment_recorded', 'no');
            $order->delete_meta_data('_ak_initial_payment_amount');
            $order->delete_meta_data('_ak_initial_payment_method');
            $order->delete_meta_data('_ak_initial_payment_method_id');
            $order->delete_meta_data('_ak_initial_payment_date');
            $order->delete_meta_data('_ak_initial_payment_txn_id');
            $order->delete_meta_data('_ak_manually_settled');
            $order->update_meta_data('_ak_additional_paid', 0.0);
            $order->update_meta_data('_ak_balance_status', Order_Recalculator::BALANCE_UNPAID);
        } else {
            if ($gateway_paid > 0.009) {
                // Lock gateway_paid intact! Track any difference as additional paid
                $additional = round($new_paid - $gateway_paid, 2);
                $order->update_meta_data('_ak_additional_paid', $additional);
            } else {
                $order->update_meta_data('_ak_additional_paid', 0.0);
                if ($order->get_meta('_ak_initial_payment_recorded') === 'yes') {
                    $order->update_meta_data('_ak_initial_payment_amount', $new_paid);
                }
            }
        }

        $note_text = $note !== '' ? ' (' . $note . ')' : '';
        $reason = sprintf(
            __('Ręczna korekta wpłaconej kwoty: łącznie %s zł (bramka: %s zł, dodatkowo: %+0.2f zł)%s', 'ak-product-set'),
            number_format_i18n($new_paid, 2),
            number_format_i18n($gateway_paid, 2),
            $new_paid - $gateway_paid,
            $note_text
        );

        $recalc_result = Order_Recalculator::recalculate($order, [
            'reason'          => $reason,
            'before_snapshot' => $before_snapshot,
        ]);

        wp_send_json_success($recalc_result);
    }
}
