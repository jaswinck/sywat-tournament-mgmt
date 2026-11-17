/**
 * SYWAT Archery - Registration Intake & Client Logic Engine
 * Path: assets/js/sywat-registration.js
 * Description: Manages multi-archer phone intake rows, round/pricing profile fee synchronization, live First-Time/Lapsed Shooter promo detection, database-driven age division & distance synchronization, dynamic Household Family Cap calculation (line or tournament scope), TFAA historical shooter autocomplete, and the dedicated Bulk Spreadsheet Import modal (#sywat_import_modal).
 * Compliance: Vanilla JS only (zero jQuery), namespaced window.SYWAT.registration controller, delegated data-sywat-action listeners, zero element.style.* mutations, class-based visibility (.active / .sywat-hidden).
 * Version: 7.3.0
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    let archerRowCount = 1;

    const DEFAULT_DIVISIONS = [
        { slug: 'cub-8-11',            code: 'C',  label: 'Cub (8 - 11)',           distance: 'Cub',  rate_tier: 'Youth', fee: 25, aliases: ['Cub'] },
        { slug: 'youth-12-14',         code: 'Y',  label: 'Youth (12 - 14)',        distance: '20yd', rate_tier: 'Youth', fee: 25, aliases: ['Youth'] },
        { slug: 'young-adult-15-17',   code: 'YA', label: 'Young Adult (15 - 17)',  distance: '20yd', rate_tier: 'Youth', fee: 25, aliases: ['Young Adult'] },
        { slug: 'adult-18-49',         code: 'A',  label: 'Adult (18 - 49)',        distance: '20yd', rate_tier: 'Adult', fee: 30, aliases: ['Adult'] },
        { slug: 'senior-50-59',        code: 'S',  label: 'Senior (50 - 59)',       distance: '20yd', rate_tier: 'Adult', fee: 30, aliases: ['Senior'] },
        { slug: 'silver-senior-60-69', code: 'SS', label: 'Silver Senior (60 - 69)',distance: '20yd', rate_tier: 'Adult', fee: 30, aliases: ['Silver Senior'] },
        { slug: 'master-senior-70',    code: 'MS', label: 'Master Senior (70+)',    distance: '20yd', rate_tier: 'Adult', fee: 30, aliases: ['Master Senior', 'Master'] }
    ];

    function cleanImportModalQueryParam() {
        if (!window.history || typeof window.history.replaceState !== 'function') {
            return;
        }
        try {
            const url = new URL(window.location.href);
            if (url.searchParams.has('open_import_modal')) {
                url.searchParams.delete('open_import_modal');
                window.history.replaceState({}, document.title, url.toString());
            }
        } catch (err) {
            // Ignore malformed URL edge cases
        }
    }

    function openImportModal() {
        const modal = document.getElementById('sywat_import_modal');
        if (!modal) {
            return;
        }
        modal.classList.add('active');
        const fileInput = modal.querySelector('input[type="file"]');
        if (fileInput && typeof fileInput.focus === 'function') {
            setTimeout(function() {
                fileInput.focus();
            }, 30);
        }
    }

    function closeImportModal() {
        const modal = document.getElementById('sywat_import_modal');
        if (modal) {
            modal.classList.remove('active');
        }
        cleanImportModalQueryParam();
    }

    window.SYWAT.registration = {
        openImportModal: openImportModal,
        closeImportModal: closeImportModal
    };

    function getTfaaShooters() {
        if (window.sywatRegData && Array.isArray(window.sywatRegData.tfaaShooters)) {
            return window.sywatRegData.tfaaShooters;
        }
        if (typeof sywatTfaaShooters !== 'undefined' && Array.isArray(sywatTfaaShooters)) {
            return sywatTfaaShooters;
        }
        return [];
    }

    function getPhoneCache() {
        if (window.sywatRegData && typeof window.sywatRegData.phoneCache === 'object' && window.sywatRegData.phoneCache !== null) {
            return window.sywatRegData.phoneCache;
        }
        if (typeof sywatPhoneCache !== 'undefined' && typeof sywatPhoneCache === 'object' && sywatPhoneCache !== null) {
            return sywatPhoneCache;
        }
        return {};
    }

    function getProfiles() {
        if (window.sywatRegData && typeof window.sywatRegData.profiles === 'object' && window.sywatRegData.profiles !== null) {
            return window.sywatRegData.profiles;
        }
        if (window.SYWAT && window.SYWAT.pricing && typeof window.SYWAT.pricing.getProfiles === 'function') {
            return window.SYWAT.pricing.getProfiles();
        }
        return {};
    }

    function getDefaultProfileKey() {
        if (window.sywatRegData && window.sywatRegData.defaultProfile) {
            return String(window.sywatRegData.defaultProfile);
        }
        const container = document.getElementById('sywat_archer_rows_container');
        if (container && container.getAttribute('data-default-profile')) {
            return String(container.getAttribute('data-default-profile'));
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

    function getPromoRules() {
        if (window.sywatRegData && typeof window.sywatRegData.promoRules === 'object' && window.sywatRegData.promoRules !== null) {
            return window.sywatRegData.promoRules;
        }
        return {};
    }

    function isArcherPromoEligible(archerName, profileSlug) {
        const cleanName = String(archerName || '').trim().toLowerCase();
        if (!cleanName) {
            return false;
        }
        const rules = getPromoRules();
        const slug = String(profileSlug || getDefaultProfileKey()).trim();
        const rule = rules[slug];
        if (!rule || !rule.active) {
            return false;
        }
        if (rule.ineligible && Object.prototype.hasOwnProperty.call(rule.ineligible, cleanName)) {
            return false;
        }
        return true;
    }

    function getDivisions() {
        if (window.sywatRegData && Array.isArray(window.sywatRegData.divisions) && window.sywatRegData.divisions.length > 0) {
            return window.sywatRegData.divisions;
        }
        return DEFAULT_DIVISIONS;
    }

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
     * Resolve individual entry fee for a specific round profile + division + distance.
     */
    function getFeeForRow(profileSlug, divSlug, divLabel, distance) {
        const prof = getProfile(profileSlug);
        const divCfg = divLabel ? findDivisionConfig(divLabel) : null;
        const resolvedSlug = divSlug || (divCfg && divCfg.slug ? divCfg.slug : '');

        if (prof) {
            if (
                resolvedSlug &&
                prof.division_overrides &&
                typeof prof.division_overrides[resolvedSlug] !== 'undefined' &&
                prof.division_overrides[resolvedSlug] !== ''
            ) {
                const ovVal = parseFloat(prof.division_overrides[resolvedSlug]);
                if (!Number.isNaN(ovVal) && ovVal >= 0) {
                    return ovVal;
                }
            }

            const isYouth = (distance === 'Cub') || (divCfg && divCfg.rate_tier === 'Youth');
            const tierRate = isYouth ? parseFloat(prof.youth_rate) : parseFloat(prof.adult_rate);
            if (!Number.isNaN(tierRate) && tierRate >= 0) {
                return tierRate;
            }
        }

        if (divCfg && typeof divCfg.fee !== 'undefined' && !Number.isNaN(parseFloat(divCfg.fee))) {
            return parseFloat(divCfg.fee);
        }

        return (distance === 'Cub' || (divCfg && divCfg.rate_tier === 'Youth')) ? 25 : 30;
    }

    /**
     * Update division option labels and data-fee attributes on a single intake row
     * whenever its Round Format selector changes (e.g., Field vs. Field 50).
     */
    function updateRowDivisionOptionLabels(row) {
        if (!row) return;
        const roundSelect = row.querySelector('.sywat-round-profile-select');
        const roundInput  = row.querySelector('.sywat-round-profile-input');
        const divSelect   = row.querySelector('.sywat-division-select');
        if (!divSelect) return;

        const roundSlug = roundSelect
            ? roundSelect.value
            : (roundInput ? roundInput.value : getDefaultProfileKey());

        Array.from(divSelect.options).forEach(opt => {
            if (!opt.value) return;
            const optSlug = opt.getAttribute('data-slug') || '';
            const optDist = opt.getAttribute('data-distance') || '20yd';
            const fee     = getFeeForRow(roundSlug, optSlug, opt.value, optDist);
            opt.setAttribute('data-fee', fee.toFixed(2));
            opt.textContent = opt.value + ' [$' + fee.toFixed(2) + ']';
        });
    }

    function getDefaultCubDivisionLabel() {
        const divisions = getDivisions();
        for (let i = 0; i < divisions.length; i++) {
            if (divisions[i].distance === 'Cub' && divisions[i].label) {
                return divisions[i].label;
            }
        }
        return 'Cub (8 - 11)';
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    window.checkAndFillPhone = function(archerName) {
        if (!archerName) return;
        const phoneCache = getPhoneCache();
        const cleanName  = archerName.trim().toLowerCase();
        const phoneInput = document.getElementById('sywat_group_phone');

        if (phoneInput && !phoneInput.value && phoneCache[cleanName]) {
            phoneInput.value = phoneCache[cleanName];
            window.formatPhoneNumber(phoneInput);
        }
    };

    window.handleCallerNameInput = function(callerInput) {
        const firstArcherInput = document.getElementById('sywat_first_archer_name') || document.querySelector('.sywat-archer-name-input');
        if (!firstArcherInput) return;

        if (!firstArcherInput.dataset.manuallyEdited) {
            firstArcherInput.value = callerInput.value;
            window.handleTfaaAutocomplete(firstArcherInput);
            window.recalculateGroupTotal();
        }
        window.checkAndFillPhone(callerInput.value);
    };

    window.handleArcherNameInput = function(archerInput) {
        const callerInput = document.getElementById('sywat_group_name');
        if (callerInput && archerInput.id === 'sywat_first_archer_name') {
            if (archerInput.value !== callerInput.value && archerInput.value !== '') {
                archerInput.dataset.manuallyEdited = 'true';
            } else if (archerInput.value === '') {
                delete archerInput.dataset.manuallyEdited;
            }
        }
        window.handleTfaaAutocomplete(archerInput);
        window.recalculateGroupTotal();
        window.checkAndFillPhone(archerInput.value);
    };

    window.handleDivisionChange = function(divSelect) {
        if (!divSelect) return;
        divSelect.dataset.userChosen = 'true';

        const row = divSelect.closest('.sywat-archer-input-row');
        if (row) {
            const distSelect = row.querySelector('.sywat-distance-select');
            if (distSelect) {
                const selectedDiv  = divSelect.value;
                const selectedOpt  = divSelect.options[divSelect.selectedIndex];
                const optDist      = selectedOpt ? selectedOpt.getAttribute('data-distance') : '';
                const divCfg       = optDist ? null : findDivisionConfig(selectedDiv);
                const resolvedDist = optDist || (divCfg ? divCfg.distance : '');

                if (resolvedDist === 'Cub' || (resolvedDist === '' && selectedDiv && selectedDiv.includes('Cub'))) {
                    distSelect.value = 'Cub';
                } else if (selectedDiv !== '') {
                    distSelect.value = '20yd';
                }
            }
        }

        window.recalculateGroupTotal();
    };

    window.handleDistanceChange = function(distSelect) {
        const row = distSelect.closest('.sywat-archer-input-row');
        if (!row) return;
        const divSelect = row.querySelector('.sywat-division-select');
        if (!divSelect) return;

        const defaultCubLabel = getDefaultCubDivisionLabel();

        if (distSelect.value === 'Cub') {
            divSelect.value = defaultCubLabel;
            divSelect.dataset.userChosen = 'true';
        } else if (distSelect.value === '20yd') {
            const selectedOpt  = divSelect.options[divSelect.selectedIndex];
            const optDist      = selectedOpt ? selectedOpt.getAttribute('data-distance') : '';
            const divCfg       = optDist ? null : findDivisionConfig(divSelect.value);
            const resolvedDist = optDist || (divCfg ? divCfg.distance : '');

            if (resolvedDist === 'Cub' || divSelect.value === defaultCubLabel) {
                divSelect.value = '';
                delete divSelect.dataset.userChosen;
            }
        }
        window.recalculateGroupTotal();
    };

    window.matchTfaaDivision = function(tfaaDiv, selectEl) {
        if (!tfaaDiv || !selectEl) return;
        const cleanTfaa = tfaaDiv.trim().toLowerCase();

        // 1. Direct option value match
        for (let opt of selectEl.options) {
            if (opt.value && opt.value.trim().toLowerCase() === cleanTfaa) {
                selectEl.value = opt.value;
                window.handleDivisionChange(selectEl);
                return;
            }
        }

        // 2. Match via configured division aliases or export code
        const matchedCfg = findDivisionConfig(tfaaDiv);
        if (matchedCfg && matchedCfg.label) {
            for (let opt of selectEl.options) {
                if (opt.value && opt.value.trim().toLowerCase() === matchedCfg.label.trim().toLowerCase()) {
                    selectEl.value = opt.value;
                    window.handleDivisionChange(selectEl);
                    return;
                }
            }
        }

        // 3. Base-name fuzzy fallback
        const tfaaBaseName = cleanTfaa.split(' (')[0].trim();

        for (let opt of selectEl.options) {
            if (!opt.value) continue;
            const optLower = opt.value.trim().toLowerCase();
            const optBaseName = optLower.split(' (')[0].trim();

            if (optBaseName === tfaaBaseName || optLower.includes(cleanTfaa) || cleanTfaa.includes(optBaseName)) {
                selectEl.value = opt.value;
                window.handleDivisionChange(selectEl);
                return;
            }
        }
    };

    /**
     * Registration Autocomplete: Auto-fills Name and Age Division ONLY.
     */
    window.handleTfaaAutocomplete = function(input) {
        const query = input.value.trim().toLowerCase();
        window.closeAllTfaaAutocompletes();

        const shooters = getTfaaShooters();
        if (query.length < 2 || !Array.isArray(shooters) || shooters.length === 0) {
            return;
        }

        // Filter unique names for intake autocomplete
        const seenNames = new Set();
        const matches = [];

        shooters.forEach(s => {
            if (s.name && s.name.toLowerCase().includes(query)) {
                const norm = s.name.trim().toLowerCase();
                if (!seenNames.has(norm)) {
                    seenNames.add(norm);
                    matches.push(s);
                }
            }
        });

        if (matches.length === 0) return;

        const parent = input.parentElement;
        if (getComputedStyle(parent).position === 'static') {
            parent.classList.add('sywat-relative-parent');
        }

        const dropdown = document.createElement('div');
        dropdown.className = 'sywat-tfaa-autocomplete-dropdown';

        matches.slice(0, 8).forEach(m => {
            const item = document.createElement('div');
            item.className = 'sywat-tfaa-item';

            item.innerHTML = `<strong>${escapeHtml(m.name)}</strong> <span class="sywat-tfaa-item-badge">${escapeHtml(m.division || '')}</span>`;

            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                input.value = m.name;
                window.closeAllTfaaAutocompletes();

                const callerInput = document.getElementById('sywat_group_name');
                if (callerInput && (!callerInput.value.trim() || input.id === 'sywat_first_archer_name')) {
                    callerInput.value = m.name;
                }

                const row = input.closest('.sywat-archer-input-row');
                if (row) {
                    const divSelect = row.querySelector('.sywat-division-select');
                    if (divSelect && m.division) {
                        window.matchTfaaDivision(m.division, divSelect);
                    }
                }
                window.checkAndFillPhone(m.name);
                window.recalculateGroupTotal();
            });

            dropdown.appendChild(item);
        });

        parent.appendChild(dropdown);
    };

    window.closeAllTfaaAutocompletes = function() {
        document.querySelectorAll('.sywat-tfaa-autocomplete-dropdown').forEach(el => el.remove());
    };

    window.formatPhoneNumber = function(input) {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 10) digits = digits.substring(0, 10);

        if (digits.length > 6) {
            input.value = digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6);
        } else if (digits.length > 3) {
            input.value = digits.substring(0, 3) + '-' + digits.substring(3);
        } else {
            input.value = digits;
        }
    };

    window.updateAllArcherLines = function(selectedLineIdx) {
        if (selectedLineIdx !== undefined && selectedLineIdx !== null && selectedLineIdx !== '') {
            sessionStorage.setItem('sywat_last_line_idx', selectedLineIdx);
        }
        const lineSelects = document.querySelectorAll('.sywat-line-override-select');
        lineSelects.forEach(select => {
            select.value = selectedLineIdx;
        });
        window.recalculateGroupTotal();
    };

    window.addArcherRow = function() {
        const container = document.getElementById('sywat_archer_rows_container');
        if (!container) return;

        const existingRows = container.querySelectorAll('.sywat-archer-input-row');
        const rowIndex     = existingRows.length > 0 ? existingRows.length : archerRowCount;
        const isMultiRound = container.getAttribute('data-multi-round') === '1';
        const defProfKey   = getDefaultProfileKey();

        const savedLineIdx = sessionStorage.getItem('sywat_last_line_idx');
        const defaultLineSelect = document.getElementById('sywat_default_line_idx');
        const defaultLineIdx = savedLineIdx !== null
            ? savedLineIdx
            : (defaultLineSelect ? defaultLineSelect.value : '0');

        let lineOptionsHtml = '';
        if (defaultLineSelect) {
            Array.from(defaultLineSelect.options).forEach(opt => {
                const isSel = (opt.value === defaultLineIdx) ? 'selected' : '';
                lineOptionsHtml += `<option value="${escapeHtml(opt.value)}" ${isSel}>${escapeHtml(opt.text)}</option>`;
            });
        }

        let roundControlHtml = '';
        if (isMultiRound) {
            const profiles = getProfiles();
            let roundOptionsHtml = '';
            Object.keys(profiles).forEach(pSlug => {
                const pRec    = profiles[pSlug];
                const isSel   = (pSlug === defProfKey) ? 'selected' : '';
                const optText = pRec.label + (pRec.round_subtitle ? ' (' + pRec.round_subtitle + ')' : '');
                roundOptionsHtml += `<option value="${escapeHtml(pSlug)}" ${isSel}>${escapeHtml(optText)}</option>`;
            });
            roundControlHtml = `
                <div class="sywat-field-group sywat-col-archer-dist">
                    <select name="archers[${rowIndex}][pricing_profile]" class="sywat-round-profile-select" onchange="recalculateGroupTotal()">
                        ${roundOptionsHtml}
                    </select>
                </div>
            `;
        } else {
            roundControlHtml = `<input type="hidden" name="archers[${rowIndex}][pricing_profile]" class="sywat-round-profile-input" value="${escapeHtml(defProfKey)}">`;
        }

        let divisionOptionsHtml = '<option value="" disabled selected hidden>Select Division</option>';
        getDivisions().forEach(d => {
            const dist     = d.distance === 'Cub' ? 'Cub' : '20yd';
            const rateTier = d.rate_tier === 'Youth' ? 'Youth' : 'Adult';
            const divSlug  = d.slug || '';
            const fee      = getFeeForRow(defProfKey, divSlug, d.label, dist);
            divisionOptionsHtml += `<option value="${escapeHtml(d.label)}" data-slug="${escapeHtml(divSlug)}" data-distance="${escapeHtml(dist)}" data-rate-tier="${escapeHtml(rateTier)}" data-fee="${fee.toFixed(2)}">${escapeHtml(d.label)} [$${fee.toFixed(2)}]</option>`;
        });

        const rowDiv = document.createElement('div');
        rowDiv.className = 'sywat-archer-input-row';

        rowDiv.innerHTML = `
            <div class="sywat-field-group sywat-col-archer-name">
                <input type="text" name="archers[${rowIndex}][name]" class="sywat-archer-name-input" placeholder="Archer Full Name *" autocomplete="off" required oninput="handleArcherNameInput(this); recalculateGroupTotal();">
            </div>

            ${roundControlHtml}

            <div class="sywat-field-group sywat-col-archer-dist">
                <select name="archers[${rowIndex}][distance]" class="sywat-distance-select" onchange="handleDistanceChange(this)">
                    <option value="20yd" selected>20yd</option>
                    <option value="Cub">Cub (10yd)</option>
                </select>
            </div>

            <div class="sywat-field-group sywat-col-archer-div">
                <select name="archers[${rowIndex}][division]" class="sywat-division-select" onchange="handleDivisionChange(this);">
                    ${divisionOptionsHtml}
                </select>
            </div>

            <div class="sywat-field-group sywat-col-archer-line">
                <select name="archers[${rowIndex}][line_idx]" class="sywat-line-override-select" onchange="recalculateGroupTotal()">
                    ${lineOptionsHtml}
                </select>
            </div>

            <div class="sywat-col-archer-action">
                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" onclick="removeArcherRow(this)" title="Remove Archer" aria-label="Remove Archer"></button>
            </div>
        `;

        container.appendChild(rowDiv);
        archerRowCount++;
        window.recalculateGroupTotal();
    };

    window.removeArcherRow = function(btn) {
        const rows = document.querySelectorAll('.sywat-archer-input-row');
        if (rows.length <= 1) {
            alert('At least one archer row is required.');
            return;
        }
        btn.closest('.sywat-archer-input-row').remove();
        window.recalculateGroupTotal();
    };

    window.recalculateGroupTotal = function() {
        const rows      = document.querySelectorAll('.sywat-archer-input-row');
        const profiles  = getProfiles();
        const defSlug   = getDefaultProfileKey();
        const bucketMap = {};

        // Check if any offered pricing profile uses tournament-wide family caps
        let useTournWideCap = false;
        Object.keys(profiles).forEach(pSlug => {
            const p = profiles[pSlug];
            if (p && p.family_cap_enabled && p.family_cap_scope === 'tournament') {
                useTournWideCap = true;
            }
        });

        const claimedPromoNames = {};
        let promoAppliedCount   = 0;

        rows.forEach(row => {
            const nameInput   = row.querySelector('input[type="text"]');
            const roundSelect = row.querySelector('.sywat-round-profile-select');
            const roundInput  = row.querySelector('.sywat-round-profile-input');
            const distSelect  = row.querySelector('.sywat-distance-select');
            const divSelect   = row.querySelector('.sywat-division-select');
            const lineSelect  = row.querySelector('.sywat-line-override-select');

            if (nameInput && nameInput.value.trim() !== '') {
                const rawName   = nameInput.value.trim();
                const normName  = rawName.toLowerCase();
                const roundSlug = roundSelect
                    ? roundSelect.value
                    : (roundInput ? roundInput.value : defSlug);
                const lIdx      = lineSelect ? lineSelect.value : '0';
                const dist      = distSelect ? distSelect.value : '20yd';
                const div       = divSelect ? divSelect.value : '';
                const selectedOpt = (divSelect && divSelect.selectedIndex >= 0) ? divSelect.options[divSelect.selectedIndex] : null;
                const divSlug   = selectedOpt ? (selectedOpt.getAttribute('data-slug') || '') : '';

                let isPromoFree = false;
                if (!claimedPromoNames[normName] && isArcherPromoEligible(rawName, roundSlug)) {
                    isPromoFree = true;
                    claimedPromoNames[normName] = true;
                    promoAppliedCount++;
                }

                const archerFee = isPromoFree ? 0 : getFeeForRow(roundSlug, divSlug, div, dist);
                const profRec   = getProfile(roundSlug);
                const capBucket = useTournWideCap ? 'tournament' : String(lIdx);

                if (!isPromoFree) {
                    if (!bucketMap[capBucket]) {
                        bucketMap[capBucket] = {
                            count: 0,
                            rawTotal: 0,
                            capEnabled: false,
                            capAmount: 0,
                            minArchers: 99
                        };
                    }

                    bucketMap[capBucket].count++;
                    bucketMap[capBucket].rawTotal += archerFee;

                    if (profRec && profRec.family_cap_enabled) {
                        bucketMap[capBucket].capEnabled = true;
                        const pCap = parseFloat(profRec.family_cap_amount) || 0;
                        const pMin = parseInt(profRec.family_min_archers, 10) || 3;
                        if (pCap > bucketMap[capBucket].capAmount) {
                            bucketMap[capBucket].capAmount = pCap;
                        }
                        if (pMin < bucketMap[capBucket].minArchers) {
                            bucketMap[capBucket].minArchers = pMin;
                        }
                    }
                }
            }
        });

        let overallTotal       = 0;
        let anyCapped          = false;
        let cappedBucketsCount = 0;

        Object.keys(bucketMap).forEach(bKey => {
            const grp       = bucketMap[bKey];
            const minNeeded = (grp.minArchers && grp.minArchers !== 99) ? grp.minArchers : 3;
            const isCapped  = grp.capEnabled && grp.count >= minNeeded && grp.rawTotal > grp.capAmount;
            if (isCapped) {
                anyCapped = true;
                cappedBucketsCount++;
                overallTotal += grp.capAmount;
            } else {
                overallTotal += grp.rawTotal;
            }
        });

        const totalDisp = document.getElementById('sywat_group_total_disp');
        const capBadge  = document.getElementById('sywat_family_cap_badge');
        const paidInput = document.getElementById('sywat_amount_paid_today');
        const dueDisp   = document.getElementById('sywat_balance_due_disp');

        if (totalDisp) totalDisp.textContent = '$' + overallTotal.toFixed(2);
        if (capBadge) {
            if (anyCapped || promoAppliedCount > 0) {
                capBadge.classList.remove('sywat-hidden');
                const badgeParts = [];
                if (anyCapped) {
                    badgeParts.push(
                        useTournWideCap
                            ? '🟢 Family Cap Applied'
                            : '🟢 Family Cap Applied (' + cappedBucketsCount + ' Line' + (cappedBucketsCount > 1 ? 's' : '') + ')'
                    );
                }
                if (promoAppliedCount > 0) {
                    badgeParts.push('🎟️ Promo Free Entry ($0.00 × ' + promoAppliedCount + ')');
                }
                capBadge.textContent = badgeParts.join(' • ');
            } else {
                capBadge.classList.add('sywat-hidden');
            }
        }

        const paidVal    = paidInput ? parseFloat(paidInput.value) || 0 : 0;
        const balanceDue = Math.max(0, overallTotal - paidVal);

        if (dueDisp) dueDisp.textContent = '$' + balanceDue.toFixed(2);
    };

    window.initAutoDismissAlerts = function() {
        setTimeout(function() {
            const alerts = document.querySelectorAll('.sywat-alert, #sywat-notice, .sywat-modal-notice');
            alerts.forEach(function(alert) {
                if (alert.querySelector('form') || alert.querySelector('button') || (alert.textContent && alert.textContent.includes('OVERFLOW'))) {
                    return;
                }
                alert.classList.add('sywat-dismissing');
                setTimeout(function() { alert.remove(); }, 500);
            });
        }, 5000);
    };

    document.addEventListener('input', function(e) {
        if (e.target && e.target.name && e.target.name.includes('[name]')) {
            window.handleTfaaAutocomplete(e.target);
        }
    });

    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList && e.target.classList.contains('sywat-round-profile-select')) {
            const row = e.target.closest('.sywat-archer-input-row');
            updateRowDivisionOptionLabels(row);
            window.recalculateGroupTotal();
        }
    });

    document.addEventListener('click', function(e) {
        const actionTrigger = e.target.closest('[data-sywat-action]');
        if (actionTrigger) {
            const action = actionTrigger.getAttribute('data-sywat-action');
            if (action === 'open-import-modal') {
                e.preventDefault();
                openImportModal();
                return;
            }
            if (action === 'close-import-modal') {
                e.preventDefault();
                closeImportModal();
                return;
            }
            if (action === 'dismiss-overflow-modal') {
                e.preventDefault();
                const overflowModal = document.getElementById('sywat_capacity_overflow_modal');
                if (overflowModal) {
                    overflowModal.remove();
                }
                return;
            }
        }

        if (!e.target.closest('.sywat-tfaa-autocomplete-dropdown') && (!e.target.name || !e.target.name.includes('[name]'))) {
            window.closeAllTfaaAutocompletes();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const defaultLineSelect = document.getElementById('sywat_default_line_idx');
        if (defaultLineSelect) {
            const savedLineIdx = sessionStorage.getItem('sywat_last_line_idx');
            if (savedLineIdx !== null && defaultLineSelect.querySelector('option[value="' + savedLineIdx + '"]')) {
                defaultLineSelect.value = savedLineIdx;
                window.updateAllArcherLines(savedLineIdx);
            }
        }
        document.querySelectorAll('.sywat-archer-input-row').forEach(row => {
            updateRowDivisionOptionLabels(row);
        });
        window.recalculateGroupTotal();
        window.initAutoDismissAlerts();

        const importModal = document.getElementById('sywat_import_modal');
        if (importModal) {
            const params = new URLSearchParams(window.location.search);
            if (params.get('open_import_modal') === '1' || importModal.getAttribute('data-auto-open') === '1') {
                openImportModal();
                cleanImportModalQueryParam();
            }
        }
    });

})();
