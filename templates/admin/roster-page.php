<?php
/**
 * Template: Admin Roster Page
 *
 * Variables available from Roster_Admin_Page::render_page():
 *   $weekends         array<int, string>  product_id => title
 *   $selected_weekend int                 currently selected weekend product ID (0 = none)
 *   $participants     array[]             participant rows from Roster_Query
 *   $nonce            string              nonce for export links
 */

if (!defined('ABSPATH')) {
    exit;
}

use AK_Set\Admin\Roster_Admin_Page;

$participants     = $participants ?? [];
$selected_weekend = $selected_weekend ?? 0;
$weekends         = $weekends ?? [];

$count       = count($participants);
$base_url    = admin_url('admin.php?page=' . Roster_Admin_Page::MENU_SLUG);
$csv_url     = $selected_weekend
    ? wp_nonce_url($base_url . '&weekend_id=' . $selected_weekend . '&ak_export=csv', Roster_Admin_Page::NONCE_ACTION)
    : '';
$all_csv_url = wp_nonce_url($base_url . '&ak_export=all', Roster_Admin_Page::NONCE_ACTION);
?>
<div class="wrap ak-roster-wrap">

<style>
/* ── AK Roster — scoped admin styles ──────────────────────────────────── */
.ak-roster-wrap h1 { margin-bottom: 16px; }

