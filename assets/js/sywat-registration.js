/**
 * SYWAT Archery - Registration Intake & Client Logic Engine
 * Path: assets/js/sywat-registration.js
 * Description: Manages multi-archer phone intake rows with per-archer multi-line checkbox matrices (zero pre-checked defaults, single-entry multi-line registration), simplified 3-tier Category / Rate selection (Cub, Youth, Adult), round/pricing profile fee synchronization, live First-Time/Lapsed Shooter promo detection, dynamic Household Family Cap calculation (line or tournament scope), pre-submit line-selection validation, unified Member Directory + 18-Month TFAA age-division & shooter autocomplete on both Caller / Primary Archer Name (#sywat_group_name) and every Archer Full Name row (.sywat-archer-name-input) with given-name alias & stem matching, and the dedicated Bulk Spreadsheet Import modal (#sywat_import_modal).
 * Compliance: Vanilla JS only (zero jQuery), namespaced window.SYWAT.registration controller (zero bare globals), single delegated data-sywat-action listeners (input, focusin, change, click, submit), zero document-level keydown listeners, zero element.style.* mutations, class-based visibility (.active / .sywat-hidden / .sywat-field-relative).
 * Version: 7.5.3
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    let nextArcherRowIndex = 1;

    const DEFAULT_DIVISIONS = [
        { slug: 'cub-8-11',            code: 'C',  label: 'Cub (8 - 11)',           distance: 'Cub',  rate_tier: 'Youth', fee: 25, aliases: ['Cub'] },
        { slug: 'youth-12-14',         code: 'Y',  label: 'Youth (12 - 14)',        distance: '20yd', rate_tier: 'Youth', fee: 25, aliases: ['Youth'] },
        { slug: 'young-adult-15-17',   code: 'YA', label: 'Young Adult (15 - 17)',  distance: '20yd', rate_tier: 'Youth', fee: 25, aliases: ['Young Adult'] },
        { slug: 'adult-18-49',         code: 'A',  label: 'Adult (18 - 49)',        distance: '20yd', rate_tier: 'Adult', fee: 30, aliases: ['Adult'] },
        { slug: 'senior-50-59',        code: 'S',  label: 'Senior (50 - 59)',       distance: '20yd', rate_tier: 'Adult', fee: 30, aliases: ['Senior'] },
        { slug: 'silver-senior-60-69', code: 'SS', label: 'Silver Senior (60 - 69)',distance: '20yd', rate_tier: 'Adult', fee: 30, aliases: ['Silver Senior'] },
        { slug: 'master-senior-70',    code: 'MS', label: 'Master Senior (70+)',    distance: '20yd', rate_tier: 'Adult', fee: 30, aliases: ['Master Senior', 'Master'] }
    ];

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function isPlaceholderDivision(divStr) {
        const clean = String(divStr || '').trim().toLowerCase();
        if (!clean) return true;
        return ['-', '--', '—', '–', 'n/a', 'na', 'none', 'unassigned', 'unknown', 'no show'].indexOf(clean) !== -1;
    }

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

    function getTfaaShooters() {
        if (window.sywatRegData && Array.isArray(window.sywatRegData.tfaaShooters)) {
            return window.sywatRegData.tfaaShooters;
        }
        return [];
    }

    function getGivenNameAliases() {
        if (window.sywatRegData && typeof window.sywatRegData.givenNameAliases === 'object' && window.sywatRegData.givenNameAliases !== null) {
            return window.sywatRegData.givenNameAliases;
        }
        return {};
    }

    /**
     * Build normalized exact, First+Last (+ suffix), first-token, and last-token keys
     * matching includes/common/sywat-archer-identity-helpers.php.
     */
    function buildRegNameMatchKeys(rawName) {
        if (!rawName) {
            return { exact: '', fl: '', first: '', last: '', suffix: '', tokenCount: 0, lastLen: 0 };
        }
        const cleaned = String(rawName)
            .replace(/\[.*?\]|\(.*?\)/g, '')
            .replace(/[.,"\u00a0]/g, ' ')
            .replace(/[^a-zA-Z0-9\s'-]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

        if (!cleaned) {
            return { exact: '', fl: '', first: '', last: '', suffix: '', tokenCount: 0, lastLen: 0 };
        }

        const tokens   = cleaned.split(' ');
        const suffixes = ['jr', 'sr', 'ii', 'iii', 'iv', 'v', '2nd', '3rd', '4th'];
        let suffix     = '';

        if (tokens.length > 2 && suffixes.indexOf(tokens[tokens.length - 1]) !== -1) {
            suffix = tokens.pop();
        }

        const coreExact = tokens.join(' ');
        const exactKey  = suffix ? (coreExact + ' ' + suffix) : coreExact;
        const firstTok  = tokens.length > 0 ? tokens[0] : '';
        const lastTok   = tokens.length > 1 ? tokens[tokens.length - 1] : firstTok;
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
            lastLen: lastTok.length
        };
    }

    /**
     * Check whether two given-name tokens are compatible via canonical nickname group or 3+ char stem.
     */
    function areRegGivenNamesCompatible(firstA, firstB) {
        const a = String(firstA || '').trim().toLowerCase();
        const b = String(firstB || '').trim().toLowerCase();
        if (!a || !b) return false;
        if (a === b) return true;

        const aliases = getGivenNameAliases();
        const rootsA  = Array.isArray(aliases[a]) ? aliases[a] : [];
        const rootsB  = Array.isArray(aliases[b]) ? aliases[b] : [];
        for (let i = 0; i < rootsA.length; i++) {
            if (rootsB.indexOf(rootsA[i]) !== -1) {
                return true;
            }
        }

        if (a.length >= 3 && b.length >= 3) {
            if (a.startsWith(b) || b.startsWith(a)) {
                return true;
            }
        }
        return false;
    }

    function getPhoneCache() {
        if (window.sywatRegData && typeof window.sywatRegData.phoneCache === 'object' && window.sywatRegData.phoneCache !== null) {
            return window.sywatRegData.phoneCache;
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
     * Parse configured shooting line columns from #sywat_archer_rows_container[data-lines].
     */
    function getConfiguredLineColumns() {
        const container = document.getElementById('sywat_archer_rows_container');
        if (!container) {
            return [];
        }
        const rawJson = container.getAttribute('data-lines');
        if (!rawJson) {
            return [];
        }
        try {
            const parsed = JSON.parse(rawJson);
            return Array.isArray(parsed) ? parsed : [];
        } catch (err) {
            return [];
        }
    }

    /**
     * Resolve individual entry fee for a specific round profile + simplified tier ('cub' | 'youth' | 'adult').
     */
    function getFeeForRow(profileSlug, tierVal) {
        const tier = String(tierVal || '').trim().toLowerCase();
        if (!tier) {
            return 0;
        }

        const prof = getProfile(profileSlug);
        if (prof) {
            if (
                tier === 'cub' &&
                prof.division_overrides &&
                typeof prof.division_overrides['cub-8-11'] !== 'undefined' &&
                prof.division_overrides['cub-8-11'] !== ''
            ) {
                const cubOv = parseFloat(prof.division_overrides['cub-8-11']);
                if (!Number.isNaN(cubOv) && cubOv >= 0) {
                    return cubOv;
                }
            }

            const isYouth  = (tier === 'cub' || tier === 'youth');
            const tierRate = isYouth ? parseFloat(prof.youth_rate) : parseFloat(prof.adult_rate);
            if (!Number.isNaN(tierRate) && tierRate >= 0) {
                return tierRate;
            }
        }

        return (tier === 'cub' || tier === 'youth') ? 25 : 30;
    }

    /**
     * Update Category / Rate option labels and data-fee attributes on a single intake row
     * whenever its Round Format selector changes (e.g., Field vs. Field 50).
     */
    function updateRowRateTierOptionLabels(row) {
        if (!row) return;
        const roundSelect = row.querySelector('.sywat-round-profile-select');
        const roundInput  = row.querySelector('.sywat-round-profile-input');
        const tierSelect  = row.querySelector('.sywat-rate-tier-select');
        if (!tierSelect) return;

        const roundSlug = roundSelect
            ? roundSelect.value
            : (roundInput ? roundInput.value : getDefaultProfileKey());

        const labelMap = {
            cub:   'Cub',
            youth: 'Youth',
            adult: 'Adult'
        };

        Array.from(tierSelect.options).forEach(function(opt) {
            const val = String(opt.value || '').toLowerCase();
            if (!val || !labelMap[val]) return;
            const fee = getFeeForRow(roundSlug, val);
            opt.setAttribute('data-fee', fee.toFixed(2));
            opt.textContent = labelMap[val] + ' [$' + fee.toFixed(2) + ']';
        });
    }

    function checkAndFillPhone(archerName) {
        if (!archerName) return;
        const phoneCache = getPhoneCache();
        const cleanName  = archerName.trim().toLowerCase();
        const phoneInput = document.getElementById('sywat_group_phone');

        if (phoneInput && !phoneInput.value && phoneCache[cleanName]) {
            phoneInput.value = phoneCache[cleanName];
            formatPhoneNumber(phoneInput);
        }
    }

    function formatPhoneNumber(input) {
        if (!input) return;
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 10) digits = digits.substring(0, 10);

        if (digits.length > 6) {
            input.value = digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6);
        } else if (digits.length > 3) {
            input.value = digits.substring(0, 3) + '-' + digits.substring(3);
        } else {
            input.value = digits;
        }
    }

    function handleCallerNameInput(callerInput) {
        if (!callerInput) return;
        const firstArcherInput = document.getElementById('sywat_first_archer_name') || document.querySelector('.sywat-archer-name-input');
        if (firstArcherInput && !firstArcherInput.dataset.manuallyEdited) {
            firstArcherInput.value = callerInput.value;
            recalculateGroupTotal();
        }
        handleTfaaAutocomplete(callerInput);
        checkAndFillPhone(callerInput.value);
    }

    function handleArcherNameInput(archerInput) {
        if (!archerInput) return;
        const callerInput = document.getElementById('sywat_group_name');
        if (callerInput && archerInput.id === 'sywat_first_archer_name') {
            if (archerInput.value !== callerInput.value && archerInput.value !== '') {
                archerInput.dataset.manuallyEdited = 'true';
            } else if (archerInput.value === '') {
                delete archerInput.dataset.manuallyEdited;
            }
        }
        handleTfaaAutocomplete(archerInput);
        recalculateGroupTotal();
        checkAndFillPhone(archerInput.value);
    }

    function handleRateTierChange(tierSelect) {
        if (!tierSelect) return;
        tierSelect.dataset.userChosen = 'true';
        recalculateGroupTotal();
    }

    /**
     * Map a verified NFAA age division label/code to 'cub', 'youth', or 'adult' on the 3-tier selector.
     * Ignores empty or placeholder values so unverified shooters never receive a guessed tier.
     */
    function matchTfaaRateTier(tfaaDiv, selectEl) {
        if (!tfaaDiv || !selectEl || isPlaceholderDivision(tfaaDiv)) return;
        const cleanTfaa = String(tfaaDiv).trim().toLowerCase();
        if (!cleanTfaa) return;

        let resolvedTier = '';
        const matchedCfg = findDivisionConfig(tfaaDiv);
        if (matchedCfg) {
            if (matchedCfg.distance === 'Cub') {
                resolvedTier = 'cub';
            } else if (matchedCfg.rate_tier === 'Youth') {
                resolvedTier = 'youth';
            } else {
                resolvedTier = 'adult';
            }
        } else if (/\bcub\b/i.test(cleanTfaa)) {
            resolvedTier = 'cub';
        } else if (/youth|young\s*adult/i.test(cleanTfaa)) {
            resolvedTier = 'youth';
        } else {
            resolvedTier = 'adult';
        }

        selectEl.value = resolvedTier;
        handleRateTierChange(selectEl);
    }

    function closeAllTfaaAutocompletes() {
        document.querySelectorAll('.sywat-tfaa-autocomplete-dropdown').forEach(function(el) {
            el.remove();
        });
    }

    /**
     * Registration Autocomplete: Ranks exact, middle-initial folded, given-name alias/stem
     * ("Steven Bergh" -> "Steve Bergh"), and substring matches across the unified Member DB + TFAA index,
     * displaying each shooter's NFAA age division badge in both the top Caller / Primary Archer Name
     * field (#sywat_group_name) and every bottom Archer Full Name row (.sywat-archer-name-input).
     */
    function handleTfaaAutocomplete(input) {
        if (!input) return;
        const query = input.value.trim().toLowerCase();
        closeAllTfaaAutocompletes();

        const shooters = getTfaaShooters();
        if (query.length < 2 || !Array.isArray(shooters) || shooters.length === 0) {
            return;
        }

        const qKeys     = buildRegNameMatchKeys(query);
        const seenNames = new Set();
        const matches   = [];

        shooters.forEach(function(s) {
            if (!s || !s.name) return;
            const norm = s.name.trim().toLowerCase();
            if (seenNames.has(norm)) return;

            const sKeys     = buildRegNameMatchKeys(s.name);
            const isExact   = (qKeys.exact && sKeys.exact === qKeys.exact);
            const isFl      = (qKeys.tokenCount >= 2 && qKeys.lastLen >= 2 && qKeys.fl && sKeys.fl === qKeys.fl);
            const isAlias   = (!isExact && !isFl && qKeys.tokenCount >= 2 && sKeys.tokenCount >= 2 &&
                               qKeys.last === sKeys.last && qKeys.suffix === sKeys.suffix &&
                               areRegGivenNamesCompatible(qKeys.first, sKeys.first));
            const isPartial = norm.includes(query);

            if (isExact || isFl || isAlias || isPartial) {
                seenNames.add(norm);
                const cleanDiv = isPlaceholderDivision(s.division) ? '' : String(s.division).trim();
                matches.push({
                    name: s.name,
                    division: cleanDiv,
                    rank: isExact ? 4 : (isFl ? 3 : (isAlias ? 2 : 1))
                });
            }
        });

        if (matches.length === 0) return;

        matches.sort(function(a, b) {
            if (a.rank !== b.rank) return b.rank - a.rank;
            return a.name.localeCompare(b.name);
        });

        const parent = input.parentElement;
        if (parent && getComputedStyle(parent).position === 'static') {
            parent.classList.add('sywat-field-relative');
        }

        const dropdown = document.createElement('div');
        dropdown.className = 'sywat-tfaa-autocomplete-dropdown';

        matches.slice(0, 8).forEach(function(m) {
            const item = document.createElement('div');
            item.className = 'sywat-tfaa-item';
            const badgeHtml = m.division
                ? ' <span class="sywat-tfaa-item-badge">' + escapeHtml(m.division) + '</span>'
                : '';
            item.innerHTML = '<strong>' + escapeHtml(m.name) + '</strong>' + badgeHtml;

            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                input.value = m.name;
                closeAllTfaaAutocompletes();

                if (input.id === 'sywat_group_name') {
                    const firstArcherInput = document.getElementById('sywat_first_archer_name') || document.querySelector('.sywat-archer-name-input');
                    if (firstArcherInput && !firstArcherInput.dataset.manuallyEdited) {
                        firstArcherInput.value = m.name;
                        const firstRow = firstArcherInput.closest('.sywat-archer-input-row');
                        if (firstRow && m.division) {
                            const firstTierSelect = firstRow.querySelector('.sywat-rate-tier-select');
                            if (firstTierSelect) {
                                matchTfaaRateTier(m.division, firstTierSelect);
                            }
                        }
                    }
                    checkAndFillPhone(m.name);
                    recalculateGroupTotal();
                    return;
                }

                const callerInput = document.getElementById('sywat_group_name');
                if (callerInput && (!callerInput.value.trim() || input.id === 'sywat_first_archer_name')) {
                    callerInput.value = m.name;
                }

                const row = input.closest('.sywat-archer-input-row');
                if (row) {
                    const tierSelect = row.querySelector('.sywat-rate-tier-select');
                    if (tierSelect && m.division) {
                        matchTfaaRateTier(m.division, tierSelect);
                    }
                }
                checkAndFillPhone(m.name);
                recalculateGroupTotal();
            });

            dropdown.appendChild(item);
        });

        if (parent) {
            parent.appendChild(dropdown);
        }
    }

    /**
     * Append a new multi-line archer row with zero pre-checked shooting line checkboxes.
     */
    function addArcherRow() {
        const container = document.getElementById('sywat_archer_rows_container');
        if (!container) return;

        const existingRows = container.querySelectorAll('.sywat-archer-input-row');
        if (existingRows.length >= nextArcherRowIndex) {
            nextArcherRowIndex = existingRows.length;
        }
        const rowIndex     = nextArcherRowIndex++;
        const isMultiRound = container.getAttribute('data-multi-round') === '1';
        const defProfKey   = getDefaultProfileKey();
        const lineColumns  = getConfiguredLineColumns();

        let hiddenProfileHtml = '';
        let roundCellHtml     = '';
        if (isMultiRound) {
            const profiles = getProfiles();
            let roundOptionsHtml = '';
            Object.keys(profiles).forEach(function(pSlug) {
                const pRec    = profiles[pSlug];
                const isSel   = (pSlug === defProfKey) ? ' selected' : '';
                const optText = pRec.label + (pRec.round_subtitle ? ' (' + pRec.round_subtitle + ')' : '');
                roundOptionsHtml += '<option value="' + escapeHtml(pSlug) + '"' + isSel + '>' + escapeHtml(optText) + '</option>';
            });
            roundCellHtml =
                '<td data-label="Round Format *">' +
                    '<select name="archers[' + rowIndex + '][pricing_profile]" class="sywat-round-profile-select" data-sywat-action="round-profile-change">' +
                        roundOptionsHtml +
                    '</select>' +
                '</td>';
        } else {
            hiddenProfileHtml = '<input type="hidden" name="archers[' + rowIndex + '][pricing_profile]" class="sywat-round-profile-input" value="' + escapeHtml(defProfKey) + '">';
        }

        const cubFee   = getFeeForRow(defProfKey, 'cub');
        const youthFee = getFeeForRow(defProfKey, 'youth');
        const adultFee = getFeeForRow(defProfKey, 'adult');

        const tierOptionsHtml =
            '<option value="" disabled selected hidden>Select Category *</option>' +
            '<option value="cub" data-distance="Cub" data-rate-tier="Youth" data-fee="' + cubFee.toFixed(2) + '">Cub [$' + cubFee.toFixed(2) + ']</option>' +
            '<option value="youth" data-distance="20yd" data-rate-tier="Youth" data-fee="' + youthFee.toFixed(2) + '">Youth [$' + youthFee.toFixed(2) + ']</option>' +
            '<option value="adult" data-distance="20yd" data-rate-tier="Adult" data-fee="' + adultFee.toFixed(2) + '">Adult [$' + adultFee.toFixed(2) + ']</option>';

        let lineCellsHtml = '';
        lineColumns.forEach(function(col) {
            const colIdx   = String(col.idx !== undefined ? col.idx : '');
            const colLabel = String(col.label || ('Line ' + (parseInt(colIdx, 10) + 1)));
            const ariaText = 'Register archer for ' + colLabel;
            lineCellsHtml +=
                '<td class="sywat-col-center" data-label="' + escapeHtml(colLabel) + '">' +
                    '<input type="checkbox" class="sywat-archer-line-cb" name="archers[' + rowIndex + '][line_indices][]" value="' + escapeHtml(colIdx) + '" aria-label="' + escapeHtml(ariaText) + '" data-sywat-action="archer-line-toggle">' +
                '</td>';
        });

        const tr = document.createElement('tr');
        tr.className = 'sywat-archer-input-row';
        tr.innerHTML =
            '<td data-label="Archer Full Name *">' +
                '<input type="text" name="archers[' + rowIndex + '][name]" class="sywat-archer-name-input" placeholder="Archer Full Name *" autocomplete="off" required data-sywat-action="archer-name-input">' +
                hiddenProfileHtml +
            '</td>' +
            roundCellHtml +
            '<td data-label="Category / Rate *">' +
                '<select name="archers[' + rowIndex + '][rate_tier]" class="sywat-rate-tier-select" required data-sywat-action="rate-tier-change">' +
                    tierOptionsHtml +
                '</select>' +
            '</td>' +
            lineCellsHtml +
            '<td class="sywat-col-center" data-label="Action">' +
                '<button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" data-sywat-action="remove-archer-row" title="Remove Archer" aria-label="Remove Archer"></button>' +
            '</td>';

        container.appendChild(tr);
        recalculateGroupTotal();
    }

    function removeArcherRow(btn) {
        if (!btn) return;
        const rows = document.querySelectorAll('.sywat-archer-input-row');
        if (rows.length <= 1) {
            window.alert('At least one archer row is required.');
            return;
        }
        const row = btn.closest('.sywat-archer-input-row');
        if (row) {
            row.remove();
        }
        recalculateGroupTotal();
    }

    /**
     * Recalculate Group Total, Family Cap status (per line or tournament), and Balance Due
     * across all checked (archer, line) pairs in the multi-line matrix.
     */
    function recalculateGroupTotal() {
        const rows      = document.querySelectorAll('.sywat-archer-input-row');
        const profiles  = getProfiles();
        const defSlug   = getDefaultProfileKey();
        const bucketMap = {};

        let useTournWideCap = false;
        Object.keys(profiles).forEach(function(pSlug) {
            const p = profiles[pSlug];
            if (p && p.family_cap_enabled && p.family_cap_scope === 'tournament') {
                useTournWideCap = true;
            }
        });

        const claimedPromoNames = {};
        let promoAppliedCount   = 0;

        rows.forEach(function(row) {
            const nameInput        = row.querySelector('.sywat-archer-name-input');
            const roundSelect      = row.querySelector('.sywat-round-profile-select');
            const roundInput       = row.querySelector('.sywat-round-profile-input');
            const tierSelect       = row.querySelector('.sywat-rate-tier-select');
            const checkedLineBoxes = row.querySelectorAll('.sywat-archer-line-cb:checked');

            if (!nameInput || nameInput.value.trim() === '' || checkedLineBoxes.length === 0) {
                return;
            }

            const rawName   = nameInput.value.trim();
            const normName  = rawName.toLowerCase();
            const roundSlug = roundSelect
                ? roundSelect.value
                : (roundInput ? roundInput.value : defSlug);
            const tierVal   = tierSelect ? tierSelect.value : '';
            const profRec   = getProfile(roundSlug);

            checkedLineBoxes.forEach(function(cb) {
                const lIdx = String(cb.value || '0');

                let isPromoFree = false;
                if (!claimedPromoNames[normName] && isArcherPromoEligible(rawName, roundSlug)) {
                    isPromoFree = true;
                    claimedPromoNames[normName] = true;
                    promoAppliedCount++;
                }

                const archerFee = isPromoFree ? 0 : getFeeForRow(roundSlug, tierVal);
                const capBucket = useTournWideCap ? 'tournament' : lIdx;

                if (!isPromoFree && tierVal !== '') {
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
            });
        });

        let overallTotal       = 0;
        let anyCapped          = false;
        let cappedBucketsCount = 0;

        Object.keys(bucketMap).forEach(function(bKey) {
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

        if (totalDisp) {
            totalDisp.textContent = '$' + overallTotal.toFixed(2);
        }

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

        if (dueDisp) {
            dueDisp.textContent = '$' + balanceDue.toFixed(2);
        }
    }

    /**
     * Client-side pre-submit validation ensuring every named archer row has at least one
     * shooting line checked before submitting the intake form.
     */
    function validatePhoneIntakeForm(form, submitEvent) {
        if (!form) return;

        const existingClientNotice = document.getElementById('sywat_client_line_validation_notice');
        if (existingClientNotice) {
            existingClientNotice.remove();
        }

        const rows           = form.querySelectorAll('.sywat-archer-input-row');
        const missingArchers = [];
        let firstMissingCb   = null;

        rows.forEach(function(row) {
            const nameInput = row.querySelector('.sywat-archer-name-input');
            const nameVal   = nameInput ? nameInput.value.trim() : '';
            if (!nameVal) {
                return;
            }
            const checkedCount = row.querySelectorAll('.sywat-archer-line-cb:checked').length;
            if (checkedCount === 0) {
                missingArchers.push(nameVal);
                if (!firstMissingCb) {
                    firstMissingCb = row.querySelector('.sywat-archer-line-cb');
                }
            }
        });

        if (missingArchers.length > 0) {
            submitEvent.preventDefault();

            const alertDiv = document.createElement('div');
            alertDiv.id = 'sywat_client_line_validation_notice';
            alertDiv.className = 'sywat-alert sywat-alert-danger sywat-mb-3';
            alertDiv.innerHTML = '<strong>Shooting Line Required:</strong> Please check at least one shooting line for <strong>' + escapeHtml(missingArchers.join(', ')) + '</strong>.';

            const intakeCard = form.closest('.sywat-intake-card');
            if (intakeCard) {
                intakeCard.insertBefore(alertDiv, form);
            } else {
                form.prepend(alertDiv);
            }

            if (firstMissingCb && typeof firstMissingCb.focus === 'function') {
                firstMissingCb.focus();
            }
        }
    }

    function initAutoDismissAlerts() {
        setTimeout(function() {
            const alerts = document.querySelectorAll('#sywat-notice.sywat-alert-success');
            alerts.forEach(function(alert) {
                if (
                    alert.closest('.sywat-modal-backdrop') ||
                    alert.querySelector('form, button, a') ||
                    (alert.textContent && alert.textContent.includes('OVERFLOW'))
                ) {
                    return;
                }
                alert.classList.add('sywat-dismissing');
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);
    }

    window.SYWAT.registration = {
        openImportModal: openImportModal,
        closeImportModal: closeImportModal,
        addArcherRow: addArcherRow,
        removeArcherRow: removeArcherRow,
        recalculateGroupTotal: recalculateGroupTotal
    };

    // Single Delegated 'input' Listener
    document.addEventListener('input', function(e) {
        const target = e.target;
        if (!target || !target.getAttribute) return;

        const action = target.getAttribute('data-sywat-action');
        if (action === 'caller-name-input') {
            handleCallerNameInput(target);
            return;
        }
        if (action === 'group-phone-input') {
            formatPhoneNumber(target);
            return;
        }
        if (action === 'amount-paid-input') {
            recalculateGroupTotal();
            return;
        }
        if (action === 'archer-name-input') {
            handleArcherNameInput(target);
        }
    });

    // Single Delegated 'focusin' Listener (Re-opens TFAA division lookup on top & bottom name fields)
    document.addEventListener('focusin', function(e) {
        const target = e.target;
        if (!target || !target.getAttribute) return;

        const action = target.getAttribute('data-sywat-action');
        if (action === 'caller-name-input' || action === 'archer-name-input') {
            handleTfaaAutocomplete(target);
        }
    });

    // Single Delegated 'change' Listener
    document.addEventListener('change', function(e) {
        const target = e.target;
        if (!target || !target.getAttribute) return;

        const action = target.getAttribute('data-sywat-action');
        if (action === 'switch-tournament') {
            if (target.form) {
                target.form.submit();
            }
            return;
        }
        if (action === 'round-profile-change') {
            const row = target.closest('.sywat-archer-input-row');
            updateRowRateTierOptionLabels(row);
            recalculateGroupTotal();
            return;
        }
        if (action === 'rate-tier-change') {
            handleRateTierChange(target);
            return;
        }
        if (action === 'archer-line-toggle') {
            recalculateGroupTotal();
        }
    });

    // Single Delegated 'click' Listener
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
            if (action === 'add-archer-row') {
                e.preventDefault();
                addArcherRow();
                return;
            }
            if (action === 'remove-archer-row') {
                e.preventDefault();
                removeArcherRow(actionTrigger);
                return;
            }
        }

        if (
            !e.target.closest('.sywat-tfaa-autocomplete-dropdown') &&
            !e.target.closest('.sywat-archer-name-input') &&
            !e.target.closest('#sywat_group_name')
        ) {
            closeAllTfaaAutocompletes();
        }
    });

    // Single Delegated 'submit' Listener
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!form || !form.getAttribute) return;

        const action = form.getAttribute('data-sywat-action');
        if (action === 'confirm-purge-line') {
            const msg = form.getAttribute('data-confirm-msg') || 'Are you sure you want to clear this line?';
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
            return;
        }
        if (action === 'phone-intake-form') {
            validatePhoneIntakeForm(form, e);
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const existingRows = document.querySelectorAll('.sywat-archer-input-row');
        if (existingRows.length > 0) {
            nextArcherRowIndex = existingRows.length;
        }
        existingRows.forEach(function(row) {
            updateRowRateTierOptionLabels(row);
        });
        recalculateGroupTotal();
        initAutoDismissAlerts();

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
