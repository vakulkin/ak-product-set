<?php

namespace AK_Set\Admin;

use AK_Set\Order\Order_Recalculator;
use AK_Set\Order\Participant_Transfer_Service;
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
        add_action('wp_ajax_ak_remove_weekend_participant',  [$this, 'ajax_remove_weekend_participant']);
        add_action('wp_ajax_ak_add_participant',              [$this, 'ajax_add_participant']);
        add_action('wp_ajax_ak_reschedule_participant',       [$this, 'ajax_reschedule_participant']);
        add_action('wp_ajax_ak_transfer_participant',         [$this, 'ajax_transfer_participant']);
        add_action('wp_ajax_ak_mark_balance_settled',         [$this, 'ajax_mark_balance_settled']);
        add_action('wp_ajax_ak_update_participant_tshirt',    [$this, 'ajax_update_participant_tshirt']);

        // Backwards compatibility
        add_action('wp_ajax_ak_remove_participant_completely',[$this, 'ajax_remove_participant_completely']);
        add_action('wp_ajax_ak_remove_participant',           [$this, 'ajax_remove_participant_completely']);
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
                    $participant_wids[$uuid][] = $wid;

                    $slots[] = [
                        'uuid'         => $uuid,
                        'name'         => !empty($p['name']) ? $p['name'] : $uuid,
                        'email'        => $p['email'] ?? '',
                        'phone'        => $p['phone'] ?? '',
                        'tshirt_size'  => $p['tshirt_size'] ?? '',
                        'tshirt_cut'   => $p['tshirt_cut'] ?? '',
                        'weekend_id'   => $wid,
                        'weekend_name' => $wtitle,
                    ];
                }
            }
        }

        $balance_status   = (string) $order->get_meta('_ak_balance_status') ?: Order_Recalculator::BALANCE_SETTLED;
        $balance_amount   = (float) $order->get_meta('_ak_balance_amount');
        $total_paid       = (float) $order->get_meta('_ak_total_paid');
        $current_total    = (float) $order->get_total();

        // Strict normalization: if balance amount is 0 (or close to 0) or current total matches total paid, it is strictly settled
        if (abs($balance_amount) < 0.01 || abs($current_total - ($total_paid ?: $current_total)) < 0.01) {
            $balance_status = Order_Recalculator::BALANCE_SETTLED;
            $balance_amount = 0.0;
        } else {
            $balance_amount = abs($balance_amount);
        }

        $breakdown        = (string) $order->get_meta('_ak_pricing_breakdown');
        $history          = (string) $order->get_meta('_ak_balance_history');
        $tshirt_cost      = Order_Recalculator::get_tshirt_cost();
        $all_weekends     = Roster_Query::get_weekends_for_selector();
        $nonce            = wp_create_nonce(self::NONCE_ACTION);

        ?>
        <div class="ak-order-manager-wrap" style="font-size: 13px; color: #2c3338;">
            <style>
                .ak-om-panel { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; margin-bottom: 16px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); overflow: hidden; }
                .ak-om-panel-header { padding: 10px 14px; background: #f6f7f7; border-bottom: 1px solid #c3c4c7; font-weight: 600; font-size: 13px; color: #1d2327; border-top-left-radius: 3px; border-top-right-radius: 3px; }
                .ak-om-panel-body { padding: 14px; }
                .ak-om-notice { background: #f0f6fc; border-left: 3px solid #2271b1; padding: 8px 12px; margin-bottom: 14px; font-size: 12px; color: #1d2327; }
                .ak-om-notice a { font-weight: 600; color: #2271b1; text-decoration: underline; }
                .ak-om-tag { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; line-height: 1.2; }
                .ak-tag-settled   { background: #edfaed; color: #236324; border: 1px solid #b8e2b9; }
                .ak-tag-underpaid { background: #fdf2f2; color: #9b1c1c; border: 1px solid #f8b4b4; }
                .ak-tag-overpaid  { background: #fef8eb; color: #8e5805; border: 1px solid #fce3a4; }

                .ak-om-metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
                .ak-om-metric-card { background: #f8f9fa; border: 1px solid #dcdcde; border-radius: 4px; padding: 10px 14px; display: flex; flex-direction: column; justify-content: space-between; }
                .ak-om-metric-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; color: #646970; margin-bottom: 4px; }
                .ak-om-metric-value { font-size: 16px; font-weight: 700; color: #1d2327; line-height: 1.3; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

                .ak-om-table { width: 100%; border-collapse: collapse; }
                .ak-om-table th { background: #f6f7f7; padding: 9px 12px; border-bottom: 1px solid #c3c4c7; font-weight: 600; color: #2c3338; text-align: left; font-size: 12px; }
                .ak-om-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
                .ak-om-table tr:last-child td { border-bottom: none; }
                .ak-om-table tr:hover td { background: #fcfcfc; }

                .ak-om-pre-box { background: transparent; border: none; padding: 0; font-size: 11px; white-space: pre-wrap; font-family: Consolas, Monaco, "Courier New", monospace; line-height: 1.5; margin: 0; color: #2c3338; }

                .ak-om-modal-bg { display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
                .ak-om-modal-content { background: #fff; max-width: 520px; margin: 8% auto; padding: 22px; border-radius: 4px; box-shadow: 0 4px 14px rgba(0,0,0,0.2); }
            </style>

            <?php
            $transferred_to_orders = $order->get_meta('_ak_transferred_to_orders');
            if (!is_array($transferred_to_orders)) {
                $single_to = (int) $order->get_meta('_ak_transferred_to_order');
                $transferred_to_orders = $single_to > 0 ? [$single_to] : [];
            }
            $transferred_from = (int) $order->get_meta('_ak_transferred_from_order');
            ?>

            <!-- SECTION 1: Status & Financial Settlement Panel -->
            <div class="ak-om-panel">
                <div class="ak-om-panel-header">
                    <span><?php esc_html_e('Rozliczenie zamówienia', 'ak-product-set'); ?></span>
                </div>
                <div class="ak-om-panel-body">
                    <?php if (!empty($transferred_to_orders) || $transferred_from > 0) : ?>
                        <div class="ak-om-notice">
                            <?php if ($transferred_from > 0) : ?>
                                <div>
                                    <?php esc_html_e('Przeniesiono z zamówienia', 'ak-product-set'); ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&action=edit&id=' . $transferred_from)); ?>" target="_blank">
                                        #<?php echo esc_html($transferred_from); ?> ↗
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($transferred_to_orders)) : ?>
                                <div style="<?php echo $transferred_from > 0 ? 'margin-top: 4px;' : ''; ?>">
                                    <?php esc_html_e('Przeniesiono do zamówień:', 'ak-product-set'); ?>
                                    <?php foreach ($transferred_to_orders as $to_id) : ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&action=edit&id=' . $to_id)); ?>" target="_blank" style="margin-right: 6px;">
                                            #<?php echo esc_html($to_id); ?> ↗
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="ak-om-metrics-grid">
                        <div class="ak-om-metric-card">
                            <div class="ak-om-metric-label"><?php esc_html_e('Wpłacono', 'ak-product-set'); ?></div>
                            <div class="ak-om-metric-value"><?php echo esc_html(number_format_i18n($total_paid ?: (float)$order->get_total(), 2)); ?> zł</div>
                        </div>

                        <div class="ak-om-metric-card">
                            <div class="ak-om-metric-label"><?php esc_html_e('Wartość zamówienia', 'ak-product-set'); ?></div>
                            <div class="ak-om-metric-value"><?php echo esc_html(number_format_i18n((float)$order->get_total(), 2)); ?> zł</div>
                        </div>

                        <div class="ak-om-metric-card" style="justify-content: center;">
                            <div class="ak-om-metric-label"><?php esc_html_e('Stan rozliczenia', 'ak-product-set'); ?></div>
                            <div class="ak-om-metric-value">
                                <?php if ($balance_status === Order_Recalculator::BALANCE_UNDERPAID && $balance_amount >= 0.01) : ?>
                                    <span class="ak-om-tag ak-tag-underpaid">
                                        <?php printf(esc_html__('Niedopłata: +%s zł (klient zalega)', 'ak-product-set'), number_format_i18n($balance_amount, 2)); ?>
                                    </span>
                                <?php elseif ($balance_status === Order_Recalculator::BALANCE_OVERPAID && $balance_amount >= 0.01) : ?>
                                    <span class="ak-om-tag ak-tag-overpaid">
                                        <?php printf(esc_html__('Nadpłata: %s zł (do zwrotu)', 'ak-product-set'), number_format_i18n($balance_amount, 2)); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="ak-om-tag ak-tag-settled">
                                        <?php esc_html_e('Rozliczone (brak zaległości)', 'ak-product-set'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #f0f0f1; display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                        <?php if ($balance_status !== Order_Recalculator::BALANCE_SETTLED) : ?>
                            <button type="button" class="button button-small ak-om-mark-settled-btn" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                                <?php esc_html_e('Oznacz jako uregulowane', 'ak-product-set'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="button button-primary button-small ak-om-add-btn">
                            + <?php esc_html_e('Dodaj uczestnika', 'ak-product-set'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: Participants / Slots Framed Panel -->
            <div class="ak-om-panel">
                <div class="ak-om-panel-header">
                    <span><?php esc_html_e('Terminy uczestników', 'ak-product-set'); ?></span>
                </div>

                <?php if (empty($slots)) : ?>
                    <div style="padding: 16px; text-align: center;">
                        <p style="color: #646970; font-size: 13px; margin: 0 0 10px 0;">
                            <?php esc_html_e('Brak aktywnych terminów uczestników w tym zamówieniu', 'ak-product-set'); ?>
                        </p>
                        <button type="button" class="button button-primary ak-om-add-btn">
                            + <?php esc_html_e('Dodaj uczestnika', 'ak-product-set'); ?>
                        </button>
                    </div>
                <?php else : ?>
                    <table class="ak-om-table">
                        <thead>
                            <tr>
                                <th style="width: 30px; text-align: center;">#</th>
                                <th><?php esc_html_e('Termin', 'ak-product-set'); ?></th>
                                <th><?php esc_html_e('Uczestnik', 'ak-product-set'); ?></th>
                                <th><?php esc_html_e('Kontakt', 'ak-product-set'); ?></th>
                                <th><?php esc_html_e('Koszulka', 'ak-product-set'); ?></th>
                                <th style="text-align: right; width: 220px;"><?php esc_html_e('Akcje', 'ak-product-set'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $idx = 1;
                            foreach ($slots as $slot) :
                                $cut = Helper::get_tshirt_cut_label($slot['tshirt_cut'] ?? '');
                                $shirt_btn_label = !empty($slot['tshirt_size']) ? esc_html($slot['tshirt_size']) . ' [' . esc_html($cut) . ']' : __('Wybierz koszulkę', 'ak-product-set');
                                $enrolled = $participant_wids[$slot['uuid']] ?? [];
                            ?>
                                <tr>
                                    <td style="text-align: center; color: #8c8f94;"><?php echo $idx++; ?></td>
                                    <td>
                                        <span><?php echo esc_html($slot['weekend_name']); ?></span>
                                    </td>
                                    <td>
                                        <span><?php echo esc_html($slot['name']); ?></span><br>
                                        <code style="color:#8c8f94; font-size: 11px; background: transparent; padding: 0;"><?php echo esc_html(substr($slot['uuid'], 0, 8)); ?></code>
                                    </td>
                                    <td>
                                        <?php if (!empty($slot['email'])) : ?><div style="color: #2c3338;"><?php echo esc_html($slot['email']); ?></div><?php endif; ?>
                                        <?php if (!empty($slot['phone'])) : ?><div style="color: #646970; font-size: 12px;"><?php echo esc_html($slot['phone']); ?></div><?php endif; ?>
                                        <?php if (empty($slot['email']) && empty($slot['phone'])) : ?><span style="color: #8c8f94;">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small ak-om-edit-tshirt-btn"
                                            data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                            data-name="<?php echo esc_attr($slot['name']); ?>"
                                            data-size="<?php echo esc_attr($slot['tshirt_size']); ?>"
                                            data-cut="<?php echo esc_attr($slot['tshirt_cut']); ?>"
                                            title="<?php esc_attr_e('Kliknij, aby zmienić rozmiar / krój koszulki', 'ak-product-set'); ?>">
                                            <?php echo $shirt_btn_label; ?>
                                        </button>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button type="button" class="button button-small ak-om-reschedule-btn"
                                            data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                            data-from-id="<?php echo esc_attr($slot['weekend_id']); ?>"
                                            data-name="<?php echo esc_attr($slot['name']); ?>"
                                            data-title="<?php echo esc_attr($slot['weekend_name']); ?>"
                                            data-enrolled-wids="<?php echo esc_attr(json_encode($enrolled)); ?>"
                                            title="<?php esc_attr_e('Zmień ten termin na inny', 'ak-product-set'); ?>">
                                            <?php esc_html_e('Zmień termin', 'ak-product-set'); ?>
                                        </button>
                                        <button type="button" class="button button-small ak-om-transfer-btn"
                                            data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                            data-wid="<?php echo esc_attr($slot['weekend_id']); ?>"
                                            data-name="<?php echo esc_attr($slot['name']); ?>"
                                            data-title="<?php echo esc_attr($slot['weekend_name']); ?>"
                                            title="<?php esc_attr_e('Przenieś ten termin do innego zamówienia', 'ak-product-set'); ?>">
                                            <?php esc_html_e('Przenieś', 'ak-product-set'); ?>
                                        </button>
                                        <button type="button" class="button button-small button-link-delete ak-om-cancel-btn"
                                            data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                            data-wid="<?php echo esc_attr($slot['weekend_id']); ?>"
                                            data-name="<?php echo esc_attr($slot['name']); ?>"
                                            data-title="<?php echo esc_attr($slot['weekend_name']); ?>"
                                            title="<?php esc_attr_e('Anuluj ten termin dla uczestnika', 'ak-product-set'); ?>">
                                            <?php esc_html_e('Anuluj', 'ak-product-set'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- SECTION 3: Pricing Breakdown -->
            <?php if ($breakdown !== '') : ?>
                <div class="ak-om-panel">
                    <div class="ak-om-panel-header">
                        <span><?php esc_html_e('Kalkulacja cenowa (Breakdown)', 'ak-product-set'); ?></span>
                    </div>
                    <div class="ak-om-panel-body">
                        <pre class="ak-om-pre-box"><?php echo esc_html($breakdown); ?></pre>
                    </div>
                </div>
            <?php endif; ?>

            <!-- SECTION 4: History & Audit Trail -->
            <?php if ($history !== '') : ?>
                <div class="ak-om-panel">
                    <div class="ak-om-panel-header">
                        <span><?php esc_html_e('Historia zmian', 'ak-product-set'); ?></span>
                    </div>
                    <div class="ak-om-panel-body">
                        <pre class="ak-om-pre-box"><?php echo esc_html($history); ?></pre>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Modal: Add Participant -->
            <div id="ak-om-modal-add" class="ak-om-modal-bg">
                <div class="ak-om-modal-content">
                    <h3><?php esc_html_e('Dodaj uczestnika do zamówienia', 'ak-product-set'); ?></h3>
                    <p style="color: #666; font-size: 12px; margin-bottom: 12px;">
                        <?php esc_html_e('Wybierz terminy, w których weźmie udział nowy uczestnik. Każdy termin utworzy osobny wpis i zamówienie zostanie przeliczone.', 'ak-product-set'); ?>
                    </p>
                    <div style="margin-bottom: 8px;">
                        <label><strong><?php esc_html_e('Imię i nazwisko:', 'ak-product-set'); ?></strong> *</label><br>
                        <input type="text" id="ak-om-new-name" class="widefat" required>
                    </div>
                    <div style="margin-bottom: 8px;">
                        <label><?php esc_html_e('Adres e-mail:', 'ak-product-set'); ?></label><br>
                        <input type="email" id="ak-om-new-email" class="widefat">
                    </div>
                    <div style="margin-bottom: 8px;">
                        <label><?php esc_html_e('Telefon:', 'ak-product-set'); ?></label><br>
                        <input type="text" id="ak-om-new-phone" class="widefat">
                    </div>
                    <div style="display: flex; gap: 10px; margin-bottom: 12px;">
                        <div style="flex: 1;">
                            <label><?php esc_html_e('Rozmiar koszulki:', 'ak-product-set'); ?></label><br>
                            <select id="ak-om-new-tshirt-size" class="widefat">
                                <option value="">—</option>
                                <?php foreach (Helper::get_tshirt_sizes() as $size_val => $size_label) : ?>
                                    <option value="<?php echo esc_attr($size_val); ?>"><?php echo esc_html($size_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label><?php esc_html_e('Krój koszulki:', 'ak-product-set'); ?></label><br>
                            <select id="ak-om-new-tshirt-cut" class="widefat">
                                <?php foreach (Helper::get_tshirt_cuts() as $cut_val => $cut_label) : ?>
                                    <option value="<?php echo esc_attr($cut_val); ?>"><?php echo esc_html($cut_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Weekend selection checkboxes -->
                    <div style="background: #f0f0f1; padding: 10px 12px; border-radius: 4px; margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <strong><?php esc_html_e('Wybierz weekendy:', 'ak-product-set'); ?></strong>
                            <label style="cursor: pointer; font-size: 11px;">
                                <input type="checkbox" id="ak-om-add-select-all" checked> <?php esc_html_e('Zaznacz wszystkie', 'ak-product-set'); ?>
                            </label>
                        </div>
                        <div id="ak-om-add-weekends-wrap" style="max-height: 120px; overflow-y: auto;">
                            <?php foreach ($set_weekends as $wid => $wtitle) : ?>
                                <label style="display: block; margin-bottom: 4px; cursor: pointer;">
                                    <input type="checkbox" class="ak-om-add-wid-cb" value="<?php echo esc_attr($wid); ?>" checked>
                                    <?php echo esc_html($wtitle); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="text-align: right;">
                        <button type="button" class="button ak-om-modal-cancel"><?php esc_html_e('Anuluj', 'ak-product-set'); ?></button>
                        <button type="button" class="button button-primary ak-om-modal-submit-add"><?php esc_html_e('Zapisz i przelicz', 'ak-product-set'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Modal: Edit T-Shirt -->
            <div id="ak-om-modal-tshirt" class="ak-om-modal-bg">
                <div class="ak-om-modal-content">
                    <h3><?php esc_html_e('Zmień koszulkę uczestnika', 'ak-product-set'); ?></h3>
                    <p id="ak-om-tshirt-desc"></p>
                    <div style="display: flex; gap: 10px; margin-bottom: 14px;">
                        <div style="flex: 1;">
                            <label><strong><?php esc_html_e('Rozmiar koszulki:', 'ak-product-set'); ?></strong></label><br>
                            <select id="ak-om-edit-tshirt-size" class="widefat">
                                <option value="">—</option>
                                <?php foreach (Helper::get_tshirt_sizes() as $size_val => $size_label) : ?>
                                    <option value="<?php echo esc_attr($size_val); ?>"><?php echo esc_html($size_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label><strong><?php esc_html_e('Krój koszulki:', 'ak-product-set'); ?></strong></label><br>
                            <select id="ak-om-edit-tshirt-cut" class="widefat">
                                <?php foreach (Helper::get_tshirt_cuts() as $cut_val => $cut_label) : ?>
                                    <option value="<?php echo esc_attr($cut_val); ?>"><?php echo esc_html($cut_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p style="font-size: 11px; color: #666; margin-bottom: 16px;">
                        <?php esc_html_e('Zmiana zostanie zapisana we wszystkich terminach tego uczestnika bez wpływu na płatność zamówienia.', 'ak-product-set'); ?>
                    </p>
                    <div style="text-align: right;">
                        <button type="button" class="button ak-om-modal-cancel"><?php esc_html_e('Anuluj', 'ak-product-set'); ?></button>
                        <button type="button" class="button button-primary ak-om-modal-submit-tshirt"><?php esc_html_e('Zapisz koszulkę', 'ak-product-set'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Modal: Cancel Single Slot -->
            <div id="ak-om-modal-cancel" class="ak-om-modal-bg">
                <div class="ak-om-modal-content">
                    <h3 style="color: #b32d2e;"><?php esc_html_e('Anulowanie terminu uczestnika', 'ak-product-set'); ?></h3>
                    <p id="ak-om-cancel-desc"></p>
                    <div style="margin: 12px 0; background: #fff8e5; padding: 10px; border-left: 4px solid #ffb900;">
                        <label style="cursor: pointer;">
                            <input type="checkbox" id="ak-om-retain-tshirt" value="1">
                            <strong><?php esc_html_e('Koszulka została już wyprodukowana / wydana', 'ak-product-set'); ?></strong><br>
                            <span style="font-size: 11px; color: #666;">
                                <?php printf(esc_html__('Potrąć koszt wyprodukowanej koszulki (%s zł) przy kalkulacji kwoty.', 'ak-product-set'), number_format_i18n($tshirt_cost, 2)); ?>
                            </span>
                        </label>
                    </div>
                    <div style="text-align: right;">
                        <button type="button" class="button ak-om-modal-cancel"><?php esc_html_e('Wróć', 'ak-product-set'); ?></button>
                        <button type="button" class="button button-primary ak-om-modal-submit-cancel" style="background:#b32d2e; border-color:#b32d2e;"><?php esc_html_e('Anuluj termin', 'ak-product-set'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Modal: Reschedule Slot -->
            <div id="ak-om-modal-reschedule" class="ak-om-modal-bg">
                <div class="ak-om-modal-content">
                    <h3><?php esc_html_e('Zmień termin uczestnika', 'ak-product-set'); ?></h3>
                    <p id="ak-om-reschedule-desc"></p>
                    <div style="margin-bottom: 14px;">
                        <label><strong><?php esc_html_e('Wybierz nowy termin:', 'ak-product-set'); ?></strong></label><br>
                        <select id="ak-om-reschedule-select" class="widefat">
                            <?php foreach ($all_weekends as $pid => $title) : ?>
                                <option value="<?php echo esc_attr($pid); ?>" data-base-title="<?php echo esc_attr($title); ?>">
                                    <?php echo esc_html($title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p style="font-size: 11px; color: #666; margin: 4px 0 0 0;">
                            <?php esc_html_e('Terminy, na które uczestnik jest już zapisany, są wyłączone z wyboru.', 'ak-product-set'); ?>
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <button type="button" class="button ak-om-modal-cancel"><?php esc_html_e('Anuluj', 'ak-product-set'); ?></button>
                        <button type="button" class="button button-primary ak-om-modal-submit-reschedule"><?php esc_html_e('Zmień termin i przelicz', 'ak-product-set'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Modal: Transfer Slot -->
            <div id="ak-om-modal-transfer" class="ak-om-modal-bg">
                <div class="ak-om-modal-content">
                    <h3><?php esc_html_e('Przenieś termin do innego zamówienia', 'ak-product-set'); ?></h3>
                    <p id="ak-om-transfer-desc"></p>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; margin-bottom: 6px; cursor: pointer;">
                            <input type="radio" name="ak_transfer_type" value="<?php echo esc_attr(Participant_Transfer_Service::TRANSFER_TYPE_NEW); ?>" checked>
                            <strong><?php esc_html_e('Utwórz nowe, osobne zamówienie dla tego terminu', 'ak-product-set'); ?></strong>
                        </label>
                        <label style="display: block; cursor: pointer;">
                            <input type="radio" name="ak_transfer_type" value="<?php echo esc_attr(Participant_Transfer_Service::TRANSFER_TYPE_EXISTING); ?>">
                            <strong><?php esc_html_e('Dołącz do istniejącego zamówienia:', 'ak-product-set'); ?></strong>
                        </label>
                    </div>
                    <div id="ak-om-existing-order-wrap" style="display: none; margin-bottom: 14px;">
                        <input type="number" id="ak-om-target-order-id" placeholder="<?php esc_attr_e('Wpisz ID zamówienia, np. 1234', 'ak-product-set'); ?>" class="widefat">
                    </div>
                    <div style="text-align: right;">
                        <button type="button" class="button ak-om-modal-cancel"><?php esc_html_e('Anuluj', 'ak-product-set'); ?></button>
                        <button type="button" class="button button-primary ak-om-modal-submit-transfer"><?php esc_html_e('Przenieś i przelicz', 'ak-product-set'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var orderId       = <?php echo (int) $order->get_id(); ?>;
            var setId         = <?php echo (int) $set_id; ?>;
            var nonce         = '<?php echo esc_js($nonce); ?>';
            var activeUuid    = '';
            var activeWid     = 0;
            var activeFromId  = 0;

            $('.ak-om-modal-cancel').on('click', function() {
                $('.ak-om-modal-bg').hide();
            });

            // 1. Add participant (with weekend checkboxes)
            $('.ak-om-add-btn').on('click', function() {
                $('#ak-om-modal-add').show();
            });

            $('#ak-om-add-select-all').on('change', function() {
                $('.ak-om-add-wid-cb').prop('checked', $(this).is(':checked'));
            });

            $('.ak-om-modal-submit-add').on('click', function() {
                var name = $('#ak-om-new-name').val().trim();
                if (!name) {
                    alert('<?php echo esc_js(__('Imię i nazwisko jest wymagane.', 'ak-product-set')); ?>');
                    return;
                }

                var selectedWids = [];
                $('.ak-om-add-wid-cb:checked').each(function() {
                    selectedWids.push($(this).val());
                });

                if (selectedWids.length === 0) {
                    alert('<?php echo esc_js(__('Wybierz przynajmniej jeden termin dla uczestnika.', 'ak-product-set')); ?>');
                    return;
                }

                var btn = $(this).prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'ak_add_participant',
                    order_id: orderId,
                    set_id: setId,
                    nonce: nonce,
                    name: name,
                    email: $('#ak-om-new-email').val().trim(),
                    phone: $('#ak-om-new-phone').val().trim(),
                    tshirt_size: $('#ak-om-new-tshirt-size').val(),
                    tshirt_cut: $('#ak-om-new-tshirt-cut').val(),
                    weekend_ids: selectedWids
                }, function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data || 'Błąd');
                        btn.prop('disabled', false);
                    }
                });
            });

            // 2. Edit T-Shirt
            $('.ak-om-edit-tshirt-btn').on('click', function(e) {
                e.preventDefault();
                activeUuid = $(this).data('uuid');
                var name   = $(this).data('name');
                var size   = $(this).data('size') || '';
                var cut    = $(this).data('cut') || 'men';

                $('#ak-om-tshirt-desc').html('Zmieniasz koszulkę dla uczestnika: <strong>' + name + '</strong>.');
                $('#ak-om-edit-tshirt-size').val(size);
                $('#ak-om-edit-tshirt-cut').val(cut);
                $('#ak-om-modal-tshirt').show();
            });

            $('.ak-om-modal-submit-tshirt').on('click', function() {
                var btn = $(this).prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'ak_update_participant_tshirt',
                    order_id: orderId,
                    uuid: activeUuid,
                    tshirt_size: $('#ak-om-edit-tshirt-size').val(),
                    tshirt_cut: $('#ak-om-edit-tshirt-cut').val(),
                    nonce: nonce
                }, function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data || 'Błąd');
                        btn.prop('disabled', false);
                    }
                });
            });

            // 3. Cancel slot (Participant × Weekend)
            $('.ak-om-cancel-btn').on('click', function() {
                activeUuid = $(this).data('uuid');
                activeWid  = $(this).data('wid');
                var name   = $(this).data('name');
                var title  = $(this).data('title');

                $('#ak-om-cancel-desc').html('Czy na pewno chcesz anulować termin: <strong>' + title + '</strong> dla uczestnika: <strong>' + name + '</strong>?<br>Cena zamówienia zostanie automatycznie przeliczona.');
                $('#ak-om-retain-tshirt').prop('checked', false);
                $('#ak-om-modal-cancel').show();
            });

            $('.ak-om-modal-submit-cancel').on('click', function() {
                var btn = $(this).prop('disabled', true);
                var retainTshirt = $('#ak-om-retain-tshirt').is(':checked') ? 1 : 0;
                $.post(ajaxurl, {
                    action: 'ak_remove_weekend_participant',
                    order_id: orderId,
                    uuid: activeUuid,
                    weekend_id: activeWid,
                    retain_tshirt: retainTshirt,
                    nonce: nonce
                }, function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data || 'Błąd');
                        btn.prop('disabled', false);
                    }
                });
            });

            // 4. Reschedule slot (with duplicate disabling)
            $('.ak-om-reschedule-btn').on('click', function() {
                activeUuid   = $(this).data('uuid');
                activeFromId = $(this).data('from-id');
                var name     = $(this).data('name');
                var title    = $(this).data('title');
                var enrolledWids = $(this).data('enrolled-wids') || [];

                $('#ak-om-reschedule-desc').html('Przenosisz uczestnika <strong>' + name + '</strong> z dotychczasowego terminu: <strong>' + title + '</strong>.');

                // Disable options for weekends participant is already enrolled in
                $('#ak-om-reschedule-select option').each(function() {
                    var val = parseInt($(this).val(), 10);
                    var baseTitle = $(this).data('base-title') || $(this).text().replace(' (już zapisany)', '');
                    if (enrolledWids.indexOf(val) !== -1) {
                        $(this).prop('disabled', true).text(baseTitle + ' (już zapisany)');
                    } else {
                        $(this).prop('disabled', false).text(baseTitle);
                    }
                });

                // Select first valid non-disabled option
                var firstValid = $('#ak-om-reschedule-select option:not(:disabled)').first();
                if (firstValid.length) {
                    firstValid.prop('selected', true);
                }

                $('#ak-om-modal-reschedule').show();
            });

            $('.ak-om-modal-submit-reschedule').on('click', function() {
                var btn = $(this).prop('disabled', true);
                var toId = $('#ak-om-reschedule-select').val();
                $.post(ajaxurl, {
                    action: 'ak_reschedule_participant',
                    order_id: orderId,
                    uuid: activeUuid,
                    from_id: activeFromId,
                    to_id: toId,
                    nonce: nonce
                }, function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data || 'Błąd');
                        btn.prop('disabled', false);
                    }
                });
            });

            // 5. Transfer slot
            $('.ak-om-transfer-btn').on('click', function() {
                activeUuid = $(this).data('uuid');
                activeWid  = $(this).data('wid');
                var name   = $(this).data('name');
                var title  = $(this).data('title');

                $('#ak-om-transfer-desc').html('Przenosisz termin <strong>' + title + '</strong> uczestnika <strong>' + name + '</strong>.');
                $('#ak-om-modal-transfer').show();
            });

            $('input[name="ak_transfer_type"]').on('change', function() {
                if ($(this).val() === 'existing') {
                    $('#ak-om-existing-order-wrap').show();
                } else {
                    $('#ak-om-existing-order-wrap').hide();
                }
            });

            $('.ak-om-modal-submit-transfer').on('click', function() {
                var btn = $(this).prop('disabled', true);
                var type = $('input[name="ak_transfer_type"]:checked').val();
                var targetId = $('#ak-om-target-order-id').val();

                $.post(ajaxurl, {
                    action: 'ak_transfer_participant',
                    order_id: orderId,
                    uuid: activeUuid,
                    weekend_id: activeWid,
                    transfer_type: type,
                    target_id: targetId,
                    nonce: nonce
                }, function(res) {
                    if (res.success) {
                        if (res.data && res.data.new_order_id) {
                            alert('Utworzono nowe zamówienie #' + res.data.new_order_id);
                        }
                        location.reload();
                    } else {
                        alert(res.data || 'Błąd');
                        btn.prop('disabled', false);
                    }
                });
            });

            // 6. Mark Settled
            $('.ak-om-mark-settled-btn').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Czy na pewno chcesz oznaczyć to zamówienie jako całkowicie rozliczone/uregulowane?', 'ak-product-set')); ?>')) {
                    return;
                }
                var btn = $(this).prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'ak_mark_balance_settled',
                    order_id: orderId,
                    nonce: nonce
                }, function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data || 'Błąd');
                        btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
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

        $result = Participant_Transfer_Service::remove_weekend_for_participant($order, $uuid, $weekend_id, $retain_tshirt);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? 'Błąd');
        }
    }

    public function ajax_remove_participant_completely(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id      = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $uuid          = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
        $retain_tshirt = !empty($_POST['retain_tshirt']);

        $order = wc_get_order($order_id);
        if (!$order || !$uuid) {
            wp_send_json_error(__('Nie znaleziono zamówienia lub uczestnika.', 'ak-product-set'));
        }

        $result = Participant_Transfer_Service::remove_participant_completely($order, $uuid, $retain_tshirt);
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

        $result = Participant_Transfer_Service::add_participant($order, $set_id, $p_data, $weekend_ids);
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

        $result = Participant_Transfer_Service::reschedule_weekend_for_participant($order, $uuid, $from_id, $to_id);
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

    public function ajax_update_participant_tshirt(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Brak uprawnień.', 'ak-product-set'));
        }

        $order_id    = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $uuid        = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
        $raw_size    = isset($_POST['tshirt_size']) ? sanitize_text_field($_POST['tshirt_size']) : '';
        $raw_cut     = isset($_POST['tshirt_cut']) ? sanitize_text_field($_POST['tshirt_cut']) : '';
        $tshirt_size = Helper::is_valid_tshirt_size($raw_size) ? $raw_size : '';
        $tshirt_cut  = Helper::is_valid_tshirt_cut($raw_cut) ? $raw_cut : Helper::TSHIRT_CUT_DEFAULT;

        $order = wc_get_order($order_id);
        if (!$order || !$uuid) {
            wp_send_json_error(__('Nieprawidłowe dane zamówienia.', 'ak-product-set'));
        }

        $result = Participant_Transfer_Service::update_participant_tshirt($order, $uuid, $tshirt_size, $tshirt_cut);
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
}
