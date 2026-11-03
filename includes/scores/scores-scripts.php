<?php
/**
 * Sub-Module: Scores Engine Client Scripts
 * File: includes/scores/scores-scripts.php
 * Version: 3.9.0
 */

if (!defined('ABSPATH')) exit;

function sywat_scores_render_client_scripts() {
    ?>
    <!-- Prevent form resubmission on page refresh (F5) -->
    <script>
    if ( window.history.replaceState ) {
        window.history.replaceState( null, null, window.location.href );
    }

    document.addEventListener("DOMContentLoaded", function() {
        const currentTourn = document.getElementById('selected_tournament_val') ? document.getElementById('selected_tournament_val').value : '';
        const nonceVal     = document.getElementById('sywat_nonce') ? document.getElementById('sywat_nonce').value : '';

        // Real-Time Search Filter
        const searchInput = document.getElementById('sywat_score_search_input');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('.sywat-score-row');
                
                rows.forEach(row => {
                    const name = row.getAttribute('data-name');
                    if (name && name.includes(filter)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });

                const hiddenSearches = document.querySelectorAll('.sywat-hidden-search');
                hiddenSearches.forEach(hidden => {
                    hidden.value = this.value;
                });
            });
            searchInput.dispatchEvent(new Event('input'));
        }

        // Helper: Status Badge Updater
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

        // Helper: Range Validator
        function validateInput(input) {
            const min = parseInt(input.getAttribute('min'), 10) || 0;
            const max = parseInt(input.getAttribute('max'), 10) || 300;
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

        // Local Storage Key Generator
        function getStorageKey(regId) {
            return 'sywat_draft_' + currentTourn.replace(/[^a-z0-9]/gi, '_') + '_' + regId;
        }

        // 1. Restore Unsaved Drafts on Page Load
        document.querySelectorAll('.sywat-score-row').forEach(row => {
            const regId = row.getAttribute('data-id');
            const savedDraft = localStorage.getItem(getStorageKey(regId));
            const isLocked = row.classList.contains('locked-row');

            if (savedDraft && !isLocked) {
                try {
                    const data = JSON.parse(savedDraft);
                    const scoreInput = row.querySelector('.score-val');
                    const xInput     = row.querySelector('.x-val');

                    if (scoreInput && data.score !== undefined) scoreInput.value = data.score;
                    if (xInput && data.x_count !== undefined) xInput.value = data.x_count;

                    setRowStatus(regId, 'draft', '⚡ Local Draft');
                } catch(e) {}
            } else if (isLocked) {
                localStorage.removeItem(getStorageKey(regId));
            }
        });

        // 2. Background AJAX Saver
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
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (scoreInput) scoreInput.setAttribute('data-original-val', scoreVal);
                    if (xInput) xInput.setAttribute('data-original-val', xVal);
                    
                    localStorage.removeItem(getStorageKey(regId));
                    setRowStatus(regId, 'saved', '✓ Saved');
                } else {
                    setRowStatus(regId, 'draft', '⚡ Local Draft');
                }
            })
            .catch(() => {
                setRowStatus(regId, 'draft', '⚠️ Offline Draft');
            });
        }

        // 3. Event Listeners for Input & Blur
        document.addEventListener('input', function(e) {
            if (e.target && e.target.classList.contains('sywat-score-input') && !e.target.disabled) {
                const regId = e.target.getAttribute('data-id');
                const row   = document.querySelector('.sywat-score-row[data-id="' + regId + '"]');
                if (!row) return;

                const isValid = validateInput(e.target);
                if (isValid) {
                    const scoreInput = row.querySelector('.score-val');
                    const xInput     = row.querySelector('.x-val');
                    localStorage.setItem(getStorageKey(regId), JSON.stringify({ score: scoreInput.value, x_count: xInput.value }));
                    setRowStatus(regId, 'draft', '⚡ Local Draft');
                } else {
                    setRowStatus(regId, 'error', '⚠️ Range Error');
                }
            }
        });

        document.addEventListener('focusout', function(e) {
            if (e.target && e.target.classList.contains('sywat-score-input') && !e.target.disabled) {
                const regId = e.target.getAttribute('data-id');
                performBackgroundSave(regId);
            }
        });

        // 4. Sync Drafts Automatically When Internet Connection Restores
        window.addEventListener('online', function() {
            document.querySelectorAll('.sywat-score-row:not(.locked-row)').forEach(row => {
                const regId = row.getAttribute('data-id');
                if (localStorage.getItem(getStorageKey(regId))) {
                    performBackgroundSave(regId);
                }
            });
        });

        // 5. Concurrency Protection & Local Storage Clear on Full Batch Submit
        const batchForm = document.getElementById('sywat_batch_score_form');
        if (batchForm) {
            batchForm.addEventListener('submit', function(e) {
                if (e.submitter && e.submitter.value === 'reset_scores') {
                    return true;
                }
                if (e.submitter && e.submitter.value === 'toggle_lock') {
                    return true;
                }
                if (e.submitter && e.submitter.value === 'export_csv') {
                    return true;
                }

                const inputs = batchForm.querySelectorAll('.sywat-score-input');
                let hasError = false;
                let firstInvalid = null;
                let actuallyChangedCount = 0;

                inputs.forEach(input => {
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
                    inputs.forEach(input => {
                        if (input.disabled) return;

                        if (input.value.trim() === input.getAttribute('data-original-val').trim()) {
                            input.disabled = true;
                            input.setAttribute('data-concurrency-disabled', 'true');
                        } else {
                            actuallyChangedCount++;
                        }
                    });

                    if (actuallyChangedCount === 0) {
                        e.preventDefault();
                        inputs.forEach(input => {
                            if (input.hasAttribute('data-concurrency-disabled')) {
                                input.disabled = false;
                                input.removeAttribute('data-concurrency-disabled');
                            }
                        });
                        return false; 
                    }

                    document.querySelectorAll('.sywat-score-row').forEach(row => {
                        const regId = row.getAttribute('data-id');
                        localStorage.removeItem(getStorageKey(regId));
                    });
                }
            });
        }
    });
    </script>
    <?php
}
