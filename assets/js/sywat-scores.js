/**
 * Client Script: Score Engine Interactivity & Real-Time Auto-Save
 * Path: assets/js/sywat-scores.js
 * Description: Dynamic TFAA target URL switcher, real-time search filtering, range validation, local draft recovery, AJAX auto-save, offline sync, and batch concurrency protection.
 * Author: SYWAT Archery
 * Version: 7.0.0
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

    // 3. Real-Time Search Filter (Dual-Layer: Inline Display + Class-Driven)
    const searchInput = document.getElementById('sywat_score_search_input');
    if (searchInput) {
        function filterRows() {
            const filter = searchInput.value.trim().toLowerCase();
            const rows   = document.querySelectorAll('.sywat-score-row');

            rows.forEach(function(row) {
                const name        = row.getAttribute('data-name') || '';
                const textContent = (row.textContent || row.innerText || '').toLowerCase();

                if (!filter || name.includes(filter) || textContent.includes(filter)) {
                    row.classList.remove('sywat-hidden');
                    row.style.display = '';
                } else {
                    row.classList.add('sywat-hidden');
                    row.style.display = 'none';
                }
            });

            const hiddenSearches = document.querySelectorAll('.sywat-hidden-search');
            hiddenSearches.forEach(function(hidden) {
                hidden.value = searchInput.value;
            });
        }

        searchInput.addEventListener('input', filterRows);
        searchInput.addEventListener('keyup', filterRows);

        // Initialize search state
        filterRows();
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

    // 6. Helper: Local Storage Key Generator
    function getStorageKey(regId) {
        return 'sywat_draft_' + currentTourn.replace(/[^a-z0-9]/gi, '_') + '_' + regId;
    }

    // 7. Restore Unsaved Local Drafts on Page Load
    document.querySelectorAll('.sywat-score-row').forEach(function(row) {
        const regId      = row.getAttribute('data-id');
        const savedDraft = localStorage.getItem(getStorageKey(regId));
        const isLocked   = row.classList.contains('locked-row');

        if (savedDraft && !isLocked) {
            try {
                const data       = JSON.parse(savedDraft);
                const scoreInput = row.querySelector('.score-val');
                const xInput     = row.querySelector('.x-val');

                if (scoreInput && data.score !== undefined) scoreInput.value = data.score;
                if (xInput && data.x_count !== undefined)   xInput.value     = data.x_count;

                setRowStatus(regId, 'draft', '⚡ Local Draft');
            } catch(e) {}
        } else if (isLocked && regId) {
            localStorage.removeItem(getStorageKey(regId));
        }
    });

    // 8. Background Asynchronous Single-Score Saver
    function performBackgroundSave(regId) {
        const row = document.querySelector('.sywat-score-row[data-id="' + regId + '"]');
        if (!row || row.classList.contains('locked-row')) return;

        const scoreInput = row.querySelector('.score-val');
        const xInput     = row.querySelector('.x-val');

        const isScoreValid = validateInput(scoreInput);
        const isXValid     = validateInput(xInput);

        if (!isScoreValid || !isXValid) {
            setRowStatus(regId, 'error', '⚠️ Range Error');
            return;
        }

        const scoreVal = scoreInput ? scoreInput.value : '0';
        const xVal     = xInput ? xInput.value : '0';

        localStorage.setItem(getStorageKey(regId), JSON.stringify({ score: scoreVal, x_count: xVal }));

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

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                if (scoreInput) scoreInput.setAttribute('data-original-val', scoreVal);
                if (xInput)     xInput.setAttribute('data-original-val', xVal);

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

    // 9. Dynamic Event Listeners for User Input & Blur
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

                localStorage.setItem(getStorageKey(regId), JSON.stringify({ score: sVal, x_count: xVal }));
                setRowStatus(regId, 'draft', '⚡ Local Draft');
            } else {
                setRowStatus(regId, 'error', '⚠️ Range Error');
            }
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

    // 11. Concurrency Protection & Local Storage Clear on Full Batch Submit
    const batchForm = document.getElementById('sywat_batch_score_form');
    if (batchForm) {
        batchForm.addEventListener('submit', function(e) {
            const submitterVal = e.submitter ? e.submitter.value : '';

            if (['reset_scores', 'toggle_lock', 'export_csv', 'sync_tfaa_scores'].includes(submitterVal)) {
                return true;
            }

            const inputs = batchForm.querySelectorAll('.sywat-score-input');
            let hasError = false;
            let firstInvalid = null;
            let actuallyChangedCount = 0;

            inputs.forEach(function(input) {
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
                inputs.forEach(function(input) {
                    if (input.disabled) return;

                    const origVal = input.getAttribute('data-original-val') || '';
                    if (input.value.trim() === origVal.trim()) {
                        input.disabled = true;
                        input.setAttribute('data-concurrency-disabled', 'true');
                    } else {
                        actuallyChangedCount++;
                    }
                });

                if (actuallyChangedCount === 0) {
                    e.preventDefault();
                    inputs.forEach(function(input) {
                        if (input.hasAttribute('data-concurrency-disabled')) {
                            input.disabled = false;
                            input.removeAttribute('data-concurrency-disabled');
                        }
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
