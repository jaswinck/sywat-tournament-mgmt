/**
 * Module: Pricing Profiles, Round Formats & Promoter Client Controller
 * Path: assets/js/sywat-pricing.js
 * Description: Provides shared client-side pricing & household family cap helpers under window.SYWAT.pricing plus delegated UI event handlers for the [sywat_pricing] administration portal.
 * Compliance: Vanilla JS only (zero jQuery), namespaced under window.SYWAT.pricing, delegated data-sywat-action listeners, class-based visibility (.active / .sywat-hidden) with zero element.style.* mutations.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

(function () {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    var config = (typeof window.sywatPricingConfig !== 'undefined' && window.sywatPricingConfig)
        ? window.sywatPricingConfig
        : { defaultProfile: 'indoor', profiles: {}, tournaments: {} };

    var MODAL_ID = 'sywat_pricing_profile_modal';

    /**
     * Retrieve all active pricing profiles keyed by slug.
     *
     * @return {Object}
     */
    function getProfiles() {
        return (config && typeof config.profiles === 'object' && config.profiles !== null)
            ? config.profiles
            : {};
    }

    /**
     * Retrieve the global default pricing profile object.
     *
     * @return {Object|null}
     */
    function getDefaultProfile() {
        var profiles = getProfiles();
        var defSlug = config.defaultProfile || 'indoor';
        if (profiles[defSlug]) {
            return profiles[defSlug];
        }
        var keys = Object.keys(profiles);
        return keys.length > 0 ? profiles[keys[0]] : null;
    }

    /**
     * Retrieve the ordered list of pricing profile objects offered by a tournament.
     *
     * @param {string} tournamentName
     * @return {Array<Object>}
     */
    function getTournamentProfiles(tournamentName) {
        var profiles = getProfiles();
        var tMap = (config && typeof config.tournaments === 'object' && config.tournaments !== null)
            ? config.tournaments
            : {};
        var tInfo = tournamentName && tMap[tournamentName] ? tMap[tournamentName] : null;
        var result = [];

        if (tInfo && Array.isArray(tInfo.profiles) && tInfo.profiles.length > 0) {
            for (var i = 0; i < tInfo.profiles.length; i++) {
                var slug = tInfo.profiles[i];
                if (profiles[slug]) {
                    result.push(profiles[slug]);
                }
            }
        }

        if (result.length === 0) {
            var defProf = getDefaultProfile();
            if (defProf) {
                result.push(defProf);
            }
        }

        return result;
    }

    /**
     * Resolve the single effective pricing profile for a tournament + optional round slug.
     *
     * @param {string} tournamentName
     * @param {string} roundSlug
     * @return {Object|null}
     */
    function resolveActiveProfile(tournamentName, roundSlug) {
        var profiles = getProfiles();
        var cleanRound = String(roundSlug || '').trim().toLowerCase();

        if (cleanRound && profiles[cleanRound]) {
            return profiles[cleanRound];
        }

        var tProfiles = getTournamentProfiles(tournamentName);
        return tProfiles.length > 0 ? tProfiles[0] : getDefaultProfile();
    }

    /**
     * Determine whether a division string belongs to the Youth rate tier.
     * Uses localized window.sywatStylesConfig if present, with safe fallback.
     *
     * @param {string} division
     * @param {string} distance
     * @return {boolean}
     */
    function isYouthDivision(division, distance) {
        var divStr = String(division || '').trim();
        var distStr = String(distance || '').trim().toLowerCase();

        if (distStr === 'cub' || distStr === '10yd' || distStr === '10 yards') {
            return true;
        }

        if (typeof window.sywatStylesConfig !== 'undefined' && window.sywatStylesConfig && window.sywatStylesConfig.divisions) {
            var divs = window.sywatStylesConfig.divisions;
            var keys = Object.keys(divs);
            for (var i = 0; i < keys.length; i++) {
                var rec = divs[keys[i]];
                if (!rec) continue;
                if (rec.label && rec.label.toLowerCase() === divStr.toLowerCase()) {
                    return rec.rate_tier === 'Youth';
                }
                if (Array.isArray(rec.aliases)) {
                    for (var j = 0; j < rec.aliases.length; j++) {
                        if (String(rec.aliases[j]).toLowerCase() === divStr.toLowerCase()) {
                            return rec.rate_tier === 'Youth';
                        }
                    }
                }
            }
        }

        var lower = divStr.toLowerCase();
        return lower.indexOf('cub') !== -1 || lower.indexOf('youth') !== -1 || lower.indexOf('young adult') !== -1;
    }

    /**
     * Resolve division slug for per-division price override lookup.
     *
     * @param {string} division
     * @return {string}
     */
    function resolveDivisionSlug(division) {
        var divStr = String(division || '').trim();
        if (!divStr) return '';

        if (typeof window.sywatStylesConfig !== 'undefined' && window.sywatStylesConfig && window.sywatStylesConfig.divisions) {
            var divs = window.sywatStylesConfig.divisions;
            var keys = Object.keys(divs);
            for (var i = 0; i < keys.length; i++) {
                var slug = keys[i];
                var rec = divs[slug];
                if (!rec) continue;
                if (rec.label && rec.label.toLowerCase() === divStr.toLowerCase()) {
                    return slug;
                }
                if (Array.isArray(rec.aliases)) {
                    for (var j = 0; j < rec.aliases.length; j++) {
                        if (String(rec.aliases[j]).toLowerCase() === divStr.toLowerCase()) {
                            return slug;
                        }
                    }
                }
            }
        }

        return divStr.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    /**
     * Calculate an individual archer's entry fee for a tournament/round.
     *
     * @param {Object} opts { tournamentName, division, distance, style, roundSlug, paidStatus }
     * @return {number}
     */
    function calculateIndividualFee(opts) {
        opts = opts || {};
        var divStr = String(opts.division || '').trim();
        var paidStr = String(opts.paidStatus || '').trim();

        if (divStr.toLowerCase() === 'no show') {
            return 0;
        }
        if (paidStr.toLowerCase() === 'promo (free)' || paidStr.toLowerCase().indexOf('promo') === 0) {
            return 0;
        }

        var profile = resolveActiveProfile(opts.tournamentName || '', opts.roundSlug || '');
        if (!profile) {
            return 30;
        }

        if (paidStr.toLowerCase() === 'field governor') {
            return parseFloat(profile.field_governor_rate) || 0;
        }

        var divSlug = resolveDivisionSlug(divStr);
        if (divSlug && profile.division_overrides && typeof profile.division_overrides[divSlug] !== 'undefined' && profile.division_overrides[divSlug] !== '') {
            return parseFloat(profile.division_overrides[divSlug]) || 0;
        }

        var styleStr = String(opts.style || '').trim();
        if (styleStr && profile.pro_rate !== '' && profile.pro_rate !== null && typeof profile.pro_rate !== 'undefined') {
            var isPro = styleStr.toLowerCase().indexOf('pro') !== -1 || divStr.toLowerCase().indexOf('pro') !== -1;
            if (isPro) {
                return parseFloat(profile.pro_rate) || 0;
            }
        }

        return isYouthDivision(divStr, opts.distance || '')
            ? (parseFloat(profile.youth_rate) || 0)
            : (parseFloat(profile.adult_rate) || 0);
    }

    /**
     * Calculate household group total with Family Cap logic across one or more archers.
     *
     * @param {string} tournamentName
     * @param {Array<Object>} shooters Array of { division, distance, style, roundSlug, paidStatus, isPromoFree }
     * @return {Object} { total, rawSum, isCapped, capAmount, paidCount }
     */
    function calculateGroupTotal(tournamentName, shooters) {
        shooters = Array.isArray(shooters) ? shooters : [];
        var rawSum = 0;
        var paidCount = 0;
        var anyCapEnabled = false;
        var effectiveCap = 0;
        var effectiveMin = 99;

        for (var i = 0; i < shooters.length; i++) {
            var s = shooters[i] || {};
            var paidStr = String(s.paidStatus || '').trim();
            if (s.isPromoFree || paidStr.toLowerCase() === 'field governor' || paidStr.toLowerCase().indexOf('promo') === 0) {
                continue;
            }

            var fee = calculateIndividualFee({
                tournamentName: tournamentName,
                division: s.division || '',
                distance: s.distance || '',
                style: s.style || '',
                roundSlug: s.roundSlug || '',
                paidStatus: paidStr
            });

            rawSum += fee;
            paidCount++;

            var prof = resolveActiveProfile(tournamentName, s.roundSlug || '');
            if (prof && prof.family_cap_enabled) {
                anyCapEnabled = true;
                var pCap = parseFloat(prof.family_cap_amount) || 0;
                var pMin = parseInt(prof.family_min_archers, 10) || 3;
                if (pCap > effectiveCap) effectiveCap = pCap;
                if (pMin < effectiveMin) effectiveMin = pMin;
            }
        }

        if (effectiveMin === 99) effectiveMin = 3;

        var isCapped = anyCapEnabled && paidCount >= effectiveMin && rawSum > effectiveCap;
        var finalTotal = isCapped ? effectiveCap : rawSum;

        return {
            total: Math.round(finalTotal * 100) / 100,
            rawSum: Math.round(rawSum * 100) / 100,
            isCapped: isCapped,
            capAmount: Math.round(effectiveCap * 100) / 100,
            paidCount: paidCount
        };
    }

    /* =========================================================================
     * Admin GUI Modal & Table Filter Helpers ([sywat_pricing])
     * ========================================================================= */

    function syncConditionalSections() {
        var famCheckbox = document.getElementById('sywat_pf_family_cap_enabled');
        var famFields = document.getElementById('sywat_family_cap_fields');
        if (famCheckbox && famFields) {
            if (famCheckbox.checked) {
                famFields.classList.remove('sywat-hidden');
            } else {
                famFields.classList.add('sywat-hidden');
            }
        }

        var promoCheckbox = document.getElementById('sywat_pf_promo_enabled');
        var promoFields = document.getElementById('sywat_promo_fields');
        if (promoCheckbox && promoFields) {
            if (promoCheckbox.checked) {
                promoFields.classList.remove('sywat-hidden');
            } else {
                promoFields.classList.add('sywat-hidden');
            }
        }
    }

    function openAddModal() {
        var modal = document.getElementById(MODAL_ID);
        var form = document.getElementById('sywat_pricing_profile_form');
        if (!modal || !form) return;

        form.reset();

        var titleEl = document.getElementById('sywat_pricing_modal_title');
        if (titleEl) titleEl.textContent = 'Add Pricing & Round Format';

        var origSlugEl = document.getElementById('sywat_pf_orig_slug');
        if (origSlugEl) origSlugEl.value = '';

        var famCheckbox = document.getElementById('sywat_pf_family_cap_enabled');
        if (famCheckbox) famCheckbox.checked = true;

        var promoCheckbox = document.getElementById('sywat_pf_promo_enabled');
        if (promoCheckbox) promoCheckbox.checked = false;

        var overrideInputs = form.querySelectorAll('.sywat-pf-div-override-input');
        for (var i = 0; i < overrideInputs.length; i++) {
            overrideInputs[i].value = '';
        }

        var detailsEl = document.getElementById('sywat_pf_overrides_disclosure');
        if (detailsEl) detailsEl.open = false;

        var historyCheckboxes = form.querySelectorAll('.sywat-pf-promo-history-cb');
        for (var j = 0; j < historyCheckboxes.length; j++) {
            historyCheckboxes[j].checked = false;
        }

        syncConditionalSections();
        modal.classList.add('active');
    }

    function openEditModal(profileData) {
        var modal = document.getElementById(MODAL_ID);
        var form = document.getElementById('sywat_pricing_profile_form');
        if (!modal || !form || !profileData) return;

        form.reset();

        var titleEl = document.getElementById('sywat_pricing_modal_title');
        if (titleEl) {
            titleEl.textContent = 'Edit Pricing Format: ' + (profileData.label || '');
        }

        var setVal = function (id, val) {
            var el = document.getElementById(id);
            if (el) el.value = (val !== null && typeof val !== 'undefined') ? val : '';
        };
        var setChecked = function (id, flag) {
            var el = document.getElementById(id);
            if (el) el.checked = Boolean(flag);
        };

        setVal('sywat_pf_orig_slug', profileData.slug || '');
        setVal('sywat_pf_label', profileData.label || '');
        setVal('sywat_pf_subtitle', profileData.round_subtitle || '');
        setVal('sywat_pf_code', profileData.code || '');
        setVal('sywat_pf_sort_order', profileData.sort_order || 1);
        setVal('sywat_pf_status', profileData.status || 'active');
        setVal('sywat_pf_aliases', Array.isArray(profileData.aliases) ? profileData.aliases.join(', ') : (profileData.aliases || ''));
        setChecked('sywat_pf_is_default', profileData.is_default);

        setVal('sywat_pf_youth_rate', typeof profileData.youth_rate !== 'undefined' ? Number(profileData.youth_rate).toFixed(2) : '25.00');
        setVal('sywat_pf_adult_rate', typeof profileData.adult_rate !== 'undefined' ? Number(profileData.adult_rate).toFixed(2) : '30.00');
        setVal('sywat_pf_pro_rate', (profileData.pro_rate !== '' && profileData.pro_rate !== null && typeof profileData.pro_rate !== 'undefined') ? Number(profileData.pro_rate).toFixed(2) : '');
        setVal('sywat_pf_fg_rate', typeof profileData.field_governor_rate !== 'undefined' ? Number(profileData.field_governor_rate).toFixed(2) : '0.00');

        // Populate optional per-division overrides
        var overrides = (profileData.division_overrides && typeof profileData.division_overrides === 'object')
            ? profileData.division_overrides
            : {};
        var hasAnyOverride = false;
        var overrideInputs = form.querySelectorAll('.sywat-pf-div-override-input');
        for (var i = 0; i < overrideInputs.length; i++) {
            var dSlug = overrideInputs[i].getAttribute('data-div-slug') || '';
            if (dSlug && typeof overrides[dSlug] !== 'undefined' && overrides[dSlug] !== '') {
                overrideInputs[i].value = Number(overrides[dSlug]).toFixed(2);
                hasAnyOverride = true;
            } else {
                overrideInputs[i].value = '';
            }
        }
        var detailsEl = document.getElementById('sywat_pf_overrides_disclosure');
        if (detailsEl) detailsEl.open = hasAnyOverride;

        // Family Cap fields
        setChecked('sywat_pf_family_cap_enabled', profileData.family_cap_enabled);
        setVal('sywat_pf_family_cap_amount', typeof profileData.family_cap_amount !== 'undefined' ? Number(profileData.family_cap_amount).toFixed(2) : '75.00');
        setVal('sywat_pf_family_min_archers', profileData.family_min_archers || 3);
        setVal('sywat_pf_family_cap_scope', profileData.family_cap_scope || 'line');

        // Promotional Free Entry fields
        setChecked('sywat_pf_promo_enabled', profileData.promo_enabled);
        setVal('sywat_pf_promo_label', profileData.promo_label || 'First-Time / Lapsed Shooter Promo');
        setVal('sywat_pf_promo_lookback_value', profileData.promo_lookback_value || 24);
        setVal('sywat_pf_promo_lookback_unit', profileData.promo_lookback_unit || 'months');
        setVal('sywat_pf_promo_max_uses', typeof profileData.promo_max_uses !== 'undefined' ? profileData.promo_max_uses : 1);
        setVal('sywat_pf_promo_usage_scope', profileData.promo_usage_scope || 'season');

        var historyList = Array.isArray(profileData.promo_history_profiles) ? profileData.promo_history_profiles : [];
        var historyCheckboxes = form.querySelectorAll('.sywat-pf-promo-history-cb');
        for (var j = 0; j < historyCheckboxes.length; j++) {
            var val = historyCheckboxes[j].value;
            historyCheckboxes[j].checked = historyList.indexOf(val) !== -1;
        }

        syncConditionalSections();
        modal.classList.add('active');
    }

    function closeModal() {
        var modal = document.getElementById(MODAL_ID);
        if (modal) {
            modal.classList.remove('active');
        }
    }

    function filterPricingTable() {
        var statusSelect = document.getElementById('sywat_pricing_status_filter');
        var searchInput = document.getElementById('sywat_pricing_search_input');
        var rows = document.querySelectorAll('#sywat_pricing_profiles_table tbody tr.sywat-pricing-row');
        if (!rows.length) return;

        var statusFilter = statusSelect ? statusSelect.value : 'all';
        var query = searchInput ? String(searchInput.value || '').trim().toLowerCase() : '';

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var rowStatus = row.getAttribute('data-status') || 'active';
            var rowSearch = row.getAttribute('data-search') || '';

            var matchesStatus = (statusFilter === 'all') || (rowStatus === statusFilter);
            var matchesSearch = (!query) || (rowSearch.indexOf(query) !== -1);

            if (matchesStatus && matchesSearch) {
                row.classList.remove('sywat-hidden');
            } else {
                row.classList.add('sywat-hidden');
            }
        }
    }

    function clearPricingFilters() {
        var statusSelect = document.getElementById('sywat_pricing_status_filter');
        var searchInput = document.getElementById('sywat_pricing_search_input');
        if (statusSelect) statusSelect.value = 'active';
        if (searchInput) searchInput.value = '';
        filterPricingTable();
    }

    /* =========================================================================
     * Delegated Event Listeners (Optimizer-Safe, Zero Inline Handlers)
     * ========================================================================= */

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-sywat-action]');
        if (!trigger) return;

        var action = trigger.getAttribute('data-sywat-action');
        if (action === 'open-add-pricing-modal') {
            e.preventDefault();
            openAddModal();
        } else if (action === 'open-edit-pricing-modal') {
            e.preventDefault();
            var rawJson = trigger.getAttribute('data-profile') || '{}';
            try {
                var parsed = JSON.parse(rawJson);
                openEditModal(parsed);
            } catch (err) {
                openAddModal();
            }
        } else if (action === 'close-pricing-modal') {
            e.preventDefault();
            closeModal();
        } else if (action === 'clear-pricing-filters') {
            e.preventDefault();
            clearPricingFilters();
        }
    });

    document.addEventListener('change', function (e) {
        var trigger = e.target.closest('[data-sywat-action]');
        if (!trigger) return;

        var action = trigger.getAttribute('data-sywat-action');
        if (action === 'toggle-family-cap-fields' || action === 'toggle-promo-fields') {
            syncConditionalSections();
        } else if (action === 'filter-pricing-table') {
            filterPricingTable();
        }
    });

    document.addEventListener('input', function (e) {
        var trigger = e.target.closest('[data-sywat-action="filter-pricing-table"]');
        if (trigger) {
            filterPricingTable();
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute) return;
        var confirmMsg = form.getAttribute('data-sywat-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) {
            e.preventDefault();
        }
    });

    // Public Namespace API
    window.SYWAT.pricing = {
        getProfiles: getProfiles,
        getDefaultProfile: getDefaultProfile,
        getTournamentProfiles: getTournamentProfiles,
        resolveActiveProfile: resolveActiveProfile,
        isYouthDivision: isYouthDivision,
        calculateIndividualFee: calculateIndividualFee,
        calculateGroupTotal: calculateGroupTotal,
        openAddModal: openAddModal,
        openEditModal: openEditModal,
        closeModal: closeModal
    };
})();
