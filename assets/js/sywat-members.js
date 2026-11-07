/**
 * SYWAT Archery - Member & Board Management JavaScript
 * Path: assets/js/sywat-members.js
 * Description: Handles modal toggles (including Historical Archers Batch Import, Audit Preview Overlays, and Protected Test Data Purge Overlays), client-side phone formatting, password policy validation, dynamic shop visibility, search/status filtering, and notice auto-dismissal.
 * Compliance: Zero inline style manipulation. Uses class toggles (.active, .sywat-hidden, etc.).
 * Author: SYWAT Archery
 * Version: 3.9.5
 */

/**
 * Formats a phone number input field dynamically to XXX-XXX-XXXX format as the user types.
 * 
 * @param {HTMLInputElement} input The phone input element being edited.
 */
function sywatFormatPhoneInput(input) {
    if (!input) return;

    // Strip out all non-numeric characters
    let val = input.value.replace(/\D/g, '');

    // Limit length to a maximum of 10 digits
    if (val.length > 10) {
        val = val.substring(0, 10);
    }

    // Format into XXX-XXX-XXXX pattern
    if (val.length > 6) {
        input.value = val.substring(0, 3) + '-' + val.substring(3, 6) + '-' + val.substring(6);
    } else if (val.length > 3) {
        input.value = val.substring(0, 3) + '-' + val.substring(3);
    } else {
        input.value = val;
    }
}

/**
 * Toggles the visibility of the host shop dropdown based on whether a Shop Manager
 * or Shop User role is checked in the multi-role grid.
 * 
 * @param {string} formId      The DOM ID of the parent form container.
 * @param {string} containerId The DOM ID of the shop dropdown wrapper container.
 */
function sywatToggleShopVisibility(formId, containerId) {
    const form = document.getElementById(formId);
    const container = document.getElementById(containerId);
    if (!form || !container) return;

    const shopRoles = ['sywat_shop_manager', 'sywat_shop_user'];
    const checkedRoles = form.querySelectorAll('input[name="sywat_roles[]"]:checked');

    let isShopRoleChecked = false;
    checkedRoles.forEach(function(cb) {
        if (shopRoles.includes(cb.value)) {
            isShopRoleChecked = true;
        }
    });

    if (isShopRoleChecked) {
        container.classList.remove('sywat-hidden');
    } else {
        container.classList.add('sywat-hidden');
        const select = container.querySelector('select[name="sywat_shop_id"]');
        if (select) {
            select.value = '';
        }
    }
}

/**
 * Validates password rules in real time against length, case, numeric, and match requirements.
 * 
 * @param {string} pwId      DOM ID of the primary password input.
 * @param {string} confirmId DOM ID of the confirm password input.
 * @param {string} prefix    Prefix key for the rule status badge elements (e.g., 'add_rule', 'edit_prim_rule', or 'promote_rule').
 */
function sywatValidatePasswordRules(pwId, confirmId, prefix) {
    const pwInput = document.getElementById(pwId);
    const confirmInput = document.getElementById(confirmId);
    if (!pwInput || !confirmInput) return;

    const pwVal = pwInput.value;
    const confirmVal = confirmInput.value;

    const isLen   = pwVal.length >= 8;
    const isUpper = /[A-Z]/.test(pwVal);
    const isLower = /[a-z]/.test(pwVal);
    const isNum   = /[0-9]/.test(pwVal);
    const isMatch = (pwVal.length > 0 && pwVal === confirmVal);

    sywatUpdateRuleStatus(prefix + '_len', isLen, '8+ Chars');
    sywatUpdateRuleStatus(prefix + '_upper', isUpper, '1 Upper');
    sywatUpdateRuleStatus(prefix + '_lower', isLower, '1 Lower');
    sywatUpdateRuleStatus(prefix + '_num', isNum, '1 Num');
    sywatUpdateRuleStatus(prefix + '_match', isMatch, 'Passwords Match');
}

/**
 * Updates an individual rule badge's visual state by toggling CSS status classes.
 * 
 * @param {string}  elementId The DOM ID of the rule badge element.
 * @param {boolean} isValid   Whether the password requirement is met.
 * @param {string}  text      Label text for the rule badge.
 */
