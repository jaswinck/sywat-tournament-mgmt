/**
 * Client Script: Score Engine Interactivity & Isolated Real-Time Auto-Save
 * Path: assets/js/sywat-scores.js
 * Description: Instantaneous zero-reload shooting line (with per-tournament sessionStorage persistence), single-button Unentered / Show All score status (pinning active mid-entry rows so keyboard flow never jumps), and live search filtering; live Line Score Tracker counter & badge synchronization (counting scores, X counts, and incomplete round "INC" flags in real time); dynamic TFAA target URL switcher; range validation; local draft recovery with conditional Save Pending draft-flush button; isolated per-archer serialized admin-ajax.php auto-save (on blur, Enter key, INC toggle, and page hide); offline sync; inline locked-line validation on CSV export; and optimizer-safe delegated data-sywat-action listeners.
 * Compliance: Pure vanilla JS — zero jQuery dependency, zero inline style mutations (uses .sywat-hidden / .sywat-btn-filter-active / .sywat-btn-secondary class toggling), zero document-level keydown listeners, delegated data-sywat-action handlers.
 * Author: SYWAT Archery
 * Version: 7.7.0
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    // 1. Prevent form resubmission on page refresh (F5)
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }

    const currentTournInput = document.getElementById('selected_tournament_val');
    const lineSelect        = document.getElementById('selected_line_val');
    const nonceInput        = document.getElementById('sywat_nonce');
    const searchInput       = document.getElementById('sywat_score_search_input');
    const batchForm         = document.getElementById('sywat_batch_score_form');
    const inlineNoticeEl    = document.getElementById('sywat_scores_inline_notice');
    const savePendingBtn    = document.getElementById('sywat_scores_save_pending_btn');

    const currentTourn = currentTournInput ? currentTournInput.value : '';
    const tournSlugKey = currentTourn.replace(/[^a-z0-9]/gi, '_').toLowerCase();
    const nonceVal     = nonceInput ? nonceInput.value : '';
    const ajaxUrl      = (batchForm && batchForm.dataset.ajaxUrl) ? batchForm.dataset.ajaxUrl : window.location.href;

    // Filter & concurrency state
    let unenteredOnly        = (sessionStorage.getItem('sywat_scores_unentered_only') === '1');
    const editedInActivePass = new Set();
    const inFlightSaves      = new Set();
    const queuedSaves        = new Set();
    const draftRegIds        = new Set();

    // Parse locked line state map for CSV export validation
    let lockedLinesMap = {};
    if (batchForm && batchForm.dataset.lockedLines) {
        try {
            const parsed = JSON.parse(batchForm.dataset.lockedLines);
            if (parsed && typeof parsed === 'object') {
                lockedLinesMap = parsed;
            }
        } catch (err) {}
    }

    function hasAnyLockedLine() {
        if (batchForm && batchForm.dataset.hasLockedLines === '1') {
            return true;
        }
        return Object.keys(lockedLinesMap).some(function(k) {
            return Boolean(lockedLinesMap[k]);
        });
    }

    // 2. Dynamic TFAA Preview Button URL Switcher
    const tfaaPreviewBtn = document.getElementById('sywat_tfaa_preview_btn');
    function updateTfaaPreviewUrl() {
        if (!currentTournInput || !tfaaPreviewBtn) return;
        const selectedOpt = currentTournInput.options[currentTournInput.selectedIndex];
        if (selectedOpt) {
            const tfaaUrl = selectedOpt.getAttribute('data-tfaa-url');
            if (tfaaUrl) {
                tfaaPreviewBtn.setAttribute('href', tfaaUrl);
            }
        }
    }

    if (currentTournInput && tfaaPreviewBtn) {
        currentTournInput.addEventListener('change', updateTfaaPreviewUrl);
        updateTfaaPreviewUrl();
    }

    // 3. Helper: Row State Readers & Live Unentered Evaluator
    function getRowIncValue(row) {
        if (!row) return '0';
        const incCheckbox   = row.querySelector('.sywat-inc-val');
        const incStateInput = row.querySelector('.sywat-inc-state');
        if (incCheckbox) {
            const val = incCheckbox.checked ? '1' : '0';
            if (incStateInput) {
                incStateInput.value = val;
            }
            return val;
        }
        return incStateInput ? (incStateInput.value === '1' ? '1' : '0') : '0';
    }

    function isRowCurrentlyUnentered(row) {
        if (!row) return true;
        const scoreInput = row.querySelector('.score-val');
        const xInput     = row.querySelector('.x-val');
        const scoreVal   = scoreInput ? (parseInt(scoreInput.value.trim(), 10) || 0) : 0;
        const xVal       = xInput ? (parseInt(xInput.value.trim(), 10) || 0) : 0;
        const isInc      = (getRowIncValue(row) === '1');
        const unentered  = (scoreVal === 0 && xVal === 0 && !isInc);

        row.setAttribute('data-unentered', unentered ? '1' : '0');
        return unentered;
    }

    // 4. Live Line Score Tracker Card Counter & Status Badge Synchronizer (Includes INC)
    function syncLineTrackerCounts() {
        const rows          = document.querySelectorAll('.sywat-score-row');
        const enteredByLine = Object.create(null);
        const totalByLine   = Object.create(null);

        rows.forEach(function(row) {
            const lidx      = row.getAttribute('data-line-idx') || '';
            const isEntered = !isRowCurrentlyUnentered(row);

            totalByLine[lidx] = (totalByLine[lidx] || 0) + 1;
            if (isEntered) {
                enteredByLine[lidx] = (enteredByLine[lidx] || 0) + 1;
            }
        });

        document.querySelectorAll('.sywat-tracker-card').forEach(function(card) {
            const lidx     = card.getAttribute('data-line-idx') || '';
            const total    = Object.prototype.hasOwnProperty.call(totalByLine, lidx)
                ? totalByLine[lidx]
                : (parseInt(card.getAttribute('data-total'), 10) || 0);
            const entered  = enteredByLine[lidx] || 0;
            const isLocked = card.getAttribute('data-is-locked') === '1';

            const enteredEl = card.querySelector('.sywat-tracker-entered-count');
            if (enteredEl) {
                enteredEl.textContent = String(entered);
            }

            const statusWrap = card.querySelector('.sywat-tracker-status-wrap');
            if (statusWrap) {
                if (isLocked) {
                    statusWrap.innerHTML = '<span class="sywat-badge sywat-badge-full">🔒 Locked</span>';
                } else if (total > 0 && entered >= total) {
                    statusWrap.innerHTML = '<span class="sywat-badge sywat-badge-open">✅ Complete</span>';
                } else {
                    const pending = Math.max(0, total - entered);
                    statusWrap.innerHTML = '<span class="sywat-badge sywat-badge-prereg">' + pending + ' Pending</span>';
                }
            }
        });
    }

    // 5. Synchronize Unentered Toggle & Conditional Save Pending Draft Button
    function syncUnenteredButton(unenteredCount) {
        const btn = document.getElementById('sywat_scores_unentered_btn');
        if (!btn) return;

        btn.textContent = unenteredOnly ? 'Show All' : ('Unentered (' + unenteredCount + ')');
        btn.classList.toggle('sywat-btn-filter-active', unenteredOnly);
        btn.classList.toggle('sywat-btn-secondary', !unenteredOnly);
        btn.setAttribute('aria-pressed', unenteredOnly ? 'true' : 'false');
    }

    function syncPendingDraftButton() {
        if (!savePendingBtn) return;
        const count = draftRegIds.size;
        savePendingBtn.textContent = count > 0 ? ('Save Pending (' + count + ')') : 'Save Pending';
        savePendingBtn.classList.toggle('sywat-hidden', count === 0);
    }

    // 6. Instant Client-Side Line + Unentered Status + Live Search Filter (Zero Page Reload)
    function applyScoresLineAndSearchFilter() {
        const selectedLine    = lineSelect ? String(lineSelect.value || 'all') : 'all';
        const rawQuery        = searchInput ? String(searchInput.value || '') : '';
        const cleanQuery      = rawQuery.trim().toLowerCase();
        const rows            = document.querySelectorAll('.sywat-score-row');
        const isLineFiltered  = Boolean(selectedLine && selectedLine !== 'all');
        const isQueryFiltered = cleanQuery.length > 0;

        let visibleCount   = 0;
        let unenteredCount = 0;

        rows.forEach(function(row) {
            const regId              = row.getAttribute('data-id') || '';
            const rowLine            = row.getAttribute('data-line-idx') || '';
            const dataName           = (row.getAttribute('data-name') || '').toLowerCase();
            const currentlyUnentered = isRowCurrentlyUnentered(row);
            const lineOk             = (!isLineFiltered || rowLine === selectedLine);
            const queryOk            = (!isQueryFiltered || dataName.includes(cleanQuery));

            if (lineOk && queryOk && currentlyUnentered) {
                unenteredCount++;
            }

            // Keep rows edited during the active unentered filter pass visible until toggled off/on
            const statusOk = (!unenteredOnly || currentlyUnentered || editedInActivePass.has(regId));
            const isMatch  = (lineOk && queryOk && statusOk);

            row.classList.toggle('sywat-hidden', !isMatch);
            if (isMatch) {
                visibleCount++;
            }
        });

        const countEl = document.getElementById('sywat_scores_visible_count');
        if (countEl) {
            countEl.textContent = String(visibleCount);
        }

        syncUnenteredButton(unenteredCount);

        const emptyMsg = document.getElementById('sywat_scores_empty_filter_msg');
        if (emptyMsg) {
            emptyMsg.classList.toggle('sywat-hidden', visibleCount > 0);
        }

        document.querySelectorAll('.sywat-scores-hidden-line').forEach(function(hiddenLine) {
            hiddenLine.value = selectedLine;
        });

        document.querySelectorAll('.sywat-hidden-search').forEach(function(hiddenInput) {
            hiddenInput.value = rawQuery;
        });

        if (tournSlugKey) {
            if (selectedLine && selectedLine !== 'all') {
                sessionStorage.setItem('sywat_scores_line_' + tournSlugKey, selectedLine);
            } else {
                sessionStorage.removeItem('sywat_scores_line_' + tournSlugKey);
            }
        }

        if (rawQuery) {
            sessionStorage.setItem('sywat_score_search_query', rawQuery);
        } else {
            sessionStorage.removeItem('sywat_score_search_query');
        }
    }

    function toggleScoresUnenteredFilter() {
        unenteredOnly = !unenteredOnly;
        editedInActivePass.clear();

        if (unenteredOnly) {
            sessionStorage.setItem('sywat_scores_unentered_only', '1');
        } else {
            sessionStorage.removeItem('sywat_scores_unentered_only');
        }

        applyScoresLineAndSearchFilter();
    }

    // 7. Helper: Status Badge Class & Text Updater
    function setRowStatus(regId, state, customMsg) {
        const badge = document.getElementById('status_' + regId);
        if (!badge) return;

        badge.className = 'sywat-save-status';
        if (state === 'saved') {
            badge.classList.add('sywat-status-saved');
            badge.textContent = customMsg || '✓ Saved';
            draftRegIds.delete(regId);
        } else if (state === 'draft') {
            badge.classList.add('sywat-status-draft');
            badge.textContent = customMsg || '⚡ Local Draft';
            draftRegIds.add(regId);
        } else if (state === 'saving') {
            badge.classList.add('sywat-status-saving');
            badge.textContent = customMsg || '⏳ Saving...';
        } else if (state === 'error') {
            badge.classList.add('sywat-status-error');
            badge.textContent = customMsg || '⚠️ Invalid Range';
        }

        syncPendingDraftButton();
    }

    // 8. Helper: Input Range & Format Validator
    function validateInput(input) {
        if (!input) return true;
        const min    = parseInt(input.getAttribute('min'), 10) || 0;
        const max    = parseInt(input.getAttribute('max'), 10) || 300;
        const valStr = input.value.trim();

        if (valStr === '') {
            input.classList.remove('invalid-input');
            return true;
        }

        const val = parseInt(valStr, 10);
        if (isNaN(val) || val < min || val > max) {
            input.classList.add('invalid-input');
            return false;
        }

        input.classList.remove('invalid-input');
        return true;
    }

    // 9. Helper: Local Storage Key Generator
    function getStorageKey(regId) {
        return 'sywat_draft_' + tournSlugKey + '_' + regId;
    }

    // 10. Restore Unsaved Local Drafts & Saved Line Filter on Page Load
    document.querySelectorAll('.sywat-score-row').forEach(function(row) {
        const regId      = row.getAttribute('data-id');
        const savedDraft = localStorage.getItem(getStorageKey(regId));
        const isLocked   = row.classList.contains('locked-row');

        if (savedDraft && !isLocked) {
            try {
                const data          = JSON.parse(savedDraft);
                const scoreInput    = row.querySelector('.score-val');
                const xInput        = row.querySelector('.x-val');
                const incCheckbox   = row.querySelector('.sywat-inc-val');
                const incStateInput = row.querySelector('.sywat-inc-state');

                if (scoreInput && data.score !== undefined) scoreInput.value = data.score;
                if (xInput && data.x_count !== undefined)   xInput.value     = data.x_count;

                if (data.is_inc !== undefined) {
                    const incVal = (data.is_inc === 1 || data.is_inc === '1' || data.is_inc === true) ? '1' : '0';
                    if (incCheckbox)   incCheckbox.checked = (incVal === '1');
                    if (incStateInput) incStateInput.value = incVal;
                }

                setRowStatus(regId, 'draft', '⚡ Local Draft');
            } catch (e) {}
        } else if (isLocked && regId) {
            localStorage.removeItem(getStorageKey(regId));
        }
    });

    if (lineSelect && tournSlugKey && (!lineSelect.value || lineSelect.value === 'all')) {
        const savedLine = sessionStorage.getItem('sywat_scores_line_' + tournSlugKey);
        if (savedLine) {
            const hasOption = Array.from(lineSelect.options).some(function(opt) {
                return opt.value === savedLine;
            });
            if (hasOption) {
                lineSelect.value = savedLine;
            }
        }
    }

    if (searchInput) {
        if (!searchInput.value) {
            const savedQuery = sessionStorage.getItem('sywat_score_search_query');
            if (savedQuery) {
                searchInput.value = savedQuery;
            }
        }

        searchInput.addEventListener('input', function() {
            applyScoresLineAndSearchFilter();
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
    }

    // Prevent Enter key inside score/X inputs from submitting the form; commit row save immediately instead
    document.querySelectorAll('.sywat-score-input').forEach(function(input) {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const regId = input.getAttribute('data-id');
                if (regId && !input.disabled) {
                    performBackgroundSave(regId);
                }
            }
        });
    });

    syncLineTrackerCounts();
    applyScoresLineAndSearchFilter();
    syncPendingDraftButton();

    // 11. Isolated Per-Archer Serialized Background Auto-Save (admin-ajax.php)
    function performBackgroundSave(regId, options) {
        const opts = options || {};
        const row  = document.querySelector('.sywat-score-row[data-id="' + regId + '"]');
        if (!row || row.classList.contains('locked-row')) return;

        const scoreInput    = row.querySelector('.score-val');
        const xInput        = row.querySelector('.x-val');
        const incCheckbox   = row.querySelector('.sywat-inc-val');
        const incStateInput = row.querySelector('.sywat-inc-state');

        const isScoreValid = validateInput(scoreInput);
        const isXValid     = validateInput(xInput);

        if (!isScoreValid || !isXValid) {
            setRowStatus(regId, 'error', '⚠️ Range Error');
            return;
        }

        const scoreVal = scoreInput ? (scoreInput.value.trim() !== '' ? String(parseInt(scoreInput.value.trim(), 10) || 0) : '0') : '0';
        const xVal     = xInput ? (xInput.value.trim() !== '' ? String(parseInt(xInput.value.trim(), 10) || 0) : '0') : '0';
        const incVal   = getRowIncValue(row);

        if (unenteredOnly) {
            editedInActivePass.add(regId);
        }

        syncLineTrackerCounts();
        applyScoresLineAndSearchFilter();

        localStorage.setItem(getStorageKey(regId), JSON.stringify({ score: scoreVal, x_count: xVal, is_inc: incVal }));
        draftRegIds.add(regId);
        syncPendingDraftButton();

        if (!navigator.onLine) {
            setRowStatus(regId, 'draft', '⚠️ Offline Draft');
            return;
        }

        // Queue follow-up save if an auto-save for this archer is already in flight
        if (inFlightSaves.has(regId)) {
            queuedSaves.add(regId);
            return;
        }

        inFlightSaves.add(regId);
        setRowStatus(regId, 'saving', '⏳ Saving...');

        const formData = new FormData();
        formData.append('action', 'sywat_autosave_single_score');
        formData.append('sywat_action', 'autosave_single_score');
        formData.append('sywat_nonce', nonceVal);
        formData.append('selected_tournament', currentTourn);
        formData.append('reg_id', regId);
        formData.append('score', scoreVal);
        formData.append('x_count', xVal);
        formData.append('is_inc', incVal);

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: Boolean(opts.keepalive),
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data && data.success) {
                const latestScore = scoreInput ? (scoreInput.value.trim() !== '' ? String(parseInt(scoreInput.value.trim(), 10) || 0) : '0') : '0';
                const latestX     = xInput ? (xInput.value.trim() !== '' ? String(parseInt(xInput.value.trim(), 10) || 0) : '0') : '0';
                const latestInc   = getRowIncValue(row);
                const matchesNow  = (latestScore === scoreVal && latestX === xVal && latestInc === incVal);

                if (scoreInput)    scoreInput.setAttribute('data-original-val', scoreVal);
                if (xInput)        xInput.setAttribute('data-original-val', xVal);
                if (incCheckbox)   incCheckbox.setAttribute('data-original-val', incVal);
                if (incStateInput) incStateInput.setAttribute('data-original-val', incVal);

                if (!queuedSaves.has(regId) && matchesNow) {
                    localStorage.removeItem(getStorageKey(regId));
                    setRowStatus(regId, 'saved', '✓ Saved');
                } else {
                    queuedSaves.add(regId);
                }
            } else {
                setRowStatus(regId, 'draft', '⚡ Local Draft');
            }
        })
        .catch(function() {
            setRowStatus(regId, 'draft', '⚠️ Offline Draft');
        })
        .finally(function() {
            inFlightSaves.delete(regId);
            if (queuedSaves.has(regId)) {
                queuedSaves.delete(regId);
                performBackgroundSave(regId);
            }
        });
    }

    function flushAllPendingDrafts(options) {
        const opts = options || {};
        document.querySelectorAll('.sywat-score-row:not(.locked-row)').forEach(function(row) {
            const regId = row.getAttribute('data-id');
            if (regId && (draftRegIds.has(regId) || localStorage.getItem(getStorageKey(regId)))) {
                performBackgroundSave(regId, opts);
            }
        });
    }

    // 12. Dynamic Event Listeners for User Input, INC Checkbox Toggle, & Blur
    document.addEventListener('input', function(e) {
        if (e.target && e.target.classList.contains('sywat-score-input') && !e.target.disabled) {
            const regId = e.target.getAttribute('data-id');
            const row   = document.querySelector('.sywat-score-row[data-id="' + regId + '"]');
            if (!row) return;

            const isValid = validateInput(e.target);
            if (isValid) {
                const scoreInput = row.querySelector('.score-val');
                const xInput     = row.querySelector('.x-val');
                const sVal       = scoreInput ? scoreInput.value : '0';
                const xVal       = xInput ? xInput.value : '0';
                const incVal     = getRowIncValue(row);

                if (unenteredOnly) {
                    editedInActivePass.add(regId);
                }

                localStorage.setItem(getStorageKey(regId), JSON.stringify({ score: sVal, x_count: xVal, is_inc: incVal }));
                setRowStatus(regId, 'draft', '⚡ Local Draft');
                syncLineTrackerCounts();
                applyScoresLineAndSearchFilter();
            } else {
                setRowStatus(regId, 'error', '⚠️ Range Error');
            }
        }
    });

    document.addEventListener('change', function(e) {
        if (!e.target) return;

        if (e.target.classList.contains('sywat-inc-val') && !e.target.disabled) {
            const regId = e.target.getAttribute('data-id');
            const row   = document.querySelector('.sywat-score-row[data-id="' + regId + '"]');
            if (!row || !regId) return;

            getRowIncValue(row);
            performBackgroundSave(regId);
            return;
        }

        const action = e.target.getAttribute('data-sywat-action') || '';
        if (action === 'submit-scores-tournament') {
            const filterForm = document.getElementById('sywat_score_filter_form');
            if (filterForm) {
                filterForm.submit();
            }
        } else if (action === 'filter-scores-line') {
            applyScoresLineAndSearchFilter();
        }
    });

    document.addEventListener('click', function(e) {
        const actionEl = e.target.closest('[data-sywat-action]');
        if (!actionEl) return;

        const action = actionEl.getAttribute('data-sywat-action');

        if (action === 'toggle-scores-unentered') {
            e.preventDefault();
            toggleScoresUnenteredFilter();
        } else if (action === 'select-scores-line') {
            e.preventDefault();
            if (lineSelect) {
                lineSelect.value = actionEl.getAttribute('data-line-idx') || 'all';
            }
            applyScoresLineAndSearchFilter();
            const tableEl = document.getElementById('sywat_scores_table');
            if (tableEl && typeof tableEl.scrollIntoView === 'function') {
                tableEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        } else if (action === 'clear-scores-search') {
            e.preventDefault();
            if (searchInput) {
                searchInput.value = '';
                applyScoresLineAndSearchFilter();
                searchInput.focus();
            }
        } else if (action === 'flush-pending-scores') {
            e.preventDefault();
            flushAllPendingDrafts();
        }
    });

    document.addEventListener('focusout', function(e) {
        if (e.target && e.target.classList.contains('sywat-score-input') && !e.target.disabled) {
            const regId = e.target.getAttribute('data-id');
            if (regId) performBackgroundSave(regId);
        }
    });

    // 13. Auto-Flush Pending Drafts When Reconnecting Online or Leaving/Hiding Tab
    window.addEventListener('online', function() {
        flushAllPendingDrafts();
    });

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            const activeEl = document.activeElement;
            if (activeEl && activeEl.classList && activeEl.classList.contains('sywat-score-input') && !activeEl.disabled) {
                const activeRegId = activeEl.getAttribute('data-id');
                if (activeRegId) {
                    performBackgroundSave(activeRegId, { keepalive: true });
                }
            }
            flushAllPendingDrafts({ keepalive: true });
        }
    });

    // 14. CSV Export Locked-Line Guard
    if (batchForm) {
        batchForm.addEventListener('submit', function(e) {
            const submitterVal = e.submitter ? e.submitter.value : '';

            if (submitterVal === 'export_csv') {
                if (!hasAnyLockedLine()) {
                    e.preventDefault();
                    if (inlineNoticeEl) {
                        inlineNoticeEl.innerHTML = '<div class="sywat-alert sywat-alert-danger">⚠️ At least one shooting line must be locked before exporting CSV.</div>';
                        if (typeof inlineNoticeEl.scrollIntoView === 'function') {
                            inlineNoticeEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    }
                    return false;
                }
                return true;
            }

            e.preventDefault();
            return false;
        });
    }

    window.SYWAT.scores = {
        applyLineAndSearchFilter: applyScoresLineAndSearchFilter,
        toggleUnenteredFilter: toggleScoresUnenteredFilter,
        syncLineTrackerCounts: syncLineTrackerCounts,
        performBackgroundSave: performBackgroundSave,
        flushPendingDrafts: flushAllPendingDrafts
    };
});
