/**
 * AK Product Set — Frontend AJAX Logic
 *
 * Handles:
 *  - Add participant form submission
 *  - Remove participant button clicks (delegated)
 *  - Live update of participant list + form visibility
 *  - WooCommerce cart fragment refresh after changes
 *  - Quick Select functionality (auto-detect global participants)
 */
(function () {
  'use strict';

  // Wait for DOM before doing anything.
  document.addEventListener('DOMContentLoaded', function () {
    var sections = document.querySelectorAll('.ak-product-set-section');
    if (!sections.length) return;

    var params = window.ak_set_params || {};
    var ajaxUrl = params.ajax_url || '';
    var nonce = params.nonce || '';
    var btnAddLabel = params.btn_add_label || 'Dodaj uczestnika';
    var btnLoading = params.btn_loading_label || 'Dodawanie…';
    var stockMsg = params.stock_exhausted || 'Wszystkie miejsca zajęte.';
    var errGeneric = params.error_generic || 'Wystąpił błąd.';

    // Initialize global quick select
    refreshQuickSelects();

    // -------------------------------------------------------------------------
    // Global delegated click — Quick Select
    // -------------------------------------------------------------------------
    document.addEventListener('click', function (e) {
      if (e.target.matches('.ak-quick-select-btn')) {
        var btn = e.target;
        var section = btn.closest('.ak-product-set-section');
        if (section) {
          var nameInput = section.querySelector('[name="name"]');
          var sizeInput = section.querySelector('[name="size"]');
          var cutInput = section.querySelector('[name="cut"]');
          var form = section.querySelector('.ak-participant-form');

          if (nameInput) nameInput.value = btn.dataset.name || '';
          if (sizeInput && btn.dataset.size) sizeInput.value = btn.dataset.size;
          if (cutInput && btn.dataset.cut) cutInput.value = btn.dataset.cut;

          // Auto-submit the form
          if (form) {
            form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
          }
        }
      }
    });

    // -------------------------------------------------------------------------
    // Global delegated click — Quick Select Add All
    // -------------------------------------------------------------------------
    document.addEventListener('click', function (e) {
      if (e.target.matches('.ak-quick-select-add-all-btn')) {
        var btnAll = e.target;
        var section = btnAll.closest('.ak-product-set-section');
        if (section) {
          var qsContainer = section.querySelector('.ak-quick-select-container');
          var chipBtns = qsContainer.querySelectorAll('.ak-quick-select-btn');
          if (!chipBtns.length) return;

          btnAll.disabled = true;
          btnAll.textContent = 'Dodawanie...';

          section.style.pointerEvents = 'none';
          section.style.opacity = '0.6';

          var productId = section.dataset.productId;
          var participants = [];

          chipBtns.forEach(function (chipBtn) {
            var p = { name: chipBtn.dataset.name || '' };
            if (chipBtn.dataset.size) p.size = chipBtn.dataset.size;
            if (chipBtn.dataset.cut) p.cut = chipBtn.dataset.cut;
            participants.push(p);
          });

          post('ak_add_participant', {
            product_id: productId,
            participants: JSON.stringify(participants)
          }).then(function (data) {
            if (data.success) {
              applyUpdate(section, data.data);
            } else {
              showNotice(section, (data.data && data.data.message) || errGeneric, 'error');
            }
          }).catch(function () {
            showNotice(section, errGeneric, 'error');
          }).finally(function () {
            section.style.pointerEvents = '';
            section.style.opacity = '1';
            btnAll.disabled = false;
            btnAll.textContent = 'Dodaj wszystkich powyższych';
          });
        }
      }
    });

    sections.forEach(function (section) {
      var productId = section.dataset.productId;

      // -------------------------------------------------------------------------
      // Delegated click — Remove button
      // -------------------------------------------------------------------------
      section.addEventListener('click', function (e) {
        var btn = e.target.closest('.ak-remove-btn');
        if (!btn) return;

        e.preventDefault();
        var cartItemKey = btn.dataset.key;
        if (!cartItemKey) return;

        btn.disabled = true;
        btn.textContent = '…';

        post('ak_remove_participant', {
          cart_item_key: cartItemKey,
          product_id: productId,
        }).then(function (data) {
          if (data.success) {
            applyUpdate(section, data.data);
          } else {
            showNotice(section, (data.data && data.data.message) || errGeneric, 'error');
            btn.disabled = false;
            btn.textContent = '✕';
          }
        }).catch(function () {
          showNotice(section, errGeneric, 'error');
          btn.disabled = false;
          btn.textContent = '✕';
        });
      });

      // -------------------------------------------------------------------------
      // Form submit — Add participant
      // -------------------------------------------------------------------------
      section.addEventListener('submit', function (e) {
        if (!e.target.matches('.ak-participant-form')) return;
        e.preventDefault();

        var form = e.target;
        var submitBtn = form.querySelector('.ak-btn-add');
        var nameInput = form.querySelector('[name="name"]');
        var name = nameInput ? nameInput.value.trim() : '';

        if (!name) {
          if (nameInput) nameInput.focus();
          showNotice(section, 'Podaj imię i nazwisko uczestnika.', 'error');
          return;
        }

        clearNotice(section);

        // Disable button, show loading state.
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = btnLoading;
        }

        var payload = {
          product_id: productId,
          name: name,
        };

        var sizeInput = form.querySelector('[name="size"]');
        var cutInput = form.querySelector('[name="cut"]');
        if (sizeInput) payload.size = sizeInput.value;
        if (cutInput) payload.cut = cutInput.value;

        post('ak_add_participant', payload).then(function (data) {
          if (data.success) {
            applyUpdate(section, data.data);
            form.reset();
            showNotice(section, '', ''); // clear any existing notice
          } else {
            showNotice(section, (data.data && data.data.message) || errGeneric, 'error');
          }
        }).catch(function () {
          showNotice(section, errGeneric, 'error');
        }).finally(function () {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = btnAddLabel;
          }
        });
      });
    });

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Escape HTML special characters for safe output.
     */
    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/[&<>"']/g, function (m) {
        return {
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#039;'
        }[m];
      });
    }

    /**
     * Auto-detect all unique participants globally and render Quick Select buttons.
     */
    function refreshQuickSelects() {
      var allParticipants = {};

      // 1. Gather all participants from all sections
      document.querySelectorAll('.ak-participant-item').forEach(function (li) {
        var name = li.dataset.name;
        if (!name) return;
        var size = li.dataset.size || '';
        var cut = li.dataset.cut || '';
        var hash = name + '|' + size + '|' + cut;
        if (!allParticipants[hash]) {
          allParticipants[hash] = { name: name, size: size, cut: cut };
        }
      });

      // 2. Update each section
      document.querySelectorAll('.ak-product-set-section').forEach(function (section) {
        var localHashes = {};
        section.querySelectorAll('.ak-participant-item').forEach(function (li) {
          var name = li.dataset.name;
          if (!name) return;
          var size = li.dataset.size || '';
          var cut = li.dataset.cut || '';
          var hash = name + '|' + size + '|' + cut;
          localHashes[hash] = true;
        });

        // Determine available participants for quick select
        var available = [];
        for (var hash in allParticipants) {
          if (!localHashes[hash]) {
            available.push(allParticipants[hash]);
          }
        }

        var formWrapper = section.querySelector('.ak-form-wrapper');
        if (!formWrapper) return; // Completely sold out, no form.

        var qsContainer = section.querySelector('.ak-quick-select-container');
        if (!qsContainer) {
          var formTitle = section.querySelector('.ak-form-title');
          if (formTitle) {
            qsContainer = document.createElement('div');
            qsContainer.className = 'ak-quick-select-container ak-hidden';
            formTitle.after(qsContainer);
          }
        }

        if (qsContainer) {
          if (available.length > 0) {
            var html = '<div class="ak-quick-select-label">Wybierz z już dodanych:</div><div class="ak-quick-select-chips">';
            available.forEach(function (p) {
              var label = p.name;
              var extras = [];
              if (p.size) extras.push(p.size);
              if (p.cut) extras.push(p.cut);
              if (extras.length > 0) {
                label += ' (' + extras.join(', ') + ')';
              }
              html += '<button type="button" class="ak-quick-select-btn" data-name="' + escapeHtml(p.name) + '" data-size="' + escapeHtml(p.size) + '" data-cut="' + escapeHtml(p.cut) + '">' + escapeHtml(label) + '</button>';
            });
            if (available.length > 1) {
              html += '<button type="button" class="ak-quick-select-add-all-btn">Dodaj wszystkich powyższych</button>';
            }
            html += '</div>';
            qsContainer.innerHTML = html;
            qsContainer.classList.remove('ak-hidden');
          } else {
            qsContainer.innerHTML = '';
            qsContainer.classList.add('ak-hidden');
          }
        }
      });
    }

    /**
     * Apply an AJAX response payload to the DOM for a specific section.
     * @param {HTMLElement} section
     * @param {{ list_html: string, is_exhausted: boolean }} data
     */
    function applyUpdate(section, data) {
      if (data.sections_data) {
        Object.keys(data.sections_data).forEach(function(pid) {
          var targetSection = document.querySelector('.ak-product-set-section[data-product-id="' + pid + '"]');
          if (!targetSection) return;
          var sectionData = data.sections_data[pid];

          if (sectionData.list_html) {
            var existing = targetSection.querySelector('.ak-participant-list');
            if (existing) {
              var tmp = document.createElement('div');
              tmp.innerHTML = sectionData.list_html;
              var newList = tmp.firstElementChild;
              if (newList) {
                existing.replaceWith(newList);
              }
            }
          }

          var form = targetSection.querySelector('.ak-participant-form');
          var exhaustedEl = targetSection.querySelector('.ak-stock-exhausted');

          if (sectionData.is_exhausted) {
            if (form) form.classList.add('ak-hidden');
            if (exhaustedEl) exhaustedEl.classList.remove('ak-hidden');
          } else {
            if (form) form.classList.remove('ak-hidden');
            if (exhaustedEl) exhaustedEl.classList.add('ak-hidden');
          }
        });
      } else {
        // Replace participant list HTML.
        if (data.list_html) {
          var existing = section.querySelector('.ak-participant-list');
          if (existing) {
            var tmp = document.createElement('div');
            tmp.innerHTML = data.list_html;
            var newList = tmp.firstElementChild;
            if (newList) {
              existing.replaceWith(newList);
            }
          }
        }

        // Toggle form / exhausted message.
        var form = section.querySelector('.ak-participant-form');
        var exhaustedEl = section.querySelector('.ak-stock-exhausted');

        if (data.is_exhausted) {
          if (form) form.classList.add('ak-hidden');
          if (exhaustedEl) exhaustedEl.classList.remove('ak-hidden');
        } else {
          if (form) form.classList.remove('ak-hidden');
          if (exhaustedEl) exhaustedEl.classList.add('ak-hidden');
        }
      }

      // Update global floating cart bar if present.
      if (typeof data.global_cart_total !== 'undefined') {
        var floatingBar = document.querySelector('.ak-floating-cart-bar');
        if (floatingBar) {
          var totalSpan = floatingBar.querySelector('.ak-global-cart-total-value');
          if (totalSpan) {
            totalSpan.innerHTML = data.global_cart_total;
          }
          if (data.is_cart_empty) {
            floatingBar.classList.add('ak-hidden');
          } else {
            floatingBar.classList.remove('ak-hidden');
          }
        }
      }

      // Refresh Quick Selects across the entire page since participants changed
      refreshQuickSelects();

      // Trigger standard wc cart event so fragments update (if active)
      document.body.dispatchEvent(new Event('wc_fragment_refresh', { bubbles: true }));
    }

    /**
     * Show an inline notice inside the form.
     * @param {HTMLElement} section
     * @param {string} message
     * @param {string} type    'error' | 'success' | ''
     */
    function showNotice(section, message, type) {
      var noticeEl = section.querySelector('.ak-form-notice');
      if (!noticeEl) return;

      noticeEl.textContent = message;
      noticeEl.className = 'ak-form-notice';

      if (!message) {
        noticeEl.classList.add('ak-hidden');
        return;
      }

      if (type) noticeEl.classList.add('ak-notice--' + type);
      noticeEl.classList.remove('ak-hidden');
    }

    function clearNotice(section) {
      showNotice(section, '', '');
    }

    /**
     * Trigger WooCommerce's cart fragment refresh if jQuery + WC are present.
     * This updates the mini-cart widget and cart count in the header.
     */
    function refreshWCFragments() {
      if (window.jQuery && jQuery.fn && jQuery(document.body).trigger) {
        jQuery(document.body).trigger('wc_fragment_refresh');
      }
    }

    /**
     * Perform a POST request to wp-admin/admin-ajax.php.
     *
     * @param {string} action   WP AJAX action name.
     * @param {Object} payload  Key-value pairs added to the POST body.
     * @returns {Promise<Object>} Parsed JSON response.
     */
    function post(action, payload) {
      var body = new URLSearchParams({ action: action, nonce: nonce });
      Object.keys(payload).forEach(function (k) {
        body.append(k, payload[k]);
      });

      return fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
      });
    }
  });
})();
