/**
 * AK Product Set - Checkout NIP & Company Autofill
 */
(function($) {
    'use strict';

    function isValidNip(nip) {
        nip = (nip || '').replace(/[^0-9]/g, '');
        if (nip.length !== 10) {
            return false;
        }
        if (/^(\d)\1{9}$/.test(nip)) {
            return false;
        }
        var weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        var sum = 0;
        for (var i = 0; i < 9; i++) {
            sum += parseInt(nip.charAt(i), 10) * weights[i];
        }
        var control = sum % 11;
        if (control === 10) {
            return false;
        }
        return control === parseInt(nip.charAt(9), 10);
    }

    function initNipLookup() {
        var $nipInput = $('#billing_nip');
        if (!$nipInput.length || $('#ak-nip-toolbar').length) {
            return;
        }

        var i18n = (window.akNipConfig && window.akNipConfig.i18n) || {};
        var $companyDataField = $('#billing_company_data_field');
        if (!$companyDataField.length) {
            $companyDataField = $('#billing_company_data').closest('.form-row');
        }

        function toggleCompanyDataVisibility() {
            var val = $.trim($nipInput.val() || '');
            if (val.length > 0) {
                $companyDataField.addClass('ak-visible').show();
            } else {
                $companyDataField.removeClass('ak-visible').hide();
            }
        }

        // Initial visibility check
        toggleCompanyDataVisibility();

        // Build toolbar under NIP field
        var toolbarHtml = '<div id="ak-nip-toolbar" class="ak-nip-toolbar">' +
            '<button type="button" id="ak-nip-fetch-btn" class="ak-nip-btn">' +
                '<svg class="ak-nip-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>' +
                '<span class="ak-nip-btn-text">' + (i18n.fetchBtn || 'Pobierz dane firmy') + '</span>' +
            '</button>' +
            '<span id="ak-nip-status" class="ak-nip-status"></span>' +
        '</div>';

        $nipInput.closest('.form-row').append(toolbarHtml);

        var $fetchBtn = $('#ak-nip-fetch-btn');
        var $status   = $('#ak-nip-status');
        var lastQueriedNip = '';
        var debounceTimer = null;

        function setStatus(text, type) {
            $status.removeClass('ak-success ak-error ak-loading');
            if (type) {
                $status.addClass('ak-' + type);
            }
            $status.html(text);
        }

        function populateField($field, val) {
            if ($field.length && val) {
                $field.val(val).trigger('change');
                $field.addClass('ak-field-highlight');
                setTimeout(function() {
                    $field.removeClass('ak-field-highlight');
                }, 1200);
            }
        }

        function performLookup(forced) {
            var rawNip = $nipInput.val() || '';
            var cleanNip = rawNip.replace(/[^0-9]/g, '');

            if (!cleanNip) {
                setStatus('', '');
                toggleCompanyDataVisibility();
                return;
            }

            // Ensure field is visible as NIP has a value
            $companyDataField.addClass('ak-visible').show();

            if (!isValidNip(cleanNip)) {
                setStatus(i18n.invalidNip || 'Nieprawidłowy numer NIP (błędna suma kontrolna)', 'error');
                return;
            }

            if (!forced && cleanNip === lastQueriedNip) {
                return;
            }

            lastQueriedNip = cleanNip;
            setStatus('<span class="ak-nip-spinner"></span> ' + (i18n.loading || 'Pobieranie danych...'), 'loading');
            $fetchBtn.prop('disabled', true);

            $.ajax({
                url: akNipConfig.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'ak_lookup_nip',
                    nip: cleanNip,
                    _ajax_nonce: akNipConfig.nonce
                },
                success: function(response) {
                    $fetchBtn.prop('disabled', false);
                    if (response && response.success && response.data && response.data.data) {
                        var d = response.data.data;
                        var source = response.data.source || 'biala_lista';

                        // Build single clean formatted address string for company data field
                        var formatted = (d.formatted && $.trim(d.formatted)) || '';
                        if (!formatted) {
                            formatted = d.name || '';
                            var addrParts = [];
                            if (d.street) addrParts.push(d.street);
                            if (d.postcode || d.city) addrParts.push($.trim((d.postcode || '') + ' ' + (d.city || '')));
                            if (addrParts.length) {
                                formatted += (formatted ? ', ' : '') + addrParts.join(', ');
                            }
                        }

                        // Populate single company data field
                        populateField($('#billing_company_data'), formatted);

                        // Make sure field is displayed
                        $companyDataField.addClass('ak-visible').show();

                        // Update button label to "Odśwież"
                        $fetchBtn.find('.ak-nip-btn-text').text(i18n.refreshBtn || 'Odśwież dane firmy');

                        var sourceMsg = (source === 'ceidg')
                            ? (i18n.successCEIDG || '✓ Pobrano dane z rejestru CEIDG')
                            : (i18n.successBL || '✓ Pobrano dane z Białej Listy VAT');

                        setStatus(sourceMsg + (d.name ? ': <strong>' + $('<div>').text(d.name).html() + '</strong>' : ''), 'success');
                    } else {
                        var err = (response && response.data) ? response.data : (i18n.notFound || 'Nie znaleziono firmy');
                        setStatus('⚠ ' + err, 'error');
                    }
                },
                error: function() {
                    $fetchBtn.prop('disabled', false);
                    setStatus('⚠ ' + (i18n.notFound || 'Błąd połączenia z bazą NIP. Możesz wpisać dane ręcznie.'), 'error');
                }
            });
        }

        // On input / change: toggle visibility and auto-lookup when 10 valid digits reached
        $nipInput.on('input change keyup paste', function() {
            var val = $(this).val();
            var clean = (val || '').replace(/[^0-9]/g, '');

            toggleCompanyDataVisibility();

            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }

            if (clean.length === 10 && isValidNip(clean)) {
                debounceTimer = setTimeout(function() {
                    performLookup(false);
                }, 350);
            } else if (clean.length > 0 && clean.length !== 10) {
                setStatus('', '');
            } else if (!clean.length) {
                setStatus('', '');
            }
        });

        // Click on fetch / refresh button
        $fetchBtn.on('click', function(e) {
            e.preventDefault();
            performLookup(true);
        });
    }

    $(document).ready(initNipLookup);
    $(document.body).on('updated_checkout', initNipLookup);

})(jQuery);
