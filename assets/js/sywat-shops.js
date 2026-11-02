/**
 * SYWAT Archery - Shops Module Client Scripts
 * Path: assets/js/sywat-shops.js
 * Version: 1.2.0
 */

let sywatIsFormDirty = false;

function markFormDirty() {
    sywatIsFormDirty = true;
}

function resetFormDirty() {
    sywatIsFormDirty = false;
}

function switchEditShopTab(tabName) {
    const tabs = ['profile', 'contacts', 'indoor_lines', 'outdoor_lines'];
    tabs.forEach(t => {
        const btn = document.getElementById('sywat_edit_tab_btn_' + t);
        const content = document.getElementById('sywat_edit_tab_' + t);
        if (btn && content) {
            if (t === tabName) {
                btn.classList.add('active');
                content.style.display = 'block';
            } else {
                btn.classList.remove('active');
                content.style.display = 'none';
            }
        }
    });
}

function updateFacilityTabVisibility() {
    const indoorCheck = document.querySelector('input[name="facilities[]"][value="indoor_range"]');
    const outdoorCheck = document.querySelector('input[name="facilities[]"][value="outdoor_field"]');

    const indoorBtn = document.getElementById('sywat_edit_tab_btn_indoor_lines');
    const outdoorBtn = document.getElementById('sywat_edit_tab_btn_outdoor_lines');

    const indoorContent = document.getElementById('sywat_edit_tab_indoor_lines');
    const outdoorContent = document.getElementById('sywat_edit_tab_outdoor_lines');

    if (indoorBtn) {
        const showIndoor = indoorCheck ? indoorCheck.checked : false;
        indoorBtn.style.display = showIndoor ? 'inline-block' : 'none';
        if (!showIndoor && indoorContent && indoorContent.style.display !== 'none') {
            switchEditShopTab('profile');
        }
    }

    if (outdoorBtn) {
        const showOutdoor = outdoorCheck ? outdoorCheck.checked : false;
        outdoorBtn.style.display = showOutdoor ? 'inline-block' : 'none';
        if (!showOutdoor && outdoorContent && outdoorContent.style.display !== 'none') {
            switchEditShopTab('profile');
        }
    }
}

function openAddShopModal() {
    const modal = document.getElementById('addShopModal');
    if (modal) modal.classList.add('active');
}

function closeAddShopModal() {
    const modal = document.getElementById('addShopModal');
    if (modal) modal.classList.remove('active');
}

function addShopContactRow() {
    const container = document.getElementById('sywat_shop_contacts_container');
    const noMsg = document.getElementById('sywat_no_add_contacts_msg');
    if (noMsg) noMsg.style.display = 'none';

    const row = document.createElement('div');
    row.className = 'sywat-contact-row sywat-form-grid-4';
    row.style.cssText = 'align-items:end; background:#f8fafc; padding:10px; border:1px solid #e2e8f0; border-radius:4px; margin-top:6px;';
    row.innerHTML = `
        <div>
            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:11px;">Contact Name</label>
            <input type="text" name="add_contact_names[]" placeholder="e.g. Hannah Lange" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div>
            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:11px;">Role / Title</label>
            <input type="text" name="add_contact_titles[]" placeholder="e.g. Event Manager" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div>
            <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:11px;">Email</label>
            <input type="email" name="add_contact_emails[]" placeholder="e.g. hannah@domain.com" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
        </div>
        <div style="display:flex; gap:6px; align-items:center;">
            <div style="flex:1;">
                <label style="display:block; font-weight:bold; margin-bottom:4px; font-size:11px;">Phone</label>
                <input type="text" name="add_contact_phones[]" class="sywat-phone-input" placeholder="817-439-8998" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
            </div>
            <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="height:32px;" onclick="removeShopContactRow(this)">Remove</button>
        </div>
    `;
    container.appendChild(row);
    attachPhoneFormatting(row.querySelector('.sywat-phone-input'));
    markFormDirty();
}

function removeShopContactRow(btn) {
    const row = btn.closest('.sywat-contact-row');
    if (row) row.remove();
    const container = document.getElementById('sywat_shop_contacts_container');
    const noMsg = document.getElementById('sywat_no_add_contacts_msg');
    if (container && container.querySelectorAll('.sywat-contact-row').length === 0 && noMsg) {
        noMsg.style.display = 'block';
    }
    markFormDirty();
}

