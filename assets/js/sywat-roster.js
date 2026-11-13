/**
 * SYWAT Archery - Archer Roster Management & Edit Modal Engine
 * Path: assets/js/sywat-roster.js
 * Version: 6.8.1
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    /**
     * Retrieve Line-Scoped Family Group Data
     */
    function getFamiliesByLine() {
        if (window.sywatRosterData && typeof window.sywatRosterData.existingFamiliesByLine === 'object') {
            return window.sywatRosterData.existingFamiliesByLine;
        }
        if (typeof existingFamiliesByLine !== 'undefined' && typeof existingFamiliesByLine === 'object') {
            return existingFamiliesByLine;
        }
        return {};
    }

    /**
     * Update Family Dropdown Options based on Shooting Line Selection
     */
    function updateRosterEditFamilyOptions(lineIdx) {
        const famSelect = document.getElementById('edit_family_group_id');
        if (!famSelect) return;

        famSelect.innerHTML = '<option value="">[ + Start New Family Group for This Line ]</option>';

        const familiesMap = getFamiliesByLine();
        const fams = (familiesMap && familiesMap[lineIdx]) ? familiesMap[lineIdx] : {};

        Object.keys(fams).forEach(function(fid) {
            const opt = document.createElement('option');
            opt.value = fid;
            opt.textContent = fams[fid];
            famSelect.appendChild(opt);
        });
    }

    /**
     * Close Roster Management Modal & Navigate to Clean Manage Tournaments URL
     */
    function closeRosterModal(triggerEl) {
        const modal = document.getElementById('sywat_roster_modal');
        const closeUrl = (triggerEl && triggerEl.getAttribute('data-close-url'))
            || (modal && modal.getAttribute('data-sywat-close-url'));

        if (modal) {
            modal.classList.remove('active');
        }

        if (closeUrl) {
            window.location.href = closeUrl;
            return;
        }

        const url = new URL(window.location.href);
        url.searchParams.delete('modal_action');
        url.searchParams.delete('view_tournament');
        url.searchParams.delete('filter_line_idx');
        url.searchParams.delete('search_shooter');
        url.searchParams.delete('edit_archer');
        url.searchParams.delete('open_edit_id');
        window.location.href = url.toString();
    }

    /**
     * Close Edit Archer Modal & Clean URL Query Params
     */
    function closeEditArcherModal() {
        const modal = document.getElementById('sywat_edit_archer_modal');
        if (modal) {
            modal.classList.remove('active');
        }
        const url = new URL(window.location.href);
        url.searchParams.delete('edit_archer');
        url.searchParams.delete('open_edit_id');
        window.history.replaceState({}, document.title, url.toString());
    }

    /**
     * Recalculate Paid Status & Toggle Family Group Assignment Box via CSS Class
     */
    function updatePaidStatusDisplay(type) {
        const payOption = document.getElementById(type + '_pay_option');
        const division  = document.getElementById(type + '_division');
        const displayEl = document.getElementById(type + '_paid_status_display');
        const familyBox = document.getElementById(type + '_family_group_box');

        if (!payOption || !division || !displayEl) return;

        let calculatedStatus = 'Adult';
        if (payOption.value === 'Family') {
            calculatedStatus = 'Family';
            if (familyBox) {
                familyBox.classList.remove('sywat-hidden');
            }
        } else {
            if (familyBox) {
                familyBox.classList.add('sywat-hidden');
            }
            if (payOption.value === 'Field Governor') {
                calculatedStatus = 'Field Governor';
            } else {
                const youthDivs = ['Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)'];
                calculatedStatus = youthDivs.includes(division.value) ? 'Youth' : 'Adult';
            }
        }
        displayEl.textContent = calculatedStatus;
    }

    /**
     * Enforce Division vs. Shooting Style Restrictions
     */
    function handleStyleRestrictions(type) {
        const divisionEl = document.getElementById(type + '_division');
        const styleEl    = document.getElementById(type + '_style');
        if (!divisionEl || !styleEl) return;

        const currentDiv = divisionEl.value;
        const allowedAdultSenior = ['Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)'];

        for (let i = 0; i < styleEl.options.length; i++) {
            const opt = styleEl.options[i];
            const val = opt.value;
            let isDisabled = false;

            // Genesis style requires Cub division
            if (val.indexOf('Genesis') !== -1 && currentDiv !== 'Cub (8 - 11)') {
                isDisabled = true;
            }

            // FS Pro, FSL, TRAD require Adult/Senior divisions
            const isFSPro = val.indexOf('FS Pro') !== -1;
            const isFSL   = val.indexOf('FSL –') !== -1;
            const isTRAD  = val.indexOf('TRAD') !== -1 || val.toLowerCase().indexOf('traditional') !== -1;

            if ((isFSPro || isFSL || isTRAD) && !allowedAdultSenior.includes(currentDiv)) {
                isDisabled = true;
            }

            // Longbow requires Adult (18 - 49)
            const isLB = val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1;
            if (isLB && currentDiv !== 'Adult (18 - 49)') {
                isDisabled = true;
            }

            opt.disabled = isDisabled;

            // Default fallback if current selection becomes disabled
            if (isDisabled && styleEl.value === val) {
                styleEl.value = 'FS – Freestyle';
            }
        }
    }

    /**
     * Division Change Event Handler
     */
    function handleDivisionChange(type) {
        const divisionEl = document.getElementById(type + '_division');
        const distDispEl = document.getElementById(type + '_distance_display');
        if (!divisionEl) return;

        const calculatedDist = (divisionEl.value === 'Cub (8 - 11)') ? 'Cub' : '20yd';
        if (distDispEl) {
            distDispEl.value = calculatedDist;
        }

        updatePaidStatusDisplay(type);
        handleStyleRestrictions(type);
    }

    /**
     * Filter Archer Roster Table Rows via CSS Class Toggle
     */
    function applyRosterSearchFilter(rawQuery) {
        const filter = (rawQuery || '').toLowerCase().trim();
        document.querySelectorAll('.sywat-manage-row').forEach(function(row) {
            const name = row.getAttribute('data-name') || '';
            if (!filter || name.includes(filter)) {
                row.classList.remove('sywat-hidden');
            } else {
                row.classList.add('sywat-hidden');
            }
        });
    }

    // Namespaced Module API (Section IV.1)
    window.SYWAT.roster = {
        updateEditFamilyOptions: updateRosterEditFamilyOptions,
        closeRosterModal: closeRosterModal,
        closeEditArcherModal: closeEditArcherModal,
        updatePaidStatusDisplay: updatePaidStatusDisplay,
        handleStyleRestrictions: handleStyleRestrictions,
        handleDivisionChange: handleDivisionChange,
        applySearchFilter: applyRosterSearchFilter
    };

    // Legacy global aliases for backward compatibility with existing modal markup
    window.updateRosterEditFamilyOptions      = updateRosterEditFamilyOptions;
    window.closeEditArcherModal               = closeEditArcherModal;
    window.updatePaidStatusDisplay            = updatePaidStatusDisplay;
    window.sywatRosterHandleStyleRestrictions = handleStyleRestrictions;
    window.sywatRosterHandleDivisionChange    = handleDivisionChange;

    // Delegated Click Listener (Optimizer-Safe — Section IV.1)
    document.addEventListener('click', function(event) {
        const actionEl = event.target.closest('[data-sywat-action]');
        if (!actionEl) return;

        const action = actionEl.getAttribute('data-sywat-action');

        if (action === 'close-roster-modal') {
            event.preventDefault();
            closeRosterModal(actionEl);
        } else if (action === 'close-edit-archer-modal') {
            event.preventDefault();
            closeEditArcherModal();
        } else if (action === 'clear-roster-search') {
            event.preventDefault();
            const searchInput = document.getElementById('search_shooter');
            if (searchInput) {
                searchInput.value = '';
                applyRosterSearchFilter('');
                searchInput.focus();
            }
        }
    });

    // Delegated Change Listener (Optimizer-Safe — Section IV.1)
    document.addEventListener('change', function(event) {
        const actionEl = event.target.closest('[data-sywat-action]');
        if (!actionEl) return;

        const action = actionEl.getAttribute('data-sywat-action');

        if (action === 'filter-roster-line') {
            if (actionEl.form) {
                actionEl.form.submit();
            }
        } else if (action === 'roster-edit-line-change') {
            updateRosterEditFamilyOptions(actionEl.value);
        } else if (action === 'roster-edit-division-change') {
            handleDivisionChange('edit');
        } else if (action === 'roster-edit-pay-change') {
            updatePaidStatusDisplay('edit');
        }
    });

    // Delegated Submit Confirmation Listener (Section IV.1)
    document.addEventListener('submit', function(event) {
        const form = event.target.closest('form[data-sywat-confirm]');
        if (!form) return;

        const message = form.getAttribute('data-sywat-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    /**
     * DOM Initialization & Live Search Input Wiring
     */
    function initRosterModule() {
        if (document.getElementById('edit_division')) {
            handleDivisionChange('edit');
        }

        const manageSearchInput = document.getElementById('search_shooter');
        if (manageSearchInput) {
            if (manageSearchInput.value) {
                applyRosterSearchFilter(manageSearchInput.value);
            }
            manageSearchInput.addEventListener('input', function() {
                applyRosterSearchFilter(this.value);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRosterModule);
    } else {
        initRosterModule();
    }

})();
