/**
 * SYWAT Archery - Shared Shooting Styles & Age Division Rule Engine
 * Path: assets/js/sywat-shooting-styles.js
 * Description: Database-driven rule controller enforcing allowed divisions, forced-division overrides, bidirectional automatic dropdown switching, persistent inline user warnings, and fee/distance metadata lookups across Registration, Roster, and Check-In portals.
 * Compliance: Zero jQuery, zero inline style.* mutations, zero hardcoded style/division names.
 * Author: SYWAT Archery
 * Version: 7.3.0
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    function escapeHtmlRule(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function normalizeToken(str) {
        if (!str) return '';
        return String(str).replace(/[\s\u00a0]+/g, ' ').trim().toLowerCase();
    }

    /**
     * Retrieve localized shooting styles array from wp_localize_script payload.
     *
     * @return {Array} Array of shooting style configuration objects.
     */
    function getStyles() {
        const sources = [
            window.sywatShootingStylesConfig && window.sywatShootingStylesConfig.styles,
            window.sywatShootingStylesData && window.sywatShootingStylesData.styles,
            window.sywatStylesConfig && window.sywatStylesConfig.styles,
            window.sywatRegData && window.sywatRegData.shootingStyles,
            window.sywatDeskShootingStyles
        ];

        for (let i = 0; i < sources.length; i++) {
            if (Array.isArray(sources[i]) && sources[i].length > 0) {
                return sources[i];
            }
        }
        return [];
    }

    /**
     * Retrieve localized age divisions array from wp_localize_script payload.
     *
     * @return {Array} Array of age division configuration objects.
     */
    function getDivisions() {
        const sources = [
            window.sywatShootingStylesConfig && window.sywatShootingStylesConfig.divisions,
            window.sywatShootingStylesData && window.sywatShootingStylesData.divisions,
            window.sywatStylesConfig && window.sywatStylesConfig.divisions,
            window.sywatRegData && window.sywatRegData.divisions,
            window.sywatDeskDivisions
        ];

        for (let i = 0; i < sources.length; i++) {
            if (Array.isArray(sources[i]) && sources[i].length > 0) {
                return sources[i];
            }
        }
        return [];
    }

    /**
     * Find a configured Shooting Style object by label, slug, code, prefix, or alias.
     *
     * @param {string} query Raw style label, code, or alias.
     * @return {Object|null} Matching style config object or null.
     */
    function findStyle(query) {
        const needle = normalizeToken(query);
        if (!needle || needle === 'no show' || needle === 'guest') {
            return null;
        }

        const styles = getStyles();
        const needlePrefix = needle.split(' –')[0].split(' - ')[0].trim();

        // Pass 1: Exact label, slug, or code match
        for (let i = 0; i < styles.length; i++) {
            const s = styles[i];
            if (!s) continue;
            const lbl  = normalizeToken(s.label);
            const slug = normalizeToken(s.slug);
            const code = normalizeToken(s.code);

            if (needle === lbl || needle === slug || (code && needle === code)) {
                return s;
            }
        }

        // Pass 2: Prefix or alias match
        for (let i = 0; i < styles.length; i++) {
            const s = styles[i];
            if (!s) continue;
            const lbl       = normalizeToken(s.label);
            const lblPrefix = lbl.split(' –')[0].split(' - ')[0].trim();
            const code      = normalizeToken(s.code);

            if (needlePrefix && (needlePrefix === lblPrefix || (code && needlePrefix === code))) {
                return s;
            }

            if (Array.isArray(s.aliases)) {
                for (let j = 0; j < s.aliases.length; j++) {
                    const alias = normalizeToken(s.aliases[j]);
                    if (alias && (needle === alias || needlePrefix === alias)) {
                        return s;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find a configured Age Division object by label, slug, code, base name, or alias.
     *
     * @param {string} query Raw division label, code, or alias.
     * @return {Object|null} Matching division config object or null.
     */
    function findDivision(query) {
        const needle = normalizeToken(query);
        if (!needle || needle === 'no show') {
            return null;
        }

        const divisions = getDivisions();
        const needleBase = needle.replace(/\s*\([^)]*\)\s*/g, '').trim();

        // Pass 1: Exact label, slug, or code match
        for (let i = 0; i < divisions.length; i++) {
            const d = divisions[i];
            if (!d) continue;
            const lbl  = normalizeToken(d.label);
            const slug = normalizeToken(d.slug);
            const code = normalizeToken(d.code);

            if (needle === lbl || needle === slug || (code && needle === code)) {
                return d;
            }
        }

        // Pass 2: Base name (without age range) or alias match
        for (let i = 0; i < divisions.length; i++) {
            const d = divisions[i];
            if (!d) continue;
            const lbl     = normalizeToken(d.label);
            const lblBase = lbl.replace(/\s*\([^)]*\)\s*/g, '').trim();

            if (needleBase && lblBase && needleBase === lblBase) {
                return d;
            }

            if (Array.isArray(d.aliases)) {
                for (let j = 0; j < d.aliases.length; j++) {
                    const alias = normalizeToken(d.aliases[j]);
                    if (alias && (needle === alias || needleBase === alias)) {
                        return d;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve effective rule mode ('all', 'restrict', or 'force') and forced division string
     * from a style configuration record.
     *
     * @param {Object} styleRec Shooting style config object.
     * @return {Object}         { ruleMode: string, forcedDivision: string }
     */
    function getStyleRuleMeta(styleRec) {
        if (!styleRec) {
            return { ruleMode: 'all', forcedDivision: '' };
        }

        const forcedDiv = String(styleRec.forced_division || styleRec.force_division || '').trim();
        let ruleMode    = String(styleRec.rule_mode || '').trim().toLowerCase();

        if (ruleMode !== 'all' && ruleMode !== 'restrict' && ruleMode !== 'force') {
            if (forcedDiv !== '') {
                ruleMode = 'force';
            } else if (Array.isArray(styleRec.allowed_divisions) && styleRec.allowed_divisions.length > 0) {
                ruleMode = 'restrict';
            } else {
                ruleMode = 'all';
            }
        }

        return {
            ruleMode: ruleMode,
            forcedDivision: forcedDiv
        };
    }

    /**
     * Resolve the list of target/permitted division objects for a given style record,
     * ordered by configured database sort_order. Empty array means all divisions allowed.
     *
     * @param {Object} styleRec Shooting style config object.
     * @return {Array}          Sorted array of division config objects.
     */
    function getPermittedDivisionsForStyle(styleRec) {
        if (!styleRec) return [];

        const meta = getStyleRuleMeta(styleRec);
        if (meta.ruleMode === 'all') {
            return [];
        }

        const rawAllowed = [];
        if (meta.ruleMode === 'force' && meta.forcedDivision !== '') {
            rawAllowed.push(meta.forcedDivision);
        } else if (meta.ruleMode === 'restrict' && Array.isArray(styleRec.allowed_divisions)) {
            styleRec.allowed_divisions.forEach(function(item) {
                if (item && String(item).trim() !== '') {
                    rawAllowed.push(item);
                }
            });
        }

        if (rawAllowed.length === 0) {
            return [];
        }

        const seen = new Set();
        const resolved = [];

        rawAllowed.forEach(function(token) {
            const divObj = findDivision(token);
            if (divObj && divObj.label) {
                const key = normalizeToken(divObj.label);
                if (!seen.has(key)) {
                    seen.add(key);
                    resolved.push(divObj);
                }
            } else {
                const cleanStr = String(token).trim();
                const key = normalizeToken(cleanStr);
                if (key && !seen.has(key)) {
                    seen.add(key);
                    resolved.push({
                        slug: key,
                        code: '',
                        label: cleanStr,
                        sort_order: 999
                    });
                }
            }
        });

        resolved.sort(function(a, b) {
            const sa = (typeof a.sort_order === 'number') ? a.sort_order : parseInt(a.sort_order || '999', 10);
            const sb = (typeof b.sort_order === 'number') ? b.sort_order : parseInt(b.sort_order || '999', 10);
            return sa - sb;
        });

        return resolved;
    }

    /**
     * Check whether a given division value is permitted for a shooting style record.
     *
     * @param {Object} styleRec Shooting style config object.
     * @param {string} divValue Selected division value from dropdown.
     * @return {boolean}        True if permitted (or if style has no restrictions).
     */
    function isDivisionPermitted(styleRec, divValue) {
        if (!styleRec || !divValue || normalizeToken(divValue) === 'no show') {
            return true;
        }

        const meta = getStyleRuleMeta(styleRec);
        if (meta.ruleMode === 'all') {
            return true;
        }

        const permitted = getPermittedDivisionsForStyle(styleRec);
        if (permitted.length === 0) {
            return true;
        }

        const targetDivObj = findDivision(divValue);
        const targetNorm   = normalizeToken(targetDivObj ? targetDivObj.label : divValue);

        for (let i = 0; i < permitted.length; i++) {
            if (normalizeToken(permitted[i].label) === targetNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format a human-readable list of division names from database records.
     *
     * @param {Array} permittedDivs Array of division config objects.
     * @return {string}             Formatted label list.
     */
    function formatAllowedDivisionList(permittedDivs) {
        if (!Array.isArray(permittedDivs) || permittedDivs.length === 0) {
            return '';
        }
        return permittedDivs.map(function(d) {
            return d.label || '';
        }).filter(Boolean).join(', ');
    }

    /**
     * Render or clear an inline warning notice directly beneath the Style/Division field group.
     * Protects freshly rendered auto-switch notices from being cleared by synchronous follow-up
     * restriction checks in the same tick.
     *
     * @param {HTMLSelectElement} styleEl    Style select element.
     * @param {HTMLSelectElement} divisionEl Division select element.
     * @param {string}            htmlMsg    Safe HTML warning message, or empty string to clear.
     * @param {number}            lockMs     Optional milliseconds to protect this notice from immediate clearing.
     * @return {void}
     */
    function setRuleNotice(styleEl, divisionEl, htmlMsg, lockMs) {
        const anchorEl = styleEl || divisionEl;
        if (!anchorEl) return;

        const fieldGroup = anchorEl.closest('.sywat-field-group') || anchorEl.parentElement;
        if (!fieldGroup) return;

        let noticeEl = fieldGroup.querySelector('.sywat-style-rule-notice');

        if (!htmlMsg) {
            const lockUntil = parseInt(anchorEl.dataset.sywatNoticeLockUntil || '0', 10);
            if (lockUntil && Date.now() < lockUntil) {
                return;
            }
            delete anchorEl.dataset.sywatNoticeLockUntil;
            if (noticeEl) {
                noticeEl.remove();
            }
            return;
        }

        if (lockMs && lockMs > 0) {
            anchorEl.dataset.sywatNoticeLockUntil = String(Date.now() + lockMs);
        } else {
            delete anchorEl.dataset.sywatNoticeLockUntil;
        }

        if (!noticeEl) {
            noticeEl = document.createElement('div');
            noticeEl.className = 'sywat-alert sywat-alert-warning sywat-style-rule-notice';
            noticeEl.setAttribute('role', 'status');
            noticeEl.setAttribute('aria-live', 'polite');
            fieldGroup.appendChild(noticeEl);
        }

        noticeEl.innerHTML = htmlMsg;
    }

    /**
     * Ensure all active style options remain selectable so choosing any restricted or forced
     * style triggers the automatic division update and inline warning banner.
     *
     * @param {HTMLSelectElement} styleEl Style select element.
     * @return {void}
     */
    function syncStyleOptionStates(styleEl) {
        if (!styleEl || !styleEl.options) return;
        for (let i = 0; i < styleEl.options.length; i++) {
            styleEl.options[i].disabled = false;
        }
    }

    /**
     * Bidirectional Rule Enforcement Controller:
     * - When trigger === 'style': If the selected style has rule_mode === 'force' (e.g., LB – Longbow)
     *   or rule_mode === 'restrict' (e.g., Genesis, FS Pro, FSL, TRAD) and the current division is
     *   ineligible, automatically switches divisionEl.value to the required/first allowed division
     *   in database order, recalculates fees/distance, and displays a persistent inline warning banner.
     * - When trigger === 'division': If the currently selected style is not valid for the newly
     *   chosen division, updates styleEl.value to the first unrestricted style and displays an
     *   inline warning banner.
     *
     * @param {HTMLSelectElement} styleEl    Style select element.
     * @param {HTMLSelectElement} divisionEl Division select element.
     * @param {Object}            options    Optional settings: { trigger: 'division'|'style'|'init', silent: boolean }.
     * @return {boolean}                     True if either dropdown value was automatically modified.
     */
    function applyStyleRules(styleEl, divisionEl, options) {
        if (!styleEl || !divisionEl) return false;

        const opts    = options || {};
        const trigger = opts.trigger || 'division';
        const silent  = Boolean(opts.silent);

        // Ignore synthetic change events dispatched during an automatic rule update
        if (trigger === 'division' && divisionEl.dataset.sywatAutoUpdating === '1') {
            delete divisionEl.dataset.sywatAutoUpdating;
            syncStyleOptionStates(styleEl);
            return false;
        }
        if (trigger === 'style' && styleEl.dataset.sywatAutoUpdating === '1') {
            delete styleEl.dataset.sywatAutoUpdating;
            syncStyleOptionStates(styleEl);
            return false;
        }

        // Case 1: User selected a Shooting Style
        if (trigger === 'style') {
            syncStyleOptionStates(styleEl);

            const selectedStyleVal = styleEl.value || '';
            if (!selectedStyleVal || normalizeToken(selectedStyleVal) === 'no show') {
                setRuleNotice(styleEl, divisionEl, '', 0);
                return false;
            }

            const styleRec = findStyle(selectedStyleVal);
            if (!styleRec) {
                setRuleNotice(styleEl, divisionEl, '', 0);
                return false;
            }

            const meta          = getStyleRuleMeta(styleRec);
            const permittedDivs = getPermittedDivisionsForStyle(styleRec);

            if (meta.ruleMode === 'all' || permittedDivs.length === 0) {
                setRuleNotice(styleEl, divisionEl, '', 0);
                return false;
            }

            const currentDivVal     = divisionEl.value || '';
            let needsDivisionSwitch = false;

            if (meta.ruleMode === 'force' && meta.forcedDivision !== '') {
                const forcedObj  = findDivision(meta.forcedDivision);
                const forcedNorm = normalizeToken(forcedObj ? forcedObj.label : meta.forcedDivision);
                const currDivObj = findDivision(currentDivVal);
                const currNorm   = normalizeToken(currDivObj ? currDivObj.label : currentDivVal);
                needsDivisionSwitch = (currNorm !== forcedNorm);
            } else {
                needsDivisionSwitch = !isDivisionPermitted(styleRec, currentDivVal);
            }

            if (!needsDivisionSwitch) {
                setRuleNotice(styleEl, divisionEl, '', 0);
                return false;
            }

            // Locate matching <option> in divisionEl for the required / first allowed division
            let targetOptionValue = '';
            let targetDivLabel    = '';

            for (let p = 0; p < permittedDivs.length; p++) {
                const candNorm = normalizeToken(permittedDivs[p].label);
                for (let i = 0; i < divisionEl.options.length; i++) {
                    const dOpt = divisionEl.options[i];
                    if (!dOpt.value || dOpt.disabled) continue;
                    const optDivObj = findDivision(dOpt.value);
                    const optNorm   = normalizeToken(optDivObj ? optDivObj.label : dOpt.value);
                    if (optNorm === candNorm) {
                        targetOptionValue = dOpt.value;
                        targetDivLabel    = permittedDivs[p].label || dOpt.textContent.trim();
                        break;
                    }
                }
                if (targetOptionValue) break;
            }

            if (!targetOptionValue) {
                return false;
            }

            const prevDivObj   = findDivision(currentDivVal);
            const prevDivLabel = (prevDivObj && prevDivObj.label) ? prevDivObj.label : currentDivVal;

            divisionEl.value = targetOptionValue;

            if (!silent) {
                const styleLabel  = styleRec.label || selectedStyleVal;
                const allowedText = formatAllowedDivisionList(permittedDivs);
                let msg = '';

                if (permittedDivs.length === 1) {
                    if (prevDivLabel) {
                        msg = '⚠️ <strong>' + escapeHtmlRule(styleLabel) + '</strong> is restricted to <strong>' + escapeHtmlRule(targetDivLabel) + '</strong> and cannot be used in <strong>' + escapeHtmlRule(prevDivLabel) + '</strong>. Division was automatically updated to <strong>' + escapeHtmlRule(targetDivLabel) + '</strong>.';
                    } else {
                        msg = '⚠️ <strong>' + escapeHtmlRule(styleLabel) + '</strong> is restricted to <strong>' + escapeHtmlRule(targetDivLabel) + '</strong>. Division was automatically set to <strong>' + escapeHtmlRule(targetDivLabel) + '</strong>.';
                    }
                } else {
                    msg = '⚠️ <strong>' + escapeHtmlRule(styleLabel) + '</strong> is only permitted in <strong>' + escapeHtmlRule(allowedText) + '</strong>. Division was automatically updated to <strong>' + escapeHtmlRule(targetDivLabel) + '</strong>.';
                }

                // Lock notice for 250ms so chained division-restriction callbacks do not erase it
                setRuleNotice(styleEl, divisionEl, msg, 250);
            }

            // Notify downstream distance/fee listeners (Registration fee summary, etc.)
            divisionEl.dataset.sywatAutoUpdating = '1';
            divisionEl.dispatchEvent(new Event('change', { bubbles: true }));
            delete divisionEl.dataset.sywatAutoUpdating;

            return true;
        }

        // Case 2: Division changed or initial form hydration
        syncStyleOptionStates(styleEl);

        const currentDivVal   = divisionEl.value || '';
        const currentStyleVal = styleEl.value || '';

        if (!currentDivVal || normalizeToken(currentDivVal) === 'no show' || !currentStyleVal || normalizeToken(currentStyleVal) === 'no show') {
            if (trigger !== 'init') {
                setRuleNotice(styleEl, divisionEl, '', 0);
            }
            return false;
        }

        const activeStyleRec = findStyle(currentStyleVal);
        if (!activeStyleRec) {
            if (trigger !== 'init') {
                setRuleNotice(styleEl, divisionEl, '', 0);
            }
            return false;
        }

        const activeMeta = getStyleRuleMeta(activeStyleRec);

        // Check if current style violates either 'restrict' or 'force' for the chosen division
        let isConflict = false;
        if (activeMeta.ruleMode === 'force' && activeMeta.forcedDivision !== '') {
            const forcedObj  = findDivision(activeMeta.forcedDivision);
            const forcedNorm = normalizeToken(forcedObj ? forcedObj.label : activeMeta.forcedDivision);
            const currDivObj = findDivision(currentDivVal);
            const currNorm   = normalizeToken(currDivObj ? currDivObj.label : currentDivVal);
            isConflict = (currNorm !== forcedNorm);
        } else if (activeMeta.ruleMode === 'restrict') {
            isConflict = !isDivisionPermitted(activeStyleRec, currentDivVal);
        }

        if (!isConflict) {
            if (trigger !== 'init') {
                setRuleNotice(styleEl, divisionEl, '', 0);
            }
            return false;
        }

        // Current style is NOT valid for the newly selected division — pick first enabled unrestricted style
        let fallbackOption = null;
        for (let i = 0; i < styleEl.options.length; i++) {
            const opt = styleEl.options[i];
            if (!opt.value || opt.disabled || normalizeToken(opt.value) === 'no show') {
                continue;
            }
            const candRec  = findStyle(opt.value);
            const candMeta = getStyleRuleMeta(candRec);
            if (candMeta.ruleMode === 'force' || (candMeta.ruleMode === 'restrict' && !isDivisionPermitted(candRec, currentDivVal))) {
                continue;
            }
            fallbackOption = opt;
            break;
        }

        if (!fallbackOption) {
            return false;
        }

        const prevStyleLabel = activeStyleRec.label || currentStyleVal;
        const permittedDivs  = getPermittedDivisionsForStyle(activeStyleRec);
        const divRec         = findDivision(currentDivVal);
        const currentDivLbl  = (divRec && divRec.label) ? divRec.label : currentDivVal;

        styleEl.value = fallbackOption.value;
        const newStyleRec   = findStyle(fallbackOption.value);
        const newStyleLabel = (newStyleRec && newStyleRec.label) ? newStyleRec.label : fallbackOption.textContent.trim();

        if (!silent) {
            const allowedText = formatAllowedDivisionList(permittedDivs);
            let msg = '';

            if (permittedDivs.length === 1 && allowedText) {
                msg = '⚠️ <strong>' + escapeHtmlRule(prevStyleLabel) + '</strong> is restricted to <strong>' + escapeHtmlRule(allowedText) + '</strong> and cannot be used in <strong>' + escapeHtmlRule(currentDivLbl) + '</strong>. Style was updated to <strong>' + escapeHtmlRule(newStyleLabel) + '</strong>.';
            } else if (allowedText) {
                msg = '⚠️ <strong>' + escapeHtmlRule(prevStyleLabel) + '</strong> is only permitted in <strong>' + escapeHtmlRule(allowedText) + '</strong>. Style was updated to <strong>' + escapeHtmlRule(newStyleLabel) + '</strong>.';
            } else {
                msg = '⚠️ <strong>' + escapeHtmlRule(prevStyleLabel) + '</strong> is not permitted in <strong>' + escapeHtmlRule(currentDivLbl) + '</strong>. Style was updated to <strong>' + escapeHtmlRule(newStyleLabel) + '</strong>.';
            }

            setRuleNotice(styleEl, divisionEl, msg, 0);
        }

        styleEl.dataset.sywatAutoUpdating = '1';
        styleEl.dispatchEvent(new Event('change', { bubbles: true }));
        delete styleEl.dataset.sywatAutoUpdating;

        return true;
    }

    /**
     * Get distance ('Cub' or '20yd') for a division label/code from database config.
     *
     * @param {string} divValue Division label, code, or alias.
     * @return {string}         'Cub' or '20yd'.
     */
    function getDivisionDistance(divValue) {
        const divRec = findDivision(divValue);
        if (divRec && divRec.distance) {
            return divRec.distance === 'Cub' ? 'Cub' : '20yd';
        }
        return normalizeToken(divValue).indexOf('cub') !== -1 ? 'Cub' : '20yd';
    }

    /**
     * Get Individual rate tier ('Youth' or 'Adult') for a division label/code from database config.
     *
     * @param {string} divValue Division label, code, or alias.
     * @return {string}         'Youth' or 'Adult'.
     */
    function getDivisionRateTier(divValue) {
        const divRec = findDivision(divValue);
        if (divRec && divRec.rate_tier) {
            return divRec.rate_tier === 'Youth' ? 'Youth' : 'Adult';
        }
        const norm = normalizeToken(divValue);
        if (norm.indexOf('cub') !== -1 || norm.indexOf('youth') !== -1 || norm.indexOf('young adult') !== -1) {
            return 'Youth';
        }
        return 'Adult';
    }

    // Export public namespaced API
    window.SYWAT.shootingStyles = {
        getStyles: getStyles,
        getDivisions: getDivisions,
        findStyle: findStyle,
        findDivision: findDivision,
        getPermittedDivisionsForStyle: getPermittedDivisionsForStyle,
        isDivisionPermitted: isDivisionPermitted,
        applyStyleRules: applyStyleRules,
        getDivisionDistance: getDivisionDistance,
        getDivisionRateTier: getDivisionRateTier
    };

})();
