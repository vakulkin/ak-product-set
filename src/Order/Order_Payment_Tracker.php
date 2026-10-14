<?php

namespace AK_Set\Order;

use AK_Set\Order\Order_Recalculator;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Payment_Tracker {

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
     *
     * @param \WC_Order $order
     */
    public function record_initial_payment(\WC_Order $order): void {
        // Only track AK orders
        $is_ak_order = (bool)$order->get_meta('_ak_order_decomposed')
            || (bool)$order->get_meta('_ak_parent_set_id')
            || (bool)$order->get_meta('_ak_set_id')
            || (bool)$order->get_meta('_ak_original_total');

        if (!$is_ak_order) {
            return;
        }

        // Avoid duplicate recording
        if ($order->get_meta('_ak_initial_payment_recorded') === 'yes') {
            return;
        }

        $method_title = '';
        try {
            if (is_callable([$order, 'get_payment_method_title'])) {
                $method_title = (string)$order->get_payment_method_title();
            }
        } catch (\Throwable $e) {}

        $method_id = '';
        try {
            if (is_callable([$order, 'get_payment_method'])) {
                $method_id = (string)$order->get_payment_method();
            }
        } catch (\Throwable $e) {}

        if ($method_title === '' && $method_id !== '') {
            $method_title = $method_id;
        }
        if ($method_title === '') {
            $method_title = __('Bramka płatności', 'ak-product-set');
        }

        $total = (float)$order->get_total();
        if ($total <= 0.009 && method_exists($order, 'get_meta')) {
            $orig = $order->get_meta('_ak_original_total');
            if ($orig !== '' && $orig !== false) {
                $total = (float)$orig;
            }
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
                $txn_id = (string)$order->get_transaction_id();
            }
        } catch (\Throwable $e) {}

        $order->update_meta_data('_ak_initial_payment_recorded', 'yes');
        $order->update_meta_data('_ak_initial_payment_method', $method_title);
        $order->update_meta_data('_ak_initial_payment_method_id', $method_id);
        $order->update_meta_data('_ak_initial_payment_amount', $total);
        $order->update_meta_data('_ak_initial_payment_date', $date_paid);
        $order->update_meta_data('_ak_initial_payment_txn_id', $txn_id);

        $current_paid = (float)$order->get_meta('_ak_total_paid');
        if ($current_paid < 0.009) {
            $order->update_meta_data('_ak_total_paid', $total);
        }

        // If order was in unpaid state, settle it
        $balance_status = (string)$order->get_meta('_ak_balance_status');
        if ($balance_status === Order_Recalculator::BALANCE_UNPAID || $balance_status === '') {
            $order->update_meta_data('_ak_balance_status', Order_Recalculator::BALANCE_SETTLED);
            $order->update_meta_data('_ak_balance_amount', 0.0);
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
}
