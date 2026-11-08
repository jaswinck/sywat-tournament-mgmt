/**
 * SYWAT Archery - Member & Board Management JavaScript & AJAX Batch Engine
 * Path: assets/js/sywat-members.js
 * Description: Handles modal toggles (including Historical Archers Batch Import, Audit Preview Overlays, Protected Test Data Purge Overlays, Household Link Overlays, and Collapsible Standalone Account Conversion Drawers), real-time progress bar modal streams for chunked AJAX imports and test data purges, client-side phone formatting, password policy validation, dynamic shop visibility, live unassigned archer autocomplete search APIs, live Head of Household autocomplete search APIs, search/status filtering, and notice auto-dismissal.
 * Compliance: Zero inline style manipulation. Uses class toggles (.active, .sywat-hidden, etc.).
 * Author: SYWAT Archery
 * Version: 4.5.0
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
 * Toggles the visibility of the collapsible Standalone Account Conversion drawer inside Modal 4.
 */
function sywatTogglePromoteSection() {
    const box = document.getElementById('sywat_promote_collapsible');
    if (box) {
        box.classList.toggle('sywat-hidden');
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
 * Dynamic Progress Bar Modal Builder & State Controller
 */
function sywatShowProgressModal(title) {
    let modal = document.getElementById('sywat_progress_modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sywat_progress_modal';
        modal.className = 'sywat-modal-backdrop active';
        modal.innerHTML = `
            <div class="sywat-modal-content sywat-modal-sm">
                <div class="sywat-modal-header">
                    <h3 id="sywat_progress_title">${title}</h3>
                </div>
                <div class="sywat-modal-body">
                    <div class="sywat-progress-track">
                        <div id="sywat_progress_fill" class="sywat-progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="sywat-progress-meta">
                        <span id="sywat_progress_percent">0%</span>
                        <span id="sywat_progress_status">Initializing process...</span>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    } else {
        const titleEl = document.getElementById('sywat_progress_title');
        if (titleEl) titleEl.textContent = title;
        modal.classList.add('active');
    }
    sywatUpdateProgressBar(0, 'Initializing process...');
}

function sywatUpdateProgressBar(percent, statusText) {
    const fill = document.getElementById('sywat_progress_fill');
    const percentEl = document.getElementById('sywat_progress_percent');
    const statusEl = document.getElementById('sywat_progress_status');

    const cleanPercent = Math.min(100, Math.max(0, parseInt(percent, 10) || 0));

    if (fill) fill.style.width = cleanPercent + '%';
    if (percentEl) percentEl.textContent = cleanPercent + '%';
    if (statusEl && statusText) statusEl.textContent = statusText;
}

function sywatHideProgressModal() {
    const modal = document.getElementById('sywat_progress_modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

/**
 * Recursive AJAX Stream: Step-by-Step Test Account Data Purge
 */
function sywatExecutePurgeAjax(nonce, chunk) {
    const ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
    const formData = new FormData();
    formData.append('action', 'sywat_purge_test_members_chunk');
    formData.append('sywat_nonce', nonce);
    formData.append('chunk', chunk || 1);

    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(res => {
        if (!res.success) {
            alert(res.data && res.data.message ? res.data.message : 'An error occurred during batch purge.');
            sywatHideProgressModal();
            return;
        }

        const data = res.data;
        sywatUpdateProgressBar(data.percent, data.message);

        if (data.finished) {
            setTimeout(function() {
                sywatHideProgressModal();
                window.location.reload();
            }, 800);
        } else {
            sywatExecutePurgeAjax(nonce, (chunk || 1) + 1);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error encountered while executing batch purge stream.');
        sywatHideProgressModal();
    });
}

/**
 * Recursive AJAX Stream: Step-by-Step Archer Spreadsheet Ingestion
 */
function sywatExecuteImportAjax(nonce, persistentFilePath, offset) {
    const ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
    const formData = new FormData();
    formData.append('action', 'sywat_import_archers_chunk');
    formData.append('sywat_nonce', nonce);
    formData.append('persistent_file_path', persistentFilePath);
    formData.append('offset', offset || 0);
    formData.append('limit', 100);

    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(res => {
        if (!res.success) {
            alert(res.data && res.data.message ? res.data.message : 'An error occurred during batch import ingestion.');
            sywatHideProgressModal();
            return;
        }

        const data = res.data;
        sywatUpdateProgressBar(data.percent, data.message);

        if (data.finished) {
            setTimeout(function() {
                sywatHideProgressModal();
                window.location.reload();
            }, 1000);
        } else {
            sywatExecuteImportAjax(nonce, persistentFilePath, data.next_offset);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error encountered while executing batch import ingestion stream.');
        sywatHideProgressModal();
    });
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
        modal.classList.add('active');
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

// 11. Member Household Link Modal
function sywatOpenTransferMemberModal(sourceUserId, sourceUserName, targetSystemId) {
    const modal       = document.getElementById('sywat_transfer_member_modal');
    const sourceInput = document.getElementById('sywat_transfer_source_user_id');
    const systemInput = document.getElementById('sywat_transfer_target_system_id');
    const sourceLabel = document.getElementById('sywat_transfer_source_name');
    const targetInput = document.getElementById('sywat_transfer_target_head_id');
    const searchInput = document.getElementById('sywat_transfer_head_search');
    const displayBox  = document.getElementById('sywat_transfer_selected_head_display');
    const submitBtn   = document.getElementById('sywat_transfer_submit_btn');

    if (sourceInput) sourceInput.value = sourceUserId || 0;
    if (systemInput) systemInput.value = targetSystemId || '';
    if (sourceLabel) sourceLabel.textContent = sourceUserName;
    if (targetInput) targetInput.value = '';
    if (searchInput) searchInput.value = '';

    if (displayBox) {
        displayBox.innerHTML = '';
        displayBox.classList.add('sywat-hidden');
    }

    if (submitBtn) submitBtn.disabled = true;

    if (modal) {
        modal.classList.add('active');
    }
}

function sywatCloseTransferMemberModal() {
    const modal = document.getElementById('sywat_transfer_member_modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

/**
 * Handles live autocompletion searches against target Heads of Household.
 */
let sywatHeadSearchDebounceTimer = null;

function sywatHandleHeadSearch(input) {
    if (!input) return;

    const query = input.value.trim();
    const resultsBox = document.getElementById('sywat_transfer_head_search_results');
    if (!resultsBox) return;

    clearTimeout(sywatHeadSearchDebounceTimer);

    if (query.length < 2) {
        resultsBox.innerHTML = '';
        resultsBox.classList.add('sywat-hidden');
        return;
    }

    sywatHeadSearchDebounceTimer = setTimeout(function() {
        const ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
        const form = document.getElementById('sywat_transfer_member_form');
        const nonceInput = form ? form.querySelector('input[name="sywat_nonce"]') : null;
        const nonce = nonceInput ? nonceInput.value : '';

        fetch(ajaxUrl + '?action=sywat_search_head_of_households&term=' + encodeURIComponent(query) + '&sywat_nonce=' + encodeURIComponent(nonce))
            .then(res => res.json())
            .then(data => {
                if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                    let html = '';
                    data.data.forEach(item => {
                        const emailDisp = item.email ? item.email : 'No email';
                        const nfaaDisp  = item.nfaa_number ? item.nfaa_number : 'No NFAA ID';
                        html += `<div class="sywat-autocomplete-item" onclick='sywatSelectTargetHead(${JSON.stringify(item)})'>
                            <strong>${item.display_name}</strong>
                            <div class="sywat-meta-subtext">✉️ ${emailDisp} | 🆔 ${nfaaDisp}</div>
                        </div>`;
                    });
                    resultsBox.innerHTML = html;
                    resultsBox.classList.remove('sywat-hidden');
                } else {
                    resultsBox.innerHTML = '<div class="sywat-autocomplete-item sywat-meta-subtext">No matching Head of Household found</div>';
                    resultsBox.classList.remove('sywat-hidden');
                }
            })
            .catch(err => {
                console.error(err);
                resultsBox.classList.add('sywat-hidden');
            });
    }, 300);
}

/**
 * Populates selected target Head of Household user ID into the Link form.
 */
function sywatSelectTargetHead(item) {
    const targetInput = document.getElementById('sywat_transfer_target_head_id');
    const searchInput = document.getElementById('sywat_transfer_head_search');
    const resultsBox  = document.getElementById('sywat_transfer_head_search_results');
    const displayBox  = document.getElementById('sywat_transfer_selected_head_display');
    const submitBtn   = document.getElementById('sywat_transfer_submit_btn');

    if (targetInput) targetInput.value = item.user_id;
    if (searchInput) searchInput.value = item.display_name;

    if (displayBox) {
        displayBox.innerHTML = `<div class="sywat-alert sywat-alert-success sywat-notice-banner">
            👑 <strong>Selected Target Head:</strong> ${item.display_name} (${item.email})
        </div>`;
        displayBox.classList.remove('sywat-hidden');
    }

    if (submitBtn) submitBtn.disabled = false;

    if (resultsBox) {
        resultsBox.innerHTML = '';
        resultsBox.classList.add('sywat-hidden');
    }
}

/**
 * Handles live autocompletion searches against unassigned archers when linking household members.
 */
let sywatSearchDebounceTimer = null;

function sywatHandleArcherSearch(input) {
    if (!input) return;

    const query = input.value.trim();
    const resultsBox = document.getElementById('sywat_add_dep_autocomplete_results');
    if (!resultsBox) return;

    clearTimeout(sywatSearchDebounceTimer);

    if (query.length < 2) {
        resultsBox.innerHTML = '';
        resultsBox.classList.add('sywat-hidden');
        return;
    }

    sywatSearchDebounceTimer = setTimeout(function() {
        const ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
        const form = document.getElementById('sywat_add_dependent_form');
        const nonceInput = form ? form.querySelector('input[name="sywat_nonce"]') : null;
        const nonce = nonceInput ? nonceInput.value : '';

        fetch(ajaxUrl + '?action=sywat_search_unassigned_archers&term=' + encodeURIComponent(query) + '&sywat_nonce=' + encodeURIComponent(nonce))
            .then(res => res.json())
            .then(data => {
                if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                    let html = '';
                    data.data.forEach(item => {
                        const nfaaDisp = item.nfaa_number ? item.nfaa_number : 'No NFAA ID';
                        const dobDisp = item.dob ? item.dob : 'No DOB';
                        html += `<div class="sywat-autocomplete-item" onclick='sywatSelectArcherResult(${JSON.stringify(item)})'>
                            <strong>${item.first_name} ${item.last_name}</strong>
                            <div class="sywat-meta-subtext">🆔 ${nfaaDisp} | 📅 DOB: ${dobDisp}</div>
                        </div>`;
                    });
                    resultsBox.innerHTML = html;
                    resultsBox.classList.remove('sywat-hidden');
                } else {
                    resultsBox.innerHTML = '<div class="sywat-autocomplete-item sywat-meta-subtext">No unassigned archers found</div>';
                    resultsBox.classList.remove('sywat-hidden');
                }
            })
            .catch(err => {
                console.error(err);
                resultsBox.classList.add('sywat-hidden');
            });
    }, 300);
}

/**
 * Auto-fills selected unassigned archer profile fields into the Add Family Member form.
 */
function sywatSelectArcherResult(item) {
    const resultsBox = document.getElementById('sywat_add_dep_autocomplete_results');
    const searchInput = document.getElementById('sywat_add_dep_autocomplete');

    if (item.first_name) document.getElementById('add_dep_first_name').value = item.first_name;
    if (item.last_name) document.getElementById('add_dep_last_name').value = item.last_name;
    if (item.dob) document.getElementById('add_dep_dob').value = item.dob;
    if (item.gender) document.getElementById('add_dep_gender').value = item.gender;
    if (item.nfaa_number) document.getElementById('add_dep_nfaa_number').value = item.nfaa_number;
    if (item.nfaa_expiration) document.getElementById('add_dep_nfaa_expiration').value = item.nfaa_expiration;

    if (searchInput) searchInput.value = item.first_name + ' ' + item.last_name;
    if (resultsBox) {
        resultsBox.innerHTML = '';
        resultsBox.classList.add('sywat-hidden');
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
        const matchesStatus = (!statusFilter || rowStatus.indexOf(statusFilter) !== -1);

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
 * Handles DOM load initializations: shop visibility state check, notice auto-dismissal, form submit handlers, and AJAX stream progress modal triggers.
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

    // Step 1: Historical Archer Spreadsheet Audit Submit Handler
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

    // Step 2: Commit Batch Import Stream Handler (AJAX Chunking with Real-Time Progress Bar)
    const commitForm = document.getElementById('sywat_commit_import_form');
    if (commitForm) {
        commitForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const nonceInput = commitForm.querySelector('input[name="sywat_nonce"]');
            const fileInput  = commitForm.querySelector('input[name="persistent_file_path"]');

            const nonce    = nonceInput ? nonceInput.value : '';
            const filePath = fileInput ? fileInput.value : '';

            sywatCloseImportPreviewModal();
            sywatShowProgressModal('📥 Ingesting Archers into Database...');
            sywatExecuteImportAjax(nonce, filePath, 0);
        });
    }

    // Step 3: Batch Protected Test Data Purge Handler (AJAX Chunking with Real-Time Progress Bar)
    const purgeModal = document.getElementById('sywat_purge_test_modal');
    if (purgeModal) {
        const purgeForm = purgeModal.querySelector('form');
        if (purgeForm) {
            purgeForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const nonceInput = purgeForm.querySelector('input[name="sywat_nonce"]');
                const nonce      = nonceInput ? nonceInput.value : '';

                sywatClosePurgeModal();
                sywatShowProgressModal('🗑️ Purging Test Account Data...');
                sywatExecutePurgeAjax(nonce, 1);
            });
        }
    }
});
