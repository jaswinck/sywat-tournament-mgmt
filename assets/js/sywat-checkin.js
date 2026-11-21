/**
 * SYWAT Archery - Check-In Desk Client Engine
 * Path: assets/js/sywat-checkin.js
 * Description: Manages instantaneous zero-reload Check-In & Edit modal opening/population, client-side line & live search filtering, Walk-In fast-loop intake, live unpaid balance synchronization on Edit/Check-In modals, live First-Time/Lapsed Shooter promo eligibility badges, unified Member Database + 18-month TFAA smart name matching with automatic canonical name enforcement, and database-driven shooting style & age division rule enforcement.
 * Compliance: Vanilla JS only (zero jQuery), zero element.style.* mutations, class-based visibility (.sywat-hidden / .active), delegated data-sywat-action listeners, zero document-level keydown listeners.
 * Version: 7.4.0
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    let cachedUnifiedShooters = null;

    function escapeHtmlDesk(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function cleanDeskName(str) {
        if (!str) return '';
        return String(str).replace(/[\s\u00a0]+/g, ' ').trim().toLowerCase();
    }

    /**
     * Build normalized exact, First+Last, and individual token keys for smart archer name matching.
     * Folds middle initials/names (e.g., "Mark E. Tanner" -> "mark___tanner") while preserving
     * generational suffixes (Jr, Sr, II, III, IV) so parent and child records never collide.
     * Also exposes explicit first/last tokens for safe fuzzy alias matching (e.g., Steve vs Steven).
     */
    function buildDeskNameMatchKeys(rawName) {
        if (!rawName) {
            return { exact: '', fl: '', first: '', last: '', suffix: '', tokenCount: 0, lastTokenLen: 0 };
        }

        let cleaned = String(rawName)
            .replace(/\[.*?\]|\(.*?\)/g, '')
            .replace(/[.,"\u00a0]/g, ' ')
            .replace(/[^a-zA-Z0-9\s'-]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

        if (!cleaned) {
            return { exact: '', fl: '', first: '', last: '', suffix: '', tokenCount: 0, lastTokenLen: 0 };
        }

        const tokens = cleaned.split(' ');
        const suffixes = ['jr', 'sr', 'ii', 'iii', 'iv', 'v', '2nd', '3rd', '4th'];
        let suffix = '';

        if (tokens.length > 2 && suffixes.indexOf(tokens[tokens.length - 1]) !== -1) {
            suffix = tokens.pop();
        }

        const coreExact = tokens.join(' ');
        const exactKey  = suffix ? (coreExact + ' ' + suffix) : coreExact;

        const firstTok  = tokens.length > 0 ? tokens[0] : '';
        const lastTok   = tokens.length > 1 ? tokens[tokens.length - 1] : (tokens.length === 1 ? tokens[0] : '');
        let flKey       = (tokens.length >= 2) ? (firstTok + '___' + lastTok) : coreExact;

        if (suffix && flKey) {
            flKey += '___' + suffix;
        }

        return {
            exact: exactKey,
            fl: flKey,
            first: firstTok,
            last: lastTok,
            suffix: suffix,
            tokenCount: tokens.length,
            lastTokenLen: lastTok.length
        };
    }

    /**
     * Build and cache a unified shooter array combining Member Database records
     * (window.sywatDeskMembers) and 18-month TFAA historical standings (window.sywatDeskTfaaShooters).
     */
    function getUnifiedDeskShooterIndex() {
        if (Array.isArray(cachedUnifiedShooters)) {
            return cachedUnifiedShooters;
        }

        const tfaaRaw = [];
        if (Array.isArray(window.sywatDeskTfaaShooters)) {
            tfaaRaw.push.apply(tfaaRaw, window.sywatDeskTfaaShooters);
        }
        if (typeof sywatTfaaShooters !== 'undefined' && Array.isArray(sywatTfaaShooters)) {
            tfaaRaw.push.apply(tfaaRaw, sywatTfaaShooters);
        }
        if (window.sywatRegData && Array.isArray(window.sywatRegData.tfaaShooters)) {
            tfaaRaw.push.apply(tfaaRaw, window.sywatRegData.tfaaShooters);
        }
        if (window.sywatDeskData && Array.isArray(window.sywatDeskData.tfaaShooters)) {
            tfaaRaw.push.apply(tfaaRaw, window.sywatDeskData.tfaaShooters);
        }

        const memberRaw = Array.isArray(window.sywatDeskMembers) ? window.sywatDeskMembers : [];

        // Map TFAA entries by exact & First+Last keys to enrich Member Directory profiles with known styles
        const tfaaByExact = Object.create(null);
        const tfaaByFl    = Object.create(null);

        for (let i = 0; i < tfaaRaw.length; i++) {
            const t = tfaaRaw[i];
            if (!t) continue;
            const tName = (t.full_name || t.name || '').trim();
            if (!tName) continue;
            const tKeys = buildDeskNameMatchKeys(tName);
            if (tKeys.exact) {
                if (!tfaaByExact[tKeys.exact]) tfaaByExact[tKeys.exact] = [];
                tfaaByExact[tKeys.exact].push(t);
            }
            if (tKeys.fl) {
                if (!tfaaByFl[tKeys.fl]) tfaaByFl[tKeys.fl] = [];
                tfaaByFl[tKeys.fl].push(t);
            }
        }

        const unified = [];
        const seen    = new Set();

        // 1. Add Member Directory profiles first (authoritative official names, enriched with TFAA division/style history)
        for (let i = 0; i < memberRaw.length; i++) {
            const m = memberRaw[i];
            if (!m) continue;
            const mName = (m.full_name || m.name || '').trim();
            if (!mName) continue;

            const mKeys    = buildDeskNameMatchKeys(mName);
            const tfaaHits = (mKeys.exact && tfaaByExact[mKeys.exact])
                ? tfaaByExact[mKeys.exact]
                : ((mKeys.fl && tfaaByFl[mKeys.fl]) ? tfaaByFl[mKeys.fl] : []);

            if (tfaaHits.length > 0) {
                for (let j = 0; j < tfaaHits.length; j++) {
                    const th     = tfaaHits[j];
                    const divStr = (th.division || m.division || '').trim();
                    const genStr = (th.gender || m.gender || '').trim();
                    const styStr = (th.style || m.style || '').trim();
                    const dedup  = (mKeys.exact + '___' + divStr + '___' + styStr + '___' + genStr).toLowerCase();

                    if (!seen.has(dedup)) {
                        seen.add(dedup);
                        unified.push({
                            name: mName,
                            full_name: mName,
                            division: divStr,
                            gender: genStr,
                            style: styStr,
                            phone: m.phone || th.phone || '',
                            source: 'member_db',
                            keys: mKeys
                        });
                    }
                }
            } else {
                const divStr = (m.division || '').trim();
                const genStr = (m.gender || '').trim();
                const dedup  = (mKeys.exact + '___' + divStr + '______' + genStr).toLowerCase();

                if (!seen.has(dedup)) {
                    seen.add(dedup);
                    unified.push({
                        name: mName,
                        full_name: mName,
                        division: divStr,
                        gender: genStr,
                        style: '',
                        phone: m.phone || '',
                        source: 'member_db',
                        keys: mKeys
                    });
                }
            }
        }

        // 2. Add 18-month TFAA historical shooter profiles
        for (let i = 0; i < tfaaRaw.length; i++) {
            const s = tfaaRaw[i];
            if (!s) continue;
            const sName  = (s.full_name || s.name || '').trim();
            const divStr = (s.division || '').trim();
            const styStr = (s.style || '').trim();
            const genStr = (s.gender || '').trim();

            if (!sName || !styStr || styStr.toLowerCase() === 'no show' || !divStr || divStr.toLowerCase() === 'no show') {
                continue;
            }

            const sKeys = buildDeskNameMatchKeys(sName);
            const dedup = (sKeys.exact + '___' + divStr + '___' + styStr + '___' + genStr).toLowerCase();

            if (!seen.has(dedup)) {
                seen.add(dedup);
                unified.push({
                    name: sName,
                    full_name: sName,
                    division: divStr,
                    gender: genStr,
                    style: styStr,
                    phone: s.phone || s.phone_number || '',
                    source: 'tfaa_history',
                    keys: sKeys
                });
            }
        }

        cachedUnifiedShooters = unified;
        return unified;
    }

    /**
     * Retrieve Localized Database Age Divisions
     */
    function getDeskDivisions() {
        if (Array.isArray(window.sywatDeskDivisions)) {
            return window.sywatDeskDivisions;
        }
        return [];
    }

    /**
     * Retrieve Localized Active Tournament Pricing & Promoter Rules
     */
    function getDeskPricing() {
        if (window.sywatDeskPricing && typeof window.sywatDeskPricing === 'object') {
            return window.sywatDeskPricing;
        }
        return {};
    }

    /**
     * Resolve Currently Selected Walk-In Round / Pricing Profile Slug
     */
    function getActiveWalkinRoundSlug() {
        const roundEl = document.getElementById('walkin_pricing_profile');
        if (roundEl && roundEl.value) {
            return String(roundEl.value).trim();
        }
        const pricing = getDeskPricing();
        if (pricing.defaultProfile) {
            return String(pricing.defaultProfile).trim();
        }
        const profiles = pricing.profiles || {};
        const keys = Object.keys(profiles);
        return keys.length > 0 ? keys[0] : 'indoor';
    }

    /**
     * Evaluate First-Time / Lapsed Shooter Promo Eligibility for Walk-In Intake
     */
    function isWalkinPromoEligible(typedName, roundSlug) {
        const cleanName = cleanDeskName(typedName);
        if (!cleanName || cleanName.length < 2) {
            return null;
        }

        const courtesyCb = document.querySelector('#sywat_walkin_form input[name="walkin_is_courtesy"]');
        if (courtesyCb && courtesyCb.checked) {
            return null;
        }

        const pricing = getDeskPricing();
        const rules   = pricing.promoRules || {};
        const slug    = String(roundSlug || getActiveWalkinRoundSlug()).trim();
        const rule    = rules[slug];

        if (!rule || !rule.active) {
            return null;
        }

        const qKeys = buildDeskNameMatchKeys(typedName);
        if (rule.ineligible && (
            Object.prototype.hasOwnProperty.call(rule.ineligible, cleanName) ||
            (qKeys.exact && Object.prototype.hasOwnProperty.call(rule.ineligible, qKeys.exact))
        )) {
            return null;
        }

        return {
            eligible: true,
            label: rule.label || 'Promo (Free)'
        };
    }

    /**
     * Lookup Division Config Record by Label, Code, or Alias
     */
    function findDeskDivisionConfig(divValue) {
        if (!divValue) return null;
        const needle = String(divValue).trim().toLowerCase();
        if (!needle) return null;

        const divisions = getDeskDivisions();
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
     * Lookup Shooting Style Config Record via Shared Shooting Styles Engine
     */
    function findDeskStyleConfig(styleValue) {
        if (!styleValue) return null;
        if (window.SYWAT && window.SYWAT.shootingStyles && typeof window.SYWAT.shootingStyles.findStyle === 'function') {
            return window.SYWAT.shootingStyles.findStyle(styleValue);
        }
        return null;
    }

    /**
     * Calculate exact individual entry fee for Check-In or Edit modals based on
     * active Round Format, Division, Distance, Style, and Paid Option.
     */
    function calculateDeskModalFee(prefix) {
        const divisionEl = document.getElementById(prefix + '_division');
        const styleEl    = document.getElementById(prefix + '_style');
        const roundEl    = document.getElementById(prefix + '_pricing_profile');
        const payEl      = document.getElementById(prefix + '_pay_option');
        const tournEl    = document.getElementById('sywat_selected_tournament');

        if (!divisionEl) return 0;

        const payVal = payEl ? String(payEl.value || 'Individual').trim() : 'Individual';
        if (payVal === 'Promo (Free)') {
            return 0;
        }

        const divVal = String(divisionEl.value || '').trim();
        if (!divVal || divVal === 'No Show') {
            return 0;
        }

        const divCfg  = findDeskDivisionConfig(divVal);
        const distVal = (divCfg && (divCfg.distance === 'Cub' || divCfg.distance === '20yd'))
            ? divCfg.distance
            : (divVal.toLowerCase().indexOf('cub') !== -1 ? 'Cub' : '20yd');

        const styleVal  = styleEl ? String(styleEl.value || '').trim() : '';
        const pricing   = getDeskPricing();
        const roundSlug = (roundEl && roundEl.value)
            ? String(roundEl.value).trim()
            : (pricing.defaultProfile ? String(pricing.defaultProfile).trim() : 'indoor');
        const tournName = tournEl ? String(tournEl.value || '').trim() : '';

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

        const profiles = pricing.profiles || {};
        const prof     = profiles[roundSlug] || profiles[pricing.defaultProfile || 'indoor'] || null;

        if (payVal === 'Field Governor') {
            return prof && typeof prof.field_governor_rate !== 'undefined' ? (parseFloat(prof.field_governor_rate) || 0) : 0;
        }

        const isYouth = (distVal === 'Cub')
            || (divCfg && divCfg.rate_tier === 'Youth')
            || /cub|youth|young adult/i.test(divVal);

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
            const tierFee = isYouth ? parseFloat(prof.youth_rate) : parseFloat(prof.adult_rate);
            if (!Number.isNaN(tierFee)) return tierFee;
        }

        return isYouth ? 25 : 30;
    }

    /**
     * Synchronize #edit_balance_due or #checkin_unpaid_amount_disp live when Division,
     * Style, Round Format, or Paid Option changes on the Check-In Desk modals.
     */
    function syncDeskBalanceDisplay(prefix) {
        if (prefix === 'edit') {
            const balanceEl = document.getElementById('edit_balance_due');
            if (!balanceEl) return;

            const payEl  = document.getElementById('edit_pay_option');
            const payVal = payEl ? String(payEl.value || 'Individual').trim() : 'Individual';

            if (payVal === 'Promo (Free)' || payVal === 'Field Governor') {
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

            const newFee = calculateDeskModalFee('edit');
            balanceEl.value = newFee.toFixed(2);
            return;
        }

        if (prefix === 'checkin') {
            const banner   = document.getElementById('sywat_unpaid_warning_banner');
            const amountEl = document.getElementById('checkin_unpaid_amount_disp');
            if (!banner || !amountEl) return;

            const payEl           = document.getElementById('checkin_pay_option');
            const payVal          = payEl ? String(payEl.value || 'Individual').trim() : 'Individual';
            const isFamilyBalance = banner.getAttribute('data-is-family-balance') === '1';

            if (payVal === 'Promo (Free)' || payVal === 'Field Governor') {
                amountEl.textContent = '0.00';
                return;
            }

            if (isFamilyBalance || payVal === 'Family') {
                const origFamilyDue = parseFloat(banner.getAttribute('data-orig-due') || '0');
                if (!Number.isNaN(origFamilyDue)) {
                    amountEl.textContent = origFamilyDue.toFixed(2);
                }
                return;
            }

            const newCheckinFee = calculateDeskModalFee('checkin');
            amountEl.textContent = newCheckinFee.toFixed(2);
        }
    }

    function formatDeskPhoneNumber(input) {
        if (!input) return;
        let digits = String(input.value || '').replace(/\D/g, '');
        if (digits.length > 10) digits = digits.substring(0, 10);

        if (digits.length > 6) {
            input.value = digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6);
        } else if (digits.length > 3) {
            input.value = digits.substring(0, 3) + '-' + digits.substring(3);
        } else {
            input.value = digits;
        }
    }

    /**
     * Populate a <select> element cleanly, adding a temporary retired/archived option
     * if the record holds a legacy value not present in the active list.
     */
    function setDeskSelectValueWithFallback(selectEl, val, showRetiredSuffix) {
        if (!selectEl) return;

        selectEl.querySelectorAll('option[data-temp-retired="1"]').forEach(function(opt) {
            opt.remove();
        });

        const cleanVal = (val === null || val === undefined) ? '' : String(val).trim();
        if (!cleanVal) {
            selectEl.value = '';
            return;
        }

        let found = false;
        for (let i = 0; i < selectEl.options.length; i++) {
            if (selectEl.options[i].value === cleanVal) {
                found = true;
                break;
            }
        }

        if (!found) {
            const tempOpt = document.createElement('option');
            tempOpt.value = cleanVal;
            tempOpt.textContent = (showRetiredSuffix && cleanVal !== 'No Show') ? (cleanVal + ' (Retired)') : cleanVal;
            tempOpt.setAttribute('data-temp-retired', '1');
            selectEl.appendChild(tempOpt);
        }

        selectEl.value = cleanVal;
    }

    function updateCheckinFamilyOptions(lineIdx, selectedFamId) {
        const famSelect = document.getElementById('checkin_family_group_id');
        if (!famSelect) return;

        const targetVal = (selectedFamId !== undefined) ? String(selectedFamId || '') : famSelect.value;
        famSelect.innerHTML = '<option value="">[ + Start New Family Group for This Line ]</option>';

        const fams = (window.existingFamiliesByLine && window.existingFamiliesByLine[lineIdx]) ? window.existingFamiliesByLine[lineIdx] : {};

        Object.keys(fams).forEach(function(fid) {
            const opt = document.createElement('option');
            opt.value = fid;
            opt.textContent = fams[fid];
            if (fid === targetVal) opt.selected = true;
            famSelect.appendChild(opt);
        });

        if (targetVal && !Object.prototype.hasOwnProperty.call(fams, targetVal)) {
            const tempOpt = document.createElement('option');
            tempOpt.value = targetVal;
            tempOpt.textContent = 'Assigned Family Group';
            tempOpt.selected = true;
            famSelect.appendChild(tempOpt);
        }
    }

    function updateWalkinFamilyOptions(lineIdx) {
        const famSelect = document.getElementById('walkin_family_group_id');
        if (!famSelect) return;

        const currentVal = famSelect.value;
        famSelect.innerHTML = '<option value="">[ Auto-Create Group for Primary Contact ]</option>';

        const fams = (window.existingFamiliesByLine && window.existingFamiliesByLine[lineIdx]) ? window.existingFamiliesByLine[lineIdx] : {};

        Object.keys(fams).forEach(function(fid) {
            const opt = document.createElement('option');
            opt.value = fid;
            opt.textContent = fams[fid];
            if (fid === currentVal) opt.selected = true;
            famSelect.appendChild(opt);
        });
    }

    function updateEditFamilyOptions(lineIdx, selectedFamId) {
        const famSelect = document.getElementById('edit_family_group_id');
        if (!famSelect) return;

        const targetVal = (selectedFamId !== undefined) ? String(selectedFamId || '') : '';
        famSelect.innerHTML = '<option value="">[ + Start New Family Group for This Line ]</option>';

        const fams = (window.existingFamiliesByLine && window.existingFamiliesByLine[lineIdx]) ? window.existingFamiliesByLine[lineIdx] : {};

        Object.keys(fams).forEach(function(fid) {
            const opt = document.createElement('option');
            opt.value = fid;
            opt.textContent = fams[fid];
            if (fid === targetVal) opt.selected = true;
            famSelect.appendChild(opt);
        });

        if (targetVal && !Object.prototype.hasOwnProperty.call(fams, targetVal)) {
            const tempOpt = document.createElement('option');
            tempOpt.value = targetVal;
            tempOpt.textContent = 'Assigned Family Group';
            tempOpt.selected = true;
            famSelect.appendChild(tempOpt);
        }
    }

    function toggleUnpaidBanner(isChecked) {
        const banner = document.getElementById('sywat_unpaid_warning_banner');
        if (banner) {
            if (isChecked) {
                banner.classList.add('sywat-hidden');
            } else {
                banner.classList.remove('sywat-hidden');
            }
        }
    }

    /**
     * Populate and open #sywat_checkin_modal instantaneously from row JSON payload.
     */
    function openDeskCheckinModal(archer) {
        if (!archer || typeof archer !== 'object') return;
        const modal = document.getElementById('sywat_checkin_modal');
        if (!modal) return;

        closeAllDeskTfaaAutocompletes();

        const regIdEl = document.getElementById('checkin_reg_id');
        if (regIdEl) regIdEl.value = archer.id || '';

        const nameInput = document.getElementById('checkin_archer_name');
        if (nameInput) {
            nameInput.value = archer.name || '';
            delete nameInput.dataset.normalizedFrom;
        }

        const pricing   = getDeskPricing();
        const roundSlug = archer.pricing_profile || pricing.defaultProfile || 'indoor';
        const roundEl   = document.getElementById('checkin_pricing_profile');
        if (roundEl) {
            if (roundEl.tagName === 'SELECT') {
                setDeskSelectValueWithFallback(roundEl, roundSlug, false);
            } else {
                roundEl.value = roundSlug;
            }
        }

        // Unpaid balance warning banner & verification gate
        const effectiveDue   = parseFloat(archer.effective_due || 0);
        const displayDue     = parseFloat(archer.display_due || 0);
        const isFamilyBal    = !!archer.is_family_balance;
        const banner         = document.getElementById('sywat_unpaid_warning_banner');
        const titleDisp      = document.getElementById('checkin_unpaid_title_disp');
        const subtextDisp    = document.getElementById('checkin_unpaid_subtext_disp');
        const verifiedGroup  = document.getElementById('sywat_payment_verified_check_group');
        const verifiedCheck  = document.getElementById('sywat_payment_verified_check');

        if (banner) {
            banner.setAttribute('data-orig-due', displayDue.toFixed(2));
            banner.setAttribute('data-is-family-balance', isFamilyBal ? '1' : '0');
        }
        if (titleDisp) {
            titleDisp.innerHTML = '⚠️ ' + (isFamilyBal ? 'UNPAID FAMILY BALANCE' : 'UNPAID BALANCE') + ': $<span id="checkin_unpaid_amount_disp">' + displayDue.toFixed(2) + '</span>';
        }
        if (subtextDisp) {
            subtextDisp.textContent = isFamilyBal
                ? 'Family Cap Group Outstanding Balance. Collect Payment Onsite.'
                : 'Please See Event Staff for Payment';
        }
        if (verifiedCheck) {
            verifiedCheck.checked = false;
        }

        if (effectiveDue > 0) {
            if (banner) banner.classList.remove('sywat-hidden');
            if (verifiedGroup) verifiedGroup.classList.remove('sywat-hidden');
            if (verifiedCheck) verifiedCheck.required = true;
        } else {
            if (banner) banner.classList.add('sywat-hidden');
            if (verifiedGroup) verifiedGroup.classList.add('sywat-hidden');
            if (verifiedCheck) verifiedCheck.required = false;
        }

        // Division, Gender, Style (enforce style rules before selecting style)
        const divEl   = document.getElementById('checkin_division');
        const genEl   = document.getElementById('checkin_gender');
        const styleEl = document.getElementById('checkin_style');

        setDeskSelectValueWithFallback(divEl, archer.division || '', true);
        setDeskSelectValueWithFallback(genEl, archer.gender || '', false);
        handleStyleRestrictions('checkin');
        setDeskSelectValueWithFallback(styleEl, archer.style || '', true);

        // Rate & Line Family Group
        const payEl = document.getElementById('checkin_pay_option');
        if (payEl) {
            payEl.value = archer.pay_option || 'Individual';
        }
        updateCheckinFamilyOptions(String(archer.line_index || '0'), archer.family_id || '');
        toggleFamilyBox('checkin');

        // Auto-fill missing metadata from Member DB / TFAA history & render validation badges
        autoFillOnNameChange('checkin', true);

        modal.classList.add('active');
        if (nameInput) {
            nameInput.focus();
        }
    }

    function closeDeskCheckinModal() {
        const modal = document.getElementById('sywat_checkin_modal');
        if (modal) {
            modal.classList.remove('active');
        }
        closeAllDeskTfaaAutocompletes();
    }

    /**
     * Populate and open #sywat_desk_edit_modal instantaneously from row JSON payload.
     */
    function openDeskEditModal(archer) {
        if (!archer || typeof archer !== 'object') return;
        const modal = document.getElementById('sywat_desk_edit_modal');
        if (!modal) return;

        closeAllDeskTfaaAutocompletes();

        const regIdEl = document.getElementById('edit_reg_id');
        if (regIdEl) regIdEl.value = archer.id || '';

        const nameInput = document.getElementById('edit_archer_name');
        if (nameInput) {
            nameInput.value = archer.name || '';
            delete nameInput.dataset.normalizedFrom;
        }

        const pricing   = getDeskPricing();
        const roundSlug = archer.pricing_profile || pricing.defaultProfile || 'indoor';
        const roundEl   = document.getElementById('edit_pricing_profile');
        if (roundEl) {
            if (roundEl.tagName === 'SELECT') {
                setDeskSelectValueWithFallback(roundEl, roundSlug, false);
            } else {
                roundEl.value = roundSlug;
            }
        }

        const lineIdxStr = String(archer.line_index || '0');
        const lineEl     = document.getElementById('edit_line_index');
        if (lineEl) {
            lineEl.value = lineIdxStr;
        }

        const divEl   = document.getElementById('edit_division');
        const genEl   = document.getElementById('edit_gender');
        const styleEl = document.getElementById('edit_style');

        setDeskSelectValueWithFallback(divEl, archer.division || '', true);
        setDeskSelectValueWithFallback(genEl, archer.gender || '', false);
        handleStyleRestrictions('edit');
        setDeskSelectValueWithFallback(styleEl, archer.style || '', true);

        const payEl = document.getElementById('edit_pay_option');
        if (payEl) {
            payEl.value = archer.pay_option || 'Individual';
        }
        updateEditFamilyOptions(lineIdxStr, archer.family_id || '');

        const balanceEl = document.getElementById('edit_balance_due');
        if (balanceEl) {
            const balDue = parseFloat(archer.balance_due || 0);
            balanceEl.value = balDue.toFixed(2);
            balanceEl.dataset.origDue = balDue.toFixed(2);
            delete balanceEl.dataset.manualOverride;
        }

        toggleFamilyBox('edit');

        modal.classList.add('active');
        if (nameInput) {
            nameInput.focus();
        }
    }

    function closeDeskEditModal() {
        const modal = document.getElementById('sywat_desk_edit_modal');
        if (modal) {
            modal.classList.remove('active');
        }
        closeAllDeskTfaaAutocompletes();
    }

    /**
     * Smart Shooter Match Engine querying BOTH the Member Database and 18-Month TFAA History.
     * Supports exact match, First+Last (+ suffix) middle-initial folding ("Mark E. Tanner" -> "Mark Tanner"),
     * safe fuzzy alias prefix matching ("Steven" -> "Steve"), and optional substring matching for autocomplete.
     */
    function getAllTfaaMatches(typedName, allowPartial) {
        if (!typedName) return [];
        const clean = cleanDeskName(typedName);
        if (!clean || clean.length < 2) return [];

        const qKeys        = buildDeskNameMatchKeys(typedName);
        const unifiedIndex = getUnifiedDeskShooterIndex();
        const matches      = [];

        for (let i = 0; i < unifiedIndex.length; i++) {
            const s     = unifiedIndex[i];
            const sName = cleanDeskName(s.name);
            const sKeys = s.keys || buildDeskNameMatchKeys(s.name);

            const isExactMatch = (qKeys.exact && sKeys.exact === qKeys.exact);
            const isFlMatch    = (qKeys.tokenCount >= 2 && qKeys.lastTokenLen >= 2 && qKeys.fl && sKeys.fl === qKeys.fl);
            const isPartial    = allowPartial && (sName.includes(clean) || clean.includes(sName));

            // Safe Fuzzy Alias Prefix Match (e.g., "steven" vs "steve" + exact last name)
            let isFuzzyMatch = false;
            if (!isExactMatch && !isFlMatch && qKeys.tokenCount >= 2 && sKeys.tokenCount >= 2) {
                if (qKeys.last === sKeys.last && qKeys.suffix === sKeys.suffix) {
                    const qFirst = qKeys.first;
                    const sFirst = sKeys.first;
                    if (qFirst.length >= 3 && sFirst.length >= 3) {
                        if (qFirst.startsWith(sFirst) || sFirst.startsWith(qFirst)) {
                            isFuzzyMatch = true;
                        }
                    }
                }
            }

            if (isExactMatch || isFlMatch || isFuzzyMatch || isPartial) {
                matches.push({
                    name: s.name,
                    full_name: s.name,
                    division: s.division || '',
                    gender: s.gender || '',
                    style: s.style || '',
                    phone: s.phone || '',
                    source: s.source || 'tfaa_history',
                    matchRank: isExactMatch ? 4 : (isFlMatch ? 3 : (isFuzzyMatch ? 2 : 1))
                });
            }
        }

        matches.sort(function(a, b) {
            if (a.matchRank !== b.matchRank) return b.matchRank - a.matchRank;

            const aMember = (a.source === 'member_db') ? 1 : 0;
            const bMember = (b.source === 'member_db') ? 1 : 0;
            if (aMember !== bMember) return bMember - aMember;

            const aCfg = findDeskStyleConfig(a.style);
            const bCfg = findDeskStyleConfig(b.style);
            const aPro = (aCfg ? !!aCfg.is_pro : (a.style && a.style.indexOf('FS Pro') !== -1)) ? 1 : 0;
            const bPro = (bCfg ? !!bCfg.is_pro : (b.style && b.style.indexOf('FS Pro') !== -1)) ? 1 : 0;
            return bPro - aPro;
        });

        return matches;
    }

    function findTfaaMatch(typedName) {
        const matches = getAllTfaaMatches(typedName, false);
        return matches.length > 0 ? matches : null;
    }

    function findShopHistoryMatch(typedName) {
        if (!typedName || !window.sywatHistory || !window.sywatHistory.exact) return null;
        const clean = cleanDeskName(typedName);
        if (!clean) return null;

        if (window.sywatHistory.exact[clean]) return window.sywatHistory.exact[clean];

        const qKeys = buildDeskNameMatchKeys(typedName);
        if (qKeys.tokenCount >= 2 && qKeys.fl && window.sywatHistory.fl && window.sywatHistory.fl[qKeys.fl]) {
            return window.sywatHistory.fl[qKeys.fl];
        }
        return null;
    }

    /**
     * Smart option matcher translating database style codes/aliases, division aliases, and gender letters to select values.
     */
    function matchDeskTfaaSelect(value, selectEl) {
        if (typeof value !== 'string' || !selectEl || !selectEl.options) return;
        const clean = value.trim().toLowerCase();
        if (!clean) return;

        // 1. Database-Driven Shooting Style Match
        const styleCfg = findDeskStyleConfig(value);
        if (styleCfg && styleCfg.label) {
            const targetLabel = styleCfg.label.trim().toLowerCase();
            for (let i = 0; i < selectEl.options.length; i++) {
                const opt = selectEl.options[i];
                if (!opt.value) continue;
                if (opt.value.trim().toLowerCase() === targetLabel) {
                    selectEl.value = opt.value;
                    return;
                }
            }
        }

        // 2. Database-Driven Age Division Match
        const divCfg = findDeskDivisionConfig(value);
        if (divCfg && divCfg.label) {
            const targetDiv = divCfg.label.trim().toLowerCase();
            for (let i = 0; i < selectEl.options.length; i++) {
                const opt = selectEl.options[i];
                if (!opt.value) continue;
                if (opt.value.trim().toLowerCase() === targetDiv) {
                    selectEl.value = opt.value;
                    return;
                }
            }
        }

        // 3. Legacy Fallback Style Map
        const legacyStyleMap = {
            'fs': 'fs – freestyle',
            'freestyle': 'fs – freestyle',
            'fs pro': 'fs pro – freestyle pro',
            'freestyle pro': 'fs pro – freestyle pro',
            'fsl': 'fsl – freestyle limited',
            'freestyle limited': 'fsl – freestyle limited',
            'fsl-r': 'fslr – freestyle limited recurve',
            'fslr': 'fslr – freestyle limited recurve',
            'olympic recurve': 'fslr – freestyle limited recurve',
            'bhfs': 'bhfs – bowhunter freestyle',
            'bowhunter freestyle': 'bhfs – bowhunter freestyle',
            'mbh': 'mbh – modern bowhunter',
            'modern bowhunter': 'mbh – modern bowhunter',
            'bb': 'bb – barebow',
            'barebow': 'bb – barebow',
            'bbc': 'bbc – barebow compound',
            'barebow compound': 'bbc – barebow compound',
            'bbr': 'bbr – barebow recurve',
            'barebow recurve': 'bbr – barebow recurve',
            'trad': 'trad – traditional',
            'traditional': 'trad – traditional',
            'lb': 'lb – longbow',
            'longbow': 'lb – longbow',
            'genesis': 'genesis'
        };

        const cleanBase = clean.split(' –')[0].split(' - ')[0].trim();
        const mappedTarget = legacyStyleMap[clean] || legacyStyleMap[cleanBase] || '';

        for (let i = 0; i < selectEl.options.length; i++) {
            const opt = selectEl.options[i];
            if (!opt.value) continue;
            const optLower = opt.value.trim().toLowerCase();

            if (optLower === clean || (mappedTarget && optLower.startsWith(mappedTarget.split(' –')[0]))) {
                selectEl.value = opt.value;
                return;
            }
        }

        // 4. Gender resolution ("M" / "Male", "F" / "Female")
        if (clean === 'm' || clean === 'male') {
            for (let i = 0; i < selectEl.options.length; i++) {
                const opt = selectEl.options[i];
                if (opt.value && opt.value.toLowerCase().startsWith('male')) {
                    selectEl.value = opt.value;
                    return;
                }
            }
        }
        if (clean === 'f' || clean === 'female') {
            for (let i = 0; i < selectEl.options.length; i++) {
                const opt = selectEl.options[i];
                if (opt.value && opt.value.toLowerCase().startsWith('female')) {
                    selectEl.value = opt.value;
                    return;
                }
            }
        }

        // 5. Fallback prefix/substring match
        for (let i = 0; i < selectEl.options.length; i++) {
            const opt = selectEl.options[i];
            if (!opt.value) continue;
            const optLower = opt.value.trim().toLowerCase();

            const cleanSanitized = cleanBase.replace(/[^a-z0-9]/gi, '');
            const optSanitized   = optLower.split(' –')[0].split(' - ')[0].replace(/[^a-z0-9]/gi, '');

            if (cleanSanitized && optSanitized && cleanSanitized === optSanitized) {
                selectEl.value = opt.value;
                return;
            }

            if (optLower.includes(clean) || (cleanSanitized.length >= 2 && optLower.includes(cleanSanitized))) {
                selectEl.value = opt.value;
                return;
            }
        }
    }

    function handleDeskTfaaAutocomplete(input) {
        if (!input) return;
        const query = cleanDeskName(input.value);
        closeAllDeskTfaaAutocompletes();

        if (query.length < 2) return;

        const matches = getAllTfaaMatches(query, true).slice(0, 8);
        if (matches.length === 0) return;

        const parent = input.parentElement;
        if (parent && getComputedStyle(parent).position === 'static') {
            parent.classList.add('sywat-field-relative');
        }

        const dropdown = document.createElement('div');
        dropdown.className = 'sywat-desk-tfaa-dropdown';

        matches.forEach(function(m) {
            const item = document.createElement('div');
            item.className = 'sywat-desk-tfaa-item';

            let badgeText = m.division || '';
            if (m.style) {
                const styleCfg = findDeskStyleConfig(m.style);
                const shortSty = (styleCfg && styleCfg.code) ? styleCfg.code : m.style.split(' – ')[0].split(' - ')[0];
                badgeText += badgeText ? ' (' + shortSty + ')' : shortSty;
            } else if (m.source === 'member_db') {
                badgeText += badgeText ? ' (Member)' : 'Member';
            }

            item.innerHTML = '<strong>' + escapeHtmlDesk(m.name) + '</strong> <span class="sywat-tfaa-item-badge">' + escapeHtmlDesk(badgeText) + '</span>';

            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                const isPrimaryContact = (input.id === 'walkin_group_name' || input.name === 'walkin_group_name');
                const prevVal = input.getAttribute('data-prev-val') || input.value;

                input.value = m.name;
                input.setAttribute('data-prev-val', m.name);
                delete input.dataset.normalizedFrom;
                closeAllDeskTfaaAutocompletes();

                const form = input.closest('form');
                let prefix = 'walkin';
                if (input.id === 'checkin_archer_name' || input.name === 'checkin_name') {
                    prefix = 'checkin';
                } else if (input.id === 'edit_archer_name' || input.name === 'edit_name') {
                    prefix = 'edit';
                }

                if (isPrimaryContact) {
                    const phoneEl      = form ? form.querySelector('#walkin_group_phone, input[name="walkin_group_phone"]') : null;
                    const archerNameEl = form ? form.querySelector('#walkin_name_input, input[name="walkin_name"]') : null;

                    if (phoneEl && (m.phone || m.phone_number)) {
                        phoneEl.value = m.phone || m.phone_number;
                    } else if (phoneEl) {
                        const shopMatch = findShopHistoryMatch(m.name);
                        if (shopMatch && shopMatch.phone) {
                            phoneEl.value = shopMatch.phone;
                        }
                    }

                    if (archerNameEl && (!archerNameEl.value || archerNameEl.value === prevVal || archerNameEl.value === input.value)) {
                        archerNameEl.value = m.name;
                    }

                    const divEl   = form ? form.querySelector('select[name="walkin_division"]') : null;
                    const genEl   = form ? form.querySelector('select[name="walkin_gender"]') : null;
                    const styleEl = form ? form.querySelector('select[name="walkin_style"]') : null;

                    if (divEl && m.division) matchDeskTfaaSelect(m.division, divEl);
                    if (genEl && m.gender) matchDeskTfaaSelect(m.gender, genEl);
                    handleStyleRestrictions('walkin');
                    if (styleEl && m.style) matchDeskTfaaSelect(m.style, styleEl);

                    handleStyleRestrictions('walkin');
                    evaluateTfaaStatus('walkin');
                } else {
                    if (form) {
                        const divEl   = form.querySelector('select[name="walkin_division"], select[name="checkin_division"], select[name="edit_division"]');
                        const genEl   = form.querySelector('select[name="walkin_gender"], select[name="checkin_gender"], select[name="edit_gender"]');
                        const styleEl = form.querySelector('select[name="walkin_style"], select[name="checkin_style"], select[name="edit_style"]');

                        if (divEl && m.division) matchDeskTfaaSelect(m.division, divEl);
                        if (genEl && m.gender) matchDeskTfaaSelect(m.gender, genEl);
                        handleStyleRestrictions(prefix);
                        if (styleEl && m.style) matchDeskTfaaSelect(m.style, styleEl);
                    }

                    handleStyleRestrictions(prefix);
                    evaluateTfaaStatus(prefix);
                }
            });

            dropdown.appendChild(item);
        });

        if (parent) {
            parent.appendChild(dropdown);
        }
    }

    function closeAllDeskTfaaAutocompletes() {
        document.querySelectorAll('.sywat-desk-tfaa-dropdown').forEach(function(el) {
            el.remove();
        });
    }

    function loadTfaaCategory(prefix, divVal, genVal, styleVal, officialName) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        if (nameInput && officialName) {
            nameInput.value = officialName;
            delete nameInput.dataset.normalizedFrom;
        }

        const divEl   = document.getElementById(prefix + '_division');
        const genEl   = document.getElementById(prefix + '_gender');
        const styleEl = document.getElementById(prefix + '_style');

        if (divEl && divVal) matchDeskTfaaSelect(divVal, divEl);
        if (genEl && genVal) matchDeskTfaaSelect(genVal, genEl);
        handleStyleRestrictions(prefix);
        if (styleEl && styleVal) matchDeskTfaaSelect(styleVal, styleEl);

        handleStyleRestrictions(prefix);
        evaluateTfaaStatus(prefix);
    }

    function updateNameToOfficial(prefix, officialName) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        if (nameInput && officialName) {
            nameInput.value = officialName;
            autoFillOnNameChange(prefix, false);
        }
    }

    function evaluateTfaaStatus(prefix) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        const badgeEl   = document.getElementById(prefix + '_tfaa_badge');
        const pillsEl   = document.getElementById(prefix + '_tfaa_pills');

        if (!nameInput || !badgeEl || !pillsEl) return;

        const typedName   = nameInput.value.trim();
        const cleanTyped  = cleanDeskName(typedName);
        const tfaaMatches = findTfaaMatch(typedName);

        const promoInfo = (prefix === 'walkin' && cleanTyped.length >= 2)
            ? isWalkinPromoEligible(typedName, getActiveWalkinRoundSlug())
            : null;
        const promoBannerHtml = promoInfo
            ? '<div class="sywat-badge-verified sywat-modal-row-spacing">🎟️ <strong>First-Time / Lapsed Promo Eligible ($0.00)</strong> — ' + escapeHtmlDesk(promoInfo.label) + '</div>'
            : '';

        pillsEl.innerHTML = '';

        if (!tfaaMatches || !Array.isArray(tfaaMatches) || tfaaMatches.length === 0) {
            badgeEl.className = 'sywat-badge-none';
            badgeEl.innerHTML = '⚪ No History Found (Member DB / 18-Mo Window)' + promoBannerHtml;
            return;
        }

        const currDiv   = document.getElementById(prefix + '_division')?.value || '';
        const currGen   = document.getElementById(prefix + '_gender')?.value || '';
        const currStyle = document.getElementById(prefix + '_style')?.value || '';

        const uDivCfg = findDeskDivisionConfig(currDiv);
        const uStyCfg = findDeskStyleConfig(currStyle);

        let isCategoryMatch      = false;
        let isStyleOnlyMatch     = false;
        let knownStyles          = [];
        const hasMultipleMatches = tfaaMatches.length > 1;

        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'sywat-pill-box';

        tfaaMatches.forEach(function(cat) {
            const catName = cat.full_name || cat.name || '';
            const cStyCfg = findDeskStyleConfig(cat.style);
            const cDivCfg = findDeskDivisionConfig(cat.division);

            if (cat.style) {
                const shortSty = (cStyCfg && cStyCfg.code) ? cStyCfg.code : cat.style.split(/\s+[\u2013\u2014-]\s+/)[0];
                if (!knownStyles.includes(shortSty)) knownStyles.push(shortSty);
            }

            const cDiv = (cat.division || '').toLowerCase();
            const cGen = (cat.gender || '').toLowerCase();
            const cSty = (cat.style || '').toLowerCase();

            const uDiv = currDiv.toLowerCase();
            const uGen = currGen.toLowerCase();
            const uSty = currStyle.toLowerCase();

            const divMatch = !cDiv
                || (cDivCfg && uDivCfg && cDivCfg.label === uDivCfg.label)
                || (cDiv === uDiv)
                || (cDiv && uDiv && (cDiv.includes(uDiv.split(/\s*\(/)[0].trim()) || uDiv.includes(cDiv.split(/\s*\(/)[0].trim())));

            const genMatch = !cGen
                || (cGen === uGen)
                || (cGen && uGen && cGen.charAt(0) === uGen.charAt(0));

            const cStylePrefix = cSty.split(/\s+[\u2013\u2014-]\s+/)[0].trim();
            const uStylePrefix = uSty.split(/\s+[\u2013\u2014-]\s+/)[0].trim();
            const cStyleClean  = cStylePrefix.replace(/[^a-z0-9]/g, '');
            const uStyleClean  = uStylePrefix.replace(/[^a-z0-9]/g, '');
            const styleMatch   = (cStyCfg && uStyCfg && cStyCfg.label === uStyCfg.label)
                || (cSty === uSty)
                || (cStyleClean.length >= 2 && cStyleClean === uStyleClean)
                || (cSty && uSty && (uSty.includes(cSty) || cSty.includes(uStylePrefix)));

            if (styleMatch && (!hasMultipleMatches || cleanDeskName(catName) === cleanTyped)) {
                isStyleOnlyMatch = true;
            }

            if (divMatch && genMatch && (styleMatch || !cSty)) {
                if (!hasMultipleMatches || cleanDeskName(catName) === cleanTyped) {
                    isCategoryMatch = true;
                }
            }

            if (cat.style || cat.division) {
                const divShort = (cDivCfg && cDivCfg.code) ? cDivCfg.code : (cat.division ? cat.division.split(' ')[0] : '');
                const genShort = cat.gender ? (cat.gender.toLowerCase().startsWith('f') ? 'F' : 'M') : '';
                const styShort = (cStyCfg && cStyCfg.code) ? cStyCfg.code : (cat.style ? cat.style.split(/\s+[\u2013\u2014-]\s+/)[0] : 'Member');

                const pillBtn = document.createElement('button');
                pillBtn.type = 'button';
                pillBtn.className = 'sywat-btn-pill';

                let pillLabel = '';
                if (hasMultipleMatches && catName) {
                    pillLabel = '⚡ Load: <strong>' + escapeHtmlDesk(catName) + '</strong> (' + escapeHtmlDesk((styShort + ' ' + divShort + ' ' + genShort).trim()) + ')';
                } else {
                    pillLabel = '⚡ Load: <strong>' + escapeHtmlDesk(styShort) + '</strong> (' + escapeHtmlDesk((divShort + ' ' + genShort).trim()) + ')';
                }
                pillBtn.innerHTML = pillLabel;

                pillBtn.addEventListener('click', function() {
                    loadTfaaCategory(prefix, cat.division, cat.gender, cat.style, catName);
                });
                buttonContainer.appendChild(pillBtn);
            }
        });

        if (hasMultipleMatches && buttonContainer.children.length > 0) {
            const alertBox = document.createElement('div');
            alertBox.className = 'sywat-multi-style-alert';

            const alertTitle = document.createElement('div');
            alertTitle.className = 'sywat-multi-style-title';
            alertTitle.innerHTML = '⚠️ <strong>Multiple shooter profiles found. Click a profile to load:</strong>';

            alertBox.appendChild(alertTitle);
            alertBox.appendChild(buttonContainer);
            pillsEl.appendChild(alertBox);
        }

        const exactNameMatchRecord = tfaaMatches.find(function(m) {
            return cleanDeskName(m.full_name || m.name) === cleanTyped;
        });
        const officialName   = exactNameMatchRecord ? (exactNameMatchRecord.full_name || exactNameMatchRecord.name) : (tfaaMatches[0].full_name || tfaaMatches[0].name || '');
        const cleanOfficial  = cleanDeskName(officialName);
        const isNameMatch    = (cleanTyped === cleanOfficial);
        const hasMemberSrc   = tfaaMatches.some(function(m) { return m.source === 'member_db'; });
        const normalizedFrom = nameInput.dataset.normalizedFrom || '';

        let badgeText = '';
        if (isNameMatch && knownStyles.length > 0 && isCategoryMatch) {
            badgeEl.className = 'sywat-badge-verified';
            badgeText = '🟢 TFAA Validated: ' + escapeHtmlDesk(knownStyles.join(', '));
        } else if (isNameMatch && hasMemberSrc && knownStyles.length === 0) {
            badgeEl.className = 'sywat-badge-verified';
            badgeText = '🟢 Official Member Record: <strong>' + escapeHtmlDesk(officialName) + '</strong>';
        } else if (!isNameMatch && officialName !== '') {
            badgeEl.className = 'sywat-badge-known';
            const styleSummary = knownStyles.length > 0 ? ' (Known Styles: ' + escapeHtmlDesk(knownStyles.join(', ')) + ')' : '';
            badgeText = '⚠️ Name Mismatch' + styleSummary;
        } else if (!currStyle || currStyle.toLowerCase() === 'no show') {
            badgeEl.className = 'sywat-badge-known';
            badgeText = '🔵 Member Profile Available (Known Styles: ' + escapeHtmlDesk(knownStyles.join(', ')) + ')';
        } else if (isStyleOnlyMatch) {
            badgeEl.className = 'sywat-badge-known';
            badgeText = '⚠️ Division / Gender Mismatch (Known Styles: ' + escapeHtmlDesk(knownStyles.join(', ')) + ')';
        } else {
            badgeEl.className = 'sywat-badge-known';
            badgeText = '⚠️ Style Mismatch (Known Styles: ' + escapeHtmlDesk(knownStyles.join(', ')) + ')';
        }

        if (isNameMatch && normalizedFrom && cleanDeskName(normalizedFrom) !== cleanTyped) {
            badgeText += '<br><span class="sywat-badge-subtext">↳ Standardized from "<strong>' + escapeHtmlDesk(normalizedFrom) + '</strong>"</span>';
        } else if (!isNameMatch && officialName !== '') {
            badgeText += '<br><span class="sywat-badge-subtext">↳ Official Member Name: <strong>' + escapeHtmlDesk(officialName) + '</strong></span>';
            badgeText += '<button type="button" class="sywat-btn-use-official" data-sywat-action="use-official-desk-name" data-prefix="' + escapeHtmlDesk(prefix) + '" data-official-name="' + escapeHtmlDesk(officialName) + '">✏️ Use Official Name</button>';
        }

        badgeEl.innerHTML = badgeText + promoBannerHtml;
    }

    function autoFillOnNameChange(prefix, preserveExisting) {
        const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
        if (!nameInput) return;

        const typedName = nameInput.value.trim();
        if (!typedName) {
            delete nameInput.dataset.normalizedFrom;
            if (prefix === 'walkin') {
                const divEl   = document.getElementById('walkin_division');
                const genEl   = document.getElementById('walkin_gender');
                const styleEl = document.getElementById('walkin_style');
                if (divEl) divEl.value = '';
                if (genEl) genEl.value = '';
                if (styleEl) styleEl.value = '';
                handleStyleRestrictions('walkin');
            }
            evaluateTfaaStatus(prefix);
            return;
        }

        const tfaaMatches = findTfaaMatch(typedName);
        let distinctStylesCount = 0;

        if (tfaaMatches && tfaaMatches.length > 0) {
            const distinctNames = new Set();
            const styleCodes    = new Set();

            tfaaMatches.forEach(function(m) {
                const cName = (m.full_name || m.name || '').trim();
                if (cName) distinctNames.add(cleanDeskName(cName));

                if (m.style) {
                    const sCfg = findDeskStyleConfig(m.style);
                    const code = (sCfg && sCfg.code) ? sCfg.code : m.style.split(/\s+[\u2013\u2014-]\s+/)[0].trim();
                    styleCodes.add(code.replace(/[^a-z0-9]/gi, '').toLowerCase());
                }
            });

            distinctStylesCount = styleCodes.size;

            if (distinctNames.size === 1) {
                const canonicalOfficial = (tfaaMatches[0].full_name || tfaaMatches[0].name || '').trim();
                if (canonicalOfficial && cleanDeskName(canonicalOfficial) !== cleanDeskName(typedName)) {
                    nameInput.dataset.normalizedFrom = typedName;
                    nameInput.value = canonicalOfficial;
                    preserveExisting = false;
                }
            }
        }

        const effectiveTyped = nameInput.value.trim();
        const historyRecord  = (tfaaMatches && tfaaMatches.length > 0)
            ? tfaaMatches[0]
            : findShopHistoryMatch(effectiveTyped);

        if (historyRecord) {
            const divEl   = document.getElementById(prefix + '_division');
            const genEl   = document.getElementById(prefix + '_gender');
            const styleEl = document.getElementById(prefix + '_style');

            const canSetDiv   = divEl && historyRecord.division && (!preserveExisting || !divEl.value || divEl.value === 'No Show');
            const canSetGen   = genEl && historyRecord.gender && (!preserveExisting || !genEl.value || genEl.value === 'No Show');
            const canSetStyle = styleEl && historyRecord.style && (distinctStylesCount <= 1) && (!preserveExisting || !styleEl.value || styleEl.value === 'No Show');

            if (canSetDiv) matchDeskTfaaSelect(historyRecord.division, divEl);
            if (canSetGen) matchDeskTfaaSelect(historyRecord.gender, genEl);

            handleStyleRestrictions(prefix);

            if (canSetStyle) matchDeskTfaaSelect(historyRecord.style, styleEl);
        }

        handleStyleRestrictions(prefix);
        evaluateTfaaStatus(prefix);
    }

    function closeWalkinModal() {
        const modal = document.getElementById('walkinModal');
        if (modal) modal.classList.remove('active');

        const contactInput = document.getElementById('walkin_group_name');
        if (contactInput) {
            contactInput.value = '';
            contactInput.setAttribute('data-prev-val', '');
        }

        const phoneInput = document.getElementById('walkin_group_phone');
        if (phoneInput) phoneInput.value = '';

        const nameInput = document.getElementById('walkin_name_input');
        if (nameInput) {
            nameInput.value = '';
            delete nameInput.dataset.normalizedFrom;
        }

        const roundSel = document.getElementById('walkin_pricing_profile');
        if (roundSel && roundSel.tagName === 'SELECT') roundSel.selectedIndex = 0;

        const divEl = document.getElementById('walkin_division');
        if (divEl) divEl.value = '';
        const genEl = document.getElementById('walkin_gender');
        if (genEl) genEl.value = '';
        const styleEl = document.getElementById('walkin_style');
        if (styleEl) styleEl.value = '';

        const jsonInput = document.querySelector('input[name="walkin_session_archers_json"]');
        if (jsonInput) jsonInput.value = '';

        const famSel = document.getElementById('walkin_family_group_id');
        if (famSel) famSel.value = '';

        handleStyleRestrictions('walkin');
        evaluateTfaaStatus('walkin');
        closeAllDeskTfaaAutocompletes();
    }

    function openWalkinModal() {
        closeWalkinModal();
        const modal = document.getElementById('walkinModal');
        if (modal) modal.classList.add('active');

        const lineSel = document.getElementById('walkin_line_index');
        if (lineSel) {
            updateWalkinFamilyOptions(lineSel.value);
        }

        const contactInput = document.getElementById('walkin_group_name');
        if (contactInput) {
            contactInput.setAttribute('data-prev-val', contactInput.value || '');
        }

        const walkinNameInput = document.getElementById('walkin_name_input');
        if (walkinNameInput) walkinNameInput.focus();
    }

    function toggleFamilyBox(type) {
        const paySelect = document.getElementById(type + '_pay_option');
        const box       = document.getElementById(type + '_family_box');
        if (box) {
            if (!paySelect || paySelect.value === 'Family') {
                box.classList.remove('sywat-hidden');
            } else {
                box.classList.add('sywat-hidden');
            }
        }
        if (type === 'edit' || type === 'checkin') {
            syncDeskBalanceDisplay(type);
        }
    }

    function continueFamilyLoop(familyId, lineIdx) {
        openWalkinModal();

        const lineSelect = document.getElementById('walkin_line_index');
        if (lineSelect && lineIdx !== undefined) {
            lineSelect.value = lineIdx;
            updateWalkinFamilyOptions(lineIdx);
        }

        const familySelect = document.getElementById('walkin_family_group_id');
        if (familySelect) {
            familySelect.value = familyId;
        }
    }

    /**
     * Enforce Division vs. Shooting Style Restrictions (Database-Driven with Legacy Fallback)
     */
    function handleStyleRestrictions(type) {
        const divisionEl = document.getElementById(type + '_division');
        const styleEl    = document.getElementById(type + '_style');
        if (!divisionEl || !styleEl) return;

        const currentDiv = divisionEl.value;

        if (!currentDiv || currentDiv === 'No Show') {
            for (let i = 0; i < styleEl.options.length; i++) {
                styleEl.options[i].disabled = false;
            }
            if (type === 'edit' || type === 'checkin') {
                syncDeskBalanceDisplay(type);
            }
            return;
        }

        if (window.SYWAT && window.SYWAT.shootingStyles && typeof window.SYWAT.shootingStyles.applyStyleRules === 'function') {
            window.SYWAT.shootingStyles.applyStyleRules(styleEl, divisionEl, { trigger: 'division' });
            for (let i = 0; i < styleEl.options.length; i++) {
                if (styleEl.options[i].value === 'No Show') {
                    styleEl.options[i].disabled = false;
                }
            }
            if (type === 'edit' || type === 'checkin') {
                syncDeskBalanceDisplay(type);
            }
            return;
        }

        for (let i = 0; i < styleEl.options.length; i++) {
            const opt = styleEl.options[i];
            const val = opt.value;
            let isDisabled = false;

            if (val.indexOf('Genesis') !== -1 && currentDiv !== 'Cub (8 - 11)') isDisabled = true;

            const isFSPro = val.indexOf('FS Pro') !== -1;
            const isFSL   = val.indexOf('FSL –') !== -1;
            const isTRAD  = val.indexOf('TRAD') !== -1 || val.toLowerCase().indexOf('traditional') !== -1;

            if (isFSPro || isFSL || isTRAD) {
                if (!['Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)'].includes(currentDiv)) isDisabled = true;
            }

            const isLB = val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1;
            if (isLB && currentDiv !== 'Adult (18 - 49)') isDisabled = true;

            opt.disabled = isDisabled;
            if (isDisabled && styleEl.value === val) styleEl.value = 'FS – Freestyle';
        }

        if (type === 'edit' || type === 'checkin') {
            syncDeskBalanceDisplay(type);
        }
    }

    /**
     * Style Change Event Handler (Applies Force-Division Rules & Re-Evaluates Allowed Styles)
     */
    function handleStyleChange(type) {
        const divisionEl = document.getElementById(type + '_division');
        const styleEl    = document.getElementById(type + '_style');
        if (!divisionEl || !styleEl) return;

        const val = styleEl.value || '';
        if (!val || val === 'No Show') return;

        const prevDiv = divisionEl.value;

        if (window.SYWAT && window.SYWAT.shootingStyles && typeof window.SYWAT.shootingStyles.applyStyleRules === 'function') {
            window.SYWAT.shootingStyles.applyStyleRules(styleEl, divisionEl, { trigger: 'style' });
        } else {
            if (val.indexOf('Genesis') !== -1) {
                divisionEl.value = 'Cub (8 - 11)';
            } else if (val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1) {
                divisionEl.value = 'Adult (18 - 49)';
            }
        }

        if (divisionEl.value !== prevDiv) {
            handleStyleRestrictions(type);
        } else if (type === 'edit' || type === 'checkin') {
            syncDeskBalanceDisplay(type);
        }
    }

    function handleWalkinGroupInput(input) {
        if (!input) return;
        const archerNameInput = document.getElementById('walkin_name_input');
        const prevVal = input.getAttribute('data-prev-val') || '';

        if (archerNameInput) {
            if (!archerNameInput.value || archerNameInput.value === prevVal) {
                delete archerNameInput.dataset.normalizedFrom;
                archerNameInput.value = input.value;
                autoFillOnNameChange('walkin', false);
            }
        }
        input.setAttribute('data-prev-val', input.value);
    }

    /**
     * Filter Active Registered Archers Table Rows by Line & Search Query instantaneously
     * without a full-page reload, and keep hidden form inputs synchronized.
     */
    function applyDeskLineAndSearchFilter() {
        const lineSelect   = document.getElementById('sywat_selected_line_idx');
        const searchInput  = document.getElementById('sywat_search_input');
        const selectedLine = lineSelect ? String(lineSelect.value || 'all') : 'all';
        const rawQuery     = searchInput ? String(searchInput.value || '') : '';
        const cleanQuery   = rawQuery.trim().toLowerCase();
        const rows         = document.querySelectorAll('#sywat_desk_archers_table .sywat-archer-row');

        let visibleCount = 0;

        rows.forEach(function(row) {
            const rowLine    = row.getAttribute('data-line-idx') || '';
            const archerName = row.getAttribute('data-name') || '';
            const lineOk     = (!selectedLine || selectedLine === 'all' || rowLine === selectedLine);
            const queryOk    = (!cleanQuery || archerName.includes(cleanQuery));

            if (lineOk && queryOk) {
                row.classList.remove('sywat-hidden');
                visibleCount++;
            } else {
                row.classList.add('sywat-hidden');
            }
        });

        const countEl = document.getElementById('sywat_desk_visible_count');
        if (countEl) {
            countEl.textContent = String(visibleCount);
        }

        const emptyMsg = document.getElementById('sywat_desk_empty_filter_msg');
        if (emptyMsg) {
            if (visibleCount === 0) {
                emptyMsg.classList.remove('sywat-hidden');
            } else {
                emptyMsg.classList.add('sywat-hidden');
            }
        }

        document.querySelectorAll('.sywat-desk-hidden-line').forEach(function(hiddenLine) {
            hiddenLine.value = selectedLine || 'all';
        });

        document.querySelectorAll('.sywat-hidden-search').forEach(function(hiddenInput) {
            hiddenInput.value = rawQuery;
        });

        if (rawQuery) {
            sessionStorage.setItem('sywat_checkin_search_query', rawQuery);
        } else {
            sessionStorage.removeItem('sywat_checkin_search_query');
        }
    }

    function applyDeskSearchFilter(query) {
        const searchInput = document.getElementById('sywat_search_input');
        if (searchInput && query !== undefined) {
            searchInput.value = query;
        }
        applyDeskLineAndSearchFilter();
    }

    /**
     * Parse serialized archer JSON payload from a table row element.
     */
    function parseRowArcherPayload(triggerEl) {
        if (!triggerEl) return null;
        const row = triggerEl.closest('.sywat-archer-row');
        if (!row) return null;
        const rawJson = row.getAttribute('data-archer');
        if (!rawJson) return null;
        try {
            return JSON.parse(rawJson);
        } catch (err) {
            return null;
        }
    }

    // Namespaced Module API & Global Backward-Compatibility Exports
    window.SYWAT.checkin = {
        updateWalkinFamilyOptions: updateWalkinFamilyOptions,
        updateEditFamilyOptions: updateEditFamilyOptions,
        toggleUnpaidBanner: toggleUnpaidBanner,
        syncDeskBalanceDisplay: syncDeskBalanceDisplay,
        handleStyleRestrictions: handleStyleRestrictions,
        handleStyleChange: handleStyleChange,
        evaluateTfaaStatus: evaluateTfaaStatus,
        isWalkinPromoEligible: isWalkinPromoEligible,
        openCheckinModal: openDeskCheckinModal,
        closeCheckinModal: closeDeskCheckinModal,
        openEditModal: openDeskEditModal,
        closeEditModal: closeDeskEditModal,
        openWalkinModal: openWalkinModal,
        closeWalkinModal: closeWalkinModal,
        continueFamilyLoop: continueFamilyLoop,
        applyLineAndSearchFilter: applyDeskLineAndSearchFilter,
        applySearchFilter: applyDeskSearchFilter
    };

    window.updateWalkinFamilyOptions  = updateWalkinFamilyOptions;
    window.updateEditFamilyOptions    = updateEditFamilyOptions;
    window.toggleUnpaidBanner         = toggleUnpaidBanner;
    window.loadTfaaCategory           = loadTfaaCategory;
    window.updateNameToOfficial       = updateNameToOfficial;
    window.evaluateTfaaStatus         = evaluateTfaaStatus;
    window.closeWalkinModal           = closeWalkinModal;
    window.openWalkinModal            = openWalkinModal;
    window.toggleFamilyBox            = toggleFamilyBox;
    window.continueFamilyLoop         = continueFamilyLoop;
    window.handleStyleRestrictions    = handleStyleRestrictions;
    window.handleStyleChange          = handleStyleChange;
    window.handleWalkinGroupInput     = handleWalkinGroupInput;
    window.handleTfaaAutocomplete     = handleDeskTfaaAutocomplete;
    if (typeof window.formatPhoneNumber !== 'function') {
        window.formatPhoneNumber = formatDeskPhoneNumber;
    }

    /**
     * Initializes Check-In Desk event listeners and pre-fills active forms.
     */
    function initSywatCheckin() {
        const editBalanceEl = document.getElementById('edit_balance_due');
        if (editBalanceEl && typeof editBalanceEl.dataset.origDue === 'undefined') {
            editBalanceEl.dataset.origDue = editBalanceEl.value || '0';
        }

        const walkinGroupName = document.getElementById('walkin_group_name');
        if (walkinGroupName) {
            walkinGroupName.setAttribute('data-prev-val', walkinGroupName.value || '');
            walkinGroupName.addEventListener('input', function() {
                handleWalkinGroupInput(this);
            });
        }

        const walkinInput = document.getElementById('walkin_name_input');
        if (walkinInput) {
            walkinInput.addEventListener('input', function() {
                delete this.dataset.normalizedFrom;
                autoFillOnNameChange('walkin', false);
            });
        }

        const walkinCourtesyCb = document.querySelector('#sywat_walkin_form input[name="walkin_is_courtesy"]');
        if (walkinCourtesyCb) {
            walkinCourtesyCb.addEventListener('change', function() {
                evaluateTfaaStatus('walkin');
            });
        }

        const checkinArcherNameEl = document.getElementById('checkin_archer_name');
        if (checkinArcherNameEl) {
            checkinArcherNameEl.addEventListener('input', function() {
                delete this.dataset.normalizedFrom;
                autoFillOnNameChange('checkin', false);
            });
            if (checkinArcherNameEl.value.trim() !== '') {
                autoFillOnNameChange('checkin', true);
                toggleFamilyBox('checkin');
            }
        }

        const searchInput = document.getElementById('sywat_search_input');
        if (searchInput) {
            if (!searchInput.value) {
                const savedQuery = sessionStorage.getItem('sywat_checkin_search_query');
                if (savedQuery) {
                    searchInput.value = savedQuery;
                }
            }

            searchInput.addEventListener('input', function() {
                applyDeskLineAndSearchFilter();
            });
        }

        applyDeskLineAndSearchFilter();

        if (document.getElementById('walkin_division')) handleStyleRestrictions('walkin');
        if (document.getElementById('checkin_division')) handleStyleRestrictions('checkin');
        if (document.getElementById('edit_division')) handleStyleRestrictions('edit');

        // Auto-dismiss standard top-level notices after 4 seconds
        setTimeout(function() {
            const notices = document.querySelectorAll('.sywat-wrap .sywat-alert, .sywat-wrap .sywat-notice, .sywat-wrap .notice');
            notices.forEach(function(notice) {
                if (notice.closest('.sywat-modal-backdrop')) {
                    return;
                }
                notice.classList.add('sywat-dismissing');
                setTimeout(function() {
                    notice.classList.add('sywat-hidden');
                }, 500);
            });
        }, 4000);
    }

    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSywatCheckin);
    } else {
        initSywatCheckin();
    }

    // Delegated Click Listener for Optimizer-Safe Actions (Zero Inline onclick)
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.sywat-desk-tfaa-dropdown')) {
            closeAllDeskTfaaAutocompletes();
        }

        const actionEl = e.target.closest('[data-sywat-action]');
        if (!actionEl) return;

        const action = actionEl.getAttribute('data-sywat-action');

        if (action === 'open-desk-checkin-modal') {
            e.preventDefault();
            const archer = parseRowArcherPayload(actionEl);
            if (archer) {
                openDeskCheckinModal(archer);
            }
            return;
        }

        if (action === 'close-desk-checkin-modal') {
            e.preventDefault();
            closeDeskCheckinModal();
            return;
        }

        if (action === 'open-desk-edit-modal') {
            e.preventDefault();
            const archer = parseRowArcherPayload(actionEl);
            if (archer) {
                openDeskEditModal(archer);
            }
            return;
        }

        if (action === 'close-desk-edit-modal') {
            e.preventDefault();
            closeDeskEditModal();
            return;
        }

        if (action === 'open-desk-walkin-modal') {
            e.preventDefault();
            openWalkinModal();
            return;
        }

        if (action === 'close-desk-walkin-modal') {
            e.preventDefault();
            closeWalkinModal();
            return;
        }

        if (action === 'select-desk-line') {
            e.preventDefault();
            const targetLineIdx = actionEl.getAttribute('data-line-idx') || 'all';
            const lineSelect    = document.getElementById('sywat_selected_line_idx');
            if (lineSelect) {
                lineSelect.value = targetLineIdx;
            }
            applyDeskLineAndSearchFilter();
            const tableEl = document.getElementById('sywat_desk_archers_table');
            if (tableEl && typeof tableEl.scrollIntoView === 'function') {
                tableEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            return;
        }

        if (action === 'clear-desk-search') {
            e.preventDefault();
            applyDeskSearchFilter('');
            return;
        }

        if (action === 'continue-family-loop') {
            e.preventDefault();
            const famId   = actionEl.getAttribute('data-family-id') || '';
            const lineIdx = actionEl.getAttribute('data-line-idx') || '0';
            continueFamilyLoop(famId, lineIdx);
            return;
        }

        if (action === 'dismiss-family-banner') {
            e.preventDefault();
            const banner = actionEl.closest('.sywat-family-banner');
            if (banner) {
                banner.remove();
            }
            return;
        }

        if (action === 'use-official-desk-name') {
            e.preventDefault();
            const prefix       = actionEl.getAttribute('data-prefix') || 'checkin';
            const officialName = actionEl.getAttribute('data-official-name') || '';
            updateNameToOfficial(prefix, officialName);
            return;
        }

        if (action === 'confirm-delete-archer') {
            if (!window.confirm('⚠️ Are you sure you want to PERMANENTLY delete this archer?')) {
                e.preventDefault();
            }
        }
    });

    // Delegated Change Listener for Selects & Checkboxes (Zero Inline onchange)
    document.addEventListener('change', function(e) {
        if (!e.target) return;
        const action = e.target.getAttribute('data-sywat-action') || '';

        if (action === 'submit-desk-tournament') {
            const filterForm = document.getElementById('sywat_filter_form');
            if (filterForm) {
                filterForm.submit();
            }
            return;
        }

        if (action === 'filter-desk-line') {
            applyDeskLineAndSearchFilter();
            return;
        }

        if (action === 'toggle-unpaid-banner') {
            toggleUnpaidBanner(!!e.target.checked);
            return;
        }

        if (action === 'sync-checkin-balance' || e.target.id === 'checkin_pricing_profile') {
            syncDeskBalanceDisplay('checkin');
            return;
        }

        if (action === 'checkin-division-change') {
            handleStyleRestrictions('checkin');
            evaluateTfaaStatus('checkin');
            return;
        }

        if (action === 'checkin-gender-change') {
            evaluateTfaaStatus('checkin');
            return;
        }

        if (action === 'checkin-style-change') {
            handleStyleChange('checkin');
            evaluateTfaaStatus('checkin');
            return;
        }

        if (action === 'checkin-pay-change') {
            toggleFamilyBox('checkin');
            return;
        }

        if (action === 'sync-edit-balance' || e.target.id === 'edit_pricing_profile') {
            syncDeskBalanceDisplay('edit');
            return;
        }

        if (action === 'edit-line-change') {
            updateEditFamilyOptions(e.target.value);
            return;
        }

        if (action === 'edit-division-change') {
            handleStyleRestrictions('edit');
            return;
        }

        if (action === 'edit-style-change') {
            handleStyleChange('edit');
            return;
        }

        if (action === 'edit-pay-change') {
            toggleFamilyBox('edit');
            return;
        }

        if (action === 'walkin-line-change') {
            updateWalkinFamilyOptions(e.target.value);
            return;
        }

        if (action === 'walkin-round-change') {
            evaluateTfaaStatus('walkin');
            return;
        }

        if (action === 'walkin-division-change') {
            handleStyleRestrictions('walkin');
            evaluateTfaaStatus('walkin');
            return;
        }

        if (action === 'walkin-gender-change') {
            evaluateTfaaStatus('walkin');
            return;
        }

        if (action === 'walkin-style-change') {
            handleStyleChange('walkin');
            evaluateTfaaStatus('walkin');
        }
    });

    // Delegated Input Listener for Autocomplete, Phone Formatting & Balance Overrides
    document.addEventListener('input', function(e) {
        if (!e.target) return;

        if (e.target.id === 'edit_balance_due') {
            e.target.dataset.manualOverride = '1';
            return;
        }

        if (e.target.getAttribute('data-sywat-action') === 'format-desk-phone' || e.target.id === 'walkin_group_phone') {
            formatDeskPhoneNumber(e.target);
            return;
        }

        if (
            e.target.id === 'walkin_name_input' ||
            e.target.id === 'checkin_archer_name' ||
            e.target.id === 'edit_archer_name' ||
            e.target.name === 'edit_name' ||
            e.target.name === 'walkin_name' ||
            e.target.name === 'checkin_name' ||
            e.target.id === 'walkin_group_name' ||
            e.target.name === 'walkin_group_name'
        ) {
            handleDeskTfaaAutocomplete(e.target);
        }
    });

})();
