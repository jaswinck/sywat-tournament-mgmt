/**
 * SYWAT Archery - Archer Roster Management & Edit Modal Engine
 * Path: assets/js/sywat-roster.js
 * Description: Manages the Registered Archer Roster workspace with flicker-free client-side shooting line & live name search filtering, instantaneous client-side Edit Archer modal population from serialized row JSON payloads (eliminating HTTP page-reload flashes), line-scoped family group assignments, multi-round pricing & live balance synchronization, Promotional Free Entry status display, single-prompt confirmations, and database-driven shooting style & age division rule enforcement.
 * Compliance: Vanilla JS only (zero jQuery), zero element.style.* mutations, delegated event listeners, class-based visibility (.active / .sywat-hidden).
 * Version: 7.3.0
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    if (window.SYWAT._rosterInitialized) {
        return;
    }
    window.SYWAT._rosterInitialized = true;

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
     * Ensure a <select> contains a given value option (adding a temporary fallback option if needed)
     * and set its active value cleanly.
     */
    function setSelectValueWithFallback(selectEl, targetValue, fallbackLabel) {
        if (!selectEl) return;
        const val = String(targetValue || '');
        let found = false;
        for (let i = 0; i < selectEl.options.length; i++) {
            if (selectEl.options[i].value === val) {
                found = true;
                break;
            }
        }
        if (!found && val !== '') {
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = fallbackLabel || (val + ' (Retired)');
            selectEl.appendChild(opt);
        }
        selectEl.value = val;
    }

    /**
     * Open Edit Archer Modal instantaneously from a roster row's serialized JSON payload
     * without triggering a full HTTP page reload.
     */
    function openEditArcherModal(triggerEl) {
        const row = triggerEl ? triggerEl.closest('.sywat-manage-row') : null;
        const modal = document.getElementById('sywat_edit_archer_modal');
        if (!row || !modal) return;

        let archer = null;
        try {
            archer = JSON.parse(row.getAttribute('data-archer') || 'null');
        } catch (err) {
            archer = null;
        }
        if (!archer || !archer.id) return;

        const regIdInput     = document.getElementById('edit_registration_id');
        const nameInput      = document.getElementById('edit_name');
        const roundSelect    = document.getElementById('edit_pricing_profile');
        const roundHidden    = document.getElementById('edit_pricing_profile_hidden');
        const lineSelect     = document.getElementById('edit_line_index');
        const statusSelect   = document.getElementById('edit_status');
        const cancelledOpt   = document.getElementById('edit_status_cancelled_opt');
        const divisionSelect = document.getElementById('edit_division');
        const genderSelect   = document.getElementById('edit_gender');
        const styleSelect    = document.getElementById('edit_style');
        const paySelect      = document.getElementById('edit_pay_option');
        const famSelect      = document.getElementById('edit_family_group_id');
        const balanceInput   = document.getElementById('edit_balance_due');
        const distDisplay    = document.getElementById('edit_distance_display');
        const filterLineEl   = document.getElementById('filter_line_idx');
        const editFilterEl   = document.getElementById('edit_filter_line_idx');

        if (regIdInput) regIdInput.value = String(archer.id || '');
        if (nameInput) nameInput.value = String(archer.name || '');

        const activeFilterLine = filterLineEl ? String(filterLineEl.value || 'all') : 'all';
        if (editFilterEl) editFilterEl.value = activeFilterLine;

        const roundSlug = String(archer.pricing_profile || getDefaultProfileKey());
        if (roundSelect) {
            setSelectValueWithFallback(roundSelect, roundSlug, roundSlug);
            updatePayOptionLabelsForRound('edit');
        }
        if (roundHidden) {
            roundHidden.value = roundSlug;
        }

        const lineIdxStr = String(archer.line_index !== undefined && archer.line_index !== '' ? archer.line_index : '0');
        if (lineSelect) {
            lineSelect.value = lineIdxStr;
        }
        updateRosterEditFamilyOptions(lineIdxStr);

        const normStatus = String(archer.status || 'Pre-Reg');
        if (cancelledOpt) {
            cancelledOpt.classList.toggle('sywat-hidden', normStatus !== 'Cancelled');
        }
        if (statusSelect) {
            statusSelect.value = normStatus;
        }

        const divVal = String(archer.division || 'Adult (18 - 49)');
        if (divisionSelect) {
            setSelectValueWithFallback(divisionSelect, divVal, divVal + ' (Retired)');
        }

        if (genderSelect) {
            genderSelect.value = String(archer.gender || 'Male');
        }

        const styleVal = String(archer.style || '');
        if (styleSelect) {
            setSelectValueWithFallback(styleSelect, styleVal, styleVal ? (styleVal + ' (Retired)') : '-- Select Style --');
        }

        const rawPaid       = String(archer.paid || '').trim();
        const famIdVal      = String(archer.family_id || '').trim();
        const isPromoFree   = Boolean(archer.is_promo_free) || rawPaid.toLowerCase() === 'promo (free)' || rawPaid.toLowerCase().indexOf('promo') === 0;
        const isFamilyGroup = (rawPaid.toLowerCase() === 'family') || (normStatus === 'Waitlist' && famIdVal !== '');

        let payOptVal = 'Individual';
        if (isPromoFree) {
            payOptVal = 'Promo (Free)';
        } else if (isFamilyGroup) {
            payOptVal = 'Family';
        } else if (rawPaid.toLowerCase() === 'field governor') {
            payOptVal = 'Field Governor';
        }

        if (paySelect) {
            paySelect.value = payOptVal;
        }

        if (famSelect) {
            if (famIdVal !== '') {
                setSelectValueWithFallback(famSelect, famIdVal, 'Current Family Group');
            } else {
                famSelect.value = '';
            }
        }

        const dueStr = String(archer.balance_due || '0.00');
        if (balanceInput) {
            balanceInput.value = dueStr;
            balanceInput.dataset.origDue = dueStr;
            delete balanceInput.dataset.manualOverride;
        }

        if (distDisplay && divisionSelect) {
            distDisplay.value = getDivisionDistance(divisionSelect);
        }

        updatePaidStatusDisplay('edit', false);
        handleStyleRestrictions('edit');

        modal.classList.add('active');

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('edit_archer', String(archer.id));
            window.history.replaceState({}, document.title, url.toString());
        } catch (err) {
            /* ignore history state errors */
        }
    }

    /**
     * Close Roster Management Modal & Navigate to Clean Manage Tournaments URL
     */
    function closeRosterModal(triggerEl) {
        const modal = document.getElementById('sywat_roster_modal');
        const parentModal = document.getElementById('sywat_modal');
        const closeUrl = (triggerEl && triggerEl.getAttribute('data-close-url'))
            || (modal && modal.getAttribute('data-sywat-close-url'));

        if (modal) {
            modal.classList.remove('active');
        }
        if (parentModal) {
            parentModal.classList.remove('active');
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
        try {
            const url = new URL(window.location.href);
            url.searchParams.delete('edit_archer');
            url.searchParams.delete('open_edit_id');
            window.history.replaceState({}, document.title, url.toString());
        } catch (err) {
            /* ignore history state errors */
        }
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
     * Filter Archer Roster Table Rows client-side by Shooting Line and Name Search
     * (zero HTTP reload, updates counts, Clear Line button, Waitlist badge, and URL state).
     */
    function applyRosterFilters(updateHistoryState) {
        const lineSelect  = document.getElementById('filter_line_idx');
        const searchInput = document.getElementById('search_shooter');

        const selectedLine = lineSelect ? String(lineSelect.value || 'all') : 'all';
        const searchFilter = searchInput ? String(searchInput.value || '').toLowerCase().trim() : '';

        let visibleCount     = 0;
        let lineTotalActive  = 0;

        document.querySelectorAll('.sywat-manage-row').forEach(function(row) {
            const rowLine = String(row.getAttribute('data-line-idx') || '');
            const rowName = String(row.getAttribute('data-name') || '').toLowerCase();

            const lineMatch   = (selectedLine === 'all' || rowLine === selectedLine);
            const searchMatch = (!searchFilter || rowName.includes(searchFilter));

            if (lineMatch) {
                lineTotalActive++;
            }

            if (lineMatch && searchMatch) {
                row.classList.remove('sywat-hidden');
                visibleCount++;
            } else {
                row.classList.add('sywat-hidden');
            }
        });

        const activeCountEl = document.getElementById('sywat_roster_active_count');
        if (activeCountEl) {
            activeCountEl.textContent = String(visibleCount);
        }

        const emptyMsgEl = document.getElementById('sywat_roster_empty_filter_msg');
        if (emptyMsgEl) {
            emptyMsgEl.classList.toggle('sywat-hidden', visibleCount > 0);
        }

        // Synchronize hidden filter_line_idx inputs across row Remove/Purge/Edit forms
        document.querySelectorAll('.sywat-roster-hidden-line').forEach(function(inp) {
            inp.value = selectedLine;
        });

        const purgeWrap       = document.getElementById('sywat_roster_purge_wrap');
        const purgeTargetLine = document.getElementById('sywat_roster_purge_target_line');
        const purgeFilterLine = document.getElementById('sywat_roster_purge_filter_line');
        const isSpecificLine  = (selectedLine !== 'all' && selectedLine !== '');

        if (purgeTargetLine) purgeTargetLine.value = isSpecificLine ? selectedLine : '';
        if (purgeFilterLine) purgeFilterLine.value = selectedLine;
        if (purgeWrap) {
            purgeWrap.classList.toggle('sywat-hidden', !(isSpecificLine && lineTotalActive > 0));
        }

        // Update Waitlist badge count & modal URL for the active line filter
        const waitlistWrap  = document.getElementById('sywat_roster_waitlist_wrap');
        const waitlistCount = document.getElementById('sywat_roster_waitlist_count');
        const waitlistLink  = document.getElementById('sywat_roster_waitlist_link');

        if (waitlistWrap) {
            let countsMap = {};
            try {
                countsMap = JSON.parse(waitlistWrap.getAttribute('data-waitlist-counts') || '{}');
            } catch (err) {
                countsMap = {};
            }
            const wlCount = parseInt(countsMap[selectedLine] || 0, 10) || 0;
            if (waitlistCount) {
                waitlistCount.textContent = String(wlCount);
            }
            if (waitlistLink && waitlistLink.href) {
                try {
                    const wlUrl = new URL(waitlistLink.href, window.location.origin);
                    wlUrl.searchParams.set('waitlist_line', selectedLine);
                    waitlistLink.href = wlUrl.toString();
                } catch (err) {
                    /* ignore URL parse errors */
                }
            }
            waitlistWrap.classList.toggle('sywat-hidden', wlCount <= 0);
        }

        if (updateHistoryState) {
            try {
                const url = new URL(window.location.href);
                if (selectedLine === 'all') {
                    url.searchParams.delete('filter_line_idx');
                } else {
                    url.searchParams.set('filter_line_idx', selectedLine);
                }
                window.history.replaceState({}, document.title, url.toString());
            } catch (err) {
                /* ignore history state errors */
            }
        }
    }

    function applyRosterSearchFilter(rawQuery) {
        const searchInput = document.getElementById('search_shooter');
        if (searchInput && typeof rawQuery === 'string' && searchInput.value !== rawQuery) {
            searchInput.value = rawQuery;
        }
        applyRosterFilters(false);
    }

    // Namespaced Module API (Section IV.1)
    window.SYWAT.roster = {
        updateEditFamilyOptions: updateRosterEditFamilyOptions,
        updatePayOptionLabelsForRound: updatePayOptionLabelsForRound,
        openEditArcherModal: openEditArcherModal,
        closeRosterModal: closeRosterModal,
        closeEditArcherModal: closeEditArcherModal,
        updatePaidStatusDisplay: updatePaidStatusDisplay,
        syncEditBalanceDue: syncEditBalanceDue,
        handleStyleRestrictions: handleStyleRestrictions,
        handleStyleChange: handleStyleChange,
        handleDivisionChange: handleDivisionChange,
        applyFilters: applyRosterFilters,
        applySearchFilter: applyRosterSearchFilter
    };

    // Legacy global aliases for backward compatibility with existing modal markup
    window.updateRosterEditFamilyOptions      = updateRosterEditFamilyOptions;
    window.openEditArcherModal                = openEditArcherModal;
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

        if (action === 'open-edit-archer-modal') {
            event.preventDefault();
            openEditArcherModal(actionEl);
        } else if (action === 'close-roster-modal') {
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
                applyRosterFilters(false);
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
            applyRosterFilters(true);
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

    // Delegated Input Listener: Live search & manual balance override tracking
    document.addEventListener('input', function(event) {
        if (!event.target) return;

        if (event.target.id === 'search_shooter') {
            applyRosterFilters(false);
        } else if (event.target.id === 'edit_balance_due') {
            event.target.dataset.manualOverride = '1';
        }
    });

    // Prevent Enter key inside #sywat_roster_filter_form from triggering a full-page GET reload
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' && event.target && event.target.id === 'search_shooter') {
            event.preventDefault();
        }
    });

    // Delegated Submit Confirmation Listener (Single-Prompt & Double-Submit Guarded — Section IV.1)
    document.addEventListener('submit', function(event) {
        const form = event.target && event.target.closest ? event.target.closest('form[data-sywat-confirm]') : null;
        if (!form || event.defaultPrevented || event._sywatConfirmHandled) return;

        if (form.dataset.sywatSubmitting === '1') {
            event.preventDefault();
            return;
        }

        event._sywatConfirmHandled = true;
        const message = form.getAttribute('data-sywat-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        form.dataset.sywatSubmitting = '1';
    });

    /**
     * DOM Initialization & Initial Filter Synchronization
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

        if (document.getElementById('filter_line_idx') || document.getElementById('search_shooter')) {
            applyRosterFilters(false);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRosterModule);
    } else {
        initRosterModule();
    }

})();
