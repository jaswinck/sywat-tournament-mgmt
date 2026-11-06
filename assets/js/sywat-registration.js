/**
 * SYWAT Archery - Registration Intake & Client Logic Engine
 * Path: assets/js/sywat-registration.js
 * Version: 6.2.0
 */

(function() {
    'use strict';

    let archerRowCount = 1;

    /**
     * Data Accessors (Supports wp_localize_script and legacy inline globals)
     */
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
        if (window.sywatRegData && typeof window.sywatRegData.phoneCache === 'object') {
            return window.sywatRegData.phoneCache;
        }
        if (typeof sywatPhoneCache !== 'undefined' && typeof sywatPhoneCache === 'object') {
            return sywatPhoneCache;
        }
        return {};
    }

    /**
     * HTML Escaping Utility
     */
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Auto-fill Phone Number from Shooter Cache
     */
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

    /**
     * Input Handler: Primary Caller Name
     */
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

    /**
     * Input Handler: Archer Name Field
     */
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

    /**
     * Change Handler: Division Dropdown
     */
    window.handleDivisionChange = function(divSelect) {
        if (!divSelect) return;
        divSelect.dataset.userChosen = 'true';

        const row = divSelect.closest('.sywat-archer-input-row');
        if (row) {
            const distSelect = row.querySelector('.sywat-distance-select');
            if (distSelect) {
                const selectedDiv = divSelect.value;
                if (selectedDiv && selectedDiv.includes('Cub')) {
                    distSelect.value = 'Cub';
                } else if (selectedDiv !== '') {
                    distSelect.value = '20yd';
                }
            }
        }

        window.recalculateGroupTotal();
    };

    /**
     * Change Handler: Target Distance Dropdown
     */
    window.handleDistanceChange = function(distSelect) {
        const row = distSelect.closest('.sywat-archer-input-row');
        if (!row) return;
        const divSelect = row.querySelector('.sywat-division-select');
        if (!divSelect) return;

        if (distSelect.value === 'Cub') {
            divSelect.value = 'Cub (8 - 11)';
            divSelect.dataset.userChosen = 'true';
        } else if (distSelect.value === '20yd' && divSelect.value === 'Cub (8 - 11)') {
            divSelect.value = '';
            delete divSelect.dataset.userChosen;
        }
        window.recalculateGroupTotal();
    };

    /**
     * Fuzzy Division Matcher for TFAA Cache Records
     */
    window.matchTfaaDivision = function(tfaaDiv, selectEl) {
        if (!tfaaDiv || !selectEl) return;
        const cleanTfaa = tfaaDiv.trim().toLowerCase();

        for (let opt of selectEl.options) {
            if (opt.value && opt.value.trim().toLowerCase() === cleanTfaa) {
                selectEl.value = opt.value;
                window.handleDivisionChange(selectEl);
                return;
            }
        }

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
     * TFAA Autocomplete Dropdown Generator
     */
    window.handleTfaaAutocomplete = function(input) {
        const query = input.value.trim().toLowerCase();
        window.closeAllTfaaAutocompletes();

        const shooters = getTfaaShooters();
        if (query.length < 2 || !Array.isArray(shooters) || shooters.length === 0) {
            return;
        }

        const matches = shooters.filter(s => s.name && s.name.toLowerCase().includes(query)).slice(0, 8);
        const exactMatch = shooters.find(s => s.name && s.name.trim().toLowerCase() === query);
        const targetMatch = exactMatch || (matches.length === 1 ? matches[0] : null);

        if (targetMatch && targetMatch.division) {
            const row = input.closest('.sywat-archer-input-row');
            if (row) {
                const divSelect = row.querySelector('.sywat-division-select');
                if (divSelect && (!divSelect.dataset.userChosen || query === targetMatch.name.toLowerCase())) {
                    window.matchTfaaDivision(targetMatch.division, divSelect);
                }
            }
        }

        if (matches.length === 0) return;

        const parent = input.parentElement;
        if (getComputedStyle(parent).position === 'static') {
            parent.classList.add('sywat-relative-parent');
        }

        const dropdown = document.createElement('div');
        dropdown.className = 'sywat-tfaa-autocomplete-dropdown';

        matches.forEach(m => {
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

    /**
     * Close All Active TFAA Autocomplete Dropdowns
     */
    window.closeAllTfaaAutocompletes = function() {
        document.querySelectorAll('.sywat-tfaa-autocomplete-dropdown').forEach(el => el.remove());
    };

    /**
     * Phone Number Formatting Utility (XXX-XXX-XXXX)
     */
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

    /**
     * Bulk Update Shooting Line Overrides
     */
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

    /**
     * Dynamic Row Management: Add Archer Row
     */
    window.addArcherRow = function() {
        const container = document.getElementById('sywat_archer_rows_container');
        if (!container) return;

        const existingRows = container.querySelectorAll('.sywat-archer-input-row');
        const rowIndex = existingRows.length > 0 ? existingRows.length : archerRowCount;

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

        const rowDiv = document.createElement('div');
        rowDiv.className = 'sywat-archer-input-row';

        rowDiv.innerHTML = `
            <div class="sywat-field-group sywat-col-archer-name">
                <input type="text" name="archers[${rowIndex}][name]" class="sywat-archer-name-input" placeholder="Archer Full Name *" autocomplete="off" required oninput="handleArcherNameInput(this); recalculateGroupTotal();">
            </div>
            
            <div class="sywat-field-group sywat-col-archer-dist">
                <select name="archers[${rowIndex}][distance]" class="sywat-distance-select" onchange="handleDistanceChange(this)">
                    <option value="20yd" selected>20yd</option>
                    <option value="Cub">Cub (10yd)</option>
                </select>
            </div>

            <div class="sywat-field-group sywat-col-archer-div">
                <select name="archers[${rowIndex}][division]" class="sywat-division-select" onchange="handleDivisionChange(this);">
                    <option value="" disabled selected hidden>Select Division</option>
                    <option value="Cub (8 - 11)">Cub (8 - 11) [$25]</option>
                    <option value="Youth (12 - 14)">Youth (12 - 14) [$25]</option>
                    <option value="Young Adult (15 - 17)">Young Adult (15 - 17) [$25]</option>
                    <option value="Adult (18 - 49)">Adult (18 - 49) [$30]</option>
                    <option value="Senior (50 - 59)">Senior (50 - 59) [$30]</option>
                    <option value="Silver Senior (60 - 69)">Silver Senior (60 - 69) [$30]</option>
                    <option value="Master Senior (70+) ">Master Senior (70+) [$30]</option>
                </select>
            </div>

            <div class="sywat-field-group sywat-col-archer-line">
                <select name="archers[${rowIndex}][line_idx]" class="sywat-line-override-select" onchange="recalculateGroupTotal()">
                    ${lineOptionsHtml}
                </select>
            </div>

            <div class="sywat-col-archer-action">
                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" onclick="removeArcherRow(this)" title="Remove Archer"></button>
            </div>
        `;

        container.appendChild(rowDiv);
        archerRowCount++;
        window.recalculateGroupTotal();
    };

    /**
     * Dynamic Row Management: Remove Archer Row
     */
    window.removeArcherRow = function(btn) {
        const rows = document.querySelectorAll('.sywat-archer-input-row');
        if (rows.length <= 1) {
            alert('At least one archer row is required.');
            return;
        }
        btn.closest('.sywat-archer-input-row').remove();
        window.recalculateGroupTotal();
    };

    /**
     * Group Total & Family Capping Recalculations Engine
     */
    window.recalculateGroupTotal = function() {
        const rows = document.querySelectorAll('.sywat-archer-input-row');
        const youthDivisions = ['Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)'];
        const lineMap = {};

        rows.forEach(row => {
            const nameInput  = row.querySelector('input[type="text"]');
            const distSelect = row.querySelector('.sywat-distance-select');
            const divSelect  = row.querySelector('.sywat-division-select');
            const lineSelect = row.querySelector('.sywat-line-override-select');

            if (nameInput && nameInput.value.trim() !== '') {
                const lIdx = lineSelect ? lineSelect.value : '0';
                const dist = distSelect ? distSelect.value : '20yd';
                const div  = divSelect ? divSelect.value : '';

                if (!lineMap[lIdx]) {
                    lineMap[lIdx] = { count: 0, rawTotal: 0 };
                }

                lineMap[lIdx].count++;
                if (dist === 'Cub' || youthDivisions.includes(div)) {
                    lineMap[lIdx].rawTotal += 25;
                } else {
                    lineMap[lIdx].rawTotal += 30;
                }
            }
        });

        let overallTotal = 0;
        let anyCapped = false;
        let cappedLinesCount = 0;

        Object.keys(lineMap).forEach(lIdx => {
            const grp = lineMap[lIdx];
            // Enforce Family Cap: Requires 3+ archers AND a raw total of $75 or higher
            const isCapped = (grp.count >= 3 && grp.rawTotal >= 75.00);
            if (isCapped) {
                anyCapped = true;
                cappedLinesCount++;
                overallTotal += 75.00;
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
            if (anyCapped) {
                capBadge.classList.remove('sywat-hidden');
                capBadge.textContent = '🟢 Family Cap Applied (' + cappedLinesCount + ' Line' + (cappedLinesCount > 1 ? 's' : '') + ')';
            } else {
                capBadge.classList.add('sywat-hidden');
            }
        }

        const paidVal = paidInput ? parseFloat(paidInput.value) || 0 : 0;
        const balanceDue = Math.max(0, overallTotal - paidVal);

        if (dueDisp) dueDisp.textContent = '$' + balanceDue.toFixed(2);
    };

    /**
     * Auto-dismiss Alert Banners (Excludes interactive action forms/prompts)
     */
    window.initAutoDismissAlerts = function() {
        setTimeout(function() {
            const alerts = document.querySelectorAll('.sywat-alert, #sywat-notice, .sywat-modal-notice');
            alerts.forEach(function(alert) {
                // Do not auto-dismiss alerts containing interactive form actions or overflow warning prompts
                if (alert.querySelector('form') || alert.querySelector('button') || (alert.textContent && alert.textContent.includes('OVERFLOW'))) {
                    return;
                }
                alert.classList.add('sywat-dismissing');
                setTimeout(function() { alert.remove(); }, 500);
            });
        }, 5000);
    };

    /**
     * Global Event Listeners
     */
    document.addEventListener('input', function(e) {
        if (e.target && e.target.name && e.target.name.includes('[name]')) {
            window.handleTfaaAutocomplete(e.target);
        }
    });

    document.addEventListener('click', function(e) {
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
        window.recalculateGroupTotal();
        window.initAutoDismissAlerts();
    });

})();
