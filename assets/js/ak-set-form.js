/**
 * AK Product Set - Pure Decoupled UI Renderer (Reads Server JSON)
 */
(function () {
  'use strict';

  function initAKWizard() {
    var app = document.getElementById('ak-set-wizard-app');
    if (!app) return;

    // Load configuration from window.akSetData or embedded JSON script tag
    var config = null;
    if (typeof window.akSetData === 'object' && window.akSetData !== null && window.akSetData.set_id) {
      config = window.akSetData;
    } else {
      var jsonTag = document.getElementById('ak-set-data-json');
      if (jsonTag && jsonTag.textContent) {
        try {
          config = JSON.parse(jsonTag.textContent);
        } catch (e) {
          console.error('AK Set: Invalid JSON config', e);
        }
      }
    }

    if (!config || !config.set_id) {
      console.warn('AK Set: No wizard configuration found.');
      return;
    }

    var initialData = config.initial_data || {};

    var initialParticipants = Array.isArray(initialData.participants) ? initialData.participants : [];
    var initialHeadcount = parseInt(initialData.headcount, 10) || 1;
    if (initialParticipants.length > initialHeadcount) {
      initialHeadcount = initialParticipants.length;
    }

    var state = {
      currentStep: 1,
      setId: parseInt(config.set_id, 10),
      selectedWeekends: Array.isArray(initialData.selected_weekends) ? initialData.selected_weekends.map(Number) : [],
      headcount: initialHeadcount,
      participants: initialParticipants,
      pricingResult: null,
      isCalculating: false
    };


    function findWeekend(id) {
      if (!config.weekends) return null;
      for (var i = 0; i < config.weekends.length; i++) {
        if (parseInt(config.weekends[i].id, 10) === parseInt(id, 10)) {
          return config.weekends[i];
        }
      }
      return null;
    }

    /**
     * Pre-select initial weekend cards UI when editing an existing cart booking
     */
    function applyInitialSelectionUI() {
      var cards = app.querySelectorAll('.ak-weekend-card');
      var selectedFromDOM = [];

      for (var i = 0; i < cards.length; i++) {
        var card = cards[i];
        var wid = parseInt(card.getAttribute('data-weekend-id'), 10);
        var checkbox = card.querySelector('input[type="checkbox"]');
        // Check if DOM says it's selected OR if the initial JSON config says it's selected
        var isServerSelected = card.classList.contains('selected') || (checkbox && checkbox.checked) || state.selectedWeekends.indexOf(wid) !== -1;

        if (isServerSelected) {
          card.classList.add('selected');
          if (checkbox) checkbox.checked = true;
          if (selectedFromDOM.indexOf(wid) === -1 && !isNaN(wid) && wid > 0) {
            selectedFromDOM.push(wid);
          }
        } else {
          card.classList.remove('selected');
          if (checkbox) checkbox.checked = false;
        }
      }

      state.selectedWeekends = selectedFromDOM;

      var btnStep1Next = document.getElementById('ak-btn-step-1-next');
      if (btnStep1Next) {
        if (state.selectedWeekends.length > 0) {
          btnStep1Next.disabled = false;
          btnStep1Next.removeAttribute('disabled');
        } else {
          btnStep1Next.disabled = true;
          btnStep1Next.setAttribute('disabled', 'disabled');
        }
      }
    }

    /**
     * Check current headcount/participant count against available stock
     * Disables submit button and shows error notice if headcount > max_headcount
     */
    function updateStockWarningAndSubmitState() {
      var btnSubmit = document.getElementById('ak-btn-submit-cart');
      var maxStock = (state.pricingResult && state.pricingResult.max_headcount !== undefined && state.pricingResult.max_headcount !== null) ? state.pricingResult.max_headcount : null;
      var currentCount = Math.max(state.headcount, state.participants.length);

      if (maxStock !== null && currentCount > maxStock) {
        var excess = currentCount - maxStock;
        showBannerError('Dostępność miejsc uległa zmianie. Dostępny limit wynosi ' + maxStock + ' miejsc (zadeklarowano ' + currentCount + ' os.). Proszę usunąć ' + excess + ' uczestników, aby kontynuować.');
        if (btnSubmit) {
          btnSubmit.disabled = true;
          btnSubmit.setAttribute('disabled', 'disabled');
          btnSubmit.style.opacity = '0.5';
          btnSubmit.style.cursor = 'not-allowed';
        }
        return false;
      } else {
        clearError();
        if (btnSubmit) {
          btnSubmit.disabled = false;
          btnSubmit.removeAttribute('disabled');
          btnSubmit.style.opacity = '1';
          btnSubmit.style.cursor = 'pointer';
        }
        return true;
      }
    }

    /**
     * Capture typed values from existing participant input elements into state.participants
     */
    function syncParticipantInputsToState() {
      var items = app.querySelectorAll('.ak-participant-card-item');
      if (!items || items.length === 0) return;

      for (var i = 0; i < items.length; i++) {
        var card = items[i];
        var idx = parseInt(card.getAttribute('data-index'), 10);
        if (isNaN(idx)) idx = i;

        var nameInput = card.querySelector('.ak-input-p-name');
        var emailInput = card.querySelector('.ak-input-p-email');
        var phoneInput = card.querySelector('.ak-input-p-phone');
        var sizeInput = card.querySelector('.ak-input-p-size');
        var cutInput = card.querySelector('.ak-input-p-cut');

        if (!state.participants[idx]) {
          state.participants[idx] = { name: '', email: '', phone: '', tshirt_size: '', tshirt_cut: 'men' };
        }

        if (nameInput) state.participants[idx].name = nameInput.value;
        if (emailInput) state.participants[idx].email = emailInput.value;
        if (phoneInput) state.participants[idx].phone = phoneInput.value;
        if (sizeInput) state.participants[idx].tshirt_size = sizeInput.value;
        if (cutInput) state.participants[idx].tshirt_cut = cutInput.value;
      }
    }

    /**
     * Pure Decoupled Server AJAX Request: Reads Server JSON & Renders UI
     */
    function fetchServerPriceCalculation(callback) {
      if (state.selectedWeekends.length === 0) return;
      if (typeof jQuery === 'undefined') return;


      state.isCalculating = true;

      jQuery.ajax({
        url: config.ajax_url,
        type: 'POST',
        data: {
          action: 'ak_calculate_set_price',
          nonce: config.nonce,
          set_id: state.setId,
          selected_weekends: state.selectedWeekends,
          headcount: state.headcount
        },
        dataType: 'json',
        success: function (res) {
          state.isCalculating = false;

          if (res && res.success && res.data) {
            state.pricingResult = res.data;
            updateStockWarningAndSubmitState();
            renderStep2UIFromJSON(res.data);
            if (callback) callback(null, res.data);
          } else {
            var msg = res && res.data && res.data.message ? res.data.message : 'Błąd przeliczenia ceny.';
            showToast(msg);
            if (callback) callback(new Error(msg));
          }
        },
        error: function () {
          state.isCalculating = false;
          showToast('Błąd połączenia z serwerem przy przeliczaniu ceny.');
          if (callback) callback(new Error('Network error'));
        }
      });
    }

    /**
     * Render Step 2 UI directly from Server JSON payload
     */
    function renderStep2UIFromJSON(data) {
      var note = document.getElementById('ak-stock-limit-note');
      var btnAddParticipant = document.getElementById('ak-btn-add-participant');

      if (data.max_headcount !== null && data.max_headcount !== undefined) {
        if (note) {
          note.textContent = data.formatted && data.formatted.stock_note_text ? data.formatted.stock_note_text : ('Dostępny limit: ' + data.max_headcount + ' os.');
          note.style.display = 'block';
        }
        if (btnAddParticipant) {
          btnAddParticipant.disabled = (state.headcount >= data.max_headcount);
          if (btnAddParticipant.disabled) {
            btnAddParticipant.style.opacity = '0.5';
            btnAddParticipant.style.cursor = 'not-allowed';
          } else {
            btnAddParticipant.style.opacity = '1';
            btnAddParticipant.style.cursor = 'pointer';
          }
        }
      } else {
        if (note) note.style.display = 'none';
        if (btnAddParticipant) {
          btnAddParticipant.disabled = false;
          btnAddParticipant.style.opacity = '1';
          btnAddParticipant.style.cursor = 'pointer';
        }
      }

      var elPkg = document.getElementById('ak-preview-package');
      var elRnd = document.getElementById('ak-preview-round');
      var elTier = document.getElementById('ak-preview-tier');
      var elPer = document.getElementById('ak-preview-per-person');
      var elTot = document.getElementById('ak-preview-total');

      if (data.formatted) {
        if (elPkg) elPkg.textContent = data.formatted.package_size_text;
        if (elRnd) elRnd.textContent = data.formatted.round_text;
        if (elTier) elTier.textContent = data.formatted.tier_text;
        if (elPer) elPer.innerHTML = data.formatted.per_person_price || data.formatted.per_person_raw;
        if (elTot) elTot.innerHTML = data.formatted.total_price || data.formatted.total_raw;
      }
    }

    function showStep(stepNum, shouldScroll) {
      if (stepNum < 1 || stepNum > 2) return;

      syncParticipantInputsToState();


      state.currentStep = stepNum;

      // Update 2-Step Progress Indicators
      var steps = app.querySelectorAll('.ak-step-item');
      for (var i = 0; i < steps.length; i++) {
        var s = parseInt(steps[i].getAttribute('data-step'), 10);
        steps[i].classList.remove('active', 'completed');
        if (s === stepNum) {
          steps[i].classList.add('active');
        } else if (s < stepNum) {
          steps[i].classList.add('completed');
        }
      }

      // Update Step Panels
      var panels = app.querySelectorAll('.ak-wizard-step-panel');
      for (var j = 0; j < panels.length; j++) {
        panels[j].classList.remove('active');
      }

      var targetPanel = document.getElementById('ak-step-' + stepNum);
      if (targetPanel) {
        targetPanel.classList.add('active');
      }

      // Scroll to the top of the wizard smoothly only when explicitly requested (e.g. user navigation)
      if (shouldScroll) {
        app.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      // Real-time server stock & price audit on step transitions
      if (stepNum === 2) {
        // Populate Summary List
        var listContainer = document.getElementById('ak-summary-weekends-list');
        if (listContainer) {
          listContainer.innerHTML = '';
          for (var w = 0; w < state.selectedWeekends.length; w++) {
            var wid = state.selectedWeekends[w];
            var card = app.querySelector('.ak-weekend-card[data-weekend-id="' + wid + '"]');
            var name = card ? card.querySelector('.ak-weekend-title').textContent : 'Weekend #' + wid;
            var li = document.createElement('li');
            li.textContent = name;
            li.style.marginBottom = '4px';
            listContainer.appendChild(li);
          }
        }

        fetchServerPriceCalculation(function (err, data) {
          renderParticipantCards();
        });
      }
    }

    function renderParticipantCards() {
      var container = document.getElementById('ak-participants-cards-container');
      if (!container) return;

      container.innerHTML = '';

      var count = Math.max(state.headcount, state.participants.length);
      state.headcount = count;

      var existing = state.participants;
      var hasTshirt = config.has_tshirt;

      for (var i = 0; i < count; i++) {
        var pData = existing[i] || { name: '', email: '', phone: '', tshirt_size: '', tshirt_cut: 'men' };
        var card = document.createElement('div');
        card.className = 'ak-participant-card-item';
        card.setAttribute('data-index', i);

        var removeBtnHTML = (count > 1)
          ? '<button type="button" class="ak-btn ak-btn-destructive ak-btn-remove-participant" data-index="' + i + '">' +
          '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 4h10M6 4V2h4v2M5 4l.5 9h5L11 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
          'Usu\u0144</button>'
          : '';

        var html =
          '<div class="ak-participant-card-header">' +
          '<h4>Uczestnik ' + (i + 1) + '</h4>' +
          removeBtnHTML +
          '</div>' +
          '<div class="ak-participant-card-body">' +
          '<div class="ak-form-grid">' +
          '<div class="ak-form-group">' +
          '<label>Imi\u0119 i nazwisko</label>' +
          '<input type="text" class="ak-input-p-name" value="' + escapeAttr(pData.name) + '" placeholder="Jan Kowalski">' +
          '</div>' +
          '<div class="ak-form-group">' +
          '<label>Adres e-mail</label>' +
          '<input type="email" class="ak-input-p-email" value="' + escapeAttr(pData.email) + '" placeholder="jan@example.com">' +
          '</div>' +
          '<div class="ak-form-group">' +
          '<label>Telefon</label>' +
          '<input type="tel" class="ak-input-p-phone" value="' + escapeAttr(pData.phone) + '" placeholder="+48 600 000 000">' +
          '</div>';

        if (hasTshirt) {
          html += '<div class="ak-form-group">' +
            '<label>Rozmiar koszulki</label>' +
            '<select class="ak-input-p-size">' +
            '<option value="">Wybierz rozmiar</option>';

          for (var sizeKey in config.tshirt_sizes) {
            var selected = (pData.tshirt_size === sizeKey) ? 'selected' : '';
            html += '<option value="' + escapeAttr(sizeKey) + '" ' + selected + '>' + escapeHtml(config.tshirt_sizes[sizeKey]) + '</option>';
          }

          html += '</select></div>' +
            '<div class="ak-form-group">' +
            '<label>Kr\u00f3j koszulki</label>' +
            '<select class="ak-input-p-cut">';

          for (var cutKey in config.tshirt_cuts) {
            var selectedCut = (pData.tshirt_cut === cutKey) ? 'selected' : '';
            html += '<option value="' + escapeAttr(cutKey) + '" ' + selectedCut + '>' + escapeHtml(config.tshirt_cuts[cutKey]) + '</option>';
          }

          html += '</select></div>';
        }

        html += '</div></div>';
        card.innerHTML = html;
        container.appendChild(card);
      }

      updateStockWarningAndSubmitState();
    }

    function syncBeforeSubmit() {
      syncParticipantInputsToState();
      updateStockWarningAndSubmitState();

      // Ensure the array matches the DOM
      var items = app.querySelectorAll('.ak-participant-card-item');
      var list = [];
      for (var i = 0; i < items.length; i++) {
        var card = items[i];
        var nameInput = card.querySelector('.ak-input-p-name');
        var emailInput = card.querySelector('.ak-input-p-email');
        var phoneInput = card.querySelector('.ak-input-p-phone');
        var sizeInput = card.querySelector('.ak-input-p-size');
        var cutInput = card.querySelector('.ak-input-p-cut');

        list.push({
          name: nameInput ? nameInput.value.trim() : '',
          email: emailInput ? emailInput.value.trim() : '',
          phone: phoneInput ? phoneInput.value.trim() : '',
          tshirt_size: sizeInput ? sizeInput.value : '',
          tshirt_cut: cutInput ? cutInput.value : ''
        });
      }
      state.participants = list;
    }

    function isValidEmail(email) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function isValidPhone(phone) {
      var digits = String(phone).replace(/[^0-9]/g, '');
      return digits.length >= 7 && digits.length <= 15;
    }

    function submitCartAJAX() {
      clearError();

      // Client-side validation of participant inputs
      for (var i = 0; i < state.participants.length; i++) {
        var p = state.participants[i];
        var pNum = i + 1;

        if (!p.name) {
          showToast('Proszę podać imię i nazwisko dla Uczestnika ' + pNum + '.');
          return;
        }

        if (!p.email || !isValidEmail(p.email)) {
          showToast('Proszę podać prawidłowy adres e-mail dla Uczestnika ' + pNum + ' (np. jan@example.com).');
          return;
        }

        if (!p.phone || !isValidPhone(p.phone)) {
          showToast('Proszę podać prawidłowy numer telefonu dla Uczestnika ' + pNum + ' (np. +48 600 000 000).');
          return;
        }

        if (config.has_tshirt && !p.tshirt_size) {
          showToast('Proszę wybrać rozmiar koszulki dla Uczestnika ' + pNum + '.');
          return;
        }
      }

      showLoader();

      if (typeof jQuery === 'undefined') {
        hideLoader();
        showToast('Błąd środowiska: brak biblioteki jQuery.');
        return;
      }

      jQuery.ajax({
        url: config.ajax_url,
        type: 'POST',
        data: {
          action: 'ak_add_set_to_cart',
          nonce: config.nonce,
          set_id: state.setId,
          selected_weekends: state.selectedWeekends,
          headcount: state.headcount,
          participants: state.participants
        },
        dataType: 'json',
        success: function (res) {
          if (res && res.success && res.data) {
            // Trigger WC mini-cart fragment refresh in background
            if (typeof jQuery !== 'undefined') {
              if (res.data.fragments) {
                jQuery(document.body).trigger('added_to_cart', [res.data.fragments, res.data.cart_hash]);
              }
              jQuery(document.body).trigger('wc_fragment_refresh');
            }
            // Redirect directly to checkout page
            if (res.data.redirect_url) {
              window.location.href = res.data.redirect_url;
            } else if (res.data.cart_url) {
              window.location.href = res.data.cart_url;
            } else {
              hideLoader();
              showToast('Zestaw został dodany do koszyka.');
            }
          } else {
            hideLoader();
            showToast(res && res.data && res.data.message ? res.data.message : 'Nie udało się dodać zestawu do koszyka.');
          }
        },
        error: function () {
          hideLoader();
          showToast('Błąd połączenia z serwerem. Spróbuj ponownie.');
        }
      });
    }

    function showLoader() {
      var loader = document.getElementById('ak-wizard-loader');
      if (loader) loader.style.display = 'flex';
    }

    function hideLoader() {
      var loader = document.getElementById('ak-wizard-loader');
      if (loader) loader.style.display = 'none';
    }

    function showBannerError(msg) {
      var err = document.getElementById('ak-wizard-error');
      if (err) {
        err.textContent = msg;
        err.style.display = 'block';
      }
    }

    function showToast(msg) {
      var toast = document.createElement('div');
      toast.className = 'ak-toast-notification';

      var textNode = document.createElement('span');
      // Use innerHTML so that success messages with links render correctly.
      // Error messages passed here from esc_html(server) are already safe.
      textNode.innerHTML = msg;
      toast.appendChild(textNode);

      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'ak-toast-close';
      closeBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 4L4 12M4 4l8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

      var isClosed = false;
      function closeToast() {
        if (isClosed) return;
        isClosed = true;
        toast.classList.remove('ak-toast-visible');
        setTimeout(function () {
          if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
          }
        }, 300);
      }

      closeBtn.addEventListener('click', closeToast);
      toast.appendChild(closeBtn);

      document.body.appendChild(toast);

      // Trigger animation on next frame
      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          toast.classList.add('ak-toast-visible');
        });
      });

      // Auto-remove after 4 seconds
      setTimeout(closeToast, 4000);
    }

    function clearError() {
      var err = document.getElementById('ak-wizard-error');
      if (err) {
        err.style.display = 'none';
        err.textContent = '';
      }
    }

    function escapeHtml(str) {
      return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escapeAttr(str) {
      return String(str).replace(/"/g, '&quot;');
    }

    // Attach Event Listeners to App Container
    app.addEventListener('click', function (e) {
      var target = e.target;

      // 1. Weekend Card Click
      var card = target.closest('.ak-weekend-card');
      if (card && !card.classList.contains('disabled')) {
        e.preventDefault();

        var checkbox = card.querySelector('input[type="checkbox"]');
        var isSelected = card.classList.contains('selected');

        if (isSelected) {
          card.classList.remove('selected');
          if (checkbox) checkbox.checked = false;
        } else {
          card.classList.add('selected');
          if (checkbox) checkbox.checked = true;
        }

        // Collect selected weekends
        var selectedCards = app.querySelectorAll('.ak-weekend-card.selected');
        var selectedIds = [];
        for (var k = 0; k < selectedCards.length; k++) {
          var wid = parseInt(selectedCards[k].getAttribute('data-weekend-id'), 10);
          if (!isNaN(wid) && wid > 0) {
            selectedIds.push(wid);
          }
        }

        state.selectedWeekends = selectedIds;

        var btnStep1Next = document.getElementById('ak-btn-step-1-next');
        if (btnStep1Next) {
          if (selectedIds.length > 0) {
            btnStep1Next.disabled = false;
            btnStep1Next.removeAttribute('disabled');
          } else {
            btnStep1Next.disabled = true;
            btnStep1Next.setAttribute('disabled', 'disabled');
          }
        }
        return;
      }

      // 2. Step 1 Next Button
      if (target.closest('#ak-btn-step-1-next')) {
        e.preventDefault();
        if (state.selectedWeekends.length === 0) return;
        showStep(2, true);
        return;
      }

      // 3. Remove Participant Button Click
      if (target.closest('.ak-btn-remove-participant')) {
        e.preventDefault();
        var removeBtn = target.closest('.ak-btn-remove-participant');
        var removeIndex = parseInt(removeBtn.getAttribute('data-index'), 10);

        syncParticipantInputsToState();

        if (!isNaN(removeIndex) && removeIndex >= 0 && removeIndex < state.participants.length) {
          state.participants.splice(removeIndex, 1);
          state.headcount = Math.max(1, state.participants.length);

          renderParticipantCards();
          fetchServerPriceCalculation();
        }
        return;
      }

      // 4. Add Participant Button Click
      if (target.closest('#ak-btn-add-participant')) {
        e.preventDefault();
        var maxLimit = (state.pricingResult && state.pricingResult.max_headcount !== undefined) ? state.pricingResult.max_headcount : null;

        syncParticipantInputsToState();

        if (maxLimit === null || state.headcount < maxLimit) {
          state.headcount++;
          state.participants.push({ name: '', email: '', phone: '', tshirt_size: '', tshirt_cut: 'men' });
          renderParticipantCards();
          fetchServerPriceCalculation();
        } else {
          showToast('Nie możesz dodać kolejnego uczestnika. Osiągnięto limit miejsc (' + maxLimit + ' os.).');
        }
        return;
      }

      // 5. Step 2 Back
      if (target.closest('#ak-btn-step-2-back')) {
        e.preventDefault();
        showStep(1, true);
        return;
      }

      // 6. Progress bar step click — allow going back to a previous step
      var stepItem = target.closest('.ak-step-item');
      if (stepItem) {
        var clickedStep = parseInt(stepItem.getAttribute('data-step'), 10);
        if (!isNaN(clickedStep) && clickedStep < state.currentStep) {
          e.preventDefault();
          showStep(clickedStep, true);
        }
        return;
      }

      if (target.closest('#ak-btn-submit-cart')) {
        e.preventDefault();
        syncBeforeSubmit();
        clearError();
        submitCartAJAX();
        return;
      }
    });

    function handleParticipantInput(e) {
      if (e.target && (e.target.classList.contains('ak-input-p-name') || e.target.classList.contains('ak-input-p-email') || e.target.classList.contains('ak-input-p-phone') || e.target.classList.contains('ak-input-p-size') || e.target.classList.contains('ak-input-p-cut'))) {
        syncParticipantInputsToState();

      }
    }

    app.addEventListener('change', handleParticipantInput);
    app.addEventListener('input', handleParticipantInput);

    // Apply pre-selected weekends selection UI and sync DOM elements
    applyInitialSelectionUI();

    // Initial Display Setup (do NOT auto-scroll on initial page load)
    showStep(1, false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAKWizard);
  } else {
    initAKWizard();
  }
})();