function sywatUpdateRuleStatus(elementId, isValid, text) {
    const el = document.getElementById(elementId);
    if (!el) return;

    if (isValid) {
        el.classList.add('sywat-rule-valid');
        el.classList.remove('sywat-rule-invalid');
        el.textContent = '🟢 ' + text;
    } else {
        el.classList.add('sywat-rule-invalid');
        el.classList.remove('sywat-rule-valid');
        el.textContent = '🔴 ' + text;
    }
}

/**
 * Modal Window Control Handlers (Open / Close Overlay Operations)
 */

// 1. Add Standalone Primary Member Modal
function sywatOpenAddMemberModal() {
    const modal = document.getElementById('sywat_add_member_modal');
    if (modal) {
        modal.classList.add('active');
    }
    sywatToggleShopVisibility('sywat_add_member_form', 'sywat_add_shop_container');
}

function sywatCloseAddMemberModal() {
    const modal = document.getElementById('sywat_add_member_modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// 2. Edit Head of Household Primary Profile Modal
function sywatOpenEditPrimaryModal() {
    const modal = document.getElementById('sywat_edit_primary_modal');
    if (modal) {
        modal.classList.add('active');
    }
    sywatToggleShopVisibility('sywat_edit_primary_form', 'sywat_edit_primary_shop_container');
}

function sywatCloseEditPrimaryModal() {
    const modal = document.getElementById('sywat_edit_primary_modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// 3. Add Family Member Sub-Profile Modal
function sywatOpenAddDependentModal() {
    const modal = document.getElementById('sywat_add_dependent_modal');
    if (modal) {
        modal.classList.add('active');
    }
}

function sywatCloseAddDependentModal() {
    const modal = document.getElementById('sywat_add_dependent_modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// 4. Edit Family Member Sub-Profile Modal
function sywatCloseEditDependentModal() {
    const modal = document.getElementById('sywat_edit_dependent_modal');
    if (modal) {
        modal.classList.remove('active');
    }
    const url = new URL(window.location.href);
    url.searchParams.delete('edit_system_id');
    window.history.replaceState({}, document.title, url.toString());
}

// 5. Legacy Edit Standalone Member Profile Modal Cleanup
function sywatCloseEditMemberModal() {
    const modal = document.getElementById('sywat_edit_member_modal');
    if (modal) {
        modal.classList.remove('active');
    }
    const url = new URL(window.location.href);
    url.searchParams.delete('edit_member_id');
    url.searchParams.delete('edit_system_id');
    url.searchParams.delete('action_mode');
    window.history.replaceState({}, document.title, url.toString());
}

// 6. Assign Board Member Modal
function sywatOpenAddBoardModal() {
    const modal = document.getElementById('sywat_add_board_modal');
    if (modal) {
        modal.classList.add('active');
    }
}

function sywatCloseAddBoardModal() {
    const modal = document.getElementById('sywat_add_board_modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// 7. Edit Board Member Modal
function sywatCloseEditBoardModal() {
    const modal = document.getElementById('sywat_edit_board_modal');
    if (modal) {
        modal.classList.remove('active');
    }
    const url = new URL(window.location.href);
    url.searchParams.delete('edit_board_id');
    window.history.replaceState({}, document.title, url.toString());
}

// 8. Historical Archers Batch Spreadsheet Import Modal
function sywatOpenImportModal() {
    const modal = document.getElementById('sywat_import_archers_modal');
    if (modal) {
        modal.classList.add('active');
    }
}

function sywatCloseImportModal() {
    const modal = document.getElementById('sywat_import_archers_modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// 9. Historical Archers Batch Import Audit Preview Modal
function sywatOpenImportPreviewModal() {
    const modal = document.getElementById('sywat_import_preview_modal');
    if (modal) {
        modal.classList.add('active');
    }
}

function sywatCloseImportPreviewModal() {
    const modal = document.getElementById('sywat_import_preview_modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

// 10. Protected Batch Test Data Purge Modal
function sywatOpenPurgeModal() {
    const modal = document.getElementById('sywat_purge_test_modal');
    if (modal) {
        modal.classList.add('active');
    }
}

function sywatClosePurgeModal() {
    const modal = document.getElementById('sywat_purge_test_modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

/**
 * Filters the Member Directory table rows in real time based on search input and active/expired status.
 */
function sywatApplyMemberSearch() {
    const input        = document.getElementById('sywat_member_search_input');
    const statusSelect = document.getElementById('sywat_member_status_filter');
    const query        = input ? input.value.toLowerCase().trim() : '';
    const statusFilter = statusSelect ? statusSelect.value.toLowerCase().trim() : '';
    const rows         = document.querySelectorAll('.sywat-member-row');

    rows.forEach(function(row) {
        const searchData = (row.getAttribute('data-search') || '').toLowerCase();
        const rowStatus  = (row.getAttribute('data-status') || '').toLowerCase();

        const matchesQuery  = (!query || searchData.indexOf(query) !== -1);
        const matchesStatus = (!statusFilter || rowStatus === statusFilter);

        if (matchesQuery && matchesStatus) {
            row.classList.remove('sywat-hidden');
        } else {
            row.classList.add('sywat-hidden');
        }
    });
}

/**
 * Clears the member search input field, resets the status filter dropdown, and resets row visibility.
 */
function sywatResetMemberSearch() {
    const input        = document.getElementById('sywat_member_search_input');
    const statusSelect = document.getElementById('sywat_member_status_filter');
    if (input) {
        input.value = '';
    }
    if (statusSelect) {
        statusSelect.value = '';
    }
    sywatApplyMemberSearch();
}

/**
 * Filters the Board Management table rows in real time based on user search input.
 */
function sywatApplyBoardSearch() {
    const input = document.getElementById('sywat_board_search_input');
    const query = input ? input.value.toLowerCase().trim() : '';
    const rows  = document.querySelectorAll('.sywat-board-row');

    rows.forEach(function(row) {
        const searchData = (row.getAttribute('data-search') || '').toLowerCase();
        if (!query || searchData.indexOf(query) !== -1) {
            row.classList.remove('sywat-hidden');
        } else {
            row.classList.add('sywat-hidden');
        }
    });
}

/**
 * Clears the board search input field and resets board table row visibility.
 */
function sywatResetBoardSearch() {
    const input = document.getElementById('sywat_board_search_input');
    if (input) {
        input.value = '';
    }
    sywatApplyBoardSearch();
}

/**
 * Handles DOM load initializations: shop visibility state check, notice auto-dismissal, and form submit spinners.
 */
document.addEventListener("DOMContentLoaded", function() {
    sywatToggleShopVisibility('sywat_add_member_form', 'sywat_add_shop_container');
    sywatToggleShopVisibility('sywat_edit_primary_form', 'sywat_edit_primary_shop_container');
    sywatToggleShopVisibility('sywat_edit_member_full_form', 'sywat_edit_full_shop_container');

    // Auto-dismiss alert notices
    const notice = document.getElementById('sywat-notice');
    if (notice) {
        setTimeout(function() {
            notice.classList.add('sywat-fade-out');
            setTimeout(function() {
                if (notice.parentNode) {
                    notice.parentNode.removeChild(notice);
                }
            }, 500);
        }, 5000);
    }

    // Form submit handlers for loading spinners & button locking
    const importForm = document.getElementById('sywat_import_archers_form');
    if (importForm) {
        importForm.addEventListener('submit', function() {
            const btn = document.getElementById('sywat_import_submit_btn');
            if (btn) {
                btn.disabled = true;
                btn.textContent = '⌛ Auditing Spreadsheet (Please Wait)...';
            }
        });
    }

    const commitForm = document.getElementById('sywat_commit_import_form');
    if (commitForm) {
        commitForm.addEventListener('submit', function() {
            const btn = document.getElementById('sywat_commit_submit_btn');
            if (btn) {
                btn.disabled = true;
                btn.textContent = '⌛ Ingesting Archers into Database...';
            }
        });
    }
});
