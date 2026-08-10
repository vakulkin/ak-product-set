<?php

namespace AK_Set\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Roster_Admin_Page {

    const MENU_SLUG    = 'ak-roster';
    const NONCE_ACTION = 'ak_roster_export';

    public function init(): void {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_export']);
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function register_menu(): void {
        add_menu_page(
            __('Rejestr uczestników', 'ak-product-set'),
            __('Rejestr', 'ak-product-set'),
            'ak_view_roster',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-groups',
            30
        );
    }

    // -------------------------------------------------------------------------
    // Page rendering
    // -------------------------------------------------------------------------

    public function render_page(): void {
        if (!current_user_can('ak_view_roster')) {
            wp_die(esc_html__('Brak uprawnień.', 'ak-product-set'));
        }

        $selected_weekend = isset($_GET['weekend_id']) ? (int) $_GET['weekend_id'] : 0;
        $weekends         = Roster_Query::get_weekends_for_selector();
        $participants     = $selected_weekend > 0
            ? Roster_Query::get_participants_by_weekend($selected_weekend)
            : [];
        $nonce            = wp_create_nonce(self::NONCE_ACTION);

        $template = AK_SET_PATH . 'templates/admin/roster-page.php';
        if (file_exists($template)) {
            include $template;
        }
    }

    // -------------------------------------------------------------------------
    // Export handler (fires on admin_init, before any output)
    // -------------------------------------------------------------------------

    public function handle_export(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) {
            return;
        }
        if (!isset($_GET['ak_export'])) {
            return;
        }

        if (!current_user_can('ak_view_roster')) {
            wp_die(esc_html__('Brak uprawnień.', 'ak-product-set'));
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_key($_GET['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Błąd bezpieczeństwa — odśwież stronę i spróbuj ponownie.', 'ak-product-set'));
        }

        $type = sanitize_key($_GET['ak_export']);

        if ($type === 'csv') {
            $weekend_id = isset($_GET['weekend_id']) ? (int) $_GET['weekend_id'] : 0;
            if ($weekend_id > 0) {
                $this->stream_csv_for_weekend($weekend_id);
                exit;
            }
        } elseif ($type === 'all') {
            $this->stream_csv_all_weekends();
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Export: single weekend CSV
    // -------------------------------------------------------------------------

    private function stream_csv_for_weekend(int $weekend_id): void {
        $product      = wc_get_product($weekend_id);
        $safe_title   = $product
            ? sanitize_file_name($product->get_name())
            : 'weekend-' . $weekend_id;
        $participants = Roster_Query::get_participants_by_weekend($weekend_id);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rejestr-' . $safe_title . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        $this->write_csv_headers($out);
        $this->write_csv_rows($out, $participants);
        fclose($out);
    }

    // -------------------------------------------------------------------------
    // Export: all weekends as a single flat CSV
    // -------------------------------------------------------------------------

    /**
     * Stream one CSV file containing every weekend's participants.
     * A leading "Termin" column identifies which weekend each row belongs to.
     * Rows are grouped by weekend in the same order as the selector.
     */
    private function stream_csv_all_weekends(): void {
        $all = Roster_Query::get_all_weekends_with_participants();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rejestr-wszystkie-terminy.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        $this->write_csv_headers($out, true);

        $row_number = 1;
        foreach ($all as $group) {
            $weekend_label = $group['weekend_title'];
            $this->write_csv_rows($out, $group['participants'], $weekend_label, $row_number);
            $row_number += count($group['participants']);
        }

        fclose($out);
    }

    // -------------------------------------------------------------------------
    // CSV helpers
    // -------------------------------------------------------------------------

    /**
     * @param resource $handle
     * @param bool     $with_weekend  Whether to include the leading "Termin" column
     */
    private function write_csv_headers($handle, bool $with_weekend = false): void {
        $columns = [];
        if ($with_weekend) {
            $columns[] = __('Termin', 'ak-product-set');
        }
        $columns = array_merge($columns, [
            'ID uczestnika',
            'ID pozycji zamówienia',
            '#',
            __('Imię i nazwisko', 'ak-product-set'),
            __('E-mail', 'ak-product-set'),
            __('Telefon', 'ak-product-set'),
            __('Koszulka', 'ak-product-set'),
            __('Krój', 'ak-product-set'),
            __('Zestaw', 'ak-product-set'),
            __('Nr zamówienia', 'ak-product-set'),
            __('Status zamówienia', 'ak-product-set'),
            __('Data płatności', 'ak-product-set'),
            __('Metoda płatności', 'ak-product-set'),
        ]);
        fputcsv($handle, $columns);
    }

    /**
     * @param resource $handle
     * @param array[]  $participants
     * @param string   $weekend_label  Non-empty string prepends a Termin cell to every row
     * @param int      $start_index    Continuation row counter for multi-group CSVs
     */
    private function write_csv_rows($handle, array $participants, string $weekend_label = '', int $start_index = 1): void {
        $i = $start_index;
        foreach ($participants as $row) {
            $cut = '';
            if ($row['tshirt_cut'] === 'women') {
                $cut = __('Damska', 'ak-product-set');
            } elseif ($row['tshirt_cut'] === 'men') {
                $cut = __('Męska', 'ak-product-set');
            }

            $values = [];
            if ($weekend_label !== '') {
                $values[] = $weekend_label;
            }
            $values = array_merge($values, [
                $row['participant_id'],
                $row['order_item_id'],
                $i++,
                $row['name'],
                $row['email'],
                $row['phone'],
                $row['tshirt_size'],
                $cut,
                $row['set_name'],
                $row['order_id'],
                function_exists('wc_get_order_status_name')
                    ? wc_get_order_status_name($row['order_status'])
                    : $row['order_status'],
                $row['date_paid'],
                $row['payment_method'],
            ]);

            fputcsv($handle, $values);
        }
    }
}
