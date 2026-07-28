<?php
/**
 * Master Multi-Step Booking Wizard Wrapper Template
 *
 * @var \AK_Set\Models\Set_Model $set
 * @var \AK_Set\Models\Weekend_Model[] $weekends
 * @var array $js_config
 */

use AK_Set\Frontend\Template_Loader;

if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="ak-set-wizard-app" class="ak-set-wizard-container" data-set-id="<?php echo esc_attr($set->get_id()); ?>">
    <!-- JSON Data Payload -->
    <script type="application/json" id="ak-set-data-json">
        <?php echo wp_json_encode(isset($js_config) ? $js_config : []); ?>
    </script>

    <!-- Progress Bar -->
    <div class="ak-wizard-progress">
        <div class="ak-step-item active" data-step="1">
            <span class="ak-step-num">1</span>
            <span class="ak-step-label"><?php esc_html_e('Wybór terminów', 'ak-product-set'); ?></span>
        </div>
        <div class="ak-step-connector"></div>
        <div class="ak-step-item" data-step="2">
            <span class="ak-step-num">2</span>
            <span class="ak-step-label"><?php esc_html_e('Uczestnicy i podsumowanie', 'ak-product-set'); ?></span>
        </div>
    </div>

    <!-- Error Banner -->
    <div id="ak-wizard-error" class="ak-wizard-error-banner" style="display: none;"></div>

    <!-- Step Views -->
    <div class="ak-wizard-steps-wrapper">
        <?php Template_Loader::render('frontend/step-1-weekends.php', [
            'set' => $set, 
            'weekends' => $weekends,
            'initial_data' => isset($js_config['initial_data']) ? $js_config['initial_data'] : null
        ]); ?>
        <?php Template_Loader::render('frontend/step-2-participants.php', ['set' => $set]); ?>
    </div>

    <!-- Loading Overlay -->
    <div id="ak-wizard-loader" class="ak-wizard-loader-overlay" style="display: none;">
        <div class="ak-spinner"></div>
        <span class="ak-loader-text"><?php esc_html_e('Przetwarzanie...', 'ak-product-set'); ?></span>
    </div>
</div>
