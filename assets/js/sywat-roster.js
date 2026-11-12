/**
 * SYWAT Archery - Archer Roster Management & Edit Modal Engine
 * Path: assets/js/sywat-roster.js
 * Version: 5.0.0
 */

(function() {
    'use strict';

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
    window.updateRosterEditFamilyOptions = function(lineIdx) {
        const famSelect = document.getElementById('edit_family_group_id');
        if (!famSelect) return;

        famSelect.innerHTML = '<option value="">[ + Start New Family Group for This Line ]</option>';

        const familiesMap = getFamiliesByLine();
        const fams = (familiesMap && familiesMap[lineIdx]) ? familiesMap[lineIdx] : {};

        Object.keys(fams).forEach(fid => {
            const opt = document.createElement('option');
            opt.value = fid;
            opt.textContent = fams[fid];
            famSelect.appendChild(opt);
        });
    };

    /**
     * Close Edit Archer Modal & Clean URL Query Params
     */
    window.closeEditArcherModal = function() {
        const modal = document.getElementById('sywat_edit_archer_modal');
        if (modal) {
            modal.classList.remove('active');
        }
        const url = new URL(window.location.href);
        url.searchParams.delete('edit_archer');
        url.searchParams.delete('open_edit_id');
        window.history.replaceState({}, document.title, url.toString());
    };

    /**
     * Recalculate Paid Status & Toggle Family Group Assignment Box
     */
    window.updatePaidStatusDisplay = function(type) {
        const payOption = document.getElementById(type + '_pay_option');
        const division  = document.getElementById(type + '_division');
        const displayEl = document.getElementById(type + '_paid_status_display');
        const familyBox = document.getElementById(type + '_family_group_box');

        if (!payOption || !division || !displayEl) return;

        let calculatedStatus = 'Adult';
        if (payOption.value === 'Family') {
            calculatedStatus = 'Family';
            if (familyBox) familyBox.style.display = '';
        } else {
            if (familyBox) familyBox.style.display = 'none';
            if (payOption.value === 'Field Governor') {
                calculatedStatus = 'Field Governor';
            } else {
                const youthDivs = ['Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)'];
                calculatedStatus = youthDivs.includes(division.value) ? 'Youth' : 'Adult';
            }
        }
        displayEl.textContent = calculatedStatus;
    };

    /**
     * Enforce Division vs. Shooting Style Restrictions
     */
    window.sywatRosterHandleStyleRestrictions = function(type) {
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
    };

    /**
     * Division Change Event Handler
     */
    window.sywatRosterHandleDivisionChange = function(type) {
        const divisionEl = document.getElementById(type + '_division');
        const distDispEl = document.getElementById(type + '_distance_display');
        if (!divisionEl) return;

        const calculatedDist = (divisionEl.value === 'Cub (8 - 11)') ? 'Cub' : '20yd';
        if (distDispEl) {
            distDispEl.value = calculatedDist;
        }

        window.updatePaidStatusDisplay(type);
        window.sywatRosterHandleStyleRestrictions(type);
    };

    /**
     * DOM Initialization & Event Wiring
     */
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize division rules for edit modal if open
        if (document.getElementById('edit_division')) {
            window.sywatRosterHandleDivisionChange('edit');
        }

        // Live Search Filter for Archer Roster Table
        const manageSearchInput = document.getElementById('search_shooter');
        if (manageSearchInput) {
            manageSearchInput.addEventListener('input', function() {
                const filter = this.value.toLowerCase().trim();
                document.querySelectorAll('.sywat-manage-row').forEach(row => {
                    const name = row.getAttribute('data-name');
                    row.style.display = (!filter || (name && name.includes(filter))) ? '' : 'none';
                });
            });
        }
    });

})();
