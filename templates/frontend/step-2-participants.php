<?php
/**
 * Step 2: Participants & Summary
 *
 * @var \AK_Set\Models\Set_Model $set
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="ak-step-2" class="ak-wizard-step-panel">
    <div class="ak-step-header">
        <h2><?php esc_html_e('Uczestnicy i podsumowanie', 'ak-product-set'); ?></h2>
        <p><?php esc_html_e('Uzupełnij dane uczestników. Rabaty grupowe naliczają się automatycznie w zależności od liczby osób.', 'ak-product-set'); ?></p>
    </div>

    <!-- Selected weekends summary -->
    <div class="ak-summary-weekends">
        <span class="ak-summary-weekends-label"><?php esc_html_e('Wybrane terminy', 'ak-product-set'); ?></span>
        <ul id="ak-summary-weekends-list"></ul>
    </div>

    <!-- Live Price Preview Card -->
    <div id="ak-price-preview-card" class="ak-price-preview-card">
        <div class="ak-price-preview-header"><?php esc_html_e('Kalkulacja ceny', 'ak-product-set'); ?></div>
        <div class="ak-preview-row">
            <span class="ak-preview-label"><?php esc_html_e('Pakiet', 'ak-product-set'); ?></span>
            <strong id="ak-preview-package" class="ak-preview-val">&mdash;</strong>
        </div>
        <div class="ak-preview-row">
            <span class="ak-preview-label"><?php esc_html_e('Runda cenowa', 'ak-product-set'); ?></span>
            <strong id="ak-preview-round" class="ak-preview-val">&mdash;</strong>
        </div>
        <div class="ak-preview-row">
            <span class="ak-preview-label"><?php esc_html_e('Kategoria', 'ak-product-set'); ?></span>
            <strong id="ak-preview-tier" class="ak-preview-val">&mdash;</strong>
        </div>
        <div class="ak-preview-row ak-highlight-row">
            <span class="ak-preview-label"><?php esc_html_e('Cena za osobę', 'ak-product-set'); ?></span>
            <strong id="ak-preview-per-person" class="ak-preview-val">&mdash;</strong>
        </div>
        <div class="ak-preview-row ak-total-row">
            <span class="ak-preview-label"><?php esc_html_e('Łącznie', 'ak-product-set'); ?></span>
            <strong id="ak-preview-total" class="ak-preview-val price-total">&mdash;</strong>
        </div>
    </div>

    <!-- Participant cards rendered by JS -->
    <div id="ak-participants-cards-container" class="ak-participants-container"></div>

    <!-- Add participant -->
    <div class="ak-btn-add-participant-wrap">
        <button type="button" id="ak-btn-add-participant" class="ak-btn ak-btn-ghost">
            <svg class="ak-btn-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <?php esc_html_e('Dodaj uczestnika', 'ak-product-set'); ?>
        </button>
    </div>

    <div class="ak-step-footer">
        <button type="button" id="ak-btn-step-2-back" class="ak-btn ak-btn-secondary">
            <svg class="ak-btn-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M13 8H3M7 4L3 8l4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php esc_html_e('Wstecz', 'ak-product-set'); ?>
        </button>
        <button type="button" id="ak-btn-submit-cart" class="ak-btn ak-btn-success ak-btn-lg">
            <?php esc_html_e('Przejdź do płatności', 'ak-product-set'); ?>
            <svg class="ak-btn-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </div>
</div>