/* Filter bar */
.ak-roster-filter {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 14px 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.ak-roster-filter label {
    font-weight: 600;
    white-space: nowrap;
}
.ak-roster-filter select {
    min-width: 280px;
    max-width: 480px;
}

/* Toolbar */
.ak-roster-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.ak-roster-count {
    margin-left: auto;
    color: #50575e;
    font-size: 13px;
}

/* Status badges — clean, subtle WordPress admin style */
.ak-status-badge {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    white-space: nowrap;
    border: 1px solid transparent;
    line-height: 1.3;
}
.ak-status-pending     { background: #f6f7f7; color: #50575e; border-color: #dcdcde; }
.ak-status-processing  { background: #edf5fd; color: #1a73e8; border-color: #c5dcfa; }
.ak-status-on-hold     { background: #fef8ec; color: #8c5b00; border-color: #fae2b6; }
.ak-status-completed   { background: #edf7ed; color: #2e7d32; border-color: #c3e6c3; }
.ak-status-cancelled   { background: #f6f7f7; color: #646970; border-color: #dcdcde; }
.ak-status-refunded    { background: #f6f7f7; color: #646970; border-color: #dcdcde; }
.ak-status-failed      { background: #fdf0f0; color: #b32d2e; border-color: #f8c8c8; }

/* Table overrides */
.ak-roster-table td { vertical-align: middle; padding: 10px 12px; }
.ak-roster-table th { padding: 9px 12px; }
.ak-roster-table .col-meta        { width: 115px; font-size: 12px; line-height: 1.45; }
.ak-roster-table .col-participant { min-width: 190px; line-height: 1.45; }
.ak-roster-table .col-shirt       { width: 125px; }
.ak-roster-table .col-set         { width: 130px; }
.ak-roster-table .col-payment     { width: 250px; line-height: 1.45; }

/* Empty state */
.ak-roster-empty {
    padding: 40px 0;
    text-align: center;
    color: #8c8f94;
    font-size: 14px;
}
.ak-roster-empty .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    display: block;
    margin: 0 auto 12px;
    color: #c3c4c7;
}
</style>

    <h1><?php esc_html_e('Rejestr uczestników', 'ak-product-set'); ?></h1>

    <!-- Weekend selector -------------------------------------------------- -->
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="<?php echo esc_attr(Roster_Admin_Page::MENU_SLUG); ?>">
        <div class="ak-roster-filter">
            <label for="ak-weekend-select">
                <?php esc_html_e('Wybierz termin:', 'ak-product-set'); ?>
            </label>
            <select id="ak-weekend-select" name="weekend_id">
                <option value="">— <?php esc_html_e('wybierz termin', 'ak-product-set'); ?> —</option>
                <?php foreach ($weekends as $pid => $title) : ?>
                    <option value="<?php echo esc_attr($pid); ?>"
                        <?php selected($selected_weekend, $pid); ?>>
                        <?php echo esc_html($title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Pokaż', 'ak-product-set'), 'primary', 'submit', false); ?>
        </div>
    </form>

    <!-- Single toolbar row — always visible ---- -->
    <div class="ak-roster-toolbar">
        <?php if ($csv_url) : ?>
            <a href="<?php echo esc_url($csv_url); ?>" class="button button-primary">
                <?php esc_html_e('Pobierz CSV [ten termin]', 'ak-product-set'); ?>
            </a>
        <?php endif; ?>
        <a href="<?php echo esc_url($all_csv_url); ?>" class="button">
            <?php esc_html_e('Pobierz CSV [wszystkie terminy]', 'ak-product-set'); ?>
        </a>
        <?php if ($count > 0) : ?>
            <span class="ak-roster-count">
                <?php echo esc_html(sprintf(
                    /* translators: %d: number of participants */
                    _n('%d uczestnik', '%d uczestników', $count, 'ak-product-set'),
                    $count
                )); ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (!$selected_weekend) : ?>
        <!-- Prompt state -------------------------------------------------- -->
        <div class="ak-roster-empty">
            <span class="dashicons dashicons-groups"></span>
            <?php esc_html_e('Wybierz termin z listy, aby wyświetlić uczestników.', 'ak-product-set'); ?>
        </div>

    <?php elseif (empty($participants)) : ?>
        <!-- No participants ----------------------------------------------- -->
        <div class="ak-roster-empty">
            <span class="dashicons dashicons-info-outline"></span>
            <?php esc_html_e('Brak uczestników dla wybranego terminu.', 'ak-product-set'); ?>
        </div>

    <?php else : ?>
        <!-- Table: Compact 5-column layout --------------------------------- -->
        <table class="wp-list-table widefat fixed striped ak-roster-table">
            <thead>
                <tr>
                    <th class="col-meta"><?php esc_html_e('# / ID / Zamówienie', 'ak-product-set'); ?></th>
                    <th class="col-participant"><?php esc_html_e('Uczestnik i kontakt', 'ak-product-set'); ?></th>
                    <th class="col-shirt"><?php esc_html_e('Koszulka', 'ak-product-set'); ?></th>
                    <th class="col-set"><?php esc_html_e('Zestaw', 'ak-product-set'); ?></th>
                    <th class="col-payment"><?php esc_html_e('Płatność i Rozliczenie', 'ak-product-set'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $i => $row) :
                    $can_link_order = current_user_can('edit_shop_orders');
                    $order_link     = admin_url('admin.php?page=wc-orders&action=edit&id=' . $row['order_id']);

                    $cut_label = !empty($row['tshirt_cut']) ? \AK_Set\Support\Helper::get_tshirt_cut_label($row['tshirt_cut'], false) : '';

                    $status_label = function_exists('wc_get_order_status_name')
                        ? wc_get_order_status_name($row['order_status'])
                        : esc_html($row['order_status']);
                    $status_class = 'ak-status-' . sanitize_html_class($row['order_status']);

                    $bal_status = $row['balance_status'] ?? \AK_Set\Order\Order_Recalculator::BALANCE_SETTLED;
                    $bal_amount = (float) ($row['balance_amount'] ?? 0.0);

                    $date_paid    = $row['date_paid'] ?? '';
                    $is_paid      = $date_paid !== '';
                ?>
                    <tr>
                        <!-- 1. # / ID / Zamówienie (3 lines) -->
                        <td class="col-meta">
                            <div style="font-weight: 600; color: #1d2327;">#<?php echo $i + 1; ?></div>
                            <div>
                                <abbr title="<?php echo esc_attr($row['participant_id']); ?> (item: <?php echo esc_attr($row['order_item_id']); ?>)" style="text-decoration: none;">
                                    <code style="font-size: 11px; color: #646970; background: transparent; padding: 0;">
                                        <?php echo esc_html(substr($row['participant_id'], 0, 8)); ?>
                                    </code>
                                </abbr>
                            </div>
                            <div>
                                <?php if ($can_link_order) : ?>
                                    <a href="<?php echo esc_url($order_link); ?>" target="_blank" style="font-weight: 600;">
                                        #<?php echo esc_html($row['order_id']); ?> ↗
                                    </a>
                                <?php else : ?>
                                    <span style="color: #646970;">#<?php echo esc_html($row['order_id']); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- 2. Uczestnik i kontakt (3 lines) -->
                        <td class="col-participant">
                            <div><strong><?php echo esc_html($row['name']); ?></strong></div>
                            <?php if ($row['email']) : ?>
                                <div><a href="mailto:<?php echo esc_attr($row['email']); ?>"><?php echo esc_html($row['email']); ?></a></div>
                            <?php endif; ?>
                            <?php if ($row['phone']) : ?>
                                <div style="color: #646970; font-size: 12px;"><?php echo esc_html($row['phone']); ?></div>
                            <?php endif; ?>
                        </td>

                        <!-- 3. Koszulka (size + cut in one cell) -->
                        <td class="col-shirt">
                            <?php
                            if (!empty($row['tshirt_size'])) {
                                echo esc_html($row['tshirt_size']);
                                if ($cut_label) {
                                    echo ' [' . esc_html($cut_label) . ']';
                                }
                            } else {
                                echo '<span style="color: #8c8f94;">—</span>';
                            }
                            ?>
                        </td>

                        <!-- 4. Zestaw -->
                        <td class="col-set">
                            <?php echo esc_html($row['set_name'] ?: '—'); ?>
                        </td>

                        <!-- 5. Płatność i rozliczenie (status, balance, method, date) -->
                        <td class="col-payment">
                            <div style="display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 4px;">
                                <span class="ak-status-badge <?php echo esc_attr($status_class); ?>">
                                    <?php echo esc_html($status_label); ?>
                                </span>
                                <?php if ($bal_status === \AK_Set\Order\Order_Recalculator::BALANCE_UNDERPAID) : ?>
                                    <span class="ak-status-badge ak-status-failed" title="<?php esc_attr_e('Klient zalega z płatnością', 'ak-product-set'); ?>">
                                        <?php printf(esc_html__('Niedopłata (+%s zł)', 'ak-product-set'), number_format_i18n(abs($bal_amount), 2)); ?>
                                    </span>
                                <?php elseif ($bal_status === \AK_Set\Order\Order_Recalculator::BALANCE_OVERPAID) : ?>
                                    <span class="ak-status-badge ak-status-on-hold" title="<?php esc_attr_e('Nadpłata do zwrotu klientowi', 'ak-product-set'); ?>">
                                        <?php printf(esc_html__('Nadpłata (%s zł)', 'ak-product-set'), number_format_i18n(abs($bal_amount), 2)); ?>
                                    </span>
                                <?php elseif ($bal_status === \AK_Set\Order\Order_Recalculator::BALANCE_UNPAID || !$is_paid) : ?>
                                    <span class="ak-status-badge ak-status-failed" title="<?php esc_attr_e('Zamówienie nieopłacone', 'ak-product-set'); ?>">
                                        <?php esc_html_e('Nieopłacone', 'ak-product-set'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="ak-status-badge ak-status-completed">
                                        <?php esc_html_e('Rozliczone', 'ak-product-set'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="color: #646970; font-size: 12px;">
                                <?php echo esc_html($row['payment_method'] ?: '—'); ?>
                                <?php if ($is_paid) : ?>
                                    • <span><?php echo esc_html($date_paid); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div><!-- .ak-roster-wrap -->
