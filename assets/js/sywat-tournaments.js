/**
 * SYWAT Archery - Master Tournament & Season Management Client Engine
 * Path: assets/js/sywat-tournaments.js
 * Description: Manages Add/Edit Tournament modal auto-naming, shop capacity & external registration link inheritance, repeatable registration link rows, season pricing format inheritance, shooting line duplicate validation, filters, and asynchronous TFAA scorecard batch sync.
 * Compliance: Vanilla JS only (zero jQuery), namespaced under window.SYWAT.tournaments with legacy compatibility aliases, delegated data-sywat-action listeners, class-based visibility (.active / .sywat-hidden).
 * Author: SYWAT Archery
 * Version: 7.1.0
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
        if (visible) {
            el.classList.remove('sywat-hidden');
        } else {
            el.classList.add('sywat-hidden');
        }
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
     * Retrieves default line capacity limits (20yd and Cub) for the currently selected shop in the modal.
     */
    function sywatGetSelectedShopCapacities() {
        const shopSelect = document.getElementById('sywat_modal_shop_id');
        let cap20 = 20;
        let capCub = 5;

        if (!shopSelect) {
            return { cap20, capCub };
        }

        if (shopSelect.tagName.toLowerCase() === 'select') {
            const selectedOption = shopSelect.options[shopSelect.selectedIndex];
            if (selectedOption) {
                const raw20  = selectedOption.getAttribute('data-cap20');
                const rawCub = selectedOption.getAttribute('data-capcub');
                if (raw20)  cap20  = parseInt(raw20, 10) || 20;
                if (rawCub) capCub = parseInt(rawCub, 10) || 5;
            }
        } else if (shopSelect.tagName.toLowerCase() === 'input') {
            const raw20  = shopSelect.getAttribute('data-cap20');
            const rawCub = shopSelect.getAttribute('data-capcub');
            if (raw20)  cap20  = parseInt(raw20, 10) || 20;
            if (rawCub) capCub = parseInt(rawCub, 10) || 5;
        }

        return { cap20, capCub };
    }

    /**
     * Updates all repeater table line capacity input fields with the active shop's default limits.
     */
    function sywatSyncShopCapacities() {
        const { cap20, capCub } = sywatGetSelectedShopCapacities();
        const tableBody = document.querySelector('#sywat_tourn_lines_table tbody');
        if (!tableBody) return;

        const cap20Inputs  = tableBody.querySelectorAll('input[name="cap_20yd[]"]');
        const capCubInputs = tableBody.querySelectorAll('input[name="cap_cub[]"]');

        cap20Inputs.forEach(function (input) {
            input.value = cap20;
        });

        capCubInputs.forEach(function (input) {
            input.value = capCub;
        });
    }

    /**
     * Retrieves default external registration links (Array<{label, url}>) for the currently selected host shop.
     */
    function sywatGetSelectedShopRegLinks() {
        const shopSelect = document.getElementById('sywat_modal_shop_id');
        if (!shopSelect) {
            return [];
        }

        let rawJson = '[]';
        if (shopSelect.tagName.toLowerCase() === 'select') {
            const selectedOption = shopSelect.options[shopSelect.selectedIndex];
            if (selectedOption) {
                rawJson = selectedOption.getAttribute('data-reglinks') || '[]';
            }
        } else if (shopSelect.tagName.toLowerCase() === 'input') {
            rawJson = shopSelect.getAttribute('data-reglinks') || '[]';
        }

        try {
            const parsed = JSON.parse(rawJson);
            if (!Array.isArray(parsed)) {
                return [];
            }
            return parsed.filter(function (item) {
                return item && typeof item === 'object' && typeof item.url === 'string' && item.url.trim() !== '';
            });
        } catch (e) {
            return [];
        }
    }

    /**
     * Appends a repeatable External Online Registration Link row (Label + URL) in #sywat_tourn_reg_links_container.
     */
    function addTournamentRegLinkRow(prefillLabel, prefillUrl, shouldFocus) {
        const container = document.getElementById('sywat_tourn_reg_links_container');
        if (!container) return;

        const emptyMsg = document.getElementById('sywat_tourn_no_reg_links_msg');
        setElementVisible(emptyMsg, false);

        const row = document.createElement('div');
        row.className = 'sywat-reg-link-row sywat-form-grid-2 sywat-mt-3';

        const labelGroup = document.createElement('div');
        labelGroup.className = 'sywat-field-group';
        const labelEl = document.createElement('label');
        labelEl.innerHTML = 'Link Label <span class="sywat-label-subtext">(Optional for single link)</span>';
        const labelInput = document.createElement('input');
        labelInput.type = 'text';
        labelInput.name = 'tourn_reg_link_labels[]';
        labelInput.placeholder = 'e.g. 10-yard lines (Cubs)';
        labelInput.value = typeof prefillLabel === 'string' ? prefillLabel : '';
        labelGroup.appendChild(labelEl);
        labelGroup.appendChild(labelInput);

        const urlGroup = document.createElement('div');
        urlGroup.className = 'sywat-field-group';
        const urlLabelEl = document.createElement('label');
        urlLabelEl.textContent = 'Registration URL *';
        const inputWrap = document.createElement('div');
        inputWrap.className = 'sywat-search-input-group';
        const urlInput = document.createElement('input');
        urlInput.type = 'text';
        urlInput.name = 'tourn_reg_link_urls[]';
        urlInput.placeholder = 'https://fareharbor.com/embeds/book/...';
        urlInput.value = typeof prefillUrl === 'string' ? prefillUrl : '';

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'sywat-btn sywat-btn-danger sywat-btn-icon-delete';
        removeBtn.setAttribute('data-sywat-action', 'remove-tourn-reg-link');
        removeBtn.textContent = 'Remove';

        inputWrap.appendChild(urlInput);
        inputWrap.appendChild(removeBtn);
        urlGroup.appendChild(urlLabelEl);
        urlGroup.appendChild(inputWrap);

        row.appendChild(labelGroup);
        row.appendChild(urlGroup);
        container.appendChild(row);

        if (shouldFocus) {
            labelInput.focus();
        }
    }

    /**
     * Removes a repeatable External Online Registration Link row and toggles the empty-state notice.
     */
    function removeTournamentRegLinkRow(btn) {
        const row = btn.closest('.sywat-reg-link-row');
        if (!row) return;

        const container = document.getElementById('sywat_tourn_reg_links_container');
        row.remove();

        if (container && container.querySelectorAll('.sywat-reg-link-row').length === 0) {
            const emptyMsg = document.getElementById('sywat_tourn_no_reg_links_msg');
            setElementVisible(emptyMsg, true);
        }
    }

    /**
     * Populates #sywat_tourn_reg_links_container from the selected host shop's default links in Create mode.
     */
    function sywatSyncShopRegistrationLinks() {
        const container = document.getElementById('sywat_tourn_reg_links_container');
        if (!container) return;

        // Preserve saved tournament registration links when editing an existing tournament
        if (container.getAttribute('data-is-edit') === '1') {
            return;
        }

        const links = sywatGetSelectedShopRegLinks();
        container.innerHTML = '';

        const emptyMsg = document.getElementById('sywat_tourn_no_reg_links_msg');
        if (links.length === 0) {
            setElementVisible(emptyMsg, true);
            return;
        }

        setElementVisible(emptyMsg, false);
        links.forEach(function (item) {
            addTournamentRegLinkRow(item.label || '', item.url || '', false);
        });
    }

    /**
     * Synchronizes Offered Round / Pricing Format checkboxes with the selected
     * Competition Season when creating a new tournament.
     */
    function sywatSyncTournamentPricingProfiles() {
        const wrap        = document.getElementById('sywat_modal_pricing_profiles_wrap');
        const seasonField = document.getElementById('sywat_modal_season_slug');

        if (!wrap || !seasonField || seasonField.tagName.toLowerCase() !== 'select') {
            return;
        }

        const rawMap = wrap.getAttribute('data-season-profiles') || '{}';
        let seasonMap = {};
        try {
            seasonMap = JSON.parse(rawMap);
        } catch (e) {
            seasonMap = {};
        }

        const selectedSeason = String(seasonField.value || '').trim();
        const targetSlugs    = Array.isArray(seasonMap[selectedSeason]) ? seasonMap[selectedSeason] : [];
        const checkboxes     = wrap.querySelectorAll('.sywat-tourn-pricing-profile-cb');

        if (!checkboxes.length || targetSlugs.length === 0) {
            return;
        }

        checkboxes.forEach(function (cb) {
            cb.checked = targetSlugs.indexOf(cb.value) !== -1;
        });
    }

    function sywatAutoGenerateTournamentName(force) {
        // Always sync shop capacities & default registration links when shop selection changes
        sywatSyncShopCapacities();
        sywatSyncShopRegistrationLinks();

        if (sywatTournamentNameIsCustomized && !force) return;

        // Auto-populate End Date to the first Sunday following Start Date
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
            const nextNum = currentCount + 1;
            nameInput.value = `${shopName.trim()} - ${nextNum}`;
            return;
        }

        // Determine chronological sequence position based on start date
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

        // Reset flag if starting fresh
        const nameInput = document.getElementById('sywat_modal_tournament_name');
        if (nameInput && !nameInput.value) {
            sywatTournamentNameIsCustomized = false;
        }

        // Synchronize capacity inputs, registration links, and season-inherited pricing formats
        sywatSyncShopCapacities();
        sywatSyncShopRegistrationLinks();
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
        const timeInput = tr.querySelector('input[name="line_times[]"]');

        const dayVal = (daySelect ? daySelect.value : '').trim();
        const timeVal = (timeInput ? timeInput.value : '').trim().toLowerCase();

        if (!dayVal || !timeVal) return true;

        const currentCombo = dayVal.toLowerCase() + ' @ ' + timeVal;

        const allRows = table.querySelectorAll('tbody tr');
        for (let i = 0; i < allRows.length; i++) {
            const row = allRows[i];
            if (row === tr) continue;

            const otherDaySelect = row.querySelector('select[name="line_days[]"]');
            const otherTimeInput = row.querySelector('input[name="line_times[]"]');

            const otherDay = (otherDaySelect ? otherDaySelect.value : '').trim().toLowerCase();
            const otherTime = (otherTimeInput ? otherTimeInput.value : '').trim().toLowerCase();

            if (otherDay && otherTime && (otherDay + ' @ ' + otherTime) === currentCombo) {
                window.alert(`Duplicate tournament line schedule detected: "${dayVal} ${timeInput.value}". This day and start time is already assigned to another line.`);
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

        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' +
                '<div class="sywat-field-group">' +
                    '<select name="line_days[]" onchange="checkTournamentLineDuplicate(this);">' +
                        '<option value="" disabled selected>-- Select Day --</option>' +
                        '<option value="Friday">Friday</option>' +
                        '<option value="Saturday">Saturday</option>' +
                        '<option value="Sunday">Sunday</option>' +
                    '</select>' +
                '</div>' +
            '</td>' +
            '<td>' +
                '<div class="sywat-field-group">' +
                    '<input type="text" name="line_times[]" value="10:00 AM" placeholder="e.g. 10:00 AM" required onchange="checkTournamentLineDuplicate(this);">' +
                '</div>' +
            '</td>' +
            '<td class="sywat-col-center">' +
                '<input type="number" name="cap_20yd[]" value="' + cap20 + '" min="0" class="sywat-col-num-input" required>' +
            '</td>' +
            '<td class="sywat-col-center">' +
                '<input type="number" name="cap_cub[]" value="' + capCub + '" min="0" class="sywat-col-num-input" required>' +
            '</td>' +
            '<td class="sywat-col-center">' +
                '<button type="button" class="sywat-btn sywat-btn-danger sywat-btn-sm" onclick="removeTournamentLineRow(this)">Remove</button>' +
            '</td>';
        tableBody.appendChild(tr);
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

        // Validate external registration link URLs if entered
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
        const timeInputs = table.querySelectorAll('input[name="line_times[]"]');

        const seen = new Map();
        for (let i = 0; i < daySelects.length; i++) {
            const dayVal = (daySelects[i] ? daySelects[i].value : '').trim();
            const timeVal = (timeInputs[i] ? timeInputs[i].value : '').trim().toLowerCase();

            if (!dayVal) {
                window.alert(`Please select a Shooting Day for row ${i + 1}.`);
                if (daySelects[i]) daySelects[i].focus();
                return false;
            }

            if (dayVal && timeVal) {
                const combo = dayVal + ' @ ' + timeVal;
                if (seen.has(combo)) {
                    window.alert(`Duplicate tournament line schedule detected: "${dayVal} ${timeInputs[i].value}". Please choose a different day or start time.`);
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

        // Collect tournament names from active table rows
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

        // Capture the primary desk action nonce directly from the page
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

        // DIRECT PAGE BYPASS: Route traffic to the current page URL to hit our JSON interceptor hook
        const requestUrl = window.location.href;

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
                const rows = document.querySelectorAll('.sywat-season-row');
                rows.forEach(function (row) {
                    const searchStr = (row.getAttribute('data-search') || '').toLowerCase();
                    setElementVisible(row, searchStr.includes(filter));
                });
            });
        }

        // Bind Season -> Pricing Format inheritance in Create Tournament mode
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

        // Automatically synchronize capacity inputs, registration links, and formats in Create mode if modal is active on page paint
        const activeTournModal = document.getElementById('sywat_add_tourn_modal');
        if (activeTournModal && activeTournModal.classList.contains('active')) {
            sywatSyncShopCapacities();
            sywatSyncShopRegistrationLinks();
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
        syncShopCapacities: sywatSyncShopCapacities,
        getSelectedShopRegLinks: sywatGetSelectedShopRegLinks,
        syncShopRegistrationLinks: sywatSyncShopRegistrationLinks,
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

    // Legacy Global Compatibility Aliases for Existing Modal Markup
    window.sywatMarkNameAsCustomized          = sywatMarkNameAsCustomized;
    window.sywatToggleTournamentNameLock      = sywatToggleTournamentNameLock;
    window.sywatAutoPopulateEndDate           = sywatAutoPopulateEndDate;
    window.sywatGetSelectedShopCapacities     = sywatGetSelectedShopCapacities;
    window.sywatSyncShopCapacities            = sywatSyncShopCapacities;
    window.sywatGetSelectedShopRegLinks       = sywatGetSelectedShopRegLinks;
    window.sywatSyncShopRegistrationLinks     = sywatSyncShopRegistrationLinks;
    window.sywatSyncTournamentPricingProfiles = sywatSyncTournamentPricingProfiles;
    window.sywatAutoGenerateTournamentName    = sywatAutoGenerateTournamentName;
    window.openAddTournamentModal             = openAddTournamentModal;
    window.closeAddTournamentModal            = closeAddTournamentModal;
    window.openAddSeasonModal                 = openAddSeasonModal;
    window.closeAddSeasonModal                = closeAddSeasonModal;
    window.openImportScheduleModal            = openImportScheduleModal;
    window.closeImportScheduleModal           = closeImportScheduleModal;
    window.closeSywatModal                    = closeSywatModal;
    window.checkTournamentLineDuplicate       = checkTournamentLineDuplicate;
    window.addTournamentLineRow               = addTournamentLineRow;
    window.removeTournamentLineRow            = removeTournamentLineRow;
    window.validateTournamentLines            = validateTournamentLines;
    window.sywatApplyTournamentFilters        = sywatApplyTournamentFilters;
    window.sywatResetTournamentFilters        = sywatResetTournamentFilters;
    window.sywatOpenTfaaSyncModal             = sywatOpenTfaaSyncModal;
    window.sywatRunTfaaBatchSync              = sywatRunTfaaBatchSync;
    window.sywatCloseTfaaSyncModal            = sywatCloseTfaaSyncModal;
})();
