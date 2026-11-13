/**
 * SYWAT Archery - Temporary POC TFAA Gender & Pro Reconciliation Script
 * Path: assets/js/sywat-tfaa-reconcile.js
 * Description: Namespaced client-side controller (window.SYWAT.reconcile) for the on-demand TFAA Gender & Pro Status reconciliation modal (#sywat_tfaa_reconcile_modal). Handles modal dismissal with URL query state cleanup, select-all page checkbox toggling, and enabling/disabling datalist member mapping inputs. Self-contained for easy removal post-POC.
 * Compliance: Vanilla JS only (zero jQuery), zero bare global functions, zero inline style manipulation, zero document-level keydown listeners.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    window.SYWAT.reconcile = {
        /**
         * Close the TFAA reconciliation modal and strip temporary reconciliation query parameters from the URL.
         */
        closeModal: function() {
            const modal = document.getElementById('sywat_tfaa_reconcile_modal');
            if (modal) {
                modal.classList.remove('active');
            }

            if (window.history && typeof window.history.replaceState === 'function') {
                const url = new URL(window.location.href);
                url.searchParams.delete('reconcile_tfaa');
                url.searchParams.delete('rec_mpage');
                url.searchParams.delete('rec_upage');
                url.searchParams.delete('rec_search');
                window.history.replaceState({}, document.title, url.toString());
            }
        },

        /**
         * Toggle all matched directory target checkboxes on the currently rendered page.
         *
         * @param {boolean} isChecked Target checked state.
         */
        toggleSelectAll: function(isChecked) {
            const checkboxes = document.querySelectorAll('.sywat-reconcile-target-cb');
            checkboxes.forEach(function(cb) {
                cb.checked = Boolean(isChecked);
            });
        },

        /**
         * Enable or disable the datalist member mapping input when an unmatched row's action dropdown changes.
         *
         * @param {HTMLSelectElement} selectEl The action dropdown element.
         */
        handleUnmatchedActionChange: function(selectEl) {
            if (!selectEl) return;

            const safeKey = (selectEl.getAttribute('data-norm-key') || '').replace(/[^a-zA-Z0-9_-]/g, '');
            const mapInput = document.getElementById('sywat_map_target_' + safeKey);
            if (!mapInput) return;

            const isMapping = (selectEl.value === 'map_member');
            mapInput.disabled = !isMapping;

            if (isMapping) {
                mapInput.focus();
            } else {
                mapInput.value = '';
            }
        }
    };

    // Delegated click handler for modal close / cancel triggers
    document.addEventListener('click', function(e) {
        const closeTrigger = e.target.closest('[data-sywat-action="close-reconcile-modal"]');
        if (closeTrigger) {
            e.preventDefault();
            window.SYWAT.reconcile.closeModal();
        }
    });

    // Delegated change handler for Select All and Unmatched Action dropdowns
    document.addEventListener('change', function(e) {
        if (!e.target) return;

        if (e.target.id === 'sywat_reconcile_select_all') {
            window.SYWAT.reconcile.toggleSelectAll(e.target.checked);
            return;
        }

        if (e.target.classList.contains('sywat-unmatched-action-select')) {
            window.SYWAT.reconcile.handleUnmatchedActionChange(e.target);
        }
    });
})();
