/**
 * SYWAT Archery - Master Tournament & Season Management Client Engine
 * Path: assets/js/sywat-tournaments.js
 * Description: Manages Add/Edit Tournament modal auto-naming, shop capacity & default line templates, progressive-disclosure per-tournament registration link rows, season-filtered pricing formats, standardized 30-minute AM/PM shooting line dropdown repeaters & duplicate validation, table filters, and asynchronous TFAA scorecard batch sync.
 * Compliance: Vanilla JS only (zero jQuery), namespaced under window.SYWAT.tournaments with legacy attribute compatibility aliases, delegated data-sywat-action listeners, class-based visibility (.active / .sywat-hidden).
 * Author: SYWAT Archery
 * Version: 7.5.0
 */

(function () {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    if (window.SYWAT._tournamentsInitialized) {
        return;
    }
    window.SYWAT._tournamentsInitialized = true;

    let sywatTournamentNameIsCustomized = false;
    let sywatTournamentNameIsUnlocked   = false;

    function setElementVisible(el, visible) {
        if (!el) return;
        el.classList.toggle('sywat-hidden', !visible);
    }

    /**
     * Normalizes a line time string into a canonical token (e.g. "10:00 AM" -> "10AM", "6:30 PM" -> "6:30PM").
     * Enforces the TFAA rule that 6 o'clock lines are always PM.
     */
    function normalizeLineTimeToken(rawToken) {
        const token = String(rawToken || '').trim();
        if (!token) return '';

        const match = token.match(/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i);
        if (match) {
            const hour = parseInt(match[1], 10);
            let mins = match[2] || '';
            let meridiem = (match[3] || '').toUpperCase();

            if (mins === '00') {
                mins = '';
            }
            const minStr = mins ? ':' + mins : '';

            if (!meridiem) {
                if (hour === 6 || hour === 12 || (hour >= 1 && hour <= 5)) {
                    meridiem = 'PM';
                } else {
                    meridiem = 'AM';
                }
            }

            if (hour === 6) {
                meridiem = 'PM';
            }

            return hour + minStr + meridiem;
        }

        return token.toUpperCase();
    }

    /**
     * Builds standardized 30-minute AM/PM <option> HTML (7:00 AM through 9:30 PM)
     * matching sywat_get_standard_line_time_options() in tournament-helpers.php.
     */
    function buildStandardLineTimeOptionsHtml(defaultTime) {
        const rawDefault        = String(defaultTime || '10AM').trim();
        const normalizedDefault = normalizeLineTimeToken(rawDefault);
        const options           = [];
        let matchedStandard     = false;

        for (let mins = 7 * 60; mins <= (21 * 60) + 30; mins += 30) {
            const hour24   = Math.floor(mins / 60);
            const minute   = mins % 60;
            const meridiem = hour24 >= 12 ? 'PM' : 'AM';
            let hour12     = hour24 % 12;
            if (hour12 === 0) {
                hour12 = 12;
            }

            const minPadded    = String(minute).padStart(2, '0');
            const token        = hour12 + (minute === 0 ? '' : ':' + minPadded) + meridiem;
            const label        = hour12 + ':' + minPadded + ' ' + meridiem;
            const isSelected   = (normalizedDefault === token);
            if (isSelected) {
                matchedStandard = true;
            }

            options.push('<option value="' + token + '"' + (isSelected ? ' selected' : '') + '>' + label + '</option>');
        }

        if (normalizedDefault && !matchedStandard) {
            const safeVal   = normalizedDefault.replace(/"/g, '&quot;');
            const safeLabel = rawDefault.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            options.unshift('<option value="' + safeVal + '" selected>' + safeLabel + '</option>');
        }

        return options.join('');
    }

    function getTimeFieldDisplayLabel(fieldEl) {
        if (!fieldEl) return '';
        if (fieldEl.tagName && fieldEl.tagName.toLowerCase() === 'select' && fieldEl.selectedIndex >= 0) {
            return fieldEl.options[fieldEl.selectedIndex].text;
        }
        return fieldEl.value || '';
    }

    function sywatMarkNameAsCustomized() {
        sywatTournamentNameIsCustomized = true;
    }

    function sywatToggleTournamentNameLock(btnElem) {
        const inputBtn    = btnElem || document.getElementById('sywat_toggle_name_lock_btn');
        const inputName   = document.getElementById('sywat_modal_tournament_name');
        const statusLabel = document.getElementById('sywat_name_status_label');

        if (!inputName || !inputBtn) return;

        sywatTournamentNameIsUnlocked = !sywatTournamentNameIsUnlocked;

        if (sywatTournamentNameIsUnlocked) {
            inputName.removeAttribute('readonly');
            inputName.classList.remove('sywat-input-disabled-calc');
            inputBtn.textContent = '🔓 Unlocked';
            inputBtn.classList.remove('sywat-btn-secondary');
            inputBtn.classList.add('sywat-btn-warning');
            if (statusLabel) statusLabel.textContent = '(Custom name active)';
            sywatTournamentNameIsCustomized = true;
        } else {
            inputName.setAttribute('readonly', 'readonly');
            inputName.classList.add('sywat-input-disabled-calc');
            inputBtn.textContent = '🔒 Locked';
            inputBtn.classList.remove('sywat-btn-warning');
            inputBtn.classList.add('sywat-btn-secondary');
            if (statusLabel) statusLabel.textContent = '(Auto-generated)';
            sywatTournamentNameIsCustomized = false;
            sywatAutoGenerateTournamentName(true);
        }

        if (inputBtn && typeof inputBtn.blur === 'function') {
            inputBtn.blur();
        }
    }

    /**
     * Calculates and auto-populates the End Date field with the first Sunday on or after the Start Date.
     */
    function sywatAutoPopulateEndDate() {
        const startDateInput = document.getElementById('sywat_modal_start_date');
        const endDateInput   = document.getElementById('sywat_modal_end_date');

        if (!startDateInput || !endDateInput) return;

        const startVal = startDateInput.value.trim();
        if (!startVal) return;

        const parts = startVal.split('-');
        if (parts.length !== 3) return;

        const year  = parseInt(parts[0], 10);
        const month = parseInt(parts[1], 10) - 1;
        const day   = parseInt(parts[2], 10);

        const startDate = new Date(year, month, day);
        if (isNaN(startDate.getTime())) return;

        // Day of week: 0 = Sunday, 1 = Monday, ..., 5 = Friday, 6 = Saturday
        const dayOfWeek = startDate.getDay();
        const daysUntilSunday = (7 - dayOfWeek) % 7;

        const endDate = new Date(startDate);
        endDate.setDate(startDate.getDate() + daysUntilSunday);

        const endYear  = endDate.getFullYear();
        const endMonth = String(endDate.getMonth() + 1).padStart(2, '0');
        const endDay   = String(endDate.getDate()).padStart(2, '0');

        endDateInput.value = `${endYear}-${endMonth}-${endDay}`;
    }

    /**
     * Resolves the active shop element (<option> in Create select or hidden <input> in Edit/Shop Manager mode).
     */
    function getSelectedShopSourceElement() {
        const shopSelect = document.getElementById('sywat_modal_shop_id');
        if (!shopSelect) return null;

        if (shopSelect.tagName.toLowerCase() === 'select') {
            return shopSelect.selectedIndex >= 0 ? shopSelect.options[shopSelect.selectedIndex] : null;
        }
        return shopSelect;
    }

    /**
     * Retrieves default line capacity limits (20yd and Cub) for the currently selected shop in the modal.
     */
    function sywatGetSelectedShopCapacities() {
        let cap20  = 20;
        let capCub = 5;

        const sourceEl = getSelectedShopSourceElement();
        if (sourceEl) {
            const raw20  = sourceEl.getAttribute('data-cap20');
            const rawCub = sourceEl.getAttribute('data-capcub');
            if (raw20 !== null && raw20 !== '') {
                const parsed20 = parseInt(raw20, 10);
                if (!isNaN(parsed20)) cap20 = parsed20;
            }
            if (rawCub !== null && rawCub !== '') {
                const parsedCub = parseInt(rawCub, 10);
                if (!isNaN(parsedCub)) capCub = parsedCub;
            }
        }

        return { cap20, capCub };
    }

    /**
     * Retrieves the host shop's normalized default shooting lines array from data-default-lines.
     */
    function sywatGetSelectedShopDefaultLines() {
        const sourceEl = getSelectedShopSourceElement();
        if (!sourceEl) return [];

        try {
            const parsed = JSON.parse(sourceEl.getAttribute('data-default-lines') || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Builds a repeater table row <tr> for #sywat_tourn_lines_table using standardized 30-minute AM/PM time dropdowns.
     */
    function buildTournamentLineRow(dayVal, timeVal, cap20Val, capCubVal) {
        const { cap20, capCub } = sywatGetSelectedShopCapacities();
        const resolvedCap20  = (cap20Val !== undefined && cap20Val !== null && cap20Val !== '') ? cap20Val : cap20;
        const resolvedCapCub = (capCubVal !== undefined && capCubVal !== null && capCubVal !== '') ? capCubVal : capCub;
        const resolvedTime   = (timeVal !== undefined && timeVal !== null && timeVal !== '') ? timeVal : '10AM';

        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' +
                '<select name="line_days[]" onchange="checkTournamentLineDuplicate(this);">' +
                    '<option value="" disabled>-- Select --</option>' +
                    '<option value="Friday">Friday</option>' +
                    '<option value="Saturday">Saturday</option>' +
                    '<option value="Sunday">Sunday</option>' +
                '</select>' +
            '</td>' +
            '<td>' +
                '<select name="line_times[]" required onchange="checkTournamentLineDuplicate(this);">' +
                    buildStandardLineTimeOptionsHtml(resolvedTime) +
                '</select>' +
            '</td>' +
            '<td class="sywat-col-center">' +
                '<input type="number" name="cap_20yd[]" min="0" class="sywat-col-num-input" required>' +
            '</td>' +
            '<td class="sywat-col-center">' +
                '<input type="number" name="cap_cub[]" min="0" class="sywat-col-num-input" required>' +
            '</td>' +
            '<td class="sywat-col-center">' +
                '<button type="button" class="sywat-btn sywat-btn-danger sywat-btn-sm" onclick="removeTournamentLineRow(this)">Remove</button>' +
            '</td>';

        const daySelect   = tr.querySelector('select[name="line_days[]"]');
        const cap20Input  = tr.querySelector('input[name="cap_20yd[]"]');
        const capCubInput = tr.querySelector('input[name="cap_cub[]"]');

        if (daySelect) {
            daySelect.value = dayVal || '';
        }
        if (cap20Input) {
            cap20Input.value = resolvedCap20;
        }
        if (capCubInput) {
            capCubInput.value = resolvedCapCub;
        }

        return tr;
    }

    /**
     * Updates all repeater table line capacity input fields with the active shop's default limits.
     */
    function sywatSyncShopCapacities() {
        const { cap20, capCub } = sywatGetSelectedShopCapacities();
        const tableBody = document.querySelector('#sywat_tourn_lines_table tbody');
        if (!tableBody) return;

        tableBody.querySelectorAll('input[name="cap_20yd[]"]').forEach(function (input) {
            input.value = cap20;
        });

        tableBody.querySelectorAll('input[name="cap_cub[]"]').forEach(function (input) {
            input.value = capCub;
        });
    }

    /**
     * Resets #sywat_tourn_lines_table rows and capacities to the active host shop's configured defaults.
     */
    function resetTournamentLinesToShopDefaults() {
        const shopSelect = document.getElementById('sywat_modal_shop_id');
        if (shopSelect && shopSelect.tagName.toLowerCase() === 'select' && !shopSelect.value) {
            window.alert('Please select a Host Archery Shop first.');
            shopSelect.focus();
            return;
        }

        const tableBody = document.querySelector('#sywat_tourn_lines_table tbody');
        if (!tableBody) return;

        const confirmed = window.confirm(
            'Reset shooting lines and capacities to this shop\'s configured defaults?\n\n' +
            'If archers are already registered on this tournament, please verify their line assignments after saving. ' +
            'Changes take effect when you click "Save Tournament Configuration".'
        );
        if (!confirmed) return;

        const defaultLines = sywatGetSelectedShopDefaultLines();
        if (defaultLines.length > 0) {
            tableBody.innerHTML = '';
            defaultLines.forEach(function (line) {
                tableBody.appendChild(
                    buildTournamentLineRow(
                        line.day || '',
                        line.time || '10AM',
                        line.cap_20yd,
                        line.cap_cub
                    )
                );
            });
        } else {
            sywatSyncShopCapacities();
        }
    }

    /**
     * Appends a repeatable External Online Registration Link row (Label + URL) in #sywat_tourn_reg_links_container.
     */
    function addTournamentRegLinkRow(prefillLabel, prefillUrl, shouldFocus) {
        const container = document.getElementById('sywat_tourn_reg_links_container');
        if (!container) return;

        setElementVisible(container, true);

        const row = document.createElement('div');
        row.className = 'sywat-reg-link-row sywat-form-grid-2 sywat-mt-3';
        row.innerHTML =
            '<div class="sywat-field-group">' +
                '<label>Link Label <span class="sywat-label-subtext">(Optional for single link)</span></label>' +
                '<input type="text" name="tourn_reg_link_labels[]" placeholder="e.g. 10-yard lines (Cubs)">' +
            '</div>' +
            '<div class="sywat-field-group">' +
                '<label>Registration URL *</label>' +
                '<div class="sywat-search-input-group">' +
                    '<input type="text" name="tourn_reg_link_urls[]" placeholder="https://fareharbor.com/embeds/book/...">' +
                    '<button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" data-sywat-action="remove-tourn-reg-link">Remove</button>' +
                '</div>' +
            '</div>';

        const labelInput = row.querySelector('input[name="tourn_reg_link_labels[]"]');
        const urlInput   = row.querySelector('input[name="tourn_reg_link_urls[]"]');
        if (labelInput && typeof prefillLabel === 'string') labelInput.value = prefillLabel;
        if (urlInput && typeof prefillUrl === 'string') urlInput.value = prefillUrl;

        container.appendChild(row);

        if (shouldFocus && labelInput) {
            labelInput.focus();
        }
    }

    /**
     * Removes a repeatable External Online Registration Link row and collapses the container when empty.
     */
    function removeTournamentRegLinkRow(btn) {
        const row = btn.closest('.sywat-reg-link-row');
        if (!row) return;

        const container = document.getElementById('sywat_tourn_reg_links_container');
        row.remove();

        if (container && container.querySelectorAll('.sywat-reg-link-row').length === 0) {
            setElementVisible(container, false);
        }
    }

    /**
     * Synchronizes Offered Round / Pricing Format visibility and checkboxes with the selected
     * Competition Season when creating a new tournament.
     */
    function sywatSyncTournamentPricingProfiles() {
        const wrap        = document.getElementById('sywat_modal_pricing_profiles_wrap');
        const seasonField = document.getElementById('sywat_modal_season_slug');

        if (!wrap || !seasonField || seasonField.tagName.toLowerCase() !== 'select') {
            return;
        }

        let seasonMap = {};
        try {
            seasonMap = JSON.parse(wrap.getAttribute('data-season-profiles') || '{}');
        } catch (e) {
            seasonMap = {};
        }

        const selectedSeason = String(seasonField.value || '').trim();
        const targetSlugs    = Array.isArray(seasonMap[selectedSeason]) ? seasonMap[selectedSeason] : [];
        const optionLabels   = wrap.querySelectorAll('.sywat-tourn-pricing-option');
        const checkboxes     = wrap.querySelectorAll('.sywat-tourn-pricing-profile-cb');

        if (!checkboxes.length || targetSlugs.length === 0) {
            optionLabels.forEach(function (lbl) {
                setElementVisible(lbl, true);
            });
            return;
        }

        checkboxes.forEach(function (cb) {
            cb.checked = targetSlugs.indexOf(cb.value) !== -1;
        });

        optionLabels.forEach(function (lbl) {
            const slug = lbl.getAttribute('data-profile-slug') || '';
            setElementVisible(lbl, targetSlugs.indexOf(slug) !== -1);
        });
    }

    function sywatAutoGenerateTournamentName(force) {
        sywatSyncShopCapacities();

        if (sywatTournamentNameIsCustomized && !force) return;

        sywatAutoPopulateEndDate();

        const shopSelect     = document.getElementById('sywat_modal_shop_id');
        const startDateInput = document.getElementById('sywat_modal_start_date');
        const nameInput      = document.getElementById('sywat_modal_tournament_name');

        if (!shopSelect || !nameInput) return;

        let shopName = '';
        if (shopSelect.tagName.toLowerCase() === 'select') {
            const selectedOption = shopSelect.options[shopSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.name) {
                shopName = selectedOption.dataset.name;
            }
        } else {
            shopName = shopSelect.dataset.name || '';
        }

        if (!shopName) return;

        let rawEvents = '';
        if (shopSelect.tagName.toLowerCase() === 'select' && shopSelect.selectedIndex >= 0) {
            rawEvents = shopSelect.options[shopSelect.selectedIndex].getAttribute('data-events');
        }
        let events = [];
        if (rawEvents) {
            try {
                events = JSON.parse(rawEvents);
            } catch (e) {
                events = [];
            }
        }

        const newDate = startDateInput ? startDateInput.value.trim() : '';

        if (!newDate) {
            let currentCount = 0;
            if (shopSelect.tagName.toLowerCase() === 'select' && shopSelect.selectedIndex >= 0) {
                currentCount = parseInt(shopSelect.options[shopSelect.selectedIndex].getAttribute('data-count') || '0', 10);
            }
            nameInput.value = `${shopName.trim()} - ${currentCount + 1}`;
            return;
        }

        let seqNum = 1;
        for (let i = 0; i < events.length; i++) {
            const evt = events[i];
            if (evt.start_date && newDate > evt.start_date) {
                seqNum++;
            }
        }

        nameInput.value = `${shopName.trim()} - ${seqNum}`;
    }

    function openAddTournamentModal() {
        const modal = document.getElementById('sywat_add_tourn_modal');
        if (modal) modal.classList.add('active');

        const nameInput = document.getElementById('sywat_modal_tournament_name');
        if (nameInput && !nameInput.value) {
            sywatTournamentNameIsCustomized = false;
        }

        sywatSyncShopCapacities();
        sywatSyncTournamentPricingProfiles();
    }

    function closeAddTournamentModal() {
        const modal = document.getElementById('sywat_add_tourn_modal');
        if (modal) modal.classList.remove('active');
        sywatTournamentNameIsCustomized = false;
        const url = new URL(window.location.href);
        url.searchParams.delete('open_add_tournament');
        url.searchParams.delete('edit_tournament_modal');
        window.history.replaceState({}, document.title, url.toString());
    }

    function openAddSeasonModal() {
        const modal = document.getElementById('sywat_add_season_modal');
        if (modal) modal.classList.add('active');
    }

    function closeAddSeasonModal() {
        const modal = document.getElementById('sywat_add_season_modal');
        if (modal) modal.classList.remove('active');
        const url = new URL(window.location.href);
        url.searchParams.delete('edit_season');
        url.searchParams.delete('open_add_season');
        window.history.replaceState({}, document.title, url.toString());
    }

    function openImportScheduleModal() {
        const modal = document.getElementById('sywat_import_schedule_modal');
        if (modal) modal.classList.add('active');
    }

    function closeImportScheduleModal() {
        const modal = document.getElementById('sywat_import_schedule_modal');
        if (modal) modal.classList.remove('active');
    }

    function closeSywatModal() {
        const modal = document.getElementById('sywat_modal');
        if (modal) modal.classList.remove('active');
        const url = new URL(window.location.href);
        url.searchParams.delete('view_tournament');
        url.searchParams.delete('modal_action');
        url.searchParams.delete('filter_line_idx');
        url.searchParams.delete('search_shooter');
        window.history.replaceState({}, document.title, url.toString());
    }

    function checkTournamentLineDuplicate(el) {
        const tr = el.closest('tr');
        const table = el.closest('table');
        if (!tr || !table) return true;

        const daySelect = tr.querySelector('select[name="line_days[]"]');
        const timeField = tr.querySelector('select[name="line_times[]"], input[name="line_times[]"]');

        const dayVal = (daySelect ? daySelect.value : '').trim();
        const timeVal = normalizeLineTimeToken(timeField ? timeField.value : '').toLowerCase();

        if (!dayVal || !timeVal) return true;

        const currentCombo = dayVal.toLowerCase() + ' @ ' + timeVal;

        const allRows = table.querySelectorAll('tbody tr');
        for (let i = 0; i < allRows.length; i++) {
            const row = allRows[i];
            if (row === tr) continue;

            const otherDaySelect = row.querySelector('select[name="line_days[]"]');
            const otherTimeField = row.querySelector('select[name="line_times[]"], input[name="line_times[]"]');

            const otherDay = (otherDaySelect ? otherDaySelect.value : '').trim().toLowerCase();
            const otherTime = normalizeLineTimeToken(otherTimeField ? otherTimeField.value : '').toLowerCase();

            if (otherDay && otherTime && (otherDay + ' @ ' + otherTime) === currentCombo) {
                window.alert(`Duplicate tournament line schedule detected: "${dayVal} ${getTimeFieldDisplayLabel(timeField)}". This day and start time is already assigned to another line.`);
                if (daySelect) {
                    daySelect.value = '';
                    daySelect.focus();
                }
                return false;
            }
        }
        return true;
    }

    function addTournamentLineRow() {
        const tableBody = document.querySelector('#sywat_tourn_lines_table tbody');
        if (!tableBody) return;

        const { cap20, capCub } = sywatGetSelectedShopCapacities();
        tableBody.appendChild(buildTournamentLineRow('', '10AM', cap20, capCub));
    }

    function removeTournamentLineRow(btn) {
        const tr = btn.closest('tr');
        const tableBody = document.querySelector('#sywat_tourn_lines_table tbody');
        if (tableBody && tableBody.children.length <= 1) {
            window.alert('A tournament must have at least one shooting line defined.');
            return;
        }
        if (tr) tr.remove();
    }

    function validateTournamentLines() {
        const pricingWrap = document.getElementById('sywat_modal_pricing_profiles_wrap');
        if (pricingWrap) {
            const checkedFormats = pricingWrap.querySelectorAll('.sywat-tourn-pricing-profile-cb:checked');
            if (checkedFormats.length === 0) {
                window.alert('Please select at least one Offered Round / Pricing Format for this tournament.');
                return false;
            }
        }

        const regUrlInputs = document.querySelectorAll('#sywat_tourn_reg_links_container input[name="tourn_reg_link_urls[]"]');
        for (let i = 0; i < regUrlInputs.length; i++) {
            const rawUrl = (regUrlInputs[i].value || '').trim();
            if (rawUrl !== '' && !/^https?:\/\/.+/i.test(rawUrl)) {
                window.alert(`Registration URL #${i + 1} must begin with http:// or https://`);
                regUrlInputs[i].focus();
                return false;
            }
        }

        const table = document.querySelector('#sywat_tourn_lines_table');
        if (!table) return true;

        const daySelects = table.querySelectorAll('select[name="line_days[]"]');
        const timeFields = table.querySelectorAll('select[name="line_times[]"], input[name="line_times[]"]');

        const seen = new Map();
        for (let i = 0; i < daySelects.length; i++) {
            const dayVal = (daySelects[i] ? daySelects[i].value : '').trim();
            const timeVal = normalizeLineTimeToken(timeFields[i] ? timeFields[i].value : '').toLowerCase();

            if (!dayVal) {
                window.alert(`Please select a Shooting Day for row ${i + 1}.`);
                if (daySelects[i]) daySelects[i].focus();
                return false;
            }

            if (dayVal && timeVal) {
                const combo = dayVal + ' @ ' + timeVal;
                if (seen.has(combo)) {
                    window.alert(`Duplicate tournament line schedule detected: "${dayVal} ${getTimeFieldDisplayLabel(timeFields[i])}". Please choose a different day or start time.`);
                    daySelects[i].value = '';
                    daySelects[i].focus();
                    return false;
                }
                seen.set(combo, i);
            }
        }
        return true;
    }

    function sywatApplyTournamentFilters() {
        const districtSelect = document.getElementById('sywat_tournament_district_filter');
        const searchInput    = document.getElementById('sywat_tournament_search_input');

        const districtFilter = districtSelect ? districtSelect.value.toLowerCase().trim() : 'all';
        const searchFilter   = searchInput ? searchInput.value.toLowerCase().trim() : '';

        if (searchInput) {
            sessionStorage.setItem('sywat_tournament_search', searchInput.value);
        }

        const rows = document.querySelectorAll('.sywat-tournament-row');

        rows.forEach(function (row) {
            const name     = (row.getAttribute('data-name') || '').toLowerCase();
            const shop     = (row.getAttribute('data-shop') || '').toLowerCase();
            const district = (row.getAttribute('data-district') || '').toLowerCase();
            const fullText = row.textContent.toLowerCase();

            const districtMatch = (districtFilter === 'all' || district === districtFilter || district.includes(districtFilter));
            const searchMatch   = (!searchFilter || name.includes(searchFilter) || shop.includes(searchFilter) || district.includes(searchFilter) || fullText.includes(searchFilter));

            setElementVisible(row, districtMatch && searchMatch);
        });
    }

    function sywatResetTournamentFilters() {
        const districtSelect = document.getElementById('sywat_tournament_district_filter');
        const searchInput    = document.getElementById('sywat_tournament_search_input');

        if (districtSelect) districtSelect.value = 'all';
        if (searchInput) searchInput.value = '';

        sessionStorage.removeItem('sywat_tournament_search');

        sywatApplyTournamentFilters();

        if (document.activeElement && typeof document.activeElement.blur === 'function') {
            document.activeElement.blur();
        }
    }

    /**
     * Open the TFAA Scorecards Batch Sync Progress Modal & Start Asynchronous Queue
     */
    function sywatOpenTfaaSyncModal() {
        const modal = document.getElementById('sywat_tfaa_sync_modal');
        if (!modal) return;

        const logConsole   = document.getElementById('sywat_tfaa_sync_log_console');
        const progressFill = document.getElementById('sywat_tfaa_sync_progress_fill');
        const statusText   = document.getElementById('sywat_tfaa_sync_status_text');

        if (logConsole) logConsole.innerHTML = '';
        if (progressFill) progressFill.style.width = '0%';
        if (statusText) statusText.textContent = 'Initialising tournament queue...';

        modal.classList.add('active');

        const tournamentRows = document.querySelectorAll('.sywat-tournament-row');
        const tournamentList = [];

        tournamentRows.forEach(function (row) {
            const titleElem = row.querySelector('.sywat-tourn-title');
            if (titleElem && titleElem.textContent.trim()) {
                tournamentList.push(titleElem.textContent.trim());
            }
        });

        if (tournamentList.length === 0) {
            if (statusText) statusText.textContent = 'No tournaments found in table to sync.';
            return;
        }

        const nonceElem = document.querySelector('input[name="sywat_nonce"]');
        const nonce = nonceElem ? nonceElem.value : '';

        sywatRunTfaaBatchSync(tournamentList, nonce);
    }

    /**
     * Sequentially executes single-tournament TFAA score sync AJAX calls with robust response validation.
     *
     * @param {Array}  tournaments List of tournament names.
     * @param {string} nonce       WordPress security nonce.
     */
    async function sywatRunTfaaBatchSync(tournaments, nonce) {
        const logConsole   = document.getElementById('sywat_tfaa_sync_log_console');
        const progressFill = document.getElementById('sywat_tfaa_sync_progress_fill');
        const statusText   = document.getElementById('sywat_tfaa_sync_status_text');
        const requestUrl   = window.location.href;

        let successCount = 0;
        let failureCount = 0;

        for (let i = 0; i < tournaments.length; i++) {
            const tName = tournaments[i];
            const step  = i + 1;

            if (statusText) {
                statusText.textContent = `Syncing tournament ${step} of ${tournaments.length}: "${tName}"...`;
            }

            const formData = new FormData();
            formData.append('sywat_action', 'sync_tfaa_single_json');
            formData.append('tournament_name', tName);
            formData.append('sywat_nonce', nonce);

            try {
                const response = await fetch(requestUrl, {
                    method: 'POST',
                    body: formData
                });

                const percent = Math.round((step / tournaments.length) * 100);
                if (progressFill) {
                    progressFill.style.width = `${percent}%`;
                }

                const rawText = await response.text();
                let result = null;

                try {
                    result = JSON.parse(rawText);
                } catch (jsonErr) {
                    result = null;
                }

                const logItem = document.createElement('div');
                logItem.className = 'sywat-log-item';

                if (response.ok && result && result.success) {
                    successCount++;
                    logItem.classList.add('sywat-log-success');
                    logItem.innerHTML = `🟢 <strong>${tName}:</strong> ${result.data.message || 'Synced successfully.'}`;
                } else {
                    failureCount++;
                    logItem.classList.add('sywat-log-error');
                    let errMsg = '';

                    if (result && result.data && result.data.message) {
                        errMsg = result.data.message;
                    } else if (!response.ok) {
                        errMsg = `HTTP ${response.status} ${response.statusText || 'Error'}`;
                    } else if (rawText) {
                        errMsg = `Server response: ${rawText.slice(0, 100)}...`;
                    } else {
                        errMsg = 'Unknown server response.';
                    }

                    logItem.innerHTML = `⚠️ <strong>${tName}:</strong> ${errMsg}`;
                }

                if (logConsole) {
                    logConsole.appendChild(logItem);
                    logConsole.scrollTop = logConsole.scrollHeight;
                }
            } catch (err) {
                failureCount++;
                const percent = Math.round((step / tournaments.length) * 100);

                if (progressFill) {
                    progressFill.style.width = `${percent}%`;
                }

                const logItem = document.createElement('div');
                logItem.className = 'sywat-log-item sywat-log-error';
                logItem.innerHTML = `⚠️ <strong>${tName}:</strong> Network connection error (${err.message || 'fetch failed'}).`;

                if (logConsole) {
                    logConsole.appendChild(logItem);
                    logConsole.scrollTop = logConsole.scrollHeight;
                }
            }
        }

        if (statusText) {
            statusText.textContent = `Batch sync complete! ${successCount} succeeded, ${failureCount} failed across ${tournaments.length} tournament(s).`;
        }
    }

    /**
     * Close the TFAA Scores Batch Sync Progress Modal
     */
    function sywatCloseTfaaSyncModal() {
        const modal = document.getElementById('sywat_tfaa_sync_modal');
        if (modal) {
            modal.classList.remove('active');
        }
    }

    // Delegated Click Listener (Optimizer-Safe — Section IV.1)
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-sywat-action]');
        if (!trigger) return;

        const action = trigger.getAttribute('data-sywat-action');
        if (action === 'add-tourn-reg-link') {
            e.preventDefault();
            addTournamentRegLinkRow('', '', true);
        } else if (action === 'remove-tourn-reg-link') {
            e.preventDefault();
            removeTournamentRegLinkRow(trigger);
        } else if (action === 'reset-tourn-lines') {
            e.preventDefault();
            resetTournamentLinesToShopDefaults();
        }
    });

    function initTournamentsModule() {
        const notice = document.getElementById('sywat-notice');
        if (notice) {
            window.setTimeout(function () {
                notice.classList.add('sywat-fade-out');
                window.setTimeout(function () {
                    notice.remove();
                }, 500);
            }, 5000);
        }

        const seasonSearchInput = document.getElementById('sywat_season_search_input');
        if (seasonSearchInput) {
            seasonSearchInput.addEventListener('input', function () {
                const filter = this.value.toLowerCase().trim();
                document.querySelectorAll('.sywat-season-row').forEach(function (row) {
                    const searchStr = (row.getAttribute('data-search') || '').toLowerCase();
                    setElementVisible(row, searchStr.includes(filter));
                });
            });
        }

        // Bind Season -> Pricing Format filtering & inheritance in Create Tournament mode
        const modalSeasonSelect = document.getElementById('sywat_modal_season_slug');
        if (modalSeasonSelect && modalSeasonSelect.tagName.toLowerCase() === 'select') {
            modalSeasonSelect.addEventListener('change', function () {
                sywatSyncTournamentPricingProfiles();
            });
        }

        // Restore search persistence from sessionStorage
        const searchInput = document.getElementById('sywat_tournament_search_input');
        if (searchInput) {
            const savedSearch = sessionStorage.getItem('sywat_tournament_search');
            if (savedSearch !== null && savedSearch !== undefined) {
                searchInput.value = savedSearch;
                sywatApplyTournamentFilters();
            }
        }

        // Synchronize capacity inputs and formats in Create mode ONLY if modal is active on page paint
        const activeTournModal = document.getElementById('sywat_add_tourn_modal');
        const linesTable       = document.getElementById('sywat_tourn_lines_table');
        const isEditMode       = linesTable && linesTable.getAttribute('data-is-edit') === '1';

        if (activeTournModal && activeTournModal.classList.contains('active') && !isEditMode) {
            sywatSyncShopCapacities();
            if (modalSeasonSelect && modalSeasonSelect.tagName.toLowerCase() === 'select') {
                sywatSyncTournamentPricingProfiles();
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTournamentsModule);
    } else {
        initTournamentsModule();
    }

    // Namespaced Public API (Section IV.1)
    window.SYWAT.tournaments = {
        markNameAsCustomized: sywatMarkNameAsCustomized,
        toggleNameLock: sywatToggleTournamentNameLock,
        autoPopulateEndDate: sywatAutoPopulateEndDate,
        getSelectedShopCapacities: sywatGetSelectedShopCapacities,
        getSelectedShopDefaultLines: sywatGetSelectedShopDefaultLines,
        syncShopCapacities: sywatSyncShopCapacities,
        resetLinesToShopDefaults: resetTournamentLinesToShopDefaults,
        addRegLinkRow: addTournamentRegLinkRow,
        removeRegLinkRow: removeTournamentRegLinkRow,
        syncPricingProfiles: sywatSyncTournamentPricingProfiles,
        autoGenerateName: sywatAutoGenerateTournamentName,
        openAddModal: openAddTournamentModal,
        closeAddModal: closeAddTournamentModal,
        openAddSeasonModal: openAddSeasonModal,
        closeAddSeasonModal: closeAddSeasonModal,
        openImportScheduleModal: openImportScheduleModal,
        closeImportScheduleModal: closeImportScheduleModal,
        closeSywatModal: closeSywatModal,
        checkLineDuplicate: checkTournamentLineDuplicate,
        addLineRow: addTournamentLineRow,
        removeLineRow: removeTournamentLineRow,
        validateLines: validateTournamentLines,
        applyFilters: sywatApplyTournamentFilters,
        resetFilters: sywatResetTournamentFilters,
        openTfaaSyncModal: sywatOpenTfaaSyncModal,
        runTfaaBatchSync: sywatRunTfaaBatchSync,
        closeTfaaSyncModal: sywatCloseTfaaSyncModal
    };

    // Legacy Global Compatibility Aliases (Strictly for Existing HTML Attribute Handlers)
    window.sywatMarkNameAsCustomized       = sywatMarkNameAsCustomized;
    window.sywatToggleTournamentNameLock   = sywatToggleTournamentNameLock;
    window.sywatAutoPopulateEndDate        = sywatAutoPopulateEndDate;
    window.sywatAutoGenerateTournamentName = sywatAutoGenerateTournamentName;
    window.openAddTournamentModal          = openAddTournamentModal;
    window.closeAddTournamentModal         = closeAddTournamentModal;
    window.openAddSeasonModal              = openAddSeasonModal;
    window.closeAddSeasonModal             = closeAddSeasonModal;
    window.openImportScheduleModal         = openImportScheduleModal;
    window.closeImportScheduleModal        = closeImportScheduleModal;
    window.closeSywatModal                 = closeSywatModal;
    window.checkTournamentLineDuplicate    = checkTournamentLineDuplicate;
    window.addTournamentLineRow            = addTournamentLineRow;
    window.removeTournamentLineRow         = removeTournamentLineRow;
    window.validateTournamentLines         = validateTournamentLines;
    window.sywatApplyTournamentFilters     = sywatApplyTournamentFilters;
    window.sywatResetTournamentFilters     = sywatResetTournamentFilters;
    window.sywatOpenTfaaSyncModal          = sywatOpenTfaaSyncModal;
    window.sywatRunTfaaBatchSync           = sywatRunTfaaBatchSync;
    window.sywatCloseTfaaSyncModal         = sywatCloseTfaaSyncModal;
})();
