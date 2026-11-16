/**
 * SYWAT Archery - Archer Roster Management & Edit Modal Engine
 * Path: assets/js/sywat-roster.js
 * Description: Manages the Registered Archer Roster modal, live search filtering, line-scoped family group assignments, multi-round pricing & live balance synchronization, Promotional Free Entry status display, and database-driven shooting style & age division rule enforcement.
 * Compliance: Vanilla JS only (zero jQuery), zero element.style.* mutations, delegated event listeners, class-based visibility (.sywat-hidden).
 * Version: 7.2.0
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
     * Retrieve Localized Database Age Divisions
     */
    function getDivisions() {
        if (window.sywatRosterData && Array.isArray(window.sywatRosterData.divisions)) {
            return window.sywatRosterData.divisions;
        }
        return [];
    }

    /**
     * Retrieve Active Tournament Pricing & Round Profiles
     */
    function getProfiles() {
        if (window.sywatRosterData && typeof window.sywatRosterData.profiles === 'object' && window.sywatRosterData.profiles !== null) {
            return window.sywatRosterData.profiles;
        }
        if (window.SYWAT && window.SYWAT.pricing && typeof window.SYWAT.pricing.getProfiles === 'function') {
            return window.SYWAT.pricing.getProfiles();
        }
        return {};
    }

    function getDefaultProfileKey() {
        if (window.sywatRosterData && window.sywatRosterData.defaultProfile) {
            return String(window.sywatRosterData.defaultProfile);
        }
        const profiles = getProfiles();
        const keys = Object.keys(profiles);
        return keys.length > 0 ? keys[0] : 'indoor';
    }

    function getProfile(profileSlug) {
        const profiles = getProfiles();
        const slug = String(profileSlug || '').trim();
        if (slug && profiles[slug]) {
            return profiles[slug];
        }
        const defSlug = getDefaultProfileKey();
        return profiles[defSlug] || null;
    }

    /**
     * Lookup Division Metadata Record by Label, Code, or Alias
     */
    function findDivisionConfig(divValue) {
        if (!divValue) return null;
        const needle = String(divValue).trim().toLowerCase();
        if (!needle) return null;

        const divisions = getDivisions();
        for (let i = 0; i < divisions.length; i++) {
            const d = divisions[i];
            if ((d.label || '').trim().toLowerCase() === needle || (d.code || '').trim().toLowerCase() === needle) {
                return d;
            }
            if (Array.isArray(d.aliases)) {
                for (let j = 0; j < d.aliases.length; j++) {
                    if (String(d.aliases[j] || '').trim().toLowerCase() === needle) {
                        return d;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Resolve Shooting Distance ('Cub' vs '20yd') from Division Select Element
     */
    function getDivisionDistance(divisionEl) {
        if (!divisionEl) return '20yd';
        const selectedOpt = divisionEl.options[divisionEl.selectedIndex];
        const optDist     = selectedOpt ? selectedOpt.getAttribute('data-distance') : '';
        if (optDist === 'Cub' || optDist === '20yd') {
            return optDist;
        }

        const cfg = findDivisionConfig(divisionEl.value);
        if (cfg && (cfg.distance === 'Cub' || cfg.distance === '20yd')) {
            return cfg.distance;
        }

        return (divisionEl.value === 'Cub (8 - 11)') ? 'Cub' : '20yd';
    }

    /**
     * Resolve Individual Fee Tier ('Youth' vs 'Adult') from Division Select Element
     */
    function getDivisionRateTier(divisionEl) {
        if (!divisionEl) return 'Adult';
        const selectedOpt = divisionEl.options[divisionEl.selectedIndex];
        const optTier     = selectedOpt ? selectedOpt.getAttribute('data-rate-tier') : '';
        if (optTier === 'Youth' || optTier === 'Adult') {
            return optTier;
        }

        const cfg = findDivisionConfig(divisionEl.value);
        if (cfg && (cfg.rate_tier === 'Youth' || cfg.rate_tier === 'Adult')) {
            return cfg.rate_tier;
        }

        const youthDivs = ['Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)'];
        return youthDivs.includes(divisionEl.value) ? 'Youth' : 'Adult';
    }

    /**
     * Calculate exact individual entry fee for the Edit Archer Modal based on
     * active Round Format, Division, Distance, Style, and Paid Option.
     */
    function calculateRosterEditFee(type) {
        const divisionEl = document.getElementById(type + '_division');
        const styleEl    = document.getElementById(type + '_style');
        const roundEl    = document.getElementById(type + '_pricing_profile');
        const payOption  = document.getElementById(type + '_pay_option');
        const statusEl   = document.getElementById(type + '_status');

        if (!divisionEl) return 0;

        const statusVal = statusEl ? String(statusEl.value || '').trim() : 'Pre-Reg';
        if (statusVal === 'Waitlist' || statusVal === 'Cancelled') {
            return 0;
        }

        const payVal = payOption ? String(payOption.value || 'Individual').trim() : 'Individual';
        if (payVal === 'Promo (Free)') {
            return 0;
        }

        const divVal    = String(divisionEl.value || '').trim();
        const distVal   = getDivisionDistance(divisionEl);
        const styleVal  = styleEl ? String(styleEl.value || '').trim() : '';
        const roundSlug = roundEl ? String(roundEl.value || '').trim() : getDefaultProfileKey();
        const tournName = (window.sywatRosterData && window.sywatRosterData.tournamentName) ? String(window.sywatRosterData.tournamentName) : '';

        if (window.SYWAT && window.SYWAT.pricing && typeof window.SYWAT.pricing.calculateIndividualFee === 'function') {
            return window.SYWAT.pricing.calculateIndividualFee({
                tournamentName: tournName,
                division: divVal,
                distance: distVal,
                style: styleVal,
                roundSlug: roundSlug,
                paidStatus: (payVal === 'Field Governor') ? 'Field Governor' : ''
            });
        }

        const prof = getProfile(roundSlug);
        if (payVal === 'Field Governor') {
            return prof && typeof prof.field_governor_rate !== 'undefined' ? (parseFloat(prof.field_governor_rate) || 0) : 0;
        }

        const divCfg = findDivisionConfig(divVal);
        if (prof) {
            if (divCfg && divCfg.slug && prof.division_overrides && typeof prof.division_overrides[divCfg.slug] !== 'undefined' && prof.division_overrides[divCfg.slug] !== '') {
                const ovFee = parseFloat(prof.division_overrides[divCfg.slug]);
                if (!Number.isNaN(ovFee)) return ovFee;
            }
            if (styleVal && prof.pro_rate !== '' && prof.pro_rate !== null && typeof prof.pro_rate !== 'undefined') {
                if (styleVal.toLowerCase().indexOf('pro') !== -1 || divVal.toLowerCase().indexOf('pro') !== -1) {
                    const proFee = parseFloat(prof.pro_rate);
                    if (!Number.isNaN(proFee)) return proFee;
                }
            }
            const tier = getDivisionRateTier(divisionEl);
            const tierFee = (tier === 'Youth') ? parseFloat(prof.youth_rate) : parseFloat(prof.adult_rate);
            if (!Number.isNaN(tierFee)) return tierFee;
        }

        return (getDivisionRateTier(divisionEl) === 'Youth') ? 25 : 30;
    }

    /**
     * Synchronize #edit_balance_due live when Division, Style, Round, Status, or Pay Option changes
     * (only when the record originally had an unpaid balance and was not manually overridden).
     */
    function syncEditBalanceDue(type) {
        const balanceEl = document.getElementById(type + '_balance_due');
        if (!balanceEl) return;

        const statusEl = document.getElementById(type + '_status');
        const payEl    = document.getElementById(type + '_pay_option');
        const status   = statusEl ? String(statusEl.value || '') : 'Pre-Reg';
        const payVal   = payEl ? String(payEl.value || '') : 'Individual';

        if (status === 'Waitlist' || status === 'Cancelled' || payVal === 'Promo (Free)' || payVal === 'Field Governor') {
            balanceEl.value = '0.00';
            return;
        }

        if (balanceEl.dataset.manualOverride === '1') {
            return;
        }

        const origDue = parseFloat(balanceEl.dataset.origDue || '0');
        if (Number.isNaN(origDue) || origDue <= 0) {
            return;
        }

        if (payVal === 'Family') {
            return;
        }

        const newFee = calculateRosterEditFee(type);
        balanceEl.value = newFee.toFixed(2);
    }

    /**
     * Update Paid Option dropdown labels (Individual & Family Cap) when the archer's
     * Round Format selector changes on multi-round tournaments (e.g. Field vs Field 50).
     */
    function updatePayOptionLabelsForRound(type) {
        const roundEl   = document.getElementById(type + '_pricing_profile');
        const payOption = document.getElementById(type + '_pay_option');
        if (!roundEl || !payOption) return;

        const prof = getProfile(roundEl.value);
        if (!prof) return;

        const youthRate  = parseFloat(prof.youth_rate);
        const adultRate  = parseFloat(prof.adult_rate);
        const capEnabled = Boolean(prof.family_cap_enabled);
        const capAmount  = parseFloat(prof.family_cap_amount) || 75;
        const capMin     = parseInt(prof.family_min_archers, 10) || 3;

        const yDisp = !Number.isNaN(youthRate) ? Math.round(youthRate) : 25;
        const aDisp = !Number.isNaN(adultRate) ? Math.round(adultRate) : 30;

        const indLabel = (yDisp === aDisp)
            ? 'Individual Rate ($' + aDisp + ')'
            : 'Individual Rate ($' + yDisp + ' / $' + aDisp + ')';

        const famLabel = capEnabled
            ? 'Family Group ($' + Math.round(capAmount) + ' Cap for ' + capMin + '+ Archers)'
            : 'Family Group';

        Array.from(payOption.options).forEach(function(opt) {
            if (opt.value === 'Individual') {
                opt.textContent = indLabel;
            } else if (opt.value === 'Family') {
                opt.textContent = famLabel;
            }
        });
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
    function updatePaidStatusDisplay(type, shouldSyncBalance) {
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
            if (payOption.value === 'Promo (Free)') {
                calculatedStatus = 'Promo (Free)';
            } else if (payOption.value === 'Field Governor') {
                calculatedStatus = 'Field Governor';
            } else {
                calculatedStatus = getDivisionRateTier(division);
            }
        }
        displayEl.textContent = calculatedStatus;

        if (shouldSyncBalance !== false) {
            syncEditBalanceDue(type);
        }
    }

    /**
     * Enforce Division vs. Shooting Style Restrictions (Database-Driven with Legacy Fallback)
     */
    function handleStyleRestrictions(type) {
        const divisionEl = document.getElementById(type + '_division');
        const styleEl    = document.getElementById(type + '_style');
        if (!divisionEl || !styleEl) return;

        if (window.SYWAT && window.SYWAT.shootingStyles && typeof window.SYWAT.shootingStyles.applyStyleRules === 'function') {
            window.SYWAT.shootingStyles.applyStyleRules(styleEl, divisionEl, { trigger: 'division' });
            return;
        }

        // Legacy fallback if shared shooting styles rule controller is unavailable
        const currentDiv = divisionEl.value;
        const allowedAdultSenior = ['Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)'];

        for (let i = 0; i < styleEl.options.length; i++) {
            const opt = styleEl.options[i];
            const val = opt.value;
            let isDisabled = false;

            if (val.indexOf('Genesis') !== -1 && currentDiv !== 'Cub (8 - 11)') {
                isDisabled = true;
            }

            const isFSPro = val.indexOf('FS Pro') !== -1;
            const isFSL   = val.indexOf('FSL –') !== -1;
            const isTRAD  = val.indexOf('TRAD') !== -1 || val.toLowerCase().indexOf('traditional') !== -1;

            if ((isFSPro || isFSL || isTRAD) && !allowedAdultSenior.includes(currentDiv)) {
                isDisabled = true;
            }

            const isLB = val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1;
            if (isLB && currentDiv !== 'Adult (18 - 49)') {
                isDisabled = true;
            }

            opt.disabled = isDisabled;

            if (isDisabled && styleEl.value === val) {
                styleEl.value = 'FS – Freestyle';
            }
        }
    }

    /**
     * Style Change Event Handler (Applies Force-Division Rules & Syncs Distance/Fee Tier)
     */
    function handleStyleChange(type) {
        const divisionEl = document.getElementById(type + '_division');
        const styleEl    = document.getElementById(type + '_style');
        if (!divisionEl || !styleEl) return;

        const prevDiv = divisionEl.value;

        if (window.SYWAT && window.SYWAT.shootingStyles && typeof window.SYWAT.shootingStyles.applyStyleRules === 'function') {
            window.SYWAT.shootingStyles.applyStyleRules(styleEl, divisionEl, { trigger: 'style' });
        } else {
            const val = styleEl.value || '';
            if (val.indexOf('Genesis') !== -1) {
                divisionEl.value = 'Cub (8 - 11)';
            } else if (val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1) {
                divisionEl.value = 'Adult (18 - 49)';
            }
        }

        if (divisionEl.value !== prevDiv) {
            const distDispEl = document.getElementById(type + '_distance_display');
            if (distDispEl) {
                distDispEl.value = getDivisionDistance(divisionEl);
            }
            updatePaidStatusDisplay(type, true);
            handleStyleRestrictions(type);
        }
    }

    /**
     * Division Change Event Handler
     */
    function handleDivisionChange(type, shouldSyncBalance) {
        const divisionEl = document.getElementById(type + '_division');
        const distDispEl = document.getElementById(type + '_distance_display');
        if (!divisionEl) return;

        if (distDispEl) {
            distDispEl.value = getDivisionDistance(divisionEl);
        }

        updatePaidStatusDisplay(type, shouldSyncBalance);
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
        updatePayOptionLabelsForRound: updatePayOptionLabelsForRound,
        closeRosterModal: closeRosterModal,
        closeEditArcherModal: closeEditArcherModal,
        updatePaidStatusDisplay: updatePaidStatusDisplay,
        syncEditBalanceDue: syncEditBalanceDue,
        handleStyleRestrictions: handleStyleRestrictions,
        handleStyleChange: handleStyleChange,
        handleDivisionChange: handleDivisionChange,
        applySearchFilter: applyRosterSearchFilter
    };

    // Legacy global aliases for backward compatibility with existing modal markup
    window.updateRosterEditFamilyOptions      = updateRosterEditFamilyOptions;
    window.closeEditArcherModal               = closeEditArcherModal;
    window.updatePaidStatusDisplay            = updatePaidStatusDisplay;
    window.sywatRosterHandleStyleRestrictions = handleStyleRestrictions;
    window.sywatRosterHandleStyleChange       = handleStyleChange;
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
        const target = event.target;
        if (!target) return;

        if (target.id === 'edit_status') {
            syncEditBalanceDue('edit');
            return;
        }

        const actionEl = target.closest('[data-sywat-action]');
        if (!actionEl) return;

        const action = actionEl.getAttribute('data-sywat-action');

        if (action === 'filter-roster-line') {
            if (actionEl.form) {
                actionEl.form.submit();
            }
        } else if (action === 'roster-edit-round-change') {
            updatePayOptionLabelsForRound('edit');
            updatePaidStatusDisplay('edit', true);
        } else if (action === 'roster-edit-line-change') {
            updateRosterEditFamilyOptions(actionEl.value);
        } else if (action === 'roster-edit-division-change') {
            handleDivisionChange('edit', true);
        } else if (action === 'roster-edit-style-change') {
            handleStyleChange('edit');
        } else if (action === 'roster-edit-pay-change') {
            updatePaidStatusDisplay('edit', true);
        }
    });

    // Delegated Input Listener: Mark manual balance override when user edits #edit_balance_due directly
    document.addEventListener('input', function(event) {
        if (event.target && event.target.id === 'edit_balance_due') {
            event.target.dataset.manualOverride = '1';
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
        const editBalanceEl = document.getElementById('edit_balance_due');
        if (editBalanceEl && typeof editBalanceEl.dataset.origDue === 'undefined') {
            editBalanceEl.dataset.origDue = editBalanceEl.value || '0';
        }

        if (document.getElementById('edit_pricing_profile')) {
            updatePayOptionLabelsForRound('edit');
        }

        if (document.getElementById('edit_division')) {
            handleDivisionChange('edit', false);
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
