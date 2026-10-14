<?php
/**
 * Admin Order Participants & Recalculation Metabox Template
 *
 * Variables passed from Order_Participant_Manager::render_meta_box:
 *
 * @var \WC_Order $order
 * @var int       $set_id
 * @var array     $set_weekends
 * @var array     $slots
 * @var array     $participant_wids
 * @var string    $balance_status
 * @var float     $balance_amount
 * @var float     $total_paid
 * @var string    $initial_payment_subtitle
 * @var string    $breakdown
 * @var string    $history
 * @var float     $tshirt_cost
 * @var float     $tshirt_fee_total
 * @var array     $all_weekends
 * @var string    $nonce
 */

if (!defined('ABSPATH')) {
    exit;
}

use AK_Set\Order\Order_Recalculator;
use AK_Set\Order\Participant_Transfer_Service;
use AK_Set\Support\Helper;

$transferred_to_orders = $order->get_meta('_ak_transferred_to_orders');
if (!is_array($transferred_to_orders)) {
    $single_to = (int) $order->get_meta('_ak_transferred_to_order');
    $transferred_to_orders = $single_to > 0 ? [$single_to] : [];
}
$transferred_from = (int) $order->get_meta('_ak_transferred_from_order');
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
        .ak-tag-unpaid    { background: #fdf2f2; color: #9b1c1c; border: 1px solid #f8b4b4; }
        .ak-om-subtitle   { font-size: 11px; color: #646970; margin-top: 5px; font-weight: 400; line-height: 1.35; }

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
                    <div class="ak-om-metric-value"><?php echo esc_html(number_format_i18n($total_paid, 2)); ?> zł</div>
                </div>

                <div class="ak-om-metric-card">
                    <div class="ak-om-metric-label"><?php esc_html_e('Wartość zamówienia', 'ak-product-set'); ?></div>
                    <div class="ak-om-metric-value"><?php echo esc_html(number_format_i18n((float)$order->get_total(), 2)); ?> zł</div>
                </div>

                <?php if (!empty($tshirt_fee_total) && $tshirt_fee_total > 0.009) : ?>
                    <div class="ak-om-metric-card">
                        <div class="ak-om-metric-label"><?php esc_html_e('Opłaty za koszulki', 'ak-product-set'); ?></div>
                        <div class="ak-om-metric-value" style="color: #b32d2e;">
                            <?php echo esc_html(number_format_i18n($tshirt_fee_total, 2)); ?> zł
                        </div>
                    </div>
                <?php endif; ?>

                <div class="ak-om-metric-card" style="justify-content: center;">
                    <div class="ak-om-metric-label"><?php esc_html_e('Stan rozliczenia', 'ak-product-set'); ?></div>
                    <div class="ak-om-metric-value" style="display: block;">
                        <?php if ($balance_status === Order_Recalculator::BALANCE_UNPAID) : ?>
                            <span class="ak-om-tag ak-tag-unpaid">
                                <?php esc_html_e('Nieopłacone (oczekuje na płatność)', 'ak-product-set'); ?>
                            </span>
                            <div class="ak-om-subtitle">
                                <?php
                                $method_name = '';
                                try {
                                    if (is_callable([$order, 'get_payment_method_title'])) {
                                        $method_name = (string) $order->get_payment_method_title();
                                    }
                                } catch (\Throwable $e) {}
                                if (!$method_name) {
                                    try {
                                        if (is_callable([$order, 'get_payment_method'])) {
                                            $method_name = (string) $order->get_payment_method();
                                        }
                                    } catch (\Throwable $e) {}
                                }
                                $method_display = $method_name ?: __('Bramka płatności', 'ak-product-set');
                                printf(esc_html__('Wybrana metoda: %s (brak potwierdzenia wpłaty z bramki)', 'ak-product-set'), esc_html($method_display));
                                ?>
                            </div>
                        <?php elseif ($balance_status === Order_Recalculator::BALANCE_UNDERPAID && $balance_amount >= 0.01) : ?>
                            <span class="ak-om-tag ak-tag-underpaid">
                                <?php printf(esc_html__('Niedopłata: +%s zł (klient zalega)', 'ak-product-set'), number_format_i18n($balance_amount, 2)); ?>
                            </span>
                            <?php if ($initial_payment_subtitle) : ?>
                                <div class="ak-om-subtitle"><?php echo esc_html($initial_payment_subtitle); ?></div>
                            <?php endif; ?>
                        <?php elseif ($balance_status === Order_Recalculator::BALANCE_OVERPAID && $balance_amount >= 0.01) : ?>
                            <span class="ak-om-tag ak-tag-overpaid">
                                <?php printf(esc_html__('Nadpłata: %s zł (do zwrotu)', 'ak-product-set'), number_format_i18n($balance_amount, 2)); ?>
                            </span>
                            <?php if ($initial_payment_subtitle) : ?>
                                <div class="ak-om-subtitle"><?php echo esc_html($initial_payment_subtitle); ?></div>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="ak-om-tag ak-tag-settled">
                                <?php esc_html_e('Rozliczone (brak zaległości)', 'ak-product-set'); ?>
                            </span>
                            <?php if ($initial_payment_subtitle) : ?>
                                <div class="ak-om-subtitle"><?php echo esc_html($initial_payment_subtitle); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #f0f0f1; display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">
                <?php if (!empty($tshirt_fee_total) && $tshirt_fee_total > 0.009) : ?>
                    <button type="button" class="button button-small ak-om-revert-fee-btn" data-order-id="<?php echo esc_attr($order->get_id()); ?>" title="<?php esc_attr_e('Cofnij 1 opłatę za wyprodukowaną koszulkę (-60 zł)', 'ak-product-set'); ?>">
                        <?php printf(esc_html__('Cofnij opłatę za koszulkę (-%s zł)', 'ak-product-set'), number_format_i18n($tshirt_cost, 0)); ?>
                    </button>
                <?php endif; ?>
                <?php if ($balance_status !== Order_Recalculator::BALANCE_SETTLED) : ?>
                    <button type="button" class="button button-small ak-om-mark-settled-btn" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                        <?php esc_html_e('Oznacz jako uregulowane', 'ak-product-set'); ?>
                    </button>
                <?php else : ?>
                    <?php
                    $can_unsettle = $order->get_meta('_ak_manually_settled') === '1'
                        || $order->get_meta('_ak_pre_settled_total_paid') !== ''
                        || abs((float)$order->get_meta('_ak_original_total') - (float)$order->get_total()) > 0.01;
                    if ($can_unsettle) :
                    ?>
                        <button type="button" class="button button-small ak-om-mark-unsettled-btn" data-order-id="<?php echo esc_attr($order->get_id()); ?>" title="<?php esc_attr_e('Przywróć wyliczone saldo niedopłaty/nadpłaty', 'ak-product-set'); ?>">
                            <?php esc_html_e('Cofnij uregulowanie', 'ak-product-set'); ?>
                        </button>
                    <?php endif; ?>
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
                        <th><?php esc_html_e('Uczestnik i kontakt', 'ak-product-set'); ?></th>
                        <th><?php esc_html_e('Koszulka', 'ak-product-set'); ?></th>
                        <th style="text-align: right; width: 220px;"><?php esc_html_e('Akcje', 'ak-product-set'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $idx = 1;
                    foreach ($slots as $slot) :
                        $status          = $slot['status'] ?? 'active';
                        $is_cancelled    = ($status === 'cancelled');
                        $is_transferred  = ($status === 'transferred');
                        $is_inactive     = ($is_cancelled || $is_transferred);
                        $retained_shirts = (int) ($slot['tshirt_retained_count'] ?? 0);

                        $cut = Helper::get_tshirt_cut_label($slot['tshirt_cut'] ?? '');
                        $shirt_btn_label = !empty($slot['tshirt_size']) ? esc_html($slot['tshirt_size']) . ' [' . esc_html($cut) . ']' : __('Wybierz koszulkę', 'ak-product-set');
                        $enrolled = $participant_wids[$slot['uuid']] ?? [];
                        $name_label  = !empty($slot['name']) ? $slot['name'] : __('Imię', 'ak-product-set');
                        $phone_label = !empty($slot['phone']) ? $slot['phone'] : __('Telefon', 'ak-product-set');
                        $email_label = !empty($slot['email']) ? $slot['email'] : __('E-mail', 'ak-product-set');

                        $row_style = '';
                        if ($is_cancelled) {
                            $row_style = 'background: #fff8f8; opacity: 0.88;';
                        } elseif ($is_transferred) {
                            $row_style = 'background: #f0f6fc; opacity: 0.88;';
                        }
                    ?>
                        <tr style="<?php echo esc_attr($row_style); ?>">
                            <td style="text-align: center; color: #8c8f94;"><?php echo $idx++; ?></td>
                            <td>
                                <span style="<?php echo $is_inactive ? 'text-decoration: line-through; color: #8c8f94;' : ''; ?>">
                                    <?php echo esc_html($slot['weekend_name']); ?>
                                </span>
                                <span style="color: #8c8f94; font-size: 11px; margin-left: 4px;">#<?php echo esc_html(substr($slot['uuid'], 0, 8)); ?></span>

                                <?php if ($is_cancelled) : ?>
                                    <span class="ak-om-tag ak-tag-underpaid" style="margin-left: 6px;"><?php esc_html_e('Anulowany', 'ak-product-set'); ?></span>
                                <?php elseif ($is_transferred) : ?>
                                    <span class="ak-om-tag" style="background:#e7f3fe; color:#185abc; border:1px solid #c2e0ff; margin-left: 6px;">
                                        <?php printf(esc_html__('Przeniesiony (%s)', 'ak-product-set'), !empty($slot['transferred_to_order']) ? '#' . esc_html($slot['transferred_to_order']) : 'zamówienie'); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($retained_shirts > 0) : ?>
                                    <span class="ak-om-tag" style="background:#fff3cd; color:#856404; border:1px solid #ffeeba; margin-left: 4px;" title="<?php esc_attr_e('Liczba wyprodukowanych/zniszczonych koszulek dla tego terminu', 'ak-product-set'); ?>">
                                        <?php printf(esc_html__('Koszulki: %d szt.', 'ak-product-set'), $retained_shirts); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
                                    <button type="button" class="button button-small ak-om-edit-participant-btn"
                                        data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                        data-name="<?php echo esc_attr($slot['name']); ?>"
                                        data-email="<?php echo esc_attr($slot['email']); ?>"
                                        data-phone="<?php echo esc_attr($slot['phone']); ?>"
                                        data-size="<?php echo esc_attr($slot['tshirt_size']); ?>"
                                        data-cut="<?php echo esc_attr($slot['tshirt_cut']); ?>"
                                        data-focus="name"
                                        title="<?php esc_attr_e('Kliknij, aby edytować imię i nazwisko', 'ak-product-set'); ?>">
                                        <?php echo esc_html($name_label); ?>
                                    </button>
                                    <button type="button" class="button button-small ak-om-edit-participant-btn"
                                        data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                        data-name="<?php echo esc_attr($slot['name']); ?>"
                                        data-email="<?php echo esc_attr($slot['email']); ?>"
                                        data-phone="<?php echo esc_attr($slot['phone']); ?>"
                                        data-size="<?php echo esc_attr($slot['tshirt_size']); ?>"
                                        data-cut="<?php echo esc_attr($slot['tshirt_cut']); ?>"
                                        data-focus="phone"
                                        title="<?php esc_attr_e('Kliknij, aby edytować telefon', 'ak-product-set'); ?>">
                                        <?php echo esc_html($phone_label); ?>
                                    </button>
                                    <button type="button" class="button button-small ak-om-edit-participant-btn"
                                        data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                        data-name="<?php echo esc_attr($slot['name']); ?>"
                                        data-email="<?php echo esc_attr($slot['email']); ?>"
                                        data-phone="<?php echo esc_attr($slot['phone']); ?>"
                                        data-size="<?php echo esc_attr($slot['tshirt_size']); ?>"
                                        data-cut="<?php echo esc_attr($slot['tshirt_cut']); ?>"
                                        data-focus="email"
                                        title="<?php esc_attr_e('Kliknij, aby edytować e-mail', 'ak-product-set'); ?>">
                                        <?php echo esc_html($email_label); ?>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <button type="button" class="button button-small ak-om-edit-participant-btn ak-om-edit-tshirt-btn"
                                    data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                    data-name="<?php echo esc_attr($slot['name']); ?>"
                                    data-email="<?php echo esc_attr($slot['email']); ?>"
                                    data-phone="<?php echo esc_attr($slot['phone']); ?>"
                                    data-size="<?php echo esc_attr($slot['tshirt_size']); ?>"
                                    data-cut="<?php echo esc_attr($slot['tshirt_cut']); ?>"
                                    data-focus="tshirt"
                                    title="<?php esc_attr_e('Kliknij, aby zmienić rozmiar / krój koszulki', 'ak-product-set'); ?>">
                                    <?php echo $shirt_btn_label; ?>
                                </button>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($is_inactive) : ?>
                                    <button type="button" class="button button-small button-primary ak-om-restore-btn"
                                        data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                        data-wid="<?php echo esc_attr($slot['weekend_id']); ?>"
                                        data-name="<?php echo esc_attr($slot['name']); ?>"
                                        data-title="<?php echo esc_attr($slot['weekend_name']); ?>"
                                        title="<?php esc_attr_e('Przywróć ten termin do aktywnych', 'ak-product-set'); ?>">
                                        <?php esc_html_e('Przywróć termin', 'ak-product-set'); ?>
                                    </button>
                                    <?php if ($retained_shirts > 0) : ?>
                                        <button type="button" class="button button-small ak-om-revert-fee-btn"
                                            data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                            data-wid="<?php echo esc_attr($slot['weekend_id']); ?>"
                                            title="<?php esc_attr_e('Cofnij opłatę za koszulkę (-60 zł)', 'ak-product-set'); ?>">
                                            <?php printf(esc_html__('Cofnij -%s zł', 'ak-product-set'), number_format_i18n($tshirt_cost, 0)); ?>
                                        </button>
                                    <?php endif; ?>
                                <?php else : ?>
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
                                    <?php if ($retained_shirts > 0) : ?>
                                        <button type="button" class="button button-small ak-om-revert-fee-btn"
                                            data-uuid="<?php echo esc_attr($slot['uuid']); ?>"
                                            data-wid="<?php echo esc_attr($slot['weekend_id']); ?>"
                                            title="<?php esc_attr_e('Cofnij opłatę za koszulkę (-60 zł)', 'ak-product-set'); ?>">
                                            <?php printf(esc_html__('Cofnij -%s zł', 'ak-product-set'), number_format_i18n($tshirt_cost, 0)); ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
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

    <!-- Modal: Edit Participant Data -->
    <div id="ak-om-modal-tshirt" class="ak-om-modal-bg">
        <div class="ak-om-modal-content">
            <h3><?php esc_html_e('Edytuj dane uczestnika', 'ak-product-set'); ?></h3>
            <p id="ak-om-tshirt-desc" style="color: #646970; font-size: 13px; margin-bottom: 12px;"></p>

            <div style="margin-bottom: 10px;">
                <label><strong><?php esc_html_e('Imię i nazwisko:', 'ak-product-set'); ?></strong> *</label><br>
                <input type="text" id="ak-om-edit-name" class="widefat" required>
            </div>

            <div style="margin-bottom: 10px;">
                <label><strong><?php esc_html_e('Adres e-mail:', 'ak-product-set'); ?></strong></label><br>
                <input type="email" id="ak-om-edit-email" class="widefat">
            </div>

            <div style="margin-bottom: 10px;">
                <label><strong><?php esc_html_e('Telefon:', 'ak-product-set'); ?></strong></label><br>
                <input type="text" id="ak-om-edit-phone" class="widefat">
            </div>

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

            <div style="background: #fff8e5; border: 1px solid #fce3a4; padding: 10px 12px; border-radius: 4px; margin-bottom: 14px;">
                <label style="cursor: pointer; display: flex; align-items: flex-start; gap: 8px;">
                    <input type="checkbox" id="ak-om-edit-tshirt-produced" value="1" style="margin-top: 2px;">
                    <span>
                        <strong><?php printf(esc_html__('Koszulka została już wyprodukowana (+%s zł)', 'ak-product-set'), number_format_i18n($tshirt_cost, 2)); ?></strong><br>
                        <span style="font-size: 11px; color: #666;">
                            <?php esc_html_e('Zaznacz, jeśli poprzednia koszulka została już wykonana i zmiana powoduje konieczność produkcji nowej (do zamówienia zostanie doliczona opłata za koszulkę).', 'ak-product-set'); ?>
                        </span>
                    </span>
                </label>
            </div>

            <p style="font-size: 11px; color: #666; margin-bottom: 16px;">
                <?php esc_html_e('Zmiany zostaną zapisane we wszystkich terminach tego uczestnika w zamówieniu.', 'ak-product-set'); ?>
            </p>
            <div style="text-align: right;">
                <button type="button" class="button ak-om-modal-cancel"><?php esc_html_e('Anuluj', 'ak-product-set'); ?></button>
                <button type="button" class="button button-primary ak-om-modal-submit-tshirt"><?php esc_html_e('Zapisz dane uczestnika', 'ak-product-set'); ?></button>
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

    // 2. Edit Participant Data (Name, Contact, T-Shirt)
    $('.ak-om-edit-participant-btn, .ak-om-edit-tshirt-btn').on('click', function(e) {
        e.preventDefault();
        activeUuid = $(this).data('uuid');
        var name   = $(this).data('name') || '';
        var email  = $(this).data('email') || '';
        var phone  = $(this).data('phone') || '';
        var size   = $(this).data('size') || '';
        var cut    = $(this).data('cut') || 'men';
        var focus  = $(this).data('focus') || 'name';

        $('#ak-om-tshirt-desc').html('Edytujesz dane uczestnika: <strong>' + name + '</strong>.');
        $('#ak-om-edit-name').val(name);
        $('#ak-om-edit-email').val(email);
        $('#ak-om-edit-phone').val(phone);
        $('#ak-om-edit-tshirt-size').val(size);
        $('#ak-om-edit-tshirt-cut').val(cut);
        $('#ak-om-edit-tshirt-produced').prop('checked', false);
        $('#ak-om-modal-tshirt').show();

        setTimeout(function() {
            if (focus === 'email') {
                $('#ak-om-edit-email').focus();
            } else if (focus === 'phone') {
                $('#ak-om-edit-phone').focus();
            } else if (focus === 'tshirt') {
                $('#ak-om-edit-tshirt-size').focus();
            } else {
                $('#ak-om-edit-name').focus();
            }
        }, 50);
    });

    $('.ak-om-modal-submit-tshirt').on('click', function() {
        var name = $('#ak-om-edit-name').val().trim();
        if (!name) {
            alert('<?php echo esc_js(__('Imię i nazwisko jest wymagane.', 'ak-product-set')); ?>');
            return;
        }

        var btn = $(this).prop('disabled', true);
        $.post(ajaxurl, {
            action: 'ak_update_participant',
            order_id: orderId,
            uuid: activeUuid,
            name: name,
            email: $('#ak-om-edit-email').val().trim(),
            phone: $('#ak-om-edit-phone').val().trim(),
            tshirt_size: $('#ak-om-edit-tshirt-size').val(),
            tshirt_cut: $('#ak-om-edit-tshirt-cut').val(),
            tshirt_produced: $('#ak-om-edit-tshirt-produced').is(':checked') ? 1 : 0,
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

    // 6b. Revert Settlement / Mark Unsettled
    $('.ak-om-mark-unsettled-btn').on('click', function() {
        if (!confirm('<?php echo esc_js(__('Czy na pewno chcesz cofnąć oznaczenie jako uregulowane i przywrócić pierwotne saldo rozliczenia?', 'ak-product-set')); ?>')) {
            return;
        }
        var btn = $(this).prop('disabled', true);
        $.post(ajaxurl, {
            action: 'ak_mark_balance_unsettled',
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

    // 7. Restore weekend enrollment
    $('.ak-om-restore-btn').on('click', function() {
        var uuid = $(this).data('uuid');
        var wid  = $(this).data('wid');
        var name = $(this).data('name');
        var title = $(this).data('title');

        if (!confirm('<?php echo esc_js(__('Czy na pewno chcesz przywrócić termin:', 'ak-product-set')); ?> ' + title + ' <?php echo esc_js(__('dla uczestnika:', 'ak-product-set')); ?> ' + name + '?')) {
            return;
        }

        var btn = $(this).prop('disabled', true);
        $.post(ajaxurl, {
            action: 'ak_restore_weekend_participant',
            order_id: orderId,
            uuid: uuid,
            weekend_id: wid,
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

    // 8. Revert T-shirt Fee
    $('.ak-om-revert-fee-btn').on('click', function() {
        var uuid = $(this).data('uuid') || '';
        var wid  = $(this).data('wid') || 0;

        if (!confirm('<?php echo esc_js(__('Czy na pewno chcesz cofnąć opłatę za wyprodukowaną koszulkę (-60 zł)? Kwota zamówienia i saldo zostaną przeliczone.', 'ak-product-set')); ?>')) {
            return;
        }

        var btn = $(this).prop('disabled', true);
        $.post(ajaxurl, {
            action: 'ak_revert_tshirt_fee',
            order_id: orderId,
            uuid: uuid,
            weekend_id: wid,
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
