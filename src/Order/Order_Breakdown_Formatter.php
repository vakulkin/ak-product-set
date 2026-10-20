<?php

namespace AK_Set\Order;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Breakdown_Formatter {

    /**
     * Resolve human-readable group tier label.
     */
    public static function resolve_tier_label(string $tier): string {
        return match ($tier) {
            'g10'   => __('Grupa 10+ os. (g10)', 'ak-product-set'),
            'g5'    => __('Grupa 5-9 os. (g5)', 'ak-product-set'),
            default => __('Indywidualny (ind, <5 os.)', 'ak-product-set'),
        };
    }

    /**
     * Format a single cohort pricing breakdown block.
     *
     * @param string[]|array[] $participants
     * @param string[]         $weekend_titles
     */
    public static function format_cohort_section(
        int $cohort_index,
        string $set_title,
        array $participants,
        int $package_size,
        array $weekend_titles,
        int $round,
        string $tier,
        float $per_person_price,
        int $headcount,
        float $cohort_subtotal,
        string $unit_label = 'weekendów'
    ): string {
        $tier_label = self::resolve_tier_label($tier);
        $participant_names = array_map(function ($p) {
            if (is_array($p)) {
                return (string) (($p['name'] ?? '') ?: ($p['id'] ?? ''));
            }
            return (string) $p;
        }, $participants);

        return sprintf(
            "Pakiet %d: [%s]\n• %s: %s\n• Liczba %s: %d (%s)\n• Runda: %d | Próg grupy: %s\n• Stawka: %s zł / os. | Wartość: %d os. x %s zł = %s zł",
            $cohort_index,
            $set_title,
            __('Uczestnicy', 'ak-product-set'),
            implode(', ', $participant_names),
            $unit_label,
            $package_size,
            implode(', ', $weekend_titles),
            $round,
            $tier_label,
            number_format_i18n($per_person_price, 2),
            $headcount,
            number_format_i18n($per_person_price, 2),
            number_format_i18n($cohort_subtotal, 2)
        );
    }

    /**
     * Resolve the balance status line for the breakdown report.
     */
    public static function resolve_balance_line(string $status, float $balance, float $total_paid = 0.0, bool $for_cancellation = false): string {
        if ($for_cancellation) {
            return match ($status) {
                Order_Recalculator::BALANCE_OVERPAID  => sprintf(__('BILANS: Nadpłata %s zł (do zwrotu klientowi)', 'ak-product-set'), number_format_i18n($balance, 2)),
                Order_Recalculator::BALANCE_UNPAID    => sprintf(__('BILANS: Nieopłacone +%s zł (opłata za koszulki, oczekuje na płatność)', 'ak-product-set'), number_format_i18n($balance, 2)),
                Order_Recalculator::BALANCE_UNDERPAID => sprintf(__('BILANS: Niedopłata +%s zł (klient zalega z płatnością)', 'ak-product-set'), number_format_i18n($balance, 2)),
                default                               => ($total_paid > 0.009)
                    ? __('BILANS: Uregulowane (brak zaległości)', 'ak-product-set')
                    : __('BILANS: Uregulowane (brak zaległości i wpłat)', 'ak-product-set'),
            };
        }

        return match ($status) {
            Order_Recalculator::BALANCE_UNPAID    => sprintf(__('BILANS: Nieopłacone +%s zł (oczekuje na płatność)', 'ak-product-set'), number_format_i18n($balance, 2)),
            Order_Recalculator::BALANCE_UNDERPAID => sprintf(__('BILANS: Niedopłata +%s zł (klient zalega z płatnością)', 'ak-product-set'), number_format_i18n($balance, 2)),
            Order_Recalculator::BALANCE_OVERPAID  => sprintf(__('BILANS: Nadpłata %s zł (do zwrotu klientowi)', 'ak-product-set'), number_format_i18n($balance, 2)),
            default                               => __('BILANS: Uregulowane (brak zaległości)', 'ak-product-set'),
        };
    }

    /**
     * Resolve balance label for the audit log entry.
     */
    public static function resolve_log_status(string $status, float $balance, float $total_paid = 0.0, bool $for_cancellation = false): string {
        if ($for_cancellation) {
            return match ($status) {
                Order_Recalculator::BALANCE_OVERPAID  => sprintf(__('Nadpłata do zwrotu: %s zł', 'ak-product-set'), number_format_i18n(abs($balance), 2)),
                Order_Recalculator::BALANCE_UNPAID    => sprintf(__('Nieopłacone: %s zł', 'ak-product-set'), number_format_i18n($balance, 2)),
                Order_Recalculator::BALANCE_UNDERPAID => sprintf(__('Niedopłata: +%s zł', 'ak-product-set'), number_format_i18n($balance, 2)),
                default                               => ($total_paid > 0.009)
                    ? __('Uregulowane', 'ak-product-set')
                    : __('Uregulowane (brak wpłat)', 'ak-product-set'),
            };
        }

        return match ($status) {
            Order_Recalculator::BALANCE_UNPAID    => sprintf(__('Nieopłacone: %s zł (oczekuje na płatność)', 'ak-product-set'), number_format_i18n($balance, 2)),
            Order_Recalculator::BALANCE_UNDERPAID => sprintf(__('Niedopłata: +%s zł (klient zalega)', 'ak-product-set'), number_format_i18n($balance, 2)),
            Order_Recalculator::BALANCE_OVERPAID  => sprintf(__('Nadpłata: %s zł (do zwrotu)', 'ak-product-set'), number_format_i18n($balance, 2)),
            default                               => __('Uregulowane', 'ak-product-set'),
        };
    }

