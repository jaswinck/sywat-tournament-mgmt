/**
 * SYWAT Archery - Check-In Desk AJAX Controller
 * Path: assets/js/sywat-checkin-ajax.js
 * Description: Intercepts #sywat_desk_checkin_form (save_checkin) and .sywat-table-action-form (undo_checkin) submissions via fetch() against admin-ajax.php so check-ins complete in ~150-300ms without full-page reloads. Locks submit triggers immediately ("Saving..."), closes #sywat_checkin_modal on success, updates the target table row, linked household OWE badges, Needs Check-In (N) counter, and Line Capacity Tracker cards in place, and falls back gracefully to standard POST submission if fetch or AJAX config is unavailable.
 * Compliance: Vanilla JS only (zero jQuery), zero element.style.* mutations, class-based visibility (.sywat-hidden / .sywat-dismissing), namespaced under window.SYWAT.checkinAjax, delegated submit listener, zero document-level keydown listeners.
 * Version: 1.0.0
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    let inFlightRequest   = false;
    let noticeDismissTimer = null;

    function escapeHtmlAjax(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getDeskAjaxEndpoint() {
        if (window.sywatDeskAjax && window.sywatDeskAjax.ajaxUrl) {
            return String(window.sywatDeskAjax.ajaxUrl);
        }
        if (typeof window.ajaxurl === 'string' && window.ajaxurl !== '') {
            return window.ajaxurl;
        }
        return '';
    }

    /**
     * Render a top-level or modal-scoped alert banner using standard SYWAT alert classes.
     */
    function showDeskAjaxNotice(message, type, targetContainer) {
        const container = targetContainer || document.getElementById('sywat_desk_ajax_notice');
        if (!container || !message) return;

        if (noticeDismissTimer && !targetContainer) {
            window.clearTimeout(noticeDismissTimer);
            noticeDismissTimer = null;
        }

        const alertClass = (type === 'error') ? 'sywat-alert sywat-alert-danger' : 'sywat-alert sywat-alert-success';
        const prefixIcon = (type === 'error') ? '⚠️ ' : '✅ ';

        container.innerHTML = '<div class="' + alertClass + '">' + prefixIcon + escapeHtmlAjax(message) + '</div>';
        container.classList.remove('sywat-hidden');

        if (type !== 'error' && !targetContainer) {
            noticeDismissTimer = window.setTimeout(function() {
                const alertEl = container.querySelector('.sywat-alert');
                if (alertEl) {
                    alertEl.classList.add('sywat-dismissing');
                    window.setTimeout(function() {
                        container.innerHTML = '';
                    }, 500);
                }
            }, 4000);
        }
    }

    function clearModalAjaxError() {
        const errBox = document.getElementById('sywat_checkin_modal_ajax_error');
        if (errBox) {
            errBox.innerHTML = '';
            errBox.classList.add('sywat-hidden');
        }
    }

    function showModalAjaxError(message) {
        const form = document.getElementById('sywat_desk_checkin_form');
        if (!form) {
            showDeskAjaxNotice(message, 'error');
            return;
        }

        let errBox = document.getElementById('sywat_checkin_modal_ajax_error');
        if (!errBox) {
            errBox = document.createElement('div');
            errBox.id = 'sywat_checkin_modal_ajax_error';
            form.insertBefore(errBox, form.firstChild);
        }

        showDeskAjaxNotice(message, 'error', errBox);
    }

    /**
     * Update a single archer row in #sywat_desk_archers_table from the server's enriched row payload.
     */
    function applyRowUpdate(rowData) {
        if (!rowData || !rowData.id) return;

        const selector = '#sywat_desk_archers_table .sywat-archer-row[data-reg-id="' + String(rowData.id).replace(/"/g, '\\"') + '"]';
        const row      = document.querySelector(selector);
        if (!row) return;

        const isCheckedIn  = !!rowData.is_checked_in;
        const isLineClosed = !!rowData.is_line_closed;
        const needsCheckin = !!rowData.needs_checkin;
        const tbl          = rowData.table_display || {};

        row.setAttribute('data-line-idx', String(rowData.resolved_line_idx || '0'));
        row.setAttribute('data-needs-checkin', needsCheckin ? '1' : '0');
        if (rowData.search_tokens) {
            row.setAttribute('data-name', String(rowData.search_tokens));
        }
        if (rowData.modal_payload) {
            row.setAttribute('data-archer', JSON.stringify(rowData.modal_payload));
        }

        const cells = row.querySelectorAll('td');
        if (cells.length < 9) return;

        // Cell 0: Name, optional Round badge, and OWE badge
        let cell0Html = '<div class="sywat-archer-name">' + escapeHtmlAjax(rowData.display_name || '');
        if (rowData.round_label) {
            cell0Html += ' <span class="sywat-badge sywat-badge-prereg">' + escapeHtmlAjax(rowData.round_label) + '</span>';
        }
        cell0Html += '</div>';
        if (rowData.owe_badge_text) {
            cell0Html += '<div class="sywat-owe-badge-wrap"><span class="sywat-badge sywat-badge-full">' + escapeHtmlAjax(rowData.owe_badge_text) + '</span></div>';
        }
        cells[0].innerHTML = cell0Html;

        // Cell 1: Type badge
        if (tbl.type) {
            const badgeCls = tbl.badge_class || 'sywat-badge-prereg';
            cells[1].innerHTML = '<span class="sywat-badge ' + escapeHtmlAjax(badgeCls) + '">' + escapeHtmlAjax(tbl.type) + '</span>';
        }

        // Cells 2..5: Line, Division, Gender, Style
        if (typeof tbl.line !== 'undefined') cells[2].textContent = tbl.line;
        if (typeof tbl.division !== 'undefined') cells[3].textContent = tbl.division;
        if (typeof tbl.gender !== 'undefined') cells[4].textContent = tbl.gender;
        if (typeof tbl.style !== 'undefined') cells[5].textContent = tbl.style;

        // Cell 6: Rate / Paid
        if (rowData.is_promo_free) {
            cells[6].innerHTML = '<span class="sywat-badge sywat-badge-open">Promo ($0.00)</span>';
        } else if (typeof tbl.paid !== 'undefined') {
            cells[6].textContent = tbl.paid;
        }

        // Cell 7: Distance
        if (typeof tbl.distance !== 'undefined') {
            cells[7].textContent = tbl.distance;
        }

        // Cell 8: Actions (Checked In / Check In Archer + Edit)
        const tournEl  = document.getElementById('sywat_selected_tournament');
        const lineEl   = document.getElementById('sywat_selected_line_idx');
        const searchEl = document.getElementById('sywat_search_input');
        const nonce    = (window.sywatDeskAjax && window.sywatDeskAjax.nonce) ? String(window.sywatDeskAjax.nonce) : '';
        const tournVal = tournEl ? String(tournEl.value || '') : '';
        const lineVal  = lineEl ? String(lineEl.value || 'all') : 'all';
        const queryVal = searchEl ? String(searchEl.value || '') : '';

        let primaryActionHtml = '';
        if (isLineClosed) {
            primaryActionHtml = isCheckedIn
                ? '<span class="sywat-badge sywat-badge-open">✓ Checked In</span>'
                : '<span class="sywat-badge sywat-badge-full">Line Closed</span>';
        } else if (isCheckedIn) {
            primaryActionHtml =
                '<form method="post" class="sywat-table-action-form">' +
                    '<input type="hidden" name="sywat_nonce" value="' + escapeHtmlAjax(nonce) + '">' +
                    '<input type="hidden" name="selected_tournament" value="' + escapeHtmlAjax(tournVal) + '">' +
                    '<input type="hidden" name="selected_line_idx" class="sywat-desk-hidden-line" value="' + escapeHtmlAjax(lineVal) + '">' +
                    '<input type="hidden" name="search_query" class="sywat-hidden-search" value="' + escapeHtmlAjax(queryVal) + '">' +
                    '<input type="hidden" name="sywat_action" value="undo_checkin">' +
                    '<input type="hidden" name="reg_id" value="' + escapeHtmlAjax(rowData.id) + '">' +
                    '<button type="submit" class="sywat-btn sywat-btn-checkedin sywat-btn-icon-checkedin">Checked In</button>' +
                '</form>';
        } else {
            primaryActionHtml =
                '<button type="button" class="sywat-btn sywat-btn-success-solid sywat-btn-icon-checkin" data-sywat-action="open-desk-checkin-modal">Check In Archer</button>';
        }

        cells[8].innerHTML =
            '<div class="sywat-table-actions-vertical">' +
                primaryActionHtml +
                '<button type="button" class="sywat-btn sywat-btn-primary sywat-btn-icon-edit" data-sywat-action="open-desk-edit-modal">Edit</button>' +
            '</div>';
    }

    /**
     * Update Line Capacity Tracker card badges and open-slot counts in place.
     */
    function applyLineStatsUpdate(lineStats) {
        if (!lineStats || typeof lineStats !== 'object') return;

        Object.keys(lineStats).forEach(function(lineIdx) {
            const card = document.querySelector('.sywat-tracker-card[data-tracker-line-idx="' + String(lineIdx).replace(/"/g, '\\"') + '"]');
            if (!card) return;

            const stats      = lineStats[lineIdx] || {};
            const checkedEl  = card.querySelector('[data-tracker-checkedin]');
            const open20El   = card.querySelector('[data-tracker-20yd]');
            const openCubEl  = card.querySelector('[data-tracker-cub]');

            if (checkedEl) {
                const cIn  = parseInt(stats.checked_in || 0, 10);
                const tReg = parseInt(stats.total_reg || 0, 10);
                checkedEl.textContent = cIn + ' / ' + tReg + ' Checked In';
            }

            if (open20El) {
                const o20 = parseInt(stats.open_20yd || 0, 10);
                open20El.innerHTML = (o20 === 0)
                    ? '<span class="sywat-badge sywat-badge-full">FULL</span>'
                    : (o20 + ' Open');
            }

            if (openCubEl) {
                const oCub = parseInt(stats.open_cub || 0, 10);
                openCubEl.innerHTML = (oCub === 0)
                    ? '<span class="sywat-badge sywat-badge-full">FULL</span>'
                    : (oCub + ' Open');
            }
        });
    }

    /**
     * Apply complete AJAX response payload to the Check-In Desk DOM.
     */
    function syncDeskFromAjaxPayload(payload) {
        if (!payload || typeof payload !== 'object') return;

        if (payload.existing_families_by_line && typeof payload.existing_families_by_line === 'object') {
            window.existingFamiliesByLine = payload.existing_families_by_line;
        }

        if (payload.updated_rows && typeof payload.updated_rows === 'object') {
            Object.keys(payload.updated_rows).forEach(function(regId) {
                applyRowUpdate(payload.updated_rows[regId]);
            });
        }

        if (payload.line_stats) {
            applyLineStatsUpdate(payload.line_stats);
        }

        if (window.SYWAT && window.SYWAT.checkin && typeof window.SYWAT.checkin.applyLineAndSearchFilter === 'function') {
            window.SYWAT.checkin.applyLineAndSearchFilter();
        }
    }

    /**
     * Send Check-In or Undo Check-In form data to admin-ajax.php and update UI in place.
     */
    function submitDeskAjaxForm(form, wpAction, submitBtn, loadingLabel) {
        const ajaxUrl = getDeskAjaxEndpoint();
        if (!ajaxUrl || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
            form.submit();
            return;
        }

        if (inFlightRequest) {
            return;
        }

        inFlightRequest = true;
        clearModalAjaxError();

        const origBtnText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = loadingLabel;
        }

        const formData = new FormData(form);
        formData.set('action', wpAction);

        if (!formData.get('sywat_nonce') && window.sywatDeskAjax && window.sywatDeskAjax.nonce) {
            formData.set('sywat_nonce', String(window.sywatDeskAjax.nonce));
        }

        window.fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function(response) {
                return response.json().then(function(json) {
                    return { ok: response.ok, json: json };
                });
            })
            .then(function(result) {
                const payload = result && result.json ? result.json : null;
                if (!result.ok || !payload || !payload.success) {
                    const errMsg = (payload && payload.data && payload.data.message)
                        ? payload.data.message
                        : 'Unable to save check-in changes. Please try again.';

                    if (wpAction === 'sywat_desk_checkin') {
                        showModalAjaxError(errMsg);
                    } else {
                        showDeskAjaxNotice(errMsg, 'error');
                    }
                    return;
                }

                const data = payload.data || {};
                syncDeskFromAjaxPayload(data);

                if (wpAction === 'sywat_desk_checkin') {
                    if (window.SYWAT && window.SYWAT.checkin && typeof window.SYWAT.checkin.closeCheckinModal === 'function') {
                        window.SYWAT.checkin.closeCheckinModal();
                    } else {
                        const modal = document.getElementById('sywat_checkin_modal');
                        if (modal) modal.classList.remove('active');
                    }
                }

                if (data.message) {
                    showDeskAjaxNotice(data.message, 'success');
                }
            })
            .catch(function() {
                const netMsg = 'Network error while communicating with the server. Please check your connection and try again.';
                if (wpAction === 'sywat_desk_checkin') {
                    showModalAjaxError(netMsg);
                } else {
                    showDeskAjaxNotice(netMsg, 'error');
                }
            })
            .finally(function() {
                inFlightRequest = false;
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = origBtnText;
                }
            });
    }

    // Delegated Submit Listener for Check-In Modal Form & Row Undo Check-In Forms
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        // 1. Complete Check-In Modal Submission
        if (form.id === 'sywat_desk_checkin_form') {
            if (!getDeskAjaxEndpoint() || typeof window.fetch !== 'function') {
                return;
            }
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"][value="save_checkin"], button[type="submit"]');
            submitDeskAjaxForm(form, 'sywat_desk_checkin', submitBtn, 'Saving...');
            return;
        }

        // 2. Row Undo Check-In Submission
        if (form.classList.contains('sywat-table-action-form')) {
            const actionInput = form.querySelector('input[name="sywat_action"]');
            if (actionInput && actionInput.value === 'undo_checkin') {
                if (!getDeskAjaxEndpoint() || typeof window.fetch !== 'function') {
                    return;
                }
                e.preventDefault();
                const undoBtn = form.querySelector('button[type="submit"]');
                submitDeskAjaxForm(form, 'sywat_desk_undo_checkin', undoBtn, 'Updating...');
            }
        }
    });

    window.SYWAT.checkinAjax = {
        syncDeskFromAjaxPayload: syncDeskFromAjaxPayload
    };
})();
