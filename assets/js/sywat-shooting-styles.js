/**
 * SYWAT Archery - Shooting Styles & Divisions Client Controller
 * Path: assets/js/sywat-shooting-styles.js
 * Description: Namespaced vanilla JS module (window.SYWAT.shootingStyles) managing the Shooting Styles & Divisions Administration modals, dynamic rule-mode toggles, live table search, confirmation dialogs, and reusable client-side style/division rule enforcement.
 * Compliance: Zero jQuery, zero inline style.* mutations, zero document-level keydown handlers, delegated event listeners, guarded DOM initialization.
 * Author: SYWAT Archery
 * Version: 1.0.1
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    /**
     * Toggle visibility of Restrict vs. Force division configuration boxes inside the Style modal.
     *
     * @param {string} mode Rule mode ('all', 'restrict', or 'force').
     * @return {void}
     */
    function syncRuleModeFields(mode) {
        const restrictBox = document.getElementById('sywat_style_restrict_box');
        const forceBox    = document.getElementById('sywat_style_force_box');
        const forcedSel   = document.getElementById('sywat_style_forced_division');

        if (restrictBox) {
            if (mode === 'restrict') {
                restrictBox.classList.remove('sywat-hidden');
            } else {
                restrictBox.classList.add('sywat-hidden');
            }
        }

        if (forceBox) {
            if (mode === 'force') {
                forceBox.classList.remove('sywat-hidden');
                if (forcedSel) {
                    forcedSel.setAttribute('required', 'required');
                }
            } else {
                forceBox.classList.add('sywat-hidden');
                if (forcedSel) {
                    forcedSel.removeAttribute('required');
                }
            }
        }
    }

    /**
     * Open the Shooting Style modal in Add or Edit mode.
     *
     * @param {HTMLElement|null} triggerBtn Optional Edit button carrying data-* attributes.
     * @return {void}
     */
    function openStyleModal(triggerBtn) {
        const modal       = document.getElementById('sywat_style_editor_modal');
        const titleEl     = document.getElementById('sywat_style_modal_title');
        const origSlugEl  = document.getElementById('sywat_style_orig_slug');
        const labelEl     = document.getElementById('sywat_style_label');
        const codeEl      = document.getElementById('sywat_style_code');
        const sortEl      = document.getElementById('sywat_style_sort_order');
        const statusEl    = document.getElementById('sywat_style_status');
        const isProEl     = document.getElementById('sywat_style_is_pro');
        const aliasesEl   = document.getElementById('sywat_style_aliases');
        const ruleModeEl  = document.getElementById('sywat_style_rule_mode');
        const forcedDivEl = document.getElementById('sywat_style_forced_division');
        const allowedCbs  = document.querySelectorAll('.sywat-style-allowed-div-cb');

        if (!modal) return;

        const isEdit = Boolean(triggerBtn && triggerBtn.getAttribute('data-slug'));

        if (titleEl) {
            titleEl.textContent = isEdit ? 'Edit Shooting Style' : 'Add Shooting Style';
        }

        if (isEdit) {
            const ruleMode = triggerBtn.getAttribute('data-rule-mode') || 'all';
            let allowedList = [];
            try {
                const rawJson = triggerBtn.getAttribute('data-allowed-divisions') || '[]';
                const parsed  = JSON.parse(rawJson);
                if (Array.isArray(parsed)) {
                    allowedList = parsed;
                }
            } catch (err) {
                allowedList = [];
            }

            if (origSlugEl)  origSlugEl.value   = triggerBtn.getAttribute('data-slug') || '';
            if (labelEl)     labelEl.value      = triggerBtn.getAttribute('data-label') || '';
            if (codeEl)      codeEl.value       = triggerBtn.getAttribute('data-code') || '';
            if (sortEl)      sortEl.value       = triggerBtn.getAttribute('data-sort-order') || '10';
            if (statusEl)    statusEl.value     = triggerBtn.getAttribute('data-status') || 'active';
            if (isProEl)     isProEl.checked    = (triggerBtn.getAttribute('data-is-pro') === '1');
            if (aliasesEl)   aliasesEl.value    = triggerBtn.getAttribute('data-aliases') || '';
            if (ruleModeEl)  ruleModeEl.value   = ruleMode;
            if (forcedDivEl) forcedDivEl.value  = triggerBtn.getAttribute('data-forced-division') || '';

            allowedCbs.forEach(function(cb) {
                cb.checked = allowedList.indexOf(cb.value) !== -1;
            });

            syncRuleModeFields(ruleMode);
        } else {
            const rowCount = document.querySelectorAll('.sywat-style-config-row').length;
            if (origSlugEl)  origSlugEl.value   = '';
            if (labelEl)     labelEl.value      = '';
            if (codeEl)      codeEl.value       = '';
            if (sortEl)      sortEl.value       = String(rowCount + 1);
            if (statusEl)    statusEl.value     = 'active';
            if (isProEl)     isProEl.checked    = false;
            if (aliasesEl)   aliasesEl.value    = '';
            if (ruleModeEl)  ruleModeEl.value   = 'all';
            if (forcedDivEl) forcedDivEl.value  = '';

            allowedCbs.forEach(function(cb) {
                cb.checked = false;
            });

            syncRuleModeFields('all');
        }

        modal.classList.add('active');
    }

    /**
     * Close the Shooting Style editor modal.
     *
     * @return {void}
     */
    function closeStyleModal() {
        const modal = document.getElementById('sywat_style_editor_modal');
        if (modal) {
            modal.classList.remove('active');
        }
    }

    /**
     * Open the Age Division modal in Add or Edit mode.
     *
     * @param {HTMLElement|null} triggerBtn Optional Edit button carrying data-* attributes.
     * @return {void}
     */
    function openDivisionModal(triggerBtn) {
        const modal      = document.getElementById('sywat_division_editor_modal');
        const titleEl    = document.getElementById('sywat_div_modal_title');
        const origSlugEl = document.getElementById('sywat_div_orig_slug');
        const labelEl    = document.getElementById('sywat_div_label');
        const codeEl     = document.getElementById('sywat_div_code');
        const distEl     = document.getElementById('sywat_div_distance');
        const tierEl     = document.getElementById('sywat_div_rate_tier');
        const sortEl     = document.getElementById('sywat_div_sort_order');
        const statusEl   = document.getElementById('sywat_div_status');
        const aliasesEl  = document.getElementById('sywat_div_aliases');

        if (!modal) return;

        const isEdit = Boolean(triggerBtn && triggerBtn.getAttribute('data-slug'));

        if (titleEl) {
            titleEl.textContent = isEdit ? 'Edit Age Division' : 'Add Age Division';
        }

        if (isEdit) {
            if (origSlugEl) origSlugEl.value = triggerBtn.getAttribute('data-slug') || '';
            if (labelEl)    labelEl.value    = triggerBtn.getAttribute('data-label') || '';
            if (codeEl)     codeEl.value     = triggerBtn.getAttribute('data-code') || '';
            if (distEl)     distEl.value     = triggerBtn.getAttribute('data-distance') || '20yd';
            if (tierEl)     tierEl.value     = triggerBtn.getAttribute('data-rate-tier') || 'Adult';
            if (sortEl)     sortEl.value     = triggerBtn.getAttribute('data-sort-order') || '10';
            if (statusEl)   statusEl.value   = triggerBtn.getAttribute('data-status') || 'active';
            if (aliasesEl)  aliasesEl.value  = triggerBtn.getAttribute('data-aliases') || '';
        } else {
            if (origSlugEl) origSlugEl.value = '';
            if (labelEl)    labelEl.value    = '';
            if (codeEl)     codeEl.value     = '';
            if (distEl)     distEl.value     = '20yd';
            if (tierEl)     tierEl.value     = 'Adult';
            if (sortEl)     sortEl.value     = '8';
            if (statusEl)   statusEl.value   = 'active';
            if (aliasesEl)  aliasesEl.value  = '';
        }

        modal.classList.add('active');
    }

    /**
     * Close the Age Division editor modal.
     *
     * @return {void}
     */
    function closeDivisionModal() {
        const modal = document.getElementById('sywat_division_editor_modal');
        if (modal) {
            modal.classList.remove('active');
        }
    }

    /**
     * Filter Shooting Styles table rows using CSS class toggles.
     *
     * @param {string} rawQuery Search input text.
     * @return {void}
     */
    function applyStylesSearchFilter(rawQuery) {
        const query = (rawQuery || '').toLowerCase().trim();
        document.querySelectorAll('.sywat-style-config-row').forEach(function(row) {
            const haystack = (row.getAttribute('data-style-search') || '').toLowerCase();
            if (!query || haystack.indexOf(query) !== -1) {
                row.classList.remove('sywat-hidden');
            } else {
                row.classList.add('sywat-hidden');
            }
        });
    }

    /**
     * Look up a style configuration object from window.sywatShootingStylesConfig by label, code, or alias.
     *
     * @param {string} rawStyle Style option value or text.
     * @return {Object|null} Matching style config or null.
     */
    function findStyleConfig(rawStyle) {
        const cfg = window.sywatShootingStylesConfig;
        if (!cfg || !Array.isArray(cfg.styles) || !rawStyle) {
            return null;
        }

        const needle = String(rawStyle).trim().toLowerCase();
        if (!needle) return null;

        for (let i = 0; i < cfg.styles.length; i++) {
            const item = cfg.styles[i];
            if ((item.label || '').toLowerCase() === needle || (item.code || '').toLowerCase() === needle) {
                return item;
            }
            if (Array.isArray(item.aliases)) {
                for (let j = 0; j < item.aliases.length; j++) {
                    if ((item.aliases[j] || '').toLowerCase() === needle) {
                        return item;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Reusable client-side rule enforcer for any Division + Style <select> pair.
     * Supports 'all', 'restrict' (disables ineligible styles & falls back to defaultStyle),
     * and 'force' (pushes divisionEl to forced_division, e.g., Longbow -> Adult (18 - 49)).
     *
     * @param {HTMLSelectElement} divisionEl Division select element.
     * @param {HTMLSelectElement} styleEl    Style select element.
     * @param {string}            trigger    Which field triggered the check ('division' | 'style').
     * @return {Object} Result metadata { divisionChanged: boolean, styleChanged: boolean, forcedDivision: string }.
     */
    function applyStyleRules(divisionEl, styleEl, trigger) {
        const result = {
            divisionChanged: false,
            styleChanged: false,
            forcedDivision: ''
        };

        if (!divisionEl || !styleEl) {
            return result;
        }

        const cfg = window.sywatShootingStylesConfig;
        if (!cfg || !Array.isArray(cfg.styles)) {
            return result;
        }

        const selectedStyleCfg = findStyleConfig(styleEl.value);

        // If selected style enforces a target division (e.g., LB – Longbow -> Adult (18 - 49)), push division automatically
        if (
            selectedStyleCfg &&
            selectedStyleCfg.rule_mode === 'force' &&
            selectedStyleCfg.forced_division &&
            divisionEl.value !== 'No Show'
        ) {
            result.forcedDivision = selectedStyleCfg.forced_division;
            if (divisionEl.value !== selectedStyleCfg.forced_division) {
                divisionEl.value = selectedStyleCfg.forced_division;
                result.divisionChanged = true;
            }
        }

        const currentDiv = divisionEl.value;
        if (!currentDiv || currentDiv === 'No Show') {
            for (let i = 0; i < styleEl.options.length; i++) {
                styleEl.options[i].disabled = false;
            }
            return result;
        }

        const fallbackStyle = cfg.defaultStyle || 'FS – Freestyle';

        for (let i = 0; i < styleEl.options.length; i++) {
            const opt = styleEl.options[i];
            const optCfg = findStyleConfig(opt.value);
            let isDisabled = false;

            if (optCfg && optCfg.rule_mode === 'restrict') {
                const allowed = Array.isArray(optCfg.allowed_divisions) ? optCfg.allowed_divisions : [];
                if (allowed.indexOf(currentDiv) === -1) {
                    isDisabled = true;
                }
            }

            opt.disabled = isDisabled;

            if (isDisabled && styleEl.value === opt.value) {
                styleEl.value = fallbackStyle;
                result.styleChanged = true;
            }
        }

        return result;
    }

    // Export Public Namespaced API
    window.SYWAT.shootingStyles = {
        openStyleModal: openStyleModal,
        closeStyleModal: closeStyleModal,
        openDivisionModal: openDivisionModal,
        closeDivisionModal: closeDivisionModal,
        syncRuleModeFields: syncRuleModeFields,
        applySearchFilter: applyStylesSearchFilter,
        findStyleConfig: findStyleConfig,
        applyStyleRules: applyStyleRules
    };

    // Delegated Click Listeners
    document.addEventListener('click', function(event) {
        const actionEl = event.target.closest('[data-sywat-action]');
        if (!actionEl) return;

        const action = actionEl.getAttribute('data-sywat-action');

        if (action === 'open-add-style-modal') {
            event.preventDefault();
            openStyleModal(null);
        } else if (action === 'open-edit-style-modal') {
            event.preventDefault();
            openStyleModal(actionEl);
        } else if (action === 'close-style-modal') {
            event.preventDefault();
            closeStyleModal();
        } else if (action === 'open-add-division-modal') {
            event.preventDefault();
            openDivisionModal(null);
        } else if (action === 'open-edit-division-modal') {
            event.preventDefault();
            openDivisionModal(actionEl);
        } else if (action === 'close-division-modal') {
            event.preventDefault();
            closeDivisionModal();
        } else if (action === 'clear-styles-search') {
            event.preventDefault();
            const searchInput = document.getElementById('sywat_styles_search_input');
            if (searchInput) {
                searchInput.value = '';
                applyStylesSearchFilter('');
                searchInput.focus();
            }
        }
    });

    // Delegated Change Listener for Rule Mode Dropdown
    document.addEventListener('change', function(event) {
        const actionEl = event.target.closest('[data-sywat-action="change-style-rule-mode"]');
        if (!actionEl) return;
        syncRuleModeFields(actionEl.value);
    });

    // Delegated Live Search Input Listener
    document.addEventListener('input', function(event) {
        if (event.target && event.target.id === 'sywat_styles_search_input') {
            applyStylesSearchFilter(event.target.value);
        }
    });

    // Delegated Form Confirmation Listener
    document.addEventListener('submit', function(event) {
        const form = event.target;
        if (!form || !form.hasAttribute('data-sywat-confirm')) return;

        const message = form.getAttribute('data-sywat-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    // Guarded Initialization, Element-Scoped Keydown Guard & Auto-Dismiss System Notices
    function initSywatShootingStyles() {
        const searchInput = document.getElementById('sywat_styles_search_input');
        if (searchInput) {
            searchInput.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            });
        }

        const noticeEl = document.getElementById('sywat-notice');
        if (noticeEl) {
            setTimeout(function() {
                noticeEl.classList.add('sywat-fade-out');
                setTimeout(function() {
                    noticeEl.classList.add('sywat-hidden');
                }, 500);
            }, 5000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSywatShootingStyles);
    } else {
        initSywatShootingStyles();
    }
})();
