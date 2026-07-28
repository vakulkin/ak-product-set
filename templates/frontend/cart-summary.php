<?php
/**
 * Cart Summary Box Template
 *
 * @var array $cart_item
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="ak-cart-summary-box">
    <h4 class="ak-cart-summary-title"><?php esc_html_e('Zarejestrowany zestaw AK', 'ak-product-set'); ?></h4>

    <?php if (!empty($cart_item['_ak_headcount'])): ?>
        <p class="ak-cart-summary-headcount">
            <strong><?php esc_html_e('Liczba uczestników:', 'ak-product-set'); ?></strong>
            <?php echo esc_html($cart_item['_ak_headcount']); ?> <?php esc_html_e('os.', 'ak-product-set'); ?>
        </p>
    <?php endif; ?>

    <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="button checkout wc-forward">
        <?php esc_html_e('Przejdź do zamówienia', 'ak-product-set'); ?>
    </a>
</div>
