/**
 * SYWAT Archery - Shops Module Client Scripts
 * Path: assets/js/sywat-shops.js
 * Version: 6.8.3
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    let sywatIsFormDirty = false;

    function markFormDirty() {
        sywatIsFormDirty = true;
    }

    function resetFormDirty() {
        sywatIsFormDirty = false;
    }

    function setElementVisible(el, visible) {
        if (!el) return;
        if (visible) {
            el.classList.remove('sywat-hidden');
        } else {
            el.classList.add('sywat-hidden');
        }
    }

    function switchEditShopTab(tabName) {
        const tabs = ['profile', 'contacts', 'indoor_lines', 'outdoor_lines'];
        tabs.forEach(function(t) {
            const btn = document.getElementById('sywat_edit_tab_btn_' + t);
            const content = document.getElementById('sywat_edit_tab_' + t);
            if (btn && content) {
                if (t === tabName) {
                    btn.classList.add('active');
                    setElementVisible(content, true);
                } else {
                    btn.classList.remove('active');
                    setElementVisible(content, false);
                }
            }
        });

        const activeTabInput = document.getElementById('sywat_active_tab_input');
        if (activeTabInput) {
            activeTabInput.value = tabName;
        }
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
            setElementVisible(indoorBtn, showIndoor);
            if (!showIndoor && indoorContent && !indoorContent.classList.contains('sywat-hidden')) {
                switchEditShopTab('profile');
            }
        }

        if (outdoorBtn) {
            const showOutdoor = outdoorCheck ? outdoorCheck.checked : false;
            setElementVisible(outdoorBtn, showOutdoor);
            if (!showOutdoor && outdoorContent && !outdoorContent.classList.contains('sywat-hidden')) {
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

    function openImportShopsModal() {
        const modal = document.getElementById('importShopsModal');
        if (modal) modal.classList.add('active');
    }

    function closeImportShopsModal() {
        const modal = document.getElementById('importShopsModal');
        if (modal) modal.classList.remove('active');
    }

    function addShopContactRow() {
        const container = document.getElementById('sywat_shop_contacts_container');
        if (!container) return;

        const noMsg = document.getElementById('sywat_no_add_contacts_msg');
        setElementVisible(noMsg, false);

        const row = document.createElement('div');
        row.className = 'sywat-contact-row sywat-form-grid-4 sywat-mt-3';
        row.innerHTML =
            '<div class="sywat-field-group">' +
                '<label>Contact Name</label>' +
                '<input type="text" name="add_contact_names[]" placeholder="e.g. Hannah Lange">' +
            '</div>' +
            '<div class="sywat-field-group">' +
                '<label>Role / Title</label>' +
                '<input type="text" name="add_contact_titles[]" placeholder="e.g. Event Manager">' +
            '</div>' +
            '<div class="sywat-field-group">' +
                '<label>Email</label>' +
                '<input type="email" name="add_contact_emails[]" placeholder="e.g. hannah@domain.com">' +
            '</div>' +
            '<div class="sywat-field-group">' +
                '<label>Phone</label>' +
                '<div class="sywat-search-input-group">' +
                    '<input type="text" name="add_contact_phones[]" class="sywat-phone-input" placeholder="817-439-8998">' +
                    '<button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" data-sywat-action="remove-shop-contact">Remove</button>' +
                '</div>' +
            '</div>';
        container.appendChild(row);
        attachPhoneFormatting(row.querySelector('.sywat-phone-input'));
        markFormDirty();
    }

    function removeShopContactRow(btn) {
        const row = btn.closest('.sywat-contact-row');
        if (row) row.remove();
        const container = document.getElementById('sywat_shop_contacts_container');
        const noMsg = document.getElementById('sywat_no_add_contacts_msg');
        if (container && container.querySelectorAll('.sywat-contact-row').length === 0) {
            setElementVisible(noMsg, true);
        }
        markFormDirty();
    }

    function checkShopLineDuplicate(el) {
        const tr = el.closest('tr');
        const table = el.closest('table');
        if (!tr || !table) return true;

        const daySelect = tr.querySelector('select[name="indoor_line_days[]"], select[name="outdoor_line_days[]"]');
        const timeInput = tr.querySelector('input[name="indoor_line_times[]"], input[name="outdoor_line_times[]"]');

        const dayVal = (daySelect ? daySelect.value : '').trim();
        const timeVal = (timeInput ? timeInput.value : '').trim().toLowerCase();

        if (!dayVal || !timeVal) return true;

        const currentCombo = dayVal.toLowerCase() + ' @ ' + timeVal;
        const isIndoor = table.id.indexOf('indoor') !== -1;
        const label = isIndoor ? 'Indoor' : 'Outdoor';

        const allRows = table.querySelectorAll('tbody tr');
        for (let i = 0; i < allRows.length; i++) {
            const row = allRows[i];
            if (row === tr) continue;

            const otherDaySelect = row.querySelector('select[name="indoor_line_days[]"], select[name="outdoor_line_days[]"]');
            const otherTimeInput = row.querySelector('input[name="indoor_line_times[]"], input[name="outdoor_line_times[]"]');

            const otherDay = (otherDaySelect ? otherDaySelect.value : '').trim().toLowerCase();
            const otherTime = (otherTimeInput ? otherTimeInput.value : '').trim().toLowerCase();

            if (otherDay && otherTime && (otherDay + ' @ ' + otherTime) === currentCombo) {
                window.alert('Duplicate ' + label + ' shooting line detected: "' + dayVal + ' ' + timeInput.value + '". This day and start time is already assigned to another line.');
                if (daySelect) {
                    daySelect.value = '';
                    daySelect.focus();
                }
                return false;
            }
        }
        return true;
    }

    function addShopIndoorLineRow() {
        const tableBody = document.querySelector('#sywat_shop_indoor_lines_table tbody');
        if (!tableBody) return;

        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' +
                '<div class="sywat-field-group">' +
                    '<select name="indoor_line_days[]">' +
                        '<option value="" disabled selected>-- Select Day --</option>' +
                        '<option value="Friday">Friday</option>' +
                        '<option value="Saturday">Saturday</option>' +
                        '<option value="Sunday">Sunday</option>' +
                    '</select>' +
                '</div>' +
            '</td>' +
            '<td>' +
                '<div class="sywat-field-group">' +
                    '<input type="text" name="indoor_line_times[]" value="10:00 AM" placeholder="e.g. 10:00 AM" required>' +
                '</div>' +
            '</td>' +
            '<td>' +
                '<div class="sywat-field-group">' +
                    '<input type="number" name="cap_20yd[]" value="20" min="0" required>' +
                '</div>' +
            '</td>' +
            '<td>' +
                '<div class="sywat-field-group">' +
                    '<input type="number" name="cap_cub[]" value="5" min="0" required>' +
                '</div>' +
            '</td>' +
            '<td class="sywat-col-center">' +
                '<button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" data-sywat-action="remove-indoor-line">Remove</button>' +
            '</td>';
        tableBody.appendChild(tr);
        markFormDirty();
    }

    function removeShopIndoorLineRow(btn) {
        const tr = btn.closest('tr');
        const tableBody = document.querySelector('#sywat_shop_indoor_lines_table tbody');
        if (tableBody && tableBody.children.length <= 1) {
            window.alert('Location must have at least one indoor shooting line defined.');
            return;
        }
        if (tr) tr.remove();
        markFormDirty();
    }

    function addShopOutdoorLineRow() {
        const tableBody = document.querySelector('#sywat_shop_outdoor_lines_table tbody');
        if (!tableBody) return;

        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' +
                '<div class="sywat-field-group">' +
                    '<select name="outdoor_line_days[]">' +
                        '<option value="" disabled selected>-- Select Day --</option>' +
                        '<option value="Friday">Friday</option>' +
                        '<option value="Saturday">Saturday</option>' +
                        '<option value="Sunday">Sunday</option>' +
                    '</select>' +
                '</div>' +
            '</td>' +
            '<td>' +
                '<div class="sywat-field-group">' +
                    '<input type="text" name="outdoor_line_times[]" value="9:00 AM" placeholder="e.g. 9:00 AM" required>' +
                '</div>' +
            '</td>' +
            '<td class="sywat-col-center">' +
                '<button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-delete" data-sywat-action="remove-outdoor-line">Remove</button>' +
            '</td>';
        tableBody.appendChild(tr);
        markFormDirty();
    }

    function removeShopOutdoorLineRow(btn) {
        const tr = btn.closest('tr');
        const tableBody = document.querySelector('#sywat_shop_outdoor_lines_table tbody');
        if (tableBody && tableBody.children.length <= 1) {
            window.alert('Location must have at least one outdoor shooting line defined.');
            return;
        }
        if (tr) tr.remove();
        markFormDirty();
    }

    function validateDuplicatesInTable(tableSelector, dayInputName, timeInputName, label) {
        const table = document.querySelector(tableSelector);
        if (!table) return true;

        const daySelects = table.querySelectorAll('select[name="' + dayInputName + '"]');
        const timeInputs = table.querySelectorAll('input[name="' + timeInputName + '"]');

        const seen = new Map();
        for (let i = 0; i < daySelects.length; i++) {
            const dayVal = (daySelects[i] ? daySelects[i].value : '').trim();
            const timeVal = (timeInputs[i] ? timeInputs[i].value : '').trim().toLowerCase();

            if (!dayVal) {
                window.alert('Please select a Shooting Day for row ' + (i + 1) + ' in ' + label + ' lines.');
                if (daySelects[i]) daySelects[i].focus();
                return false;
            }

            if (dayVal && timeVal) {
                const combo = dayVal + ' @ ' + timeVal;
                if (seen.has(combo)) {
                    window.alert('Duplicate ' + label + ' shooting line detected: "' + dayVal + ' ' + timeInputs[i].value + '". Please choose a different day or start time.');
                    daySelects[i].value = '';
                    daySelects[i].focus();
                    return false;
                }
                seen.set(combo, i);
            }
        }
        return true;
    }

    function validateShopForm() {
        if (!validateDuplicatesInTable('#sywat_shop_indoor_lines_table', 'indoor_line_days[]', 'indoor_line_times[]', 'Indoor')) {
            return false;
        }
        if (!validateDuplicatesInTable('#sywat_shop_outdoor_lines_table', 'outdoor_line_days[]', 'outdoor_line_times[]', 'Outdoor')) {
            return false;
        }
        resetFormDirty();
        return true;
    }

    function confirmBackToList(event) {
        if (sywatIsFormDirty) {
            if (!window.confirm('You have unsaved changes. Are you sure you want to leave without saving?')) {
                if (event) event.preventDefault();
                return false;
            }
        }
        return true;
    }

    function attachPhoneFormatting(input) {
        if (!input || input.dataset.sywatPhoneBound === '1') return;
        input.dataset.sywatPhoneBound = '1';
        input.addEventListener('input', function() {
            const digits = this.value.replace(/\D/g, '').substring(0, 10);
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
        const searchInput = document.getElementById('sywat_shop_search_input');
        const distSelect = document.getElementById('sywat_district_filter_select');
        const searchVal = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const distVal = (distSelect ? distSelect.value : '').toLowerCase().trim();
        const rows = document.querySelectorAll('.sywat-shop-row');

        rows.forEach(function(row) {
            const name = (row.getAttribute('data-name') || '').toLowerCase();
            const contact = (row.getAttribute('data-contact') || '').toLowerCase();
            const shopId = (row.getAttribute('data-id') || '').toLowerCase();
            const district = (row.getAttribute('data-district') || '').toLowerCase();

            const matchesSearch = !searchVal || name.indexOf(searchVal) !== -1 || contact.indexOf(searchVal) !== -1 || shopId.indexOf(searchVal) !== -1;
            let matchesDistrict = false;

            if (!distVal) {
                matchesDistrict = true;
            } else if (distVal === 'unassigned') {
                matchesDistrict = !district || district === 'unassigned';
            } else {
                matchesDistrict = district === distVal;
            }

            setElementVisible(row, matchesSearch && matchesDistrict);
        });
    }

    function resetShopFilters() {
        const searchInput = document.getElementById('sywat_shop_search_input');
        const distSelect = document.getElementById('sywat_district_filter_select');
        if (searchInput) searchInput.value = '';
        if (distSelect) distSelect.value = '';
        sywatFilterShops();
    }

    // Delegated click listener (optimizer-safe before/after WP Rocket & Divi deferral)
    document.addEventListener('click', function(e) {
        const target = e.target.closest('button, a');
        if (!target) return;

        if (target.id === 'sywat_shop_clear_btn') {
            e.preventDefault();
            resetShopFilters();
            return;
        }
        if (target.id === 'sywat_open_import_shops_btn') {
            e.preventDefault();
            openImportShopsModal();
            return;
        }
        if (target.id === 'sywat_close_import_shops_btn' || target.id === 'sywat_cancel_import_shops_btn') {
            e.preventDefault();
            closeImportShopsModal();
            return;
        }
        if (target.id === 'sywat_open_add_shop_btn') {
            e.preventDefault();
            openAddShopModal();
            return;
        }
        if (target.id === 'sywat_close_add_shop_btn' || target.id === 'sywat_cancel_add_shop_btn') {
            e.preventDefault();
            closeAddShopModal();
            return;
        }

        const action = target.getAttribute('data-sywat-action');
        if (action === 'remove-shop-contact') {
            e.preventDefault();
            removeShopContactRow(target);
        } else if (action === 'remove-indoor-line') {
            e.preventDefault();
            removeShopIndoorLineRow(target);
        } else if (action === 'remove-outdoor-line') {
            e.preventDefault();
            removeShopOutdoorLineRow(target);
        }
    });

    // Delegated form submit confirmation listener
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!form || !form.getAttribute) return;
        const confirmMsg = form.getAttribute('data-sywat-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) {
            e.preventDefault();
        }
    });

    // Delegated change/input listener for dynamic shooting line duplicate checks
    document.addEventListener('change', function(e) {
        const el = e.target;
        if (!el || !el.matches) return;
        if (el.matches('select[name="indoor_line_days[]"], input[name="indoor_line_times[]"], select[name="outdoor_line_days[]"], input[name="outdoor_line_times[]"]')) {
            checkShopLineDuplicate(el);
            markFormDirty();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const editForm = document.getElementById('sywat_edit_shop_form');
        if (editForm) {
            editForm.addEventListener('change', markFormDirty);
            editForm.addEventListener('input', markFormDirty);
            editForm.addEventListener('submit', function(e) {
                if (!validateShopForm()) {
                    e.preventDefault();
                }
            });
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
        if (searchInput) {
            searchInput.addEventListener('input', sywatFilterShops);
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });
        }

        const distSelect = document.getElementById('sywat_district_filter_select');
        if (distSelect) distSelect.addEventListener('change', sywatFilterShops);

        const facilityCheckboxes = document.querySelectorAll('input[name="facilities[]"]');
        facilityCheckboxes.forEach(function(cb) {
            cb.addEventListener('change', updateFacilityTabVisibility);
        });
        updateFacilityTabVisibility();

        const notice = document.getElementById('sywat-notice');
        if (notice) {
            window.setTimeout(function() {
                notice.classList.add('sywat-fade-out');
                window.setTimeout(function() {
                    notice.remove();
                }, 500);
            }, 5000);
        }
    });

    // Expose namespaced API + legacy compatibility aliases for remaining views until refactored
    window.SYWAT.shops = {
        switchEditShopTab: switchEditShopTab,
        updateFacilityTabVisibility: updateFacilityTabVisibility,
        openAddShopModal: openAddShopModal,
        closeAddShopModal: closeAddShopModal,
        openImportShopsModal: openImportShopsModal,
        closeImportShopsModal: closeImportShopsModal,
        addShopContactRow: addShopContactRow,
        removeShopContactRow: removeShopContactRow,
        checkShopLineDuplicate: checkShopLineDuplicate,
        addShopIndoorLineRow: addShopIndoorLineRow,
        removeShopIndoorLineRow: removeShopIndoorLineRow,
        addShopOutdoorLineRow: addShopOutdoorLineRow,
        removeShopOutdoorLineRow: removeShopOutdoorLineRow,
        confirmBackToList: confirmBackToList,
        filterShops: sywatFilterShops,
        resetFilters: resetShopFilters
    };

    window.switchEditShopTab = switchEditShopTab;
    window.updateFacilityTabVisibility = updateFacilityTabVisibility;
    window.openAddShopModal = openAddShopModal;
    window.closeAddShopModal = closeAddShopModal;
    window.openImportShopsModal = openImportShopsModal;
    window.closeImportShopsModal = closeImportShopsModal;
    window.addShopContactRow = addShopContactRow;
    window.removeShopContactRow = removeShopContactRow;
    window.checkShopLineDuplicate = checkShopLineDuplicate;
    window.addShopIndoorLineRow = addShopIndoorLineRow;
    window.removeShopIndoorLineRow = removeShopIndoorLineRow;
    window.addShopOutdoorLineRow = addShopOutdoorLineRow;
    window.removeShopOutdoorLineRow = removeShopOutdoorLineRow;
    window.confirmBackToList = confirmBackToList;
    window.sywatFilterShops = sywatFilterShops;
})();