    /**
     * Format full breakdown string when recalculation results in 0 participants (cancellation).
     */
    public static function format_cancellation_breakdown(
        string $reason,
        string $fees_section,
        float $new_total,
        float $total_paid,
        string $balance_line
    ): string {
        return sprintf(
            "==================================================\n%s\n%s\n--------------------------------------------------\nŁączna wartość pakietów: 0,00 zł\n%s--------------------------------------------------\nNowa łączna kwota zamówienia: %s zł\nPierwotnie wpłacono: %s zł\n%s\n==================================================",
            __('SZCZEGÓŁOWA KALKULACJA CENY (PRZELICZENIE):', 'ak-product-set'),
            $reason,
            $fees_section,
            number_format_i18n($new_total, 2),
            number_format_i18n($total_paid, 2),
            $balance_line
        );
    }

    /**
     * Format full breakdown string for normal recalculation.
     *
     * @param string[] $breakdown_sections
     */
    public static function format_recalculation_breakdown(
        string $base_date_formatted,
        int $order_id,
        array $breakdown_sections,
        float $total_base,
        string $fees_section,
        float $new_total,
        float $total_paid,
        string $balance_line
    ): string {
        return sprintf(
            "==================================================\n%s\nData bazowa rygoru rundy: %s (zamówienie #%d)\n--------------------------------------------------\n%s\n--------------------------------------------------\nŁączna wartość bazowa pakietów: %s zł\n%s--------------------------------------------------\nNowa łączna kwota zamówienia: %s zł\nPierwotnie wpłacono: %s zł\n%s\n==================================================",
            __('SZCZEGÓŁOWA KALKULACJA CENY (PRZELICZENIE):', 'ak-product-set'),
            $base_date_formatted,
            $order_id,
            implode("\n--------------------------------------------------\n", $breakdown_sections),
            number_format_i18n($total_base, 2),
            $fees_section,
            number_format_i18n($new_total, 2),
            number_format_i18n($total_paid, 2),
            $balance_line
        );
    }

    /**
     * Format initial order calculation breakdown string.
     *
     * @param string[] $breakdown_sections
     */
    public static function format_initial_order_breakdown(
        string $date_formatted,
        int $order_id,
        array $breakdown_sections,
        float $total,
        bool $is_order_settled
    ): string {
        return sprintf(
            "==================================================\n%s\nData zamówienia: %s (zamówienie #%d)\n--------------------------------------------------\n%s\n--------------------------------------------------\nŁączna kwota zamówienia: %s zł\nWpłacono: %s zł\nBILANS: %s\n==================================================",
            __('SZCZEGÓŁOWA KALKULACJA CENY (NOWE ZAMÓWIENIE):', 'ak-product-set'),
            $date_formatted,
            $order_id,
            !empty($breakdown_sections) ? implode("\n--------------------------------------------------\n", $breakdown_sections) : __('Standardowy pakiet z koszyka', 'ak-product-set'),
            number_format_i18n($total, 2),
            number_format_i18n($is_order_settled ? $total : 0.0, 2),
            $is_order_settled
                ? __('Uregulowane (brak zaległości)', 'ak-product-set')
                : __('Nieopłacone (oczekuje na płatność)', 'ak-product-set')
        );
    }

    /**
     * Format snapshot summary of participants and their weekends.
     */
    public static function format_order_state_summary(array $participants_map): string {
        $summary_parts = [];
        foreach ($participants_map as $p_info) {
            $count = count($p_info['weekends']);
            $summary_parts[] = sprintf(
                '%s (%d %s: %s)',
                $p_info['name'],
                $count,
                ($count === 1) ? __('termin', 'ak-product-set') : (($count >= 2 && $count <= 4) ? __('terminy', 'ak-product-set') : __('terminów', 'ak-product-set')),
                implode(', ', $p_info['weekends'])
            );
        }

        return !empty($summary_parts) ? implode('; ', $summary_parts) : __('Brak uczestników', 'ak-product-set');
    }

    /**
     * Format audit log entry before/after transition.
     */
    public static function format_audit_log_entry(
        string $reason,
        float $before_total,
        string $before_summary,
        float $after_total,
        string $after_status_label,
        string $after_summary
    ): string {
        return sprintf(
            "[%s] %s\n• PRZED ZMIANĄ (%s zł):\n  %s\n• PO ZMIANIE (%s zł | %s):\n  %s",
            current_time('Y-m-d H:i'),
            $reason,
            number_format_i18n($before_total, 2),
            $before_summary,
            number_format_i18n($after_total, 2),
            $after_status_label,
            $after_summary
        );
    }
}
