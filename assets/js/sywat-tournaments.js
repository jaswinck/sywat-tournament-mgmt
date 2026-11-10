/**
 * SYWAT Archery - Master Tournament Management Client Engine
 * Path: assets/js/sywat-tournaments.js
 * Author: SYWAT Archery
 * Version: 5.4.0
 */

let sywatTournamentNameIsCustomized = false;

function sywatMarkNameAsCustomized() {
    sywatTournamentNameIsCustomized = true;
}

function sywatAutoGenerateTournamentName() {
    const shopSelect = document.getElementById('sywat_modal_shop_id');
    const nameInput  = document.getElementById('sywat_modal_tournament_name');

    if (!shopSelect || !nameInput) return;

    // Do not overwrite if user has manually typed a custom name
    if (sywatTournamentNameIsCustomized && nameInput.value.trim() !== '') return;

    const selectedOption = shopSelect.options[shopSelect.selectedIndex];
    if (!selectedOption || !selectedOption.value) return;

    const shopName = selectedOption.getAttribute('data-name') || selectedOption.text || '';
    const currentCount = parseInt(selectedOption.getAttribute('data-count') || '0', 10);
    const nextNum = currentCount + 1;

    if (shopName) {
        nameInput.value = `${shopName.trim()} - ${nextNum}`;
    }
}

function openAddTournamentModal() {
    const modal = document.getElementById('sywat_add_tourn_modal');
    if (modal) modal.classList.add('active');

    // Reset flag if starting fresh
    const nameInput = document.getElementById('sywat_modal_tournament_name');
    if (nameInput && !nameInput.value) {
        sywatTournamentNameIsCustomized = false;
    }
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

    const dayVal = (daySelect?.value || '').trim();
    const timeVal = (timeInput?.value || '').trim().toLowerCase();

    if (!dayVal || !timeVal) return true;

    const currentCombo = dayVal.toLowerCase() + ' @ ' + timeVal;

    const allRows = table.querySelectorAll('tbody tr');
    for (let row of allRows) {
        if (row === tr) continue;

        const otherDaySelect = row.querySelector('select[name="line_days[]"]');
        const otherTimeInput = row.querySelector('input[name="line_times[]"]');

        const otherDay = (otherDaySelect?.value || '').trim().toLowerCase();
        const otherTime = (otherTimeInput?.value || '').trim().toLowerCase();

        if (otherDay && otherTime && (otherDay + ' @ ' + otherTime) === currentCombo) {
            alert(`Duplicate tournament line schedule detected: "${dayVal} ${timeInput.value}". This day and start time is already assigned to another line.`);
            if (daySelect) {
                daySelect.value = "";
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

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <div class="sywat-field-group">
                <select name="line_days[]" onchange="checkTournamentLineDuplicate(this);">
                    <option value="" disabled selected>-- Select Day --</option>
                    <option value="Friday">Friday</option>
                    <option value="Saturday">Saturday</option>
                    <option value="Sunday">Sunday</option>
                </select>
            </div>
        </td>
        <td>
            <div class="sywat-field-group">
                <input type="text" name="line_times[]" value="10:00 AM" placeholder="e.g. 10:00 AM" required onchange="checkTournamentLineDuplicate(this);">
            </div>
        </td>
        <td>
            <div class="sywat-field-group">
                <input type="number" name="cap_20yd[]" value="20" min="0" required>
            </div>
        </td>
        <td>
            <div class="sywat-field-group">
                <input type="number" name="cap_cub[]" value="5" min="0" required>
            </div>
        </td>
        <td class="sywat-col-center">
            <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" onclick="removeTournamentLineRow(this)">Remove</button>
        </td>
    `;
    tableBody.appendChild(tr);
}

function removeTournamentLineRow(btn) {
    const tr = btn.closest('tr');
    const tableBody = document.querySelector('#sywat_tourn_lines_table tbody');
    if (tableBody && tableBody.children.length <= 1) {
        alert('A tournament must have at least one shooting line defined.');
        return;
    }
    if (tr) tr.remove();
}

function validateTournamentLines() {
    const table = document.querySelector('#sywat_tourn_lines_table');
    if (!table) return true;

    const daySelects = table.querySelectorAll('select[name="line_days[]"]');
    const timeInputs = table.querySelectorAll('input[name="line_times[]"]');

    const seen = new Map();
    for (let i = 0; i < daySelects.length; i++) {
        const dayVal = (daySelects[i]?.value || '').trim();
        const timeVal = (timeInputs[i]?.value || '').trim().toLowerCase();

        if (!dayVal) {
            alert(`Please select a Shooting Day for row ${i + 1}.`);
            if (daySelects[i]) daySelects[i].focus();
            return false;
        }

        if (dayVal && timeVal) {
            const combo = dayVal + ' @ ' + timeVal;
            if (seen.has(combo)) {
                alert(`Duplicate tournament line schedule detected: "${dayVal} ${timeInputs[i].value}". Please choose a different day or start time.`);
                daySelects[i].value = "";
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
    const shopSelect     = document.getElementById('sywat_tournament_shop_filter');
    const searchInput    = document.getElementById('sywat_tournament_search_input');

    const districtFilter = districtSelect ? districtSelect.value.toLowerCase().trim() : 'all';
    const shopFilter     = shopSelect ? shopSelect.value.toLowerCase().trim() : 'all';
    const searchFilter   = searchInput ? searchInput.value.toLowerCase().trim() : '';

    const rows = document.querySelectorAll('.sywat-tournament-row');

    rows.forEach(row => {
        const name     = (row.getAttribute('data-name') || '').toLowerCase();
        const shop     = (row.getAttribute('data-shop') || '').toLowerCase();
        const district = (row.getAttribute('data-district') || '').toLowerCase();
        const fullText = row.textContent.toLowerCase();

        const districtMatch = (districtFilter === 'all' || district === districtFilter || district.includes(districtFilter));
        const shopMatch     = (shopFilter === 'all' || shop === shopFilter || shop.includes(shopFilter));
        const searchMatch   = (!searchFilter || name.includes(searchFilter) || shop.includes(searchFilter) || district.includes(searchFilter) || fullText.includes(searchFilter));

        if (districtMatch && shopMatch && searchMatch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function sywatResetTournamentFilters() {
    const districtSelect = document.getElementById('sywat_tournament_district_filter');
    const shopSelect     = document.getElementById('sywat_tournament_shop_filter');
    const searchInput    = document.getElementById('sywat_tournament_search_input');

    if (districtSelect) districtSelect.value = 'all';
    if (shopSelect) shopSelect.value = 'all';
    if (searchInput) searchInput.value = '';
    sywatApplyTournamentFilters();
}

document.addEventListener("DOMContentLoaded", function() {
    const notice = document.getElementById('sywat-notice');
    if (notice) {
        setTimeout(function() {
            notice.style.transition = "opacity 0.5s ease";
            notice.style.opacity = "0";
            setTimeout(function() { notice.remove(); }, 500);
        }, 5000);
    }

    const seasonSearchInput = document.getElementById('sywat_season_search_input');
    if (seasonSearchInput) {
        seasonSearchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.sywat-season-row');
            rows.forEach(row => {
                const searchStr = (row.getAttribute('data-search') || '').toLowerCase();
                row.style.display = searchStr.includes(filter) ? '' : 'none';
            });
        });
    }
});
