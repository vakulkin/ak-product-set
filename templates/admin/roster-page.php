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

/* Status badges — colours mirror WooCommerce order status convention */
.ak-status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}
.ak-status-pending     { background: #e5e5e5; color: #555; }
.ak-status-processing  { background: #c6e1c6; color: #5b841b; }
.ak-status-on-hold     { background: #f8dda7; color: #94660c; }
.ak-status-completed   { background: #c8d7e1; color: #2e4453; }
.ak-status-cancelled   { background: #eba3a3; color: #761919; }
.ak-status-refunded    { background: #ddd;    color: #555; }
.ak-status-failed      { background: #eba3a3; color: #761919; }

/* Table overrides */
.ak-roster-table td { vertical-align: middle; }
.ak-roster-table th,
.ak-roster-table td { padding: 8px 12px; }
.ak-roster-table .col-id     { width: 90px; font-family: monospace; font-size: 11px; color: #8c8f94; }
.ak-roster-table .col-num    { width: 36px; text-align: center; color: #8c8f94; }
.ak-roster-table .col-shirt  { width: 90px; }
.ak-roster-table .col-cut    { width: 80px; }
.ak-roster-table .col-order  { width: 110px; }
.ak-roster-table .col-status { width: 130px; }
.ak-roster-table .col-paid   { width: 110px; }
.ak-roster-table .col-method { width: 140px; }

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

    <!-- Single toolbar row — always visible when there are any weekends ---- -->
    <?php if (!empty($weekends)) : ?>
        <div class="ak-roster-toolbar">
            <?php if ($csv_url) : ?>
                <a href="<?php echo esc_url($csv_url); ?>" class="button button-primary">
                    <?php esc_html_e('Pobierz CSV (ten termin)', 'ak-product-set'); ?>
                </a>
            <?php endif; ?>
            <a href="<?php echo esc_url($all_csv_url); ?>" class="button">
                <?php esc_html_e('Pobierz CSV (wszystkie terminy)', 'ak-product-set'); ?>
            </a>
            <?php if ($count > 0) : ?>
                <span class="ak-roster-count">
                    <?php echo esc_html(sprintf(
                        _n('%d uczestnik', '%d uczestników', $count, 'ak-product-set'),
                        $count
                    )); ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
        <!-- Table --------------------------------------------------------- -->
        <table class="wp-list-table widefat fixed striped ak-roster-table">
            <thead>
                <tr>
                    <th class="col-id" title="<?php esc_attr_e('Unikalny identyfikator uczestnika (UUID)', 'ak-product-set'); ?>">
                        <?php esc_html_e('ID uczestnika', 'ak-product-set'); ?>
                    </th>
                    <th class="col-num">#</th>
                    <th><?php esc_html_e('Imię i nazwisko', 'ak-product-set'); ?></th>
                    <th><?php esc_html_e('E-mail', 'ak-product-set'); ?></th>
                    <th><?php esc_html_e('Telefon', 'ak-product-set'); ?></th>
                    <th class="col-shirt"><?php esc_html_e('Koszulka', 'ak-product-set'); ?></th>
                    <th class="col-cut"><?php esc_html_e('Krój', 'ak-product-set'); ?></th>
                    <th><?php esc_html_e('Zestaw', 'ak-product-set'); ?></th>
                    <th class="col-order"><?php esc_html_e('Zamówienie', 'ak-product-set'); ?></th>
                    <th class="col-status"><?php esc_html_e('Status', 'ak-product-set'); ?></th>
                    <th class="col-paid"><?php esc_html_e('Data płatności', 'ak-product-set'); ?></th>
                    <th class="col-method"><?php esc_html_e('Metoda płatności', 'ak-product-set'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $i => $row) :
                    $can_link_order = current_user_can('edit_shop_orders');
                    $order_link     = admin_url('admin.php?page=wc-orders&action=edit&id=' . $row['order_id']);

                    $cut_label = '';
                    if ($row['tshirt_cut'] === 'women') {
                        $cut_label = __('Damska', 'ak-product-set');
                    } elseif ($row['tshirt_cut'] === 'men') {
                        $cut_label = __('Męska', 'ak-product-set');
                    }

                    $status_label = function_exists('wc_get_order_status_name')
                        ? wc_get_order_status_name($row['order_status'])
                        : esc_html($row['order_status']);
                    $status_class = 'ak-status-' . sanitize_html_class($row['order_status']);

                    $date_paid    = $row['date_paid'] ?? '';
                    $is_paid      = $date_paid !== '';
                ?>
                    <tr>
                        <td class="col-id">
                            <abbr title="<?php echo esc_attr($row['participant_id']); ?> (item: <?php echo esc_attr($row['order_item_id']); ?>)">
                                <?php echo esc_html(substr($row['participant_id'], 0, 8)); ?>
                            </abbr>
                        </td>
                        <td class="col-num"><?php echo $i + 1; ?></td>
                        <td><strong><?php echo esc_html($row['name']); ?></strong></td>
                        <td>
                            <?php if ($row['email']) : ?>
                                <a href="mailto:<?php echo esc_attr($row['email']); ?>">
                                    <?php echo esc_html($row['email']); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row['phone']); ?></td>
                        <td class="col-shirt"><?php echo esc_html($row['tshirt_size']); ?></td>
                        <td class="col-cut"><?php echo esc_html($cut_label); ?></td>
                        <td><?php echo esc_html($row['set_name']); ?></td>
                        <td class="col-order">
                            <?php if ($can_link_order) : ?>
                                <a href="<?php echo esc_url($order_link); ?>" target="_blank">
                                    #<?php echo esc_html($row['order_id']); ?>
                                </a>
                            <?php else : ?>
                                #<?php echo esc_html($row['order_id']); ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-status">
                            <span class="ak-status-badge <?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </td>
                        <td class="col-paid">
                            <?php if ($is_paid) : ?>
                                <span class="ak-status-badge ak-status-completed">
                                    <?php echo esc_html($date_paid); ?>
                                </span>
                            <?php else : ?>
                                <span class="ak-status-badge ak-status-pending">
                                    <?php esc_html_e('Nieopłacono', 'ak-product-set'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="col-method">
                            <?php echo esc_html($row['payment_method'] ?: '—'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div><!-- .ak-roster-wrap -->
