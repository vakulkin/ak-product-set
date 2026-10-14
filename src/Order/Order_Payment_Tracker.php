<?php

namespace AK_Set\Order;

use AK_Set\Order\Order_Recalculator;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Payment_Tracker {

    /**
     * Flag to ignore internal status transitions (such as un-cancelling or recalculation).
     */
    public static bool $is_internal_transition = false;

    public function init(): void {
        add_action('woocommerce_payment_complete', [$this, 'on_payment_complete'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'on_order_paid'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'on_order_paid'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 10, 4);
    }

    /**
     * Triggered directly when a payment gateway confirms payment.
     *
     * @param int|\WC_Order $order_id
     */
    public function on_payment_complete($order_id): void {
        if (self::$is_internal_transition) {
            return;
        }
        $order = $order_id instanceof \WC_Order ? $order_id : (function_exists('wc_get_order') ? wc_get_order($order_id) : null);
        if ($order) {
            $this->record_initial_payment($order);
        }
    }

    /**
     * Triggered when order enters processing or completed status.
     *
     * @param int|\WC_Order $order_id
     */
    public function on_order_paid($order_id): void {
        if (self::$is_internal_transition) {
            return;
        }
        $order = $order_id instanceof \WC_Order ? $order_id : (function_exists('wc_get_order') ? wc_get_order($order_id) : null);
        if ($order) {
            $this->record_initial_payment($order);
        }
    }

    /**
     * Triggered on general status transition.
     *
     * @param int $order_id
     * @param string $status_from
     * @param string $status_to
     * @param \WC_Order|null $order
     */
    public function on_order_status_changed($order_id, $status_from, $status_to, $order = null): void {
        if (self::$is_internal_transition) {
            return;
        }
        if (!($order instanceof \WC_Order)) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        }
        if (!$order) {
            return;
        }

        if (in_array($status_to, ['processing', 'completed'], true)) {
            $this->record_initial_payment($order);
        }
    }

    /**
     * Record immutable initial payment metadata and settle order balance if previously unpaid.
     */
    public function record_initial_payment(\WC_Order $order): void {
        if (self::$is_internal_transition) {
            return;
        }
        // Only track AK orders
        $is_ak_order = (bool)$order->get_meta('_ak_order_decomposed')
            || (bool)$order->get_meta('_ak_parent_set_id')
            || (bool)$order->get_meta('_ak_set_id')
            || (bool)$order->get_meta('_ak_original_total');

        if (!$is_ak_order) {
            return;
        }

        // Avoid duplicate recording, but auto-heal if previously corrupted to a lower fee amount
        if ($order->get_meta('_ak_initial_payment_recorded') === 'yes') {
            $recorded_amount = (float)$order->get_meta('_ak_initial_payment_amount');
            $orig_total      = (float)$order->get_meta('_ak_original_total');
            if ($orig_total > 0.009 && abs($recorded_amount - $orig_total) > 0.009) {
                // Heal corrupted initial payment to original gateway checkout total
                $order->update_meta_data('_ak_initial_payment_amount', $orig_total);
                $order->save();
            }
            return;
        }

        $orig = $order->get_meta('_ak_original_total');
        if ($orig !== '' && $orig !== false && (float)$orig > 0.009) {
            $total = (float)$orig;
        } else {
            $total = (float)$order->get_total();
        }

        $payment_info = self::record_initial_payment_metadata($order, $total);
        $method_title = $payment_info['method'];
        $txn_id       = $payment_info['txn_id'];

        $current_paid = (float)$order->get_meta('_ak_total_paid');
        if ($current_paid < 0.009) {
            $order->update_meta_data('_ak_total_paid', $total);
        }

        // If order was in unpaid state, settle it and update breakdown text
        $balance_status = (string)$order->get_meta('_ak_balance_status');
        if ($balance_status === Order_Recalculator::BALANCE_UNPAID || $balance_status === '') {
            $order->update_meta_data('_ak_balance_status', Order_Recalculator::BALANCE_SETTLED);
            $order->update_meta_data('_ak_balance_amount', 0.0);

            $breakdown = (string)$order->get_meta('_ak_pricing_breakdown');
            if ($breakdown !== '') {
                $formatted_total = number_format_i18n($total, 2);
                $breakdown = preg_replace('/(Pierwotnie wpłacono:\s*)0(?:[,\.]00)?\s*zł/iu', '${1}' . $formatted_total . ' zł', $breakdown);
                $breakdown = preg_replace('/(Wpłacono:\s*)0(?:[,\.]00)?\s*zł/iu', '${1}' . $formatted_total . ' zł', $breakdown);
                $breakdown = preg_replace('/BILANS:\s*Nieopłacone[^\n]*/iu', 'BILANS: ' . __('Uregulowane (brak zaległości)', 'ak-product-set'), $breakdown);
                $order->update_meta_data('_ak_pricing_breakdown', $breakdown);
            }
        }

        $log_entry = sprintf(
            '[%s] %s: %s zł (%s)%s',
            current_time('Y-m-d H:i'),
            __('Zarejestrowano płatność początkową zamówienia', 'ak-product-set'),
            number_format_i18n($total, 2),
            $method_title,
            $txn_id !== '' ? ' [TXN: ' . $txn_id . ']' : ''
        );
        Order_Recalculator::append_audit_log($order, $log_entry);
        $order->save();
    }

    /**
     * Resolve payment state (immutable gateway payment baseline, additional payments, and total paid).
     * Automatically heals corrupted total_paid metadata (e.g. if set to 60 PLN fee) and resolves initial gateway payment.
     *
     * @return array{
     *     gateway_paid: float,
     *     additional_paid: float,
     *     total_paid: float,
     *     init_method: string,
     *     init_amount: float,
     *     init_date: string,
     *     init_txn: string,
     *     init_recorded: bool
     * }
     */
    public static function resolve_order_payment_state(\WC_Order $order): array {
        $init_method = (string) Order_Recalculator::get_order_meta($order, '_ak_initial_payment_method', '');
        $raw_init_amount = Order_Recalculator::get_order_meta($order, '_ak_initial_payment_amount', null);
        $init_date = (string) Order_Recalculator::get_order_meta($order, '_ak_initial_payment_date', '');
        $init_txn = (string) Order_Recalculator::get_order_meta($order, '_ak_initial_payment_txn_id', '');
        $init_recorded = Order_Recalculator::get_order_meta($order, '_ak_initial_payment_recorded') === 'yes';

        $raw_total_paid = Order_Recalculator::get_order_meta($order, '_ak_total_paid', null);
        $status = Order_Recalculator::get_order_status($order);
        $is_paid = Order_Recalculator::is_order_paid($order);

        $original_total = (float) Order_Recalculator::get_order_meta($order, '_ak_original_total', 0.0);

        $has_paid_flag = $is_paid
            || in_array($status, ['processing', 'completed'], true)
            || $init_recorded
            || ($raw_total_paid !== null && (float) $raw_total_paid > 0.009);

        // Gateway payment baseline (must strictly be original checkout sum if order was paid through gateway)
        if ($has_paid_flag) {
            if ($original_total > 0.009) {
                // Auto-heal if init_amount was corrupted (e.g. 60 zł on #4508)
                if ($raw_init_amount === null || abs((float) $raw_init_amount - $original_total) > 0.009) {
                    $gateway_paid = $original_total;
                    Order_Recalculator::set_order_meta($order, '_ak_initial_payment_amount', $original_total);
                    Order_Recalculator::set_order_meta($order, '_ak_initial_payment_recorded', 'yes');
                    $init_recorded = true;
                } else {
                    $gateway_paid = (float) $raw_init_amount;
                }
            } else {
                $gateway_paid = ($raw_init_amount !== null) ? (float) $raw_init_amount : 0.0;
            }
        } else {
            $gateway_paid = ($raw_init_amount !== null) ? (float) $raw_init_amount : 0.0;
        }

        $total_paid = $gateway_paid;
        Order_Recalculator::set_order_meta($order, '_ak_total_paid', $total_paid);

        // Fallback for payment details if not recorded
        if (!$init_recorded && ($total_paid > 0.009 || $has_paid_flag)) {
            if (!$init_method) {
                try {
                    if (is_callable([$order, 'get_payment_method_title'])) {
                        $init_method = (string) $order->get_payment_method_title();
                    }
                } catch (\Throwable $e) {}
            }
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
            if (!$init_date) {
                try {
                    if (is_callable([$order, 'get_date_paid']) && $order->get_date_paid()) {
                        $init_date = $order->get_date_paid()->date('Y-m-d H:i');
                    } elseif ($order->get_date_created()) {
                        $init_date = $order->get_date_created()->date('Y-m-d H:i');
                    }
                } catch (\Throwable $e) {}
            }
            if (!$init_txn) {
                try {
                    if (is_callable([$order, 'get_transaction_id'])) {
                        $init_txn = (string) $order->get_transaction_id();
                    }
                } catch (\Throwable $e) {}
            }
            $init_recorded = true;
        }

        return [
            'gateway_paid'    => $gateway_paid,
            'total_paid'      => $total_paid,
            'init_method'     => $init_method,
            'init_amount'     => $gateway_paid,
            'init_date'       => $init_date,
            'init_txn'        => $init_txn,
            'init_recorded'   => $init_recorded,
        ];
    }

    /**
     * Safely resolve gateway payment details and record the initial payment metadata fields on the order.
     *
     * @return array{method: string, method_id: string, date_paid: string, txn_id: string}
     */
    public static function record_initial_payment_metadata(\WC_Order $order, float $total): array {
        $method_title = '';
        try {
            if (is_callable([$order, 'get_payment_method_title'])) {
                $method_title = (string) $order->get_payment_method_title();
            }
        } catch (\Throwable $e) {}

        $method_id = '';
        try {
            if (is_callable([$order, 'get_payment_method'])) {
                $method_id = (string) $order->get_payment_method();
            }
        } catch (\Throwable $e) {}

        if ($method_title === '' && $method_id !== '') {
            $method_title = $method_id;
        }
        if ($method_title === '') {
            $method_title = __('Bramka płatności', 'ak-product-set');
        }

        $date_paid = '';
        try {
            if (is_callable([$order, 'get_date_paid']) && $order->get_date_paid()) {
                $date_paid = $order->get_date_paid()->date('Y-m-d H:i');
            }
        } catch (\Throwable $e) {}
        if (!$date_paid) {
            $date_paid = current_time('Y-m-d H:i');
        }

        $txn_id = '';
        try {
            if (is_callable([$order, 'get_transaction_id'])) {
                $txn_id = (string) $order->get_transaction_id();
            }
        } catch (\Throwable $e) {}

        $order->update_meta_data('_ak_initial_payment_recorded', 'yes');
        $order->update_meta_data('_ak_initial_payment_method', $method_title);
        $order->update_meta_data('_ak_initial_payment_method_id', $method_id);
        $order->update_meta_data('_ak_initial_payment_amount', $total);
        $order->update_meta_data('_ak_initial_payment_date', $date_paid);
        $order->update_meta_data('_ak_initial_payment_txn_id', $txn_id);

        return [
            'method'    => $method_title,
            'method_id' => $method_id,
            'date_paid' => $date_paid,
            'txn_id'    => $txn_id,
        ];
    }
}

