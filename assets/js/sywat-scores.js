/**
 * Client Script: Score Engine Interactivity & Real-Time Auto-Save
 * Path: assets/js/sywat-scores.js
 * Description: Dynamic TFAA target URL switcher, real-time search filtering with sessionStorage persistence and class-based visibility toggling, range validation, local draft recovery (score, X count, and incomplete round "INC" flag), AJAX auto-save, offline sync, and multi-scorekeeper batch concurrency protection.
 * Compliance: Pure vanilla JS — zero jQuery dependency, zero inline style mutations (uses .sywat-hidden class toggling), multi-scorekeeper field-level concurrency protection.
 * Author: SYWAT Archery
 * Version: 7.3.0
 */

document.addEventListener("DOMContentLoaded", function() {
    // 1. Prevent form resubmission on page refresh (F5)
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }

    const currentTournInput = document.getElementById('selected_tournament_val');
    const nonceInput        = document.getElementById('sywat_nonce');

    const currentTourn = currentTournInput ? currentTournInput.value : '';
    const nonceVal     = nonceInput ? nonceInput.value : '';

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

    // 3. Real-Time Search Filter (Class-Based Visibility + Session Persistence)
    const searchInput = document.getElementById('sywat_score_search_input');
    const clearBtn    = document.getElementById('sywat_score_search_clear');

    function applyScoreSearchFilter(query) {
        const cleanQuery = query.trim().toLowerCase();
        const rows       = document.querySelectorAll('.sywat-score-row');

        rows.forEach(function(row) {
            const dataName    = (row.getAttribute('data-name') || '').toLowerCase();
            const textContent = (row.textContent || row.innerText || '').toLowerCase();
            const isMatch     = (!cleanQuery || dataName.includes(cleanQuery) || textContent.includes(cleanQuery));

            row.classList.toggle('sywat-hidden', !isMatch);
        });

        document.querySelectorAll('.sywat-hidden-search').forEach(function(hiddenInput) {
            hiddenInput.value = query;
        });

        if (query) {
            sessionStorage.setItem('sywat_score_search_query', query);
        } else {
            sessionStorage.removeItem('sywat_score_search_query');
        }
    }

    if (searchInput) {
        let initialQuery = searchInput.value;
        if (!initialQuery) {
            const savedQuery = sessionStorage.getItem('sywat_score_search_query');
            if (savedQuery) {
                initialQuery = savedQuery;
                searchInput.value = savedQuery;
            }
        }

        if (initialQuery) {
            applyScoreSearchFilter(initialQuery);
        }

        searchInput.addEventListener('input', function() {
            applyScoreSearchFilter(this.value);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                searchInput.value = '';
                applyScoreSearchFilter('');
                searchInput.focus();
            });
        }
    }

    // 4. Helper: Status Badge Class & Text Updater
    function setRowStatus(regId, state, customMsg) {
        const badge = document.getElementById('status_' + regId);
        if (!badge) return;

        badge.className = 'sywat-save-status';
        if (state === 'saved') {
            badge.classList.add('sywat-status-saved');
            badge.textContent = customMsg || '✓ Saved';
        } else if (state === 'draft') {
            badge.classList.add('sywat-status-draft');
            badge.textContent = customMsg || '⚡ Local Draft';
        } else if (state === 'saving') {
            badge.classList.add('sywat-status-saving');
            badge.textContent = customMsg || '⏳ Saving...';
        } else if (state === 'error') {
            badge.classList.add('sywat-status-error');
            badge.textContent = customMsg || '⚠️ Invalid Range';
        }
    }

    // 5. Helper: Input Range & Format Validator
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
        } else {
            input.classList.remove('invalid-input');
            return true;
        }
    }

    // 6. Helper: Local Storage Key Generator & Row State Reader
    function getStorageKey(regId) {
        return 'sywat_draft_' + currentTourn.replace(/[^a-z0-9]/gi, '_') + '_' + regId;
    }

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

    // 7. Restore Unsaved Local Drafts on Page Load
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

    // 8. Background Asynchronous Single-Score & INC Saver
    function performBackgroundSave(regId) {
        const row = document.querySelector('.sywat-score-row[data-id="' + regId + '"]');
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

        const scoreVal = scoreInput ? scoreInput.value : '0';
        const xVal     = xInput ? xInput.value : '0';
        const incVal   = getRowIncValue(row);

        localStorage.setItem(getStorageKey(regId), JSON.stringify({ score: scoreVal, x_count: xVal, is_inc: incVal }));

        if (!navigator.onLine) {
            setRowStatus(regId, 'draft', '⚠️ Offline Draft');
            return;
        }

        setRowStatus(regId, 'saving', '⏳ Saving...');

        const formData = new FormData();
        formData.append('sywat_action', 'autosave_single_score');
        formData.append('sywat_nonce', nonceVal);
        formData.append('selected_tournament', currentTourn);
        formData.append('reg_id', regId);
        formData.append('score', scoreVal);
        formData.append('x_count', xVal);
        formData.append('is_inc', incVal);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                if (scoreInput)    scoreInput.setAttribute('data-original-val', scoreVal);
                if (xInput)        xInput.setAttribute('data-original-val', xVal);
                if (incCheckbox)   incCheckbox.setAttribute('data-original-val', incVal);
                if (incStateInput) incStateInput.setAttribute('data-original-val', incVal);

                localStorage.removeItem(getStorageKey(regId));
                setRowStatus(regId, 'saved', '✓ Saved');
            } else {
                setRowStatus(regId, 'draft', '⚡ Local Draft');
            }
        })
        .catch(function() {
            setRowStatus(regId, 'draft', '⚠️ Offline Draft');
        });
    }

    // 9. Dynamic Event Listeners for User Input, INC Checkbox Toggle, & Blur
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

                localStorage.setItem(getStorageKey(regId), JSON.stringify({ score: sVal, x_count: xVal, is_inc: incVal }));
                setRowStatus(regId, 'draft', '⚡ Local Draft');
            } else {
                setRowStatus(regId, 'error', '⚠️ Range Error');
            }
        }
    });

    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList.contains('sywat-inc-val') && !e.target.disabled) {
            const regId = e.target.getAttribute('data-id');
            const row   = document.querySelector('.sywat-score-row[data-id="' + regId + '"]');
            if (!row || !regId) return;

            getRowIncValue(row);
            performBackgroundSave(regId);
        }
    });

    document.addEventListener('focusout', function(e) {
        if (e.target && e.target.classList.contains('sywat-score-input') && !e.target.disabled) {
            const regId = e.target.getAttribute('data-id');
            if (regId) performBackgroundSave(regId);
        }
    });

    // 10. Sync Unsaved Local Drafts Automatically When Reconnecting Online
    window.addEventListener('online', function() {
        document.querySelectorAll('.sywat-score-row:not(.locked-row)').forEach(function(row) {
            const regId = row.getAttribute('data-id');
            if (regId && localStorage.getItem(getStorageKey(regId))) {
                performBackgroundSave(regId);
            }
        });
    });

    // 11. Multi-Scorekeeper Concurrency Protection & Local Storage Clear on Full Batch Submit
    const batchForm = document.getElementById('sywat_batch_score_form');
    if (batchForm) {
        batchForm.addEventListener('submit', function(e) {
            const submitterVal = e.submitter ? e.submitter.value : '';

            if (['reset_scores', 'toggle_lock', 'export_csv', 'sync_tfaa_scores'].includes(submitterVal)) {
                return true;
            }

            // Ensure hidden inc_state inputs match current checkbox states prior to diff check
            batchForm.querySelectorAll('.sywat-score-row').forEach(function(row) {
                getRowIncValue(row);
            });

            const scoreInputs   = batchForm.querySelectorAll('.sywat-score-input');
            const trackedFields = batchForm.querySelectorAll('.sywat-score-input, .sywat-inc-state');
            let hasError             = false;
            let firstInvalid         = null;
            let actuallyChangedCount = 0;

            scoreInputs.forEach(function(input) {
                if (input.disabled) return;

                if (!validateInput(input)) {
                    hasError = true;
                    if (!firstInvalid) firstInvalid = input;
                }
            });

            if (hasError) {
                e.preventDefault();
                alert('⚠️ Cannot save scores!\n\nOne or more values are out of range:\n- Scores must be between 0 and 300.\n- X Counts must be between 0 and 60.\n\nPlease fix the highlighted red field(s) before saving.');
                if (firstInvalid) firstInvalid.focus();
            } else {
                trackedFields.forEach(function(field) {
                    if (field.disabled) return;

                    const origVal = field.getAttribute('data-original-val') || '';
                    if (field.value.trim() === origVal.trim()) {
                        field.disabled = true;
                        field.setAttribute('data-concurrency-disabled', 'true');

                        if (field.classList.contains('sywat-inc-state')) {
                            const regId = field.getAttribute('data-id');
                            const row   = regId ? batchForm.querySelector('.sywat-score-row[data-id="' + regId + '"]') : null;
                            const cb    = row ? row.querySelector('.sywat-inc-val') : null;
                            if (cb && !cb.disabled) {
                                cb.disabled = true;
                                cb.setAttribute('data-concurrency-disabled', 'true');
                            }
                        }
                    } else {
                        actuallyChangedCount++;
                    }
                });

                if (actuallyChangedCount === 0) {
                    e.preventDefault();
                    batchForm.querySelectorAll('[data-concurrency-disabled="true"]').forEach(function(field) {
                        field.disabled = false;
                        field.removeAttribute('data-concurrency-disabled');
                    });
                    return false;
                }

                document.querySelectorAll('.sywat-score-row').forEach(function(row) {
                    const regId = row.getAttribute('data-id');
                    if (regId) localStorage.removeItem(getStorageKey(regId));
                });
            }
        });
    }
});