function addShopIndoorLineRow() {
    const tableBody = document.querySelector('#sywat_shop_indoor_lines_table tbody');
    if (!tableBody) return;

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td style="padding:6px;">
            <select name="indoor_line_days[]" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                <option value="Friday">Friday</option>
                <option value="Saturday" selected>Saturday</option>
                <option value="Sunday">Sunday</option>
            </select>
        </td>
        <td style="padding:6px;"><input type="text" name="indoor_line_times[]" value="10:00 AM" placeholder="e.g. 10:00 AM" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
        <td style="padding:6px;"><input type="number" name="cap_20yd[]" value="20" min="0" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
        <td style="padding:6px;"><input type="number" name="cap_cub[]" value="5" min="0" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
        <td style="padding:6px; text-align:center;">
            <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="height:32px;" onclick="removeShopIndoorLineRow(this)">Remove</button>
        </td>
    `;
    tableBody.appendChild(tr);
    markFormDirty();
}

function removeShopIndoorLineRow(btn) {
    const tr = btn.closest('tr');
    const tableBody = document.querySelector('#sywat_shop_indoor_lines_table tbody');
    if (tableBody && tableBody.children.length <= 1) {
        alert('Location must have at least one indoor shooting line defined.');
        return;
    }
    if (tr) tr.remove();
    markFormDirty();
}

function addShopOutdoorLineRow() {
    const tableBody = document.querySelector('#sywat_shop_outdoor_lines_table tbody');
    if (!tableBody) return;

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td style="padding:6px;">
            <select name="outdoor_line_days[]" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                <option value="Friday">Friday</option>
                <option value="Saturday" selected>Saturday</option>
                <option value="Sunday">Sunday</option>
            </select>
        </td>
        <td style="padding:6px;"><input type="text" name="outdoor_line_times[]" value="9:00 AM" placeholder="e.g. 9:00 AM" required style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;"></td>
        <td style="padding:6px; text-align:center;">
            <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" style="height:32px;" onclick="removeShopOutdoorLineRow(this)">Remove</button>
        </td>
    `;
    tableBody.appendChild(tr);
    markFormDirty();
}

function removeShopOutdoorLineRow(btn) {
    const tr = btn.closest('tr');
    const tableBody = document.querySelector('#sywat_shop_outdoor_lines_table tbody');
    if (tableBody && tableBody.children.length <= 1) {
        alert('Location must have at least one outdoor shooting line defined.');
        return;
    }
    if (tr) tr.remove();
    markFormDirty();
}

function confirmBackToList(event) {
    if (sywatIsFormDirty) {
        if (!confirm('You have unsaved changes. Are you sure you want to leave without saving?')) {
            if (event) event.preventDefault();
            return false;
        }
    }
    return true;
}

function attachPhoneFormatting(input) {
    if (!input) return;
    input.addEventListener('input', function() {
        let digits = this.value.replace(/\D/g, '').substring(0, 10);
        let formatted = '';
        if (digits.length > 0) {
            if (digits.length <= 3) {
                formatted = digits;
            } else if (digits.length <= 6) {
                formatted = digits.substring(0, 3) + '-' + digits.substring(3);
            } else {
                formatted = digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6);
            }
        }
        this.value = formatted;
    });
}

function sywatFilterShops() {
    const searchVal = (document.getElementById('sywat_shop_search_input')?.value || '').toLowerCase().trim();
    const distVal = (document.getElementById('sywat_district_filter_select')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.sywat-shop-row');

    rows.forEach(row => {
        const name = (row.getAttribute('data-name') || '').toLowerCase();
        const contact = (row.getAttribute('data-contact') || '').toLowerCase();
        const shopId = (row.getAttribute('data-id') || '').toLowerCase();
        const district = (row.getAttribute('data-district') || '').toLowerCase();

        const matchesSearch = !searchVal || name.includes(searchVal) || contact.includes(searchVal) || shopId.includes(searchVal);
        let matchesDistrict = false;

        if (!distVal) {
            matchesDistrict = true;
        } else if (distVal === 'unassigned') {
            matchesDistrict = !district || district === 'unassigned';
        } else {
            matchesDistrict = district === distVal;
        }

        if (matchesSearch && matchesDistrict) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

document.addEventListener("DOMContentLoaded", function() {
    const editForm = document.getElementById('sywat_edit_shop_form');
    if (editForm) {
        editForm.addEventListener('change', markFormDirty);
        editForm.addEventListener('input', markFormDirty);
        editForm.addEventListener('submit', resetFormDirty);
    }

    window.addEventListener('beforeunload', function(e) {
        if (sywatIsFormDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    const phoneInputs = document.querySelectorAll('.sywat-phone-input');
    phoneInputs.forEach(attachPhoneFormatting);

    const searchInput = document.getElementById('sywat_shop_search_input');
    if (searchInput) searchInput.addEventListener('input', sywatFilterShops);

    const distSelect = document.getElementById('sywat_district_filter_select');
    if (distSelect) distSelect.addEventListener('change', sywatFilterShops);

    const facilityCheckboxes = document.querySelectorAll('input[name="facilities[]"]');
    facilityCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateFacilityTabVisibility);
    });
    updateFacilityTabVisibility();

    const notice = document.getElementById('sywat-notice');
    if (notice) {
        setTimeout(function() {
            notice.style.transition = "opacity 0.5s ease";
            notice.style.opacity = "0";
            setTimeout(function() { notice.remove(); }, 500);
        }, 5000);
    }
});
