<?php
/**
 * Step 1: Weekend Selection Grid Template
 *
 * @var \AK_Set\Models\Set_Model $set
 * @var \AK_Set\Models\Weekend_Model[] $weekends
 * @var array|null $initial_data
 */

if (!defined('ABSPATH')) {
    exit;
}

$pre_selected_raw = (!empty($initial_data) && !empty($initial_data['selected_weekends'])) ? $initial_data['selected_weekends'] : [];
$pre_selected = is_array($pre_selected_raw) ? array_map('intval', $pre_selected_raw) : [];
?>

<div id="ak-step-1" class="ak-wizard-step-panel active">
    <div class="ak-step-header">
        <h2><?php esc_html_e('Wybierz terminy', 'ak-product-set'); ?></h2>
        <p><?php esc_html_e('Zaznacz co najmniej jeden termin. Rabaty grupowe naliczają się automatycznie od 5 i 10 osób.', 'ak-product-set'); ?></p>
    </div>

    <div class="ak-weekends-grid">
        <?php foreach ($weekends as $weekend): ?>
            <?php
            $wid          = (int)$weekend->get_id();
            $is_expired   = $weekend->is_expired();
            $managing_stock = $weekend->managing_stock();
            $stock        = $weekend->get_stock_quantity();
            $is_disabled  = $is_expired || ($managing_stock && $stock !== null && $stock <= 0);
            $is_checked   = in_array($wid, $pre_selected, true);
            $start_dt     = $weekend->get_event_start_datetime();
            $end_dt       = $weekend->get_event_end_datetime();
            $recr_start   = $weekend->get_recruitment_start_datetime();
            $recr_end     = $weekend->get_recruitment_end_datetime();
            $location     = $weekend->get_event_location();
            $image_url    = $weekend->get_image_url('woocommerce_thumbnail');
            $main_desc    = $weekend->get_description();
            ?>
            <div class="ak-weekend-card <?php echo $is_disabled ? 'disabled' : ''; ?> <?php echo $is_checked ? 'selected' : ''; ?>"
                 data-weekend-id="<?php echo esc_attr($wid); ?>"
                 data-managing-stock="<?php echo esc_attr($managing_stock ? '1' : '0'); ?>"
                 data-stock="<?php echo esc_attr($stock !== null ? $stock : ''); ?>">

                <input type="checkbox"
                       name="ak_selected_weekends[]"
                       value="<?php echo esc_attr($wid); ?>"
                       id="ak-weekend-input-<?php echo esc_attr($wid); ?>"
                       <?php checked($is_checked); ?>
                       <?php disabled($is_disabled); ?>
                       style="display:none;">

                <div class="ak-card-checkbox-indicator">
                    <span class="ak-check-icon"></span>
                </div>

                <div class="ak-card-layout-advanced">
                    <div class="ak-card-image-col">
                        <?php if ($image_url): ?>
                            <img src="<?php echo esc_url($image_url); ?>" alt="" class="ak-weekend-img">
                        <?php else: ?>
                            <div class="ak-weekend-img-placeholder">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="ak-card-body-col">
                        <h3 class="ak-weekend-title"><?php echo esc_html($weekend->get_title()); ?></h3>

                        <?php if (!empty($start_dt)): ?>
                            <div class="ak-weekend-meta">
                                <svg class="ak-meta-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect x="1" y="3" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/>
                                    <path d="M5 1v4M11 1v4M1 7h14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                </svg>
                                <span>
                                    <strong><?php esc_html_e('Wydarzenie:', 'ak-product-set'); ?></strong> 
                                    <?php echo esc_html(date_i18n('d.m.Y, H:i', strtotime($start_dt))); ?>
                                    <?php if (!empty($end_dt)): ?>
                                        &ndash; <?php echo esc_html(date_i18n('d.m.Y, H:i', strtotime($end_dt))); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($recr_start)): ?>
                            <div class="ak-weekend-meta">
                                <svg class="ak-meta-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.4"/>
                                    <path d="M8 4v4l3 3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span>
                                    <strong><?php esc_html_e('Rekrutacja:', 'ak-product-set'); ?></strong> 
                                    <?php echo esc_html(date_i18n('d.m.Y, H:i', strtotime($recr_start))); ?>
                                    <?php if (!empty($recr_end)): ?>
                                        &ndash; <?php echo esc_html(date_i18n('d.m.Y, H:i', strtotime($recr_end))); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($location)): ?>
                            <div class="ak-weekend-meta">
                                <svg class="ak-meta-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M8 1.5A4.5 4.5 0 0 1 12.5 6c0 3-4.5 8.5-4.5 8.5S3.5 9 3.5 6A4.5 4.5 0 0 1 8 1.5Z" stroke="currentColor" stroke-width="1.4"/>
                                    <circle cx="8" cy="6" r="1.5" stroke="currentColor" stroke-width="1.4"/>
                                </svg>
                                <span>
                                    <strong><?php esc_html_e('Lokalizacja:', 'ak-product-set'); ?></strong> 
                                    <?php echo esc_html($location); ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($main_desc)): ?>
                            <div class="ak-weekend-desc">
                                <?php echo wp_kses_post($main_desc); ?>
                            </div>
                        <?php endif; ?>

                        <div class="ak-weekend-status-badge">
                            <?php if ($is_expired): ?>
                                <span class="ak-badge danger"><?php esc_html_e('Rekrutacja zakończona', 'ak-product-set'); ?></span>
                            <?php elseif ($managing_stock && $stock !== null && $stock <= 0): ?>
                                <span class="ak-badge danger"><?php esc_html_e('Brak miejsc', 'ak-product-set'); ?></span>
                            <?php elseif ($managing_stock && $stock !== null && $stock <= 5): ?>
                                <span class="ak-badge warning"><?php printf(esc_html__('Zostało %d miejsc', 'ak-product-set'), $stock); ?></span>
                            <?php elseif ($managing_stock && $stock !== null): ?>
                                <span class="ak-badge success"><?php printf(esc_html__('%d miejsc dostępnych', 'ak-product-set'), $stock); ?></span>
                            <?php else: ?>
                                <span class="ak-badge success"><?php esc_html_e('Miejsca dostępne', 'ak-product-set'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="ak-step-footer">
        <span></span>
        <button type="button" id="ak-btn-step-1-next" class="ak-btn ak-btn-primary" <?php disabled(empty($pre_selected)); ?>>
            <?php esc_html_e('Dalej', 'ak-product-set'); ?>
            <!-- Arrow right icon -->
            <svg class="ak-btn-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </div>
</div>
