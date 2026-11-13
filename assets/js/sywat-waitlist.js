/**
 * SYWAT Archery - Waitlist Queue Client Controller
 * Path: assets/js/sywat-waitlist.js
 * Description: Namespaced vanilla JS module managing live waitlist search synchronization across Roster and Check-In portals, open-line move validation, single-prompt confirmation handling, and double-submit protection.
 * Compliance: Zero jQuery, zero inline style.* mutations, delegated event listeners, guarded DOM initialization.
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    /**
     * Filter waitlist family group headers and member rows via CSS class toggle.
     *
     * @param {string} rawQuery Search input string.
     * @return {void}
     */
    function applyWaitlistSearchFilter(rawQuery) {
        const filter = (rawQuery || '').toLowerCase().trim();
        const rows = document.querySelectorAll('.sywat-waitlist-row, .sywat-waitlist-group-header-row');

        rows.forEach(function(row) {
            const searchHaystack = (
                row.getAttribute('data-waitlist-search') ||
                row.getAttribute('data-name') ||
                ''
            ).toLowerCase();

            if (!filter || searchHaystack.indexOf(filter) !== -1) {
                row.classList.remove('sywat-hidden');
            } else {
                row.classList.add('sywat-hidden');
            }
        });
    }

    /**
     * Enable or disable the sibling Move submit button when a destination line is chosen.
     *
     * @param {HTMLSelectElement} selectEl Destination line select element.
     * @return {void}
     */
    function handleMoveSelectChange(selectEl) {
        if (!selectEl) return;
        const form = selectEl.closest('.sywat-waitlist-move-form');
        if (!form) return;

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = !selectEl.value;
        }
    }

    /**
     * Validate waitlist form submissions, confirm promote/move actions without duplicate
     * prompts when sywat-roster.js is active, and prevent accidental double-submits.
     *
     * @param {SubmitEvent} event Delegated submit event.
     * @return {void}
     */
    function handleWaitlistFormSubmit(event) {
        const form = event.target.closest('.sywat-waitlist-section form');
        if (!form || event.defaultPrevented) {
            return;
        }

        // Prevent duplicate submissions while POST request is in flight
        if (form.dataset.sywatSubmitting === '1') {
            event.preventDefault();
            return;
        }

        // Handle explicit data-sywat-confirm prompts (avoid double prompt if sywat-roster.js already handles it)
        if (form.hasAttribute('data-sywat-confirm')) {
            const rosterHandlesConfirm = Boolean(window.SYWAT && window.SYWAT.roster);
            if (!rosterHandlesConfirm && !event._sywatConfirmHandled) {
                event._sywatConfirmHandled = true;
                const confirmText = form.getAttribute('data-sywat-confirm');
                if (confirmText && !window.confirm(confirmText)) {
                    event.preventDefault();
                    return;
                }
            }
        } else if (form.classList.contains('sywat-waitlist-move-form')) {
            const lineSelect = form.querySelector('select[name="target_line_index"]');
            if (!lineSelect || !lineSelect.value) {
                event.preventDefault();
                return;
            }

            const selectedOpt = lineSelect.options[lineSelect.selectedIndex];
            const destLabel = selectedOpt ? selectedOpt.text.trim() : 'the selected shooting line';
            const scopeInput = form.querySelector('input[name="move_scope"]');
            const isGroupMove = scopeInput && scopeInput.value === 'group';

            const moveConfirmText = isGroupMove
                ? 'Move this entire waitlisted family group to ' + destLabel + ' as Pre-Reg?'
                : 'Move this waitlisted archer to ' + destLabel + ' as Pre-Reg?';

            if (!window.confirm(moveConfirmText)) {
                event.preventDefault();
                return;
            }
        }

        // Lock form against double-clicks once validated
        form.dataset.sywatSubmitting = '1';
        const activeSubmitBtn = form.querySelector('button[type="submit"]');
        if (activeSubmitBtn) {
            activeSubmitBtn.disabled = true;
        }
    }

    // Namespaced Module API (Section IV.1)
    window.SYWAT.waitlist = {
        applySearchFilter: applyWaitlistSearchFilter,
        handleMoveSelectChange: handleMoveSelectChange
    };

    // Delegated Input Listener: Sync waitlist filter with Roster (#search_shooter) and Check-In (#sywat_search_input)
    document.addEventListener('input', function(event) {
        const target = event.target;
        if (!target) return;

        if (target.id === 'search_shooter' || target.id === 'sywat_search_input') {
            applyWaitlistSearchFilter(target.value);
        }
    });

    // Delegated Change Listener: Destination open-line select changes
    document.addEventListener('change', function(event) {
        const target = event.target;
        if (!target) return;

        if (target.matches('.sywat-waitlist-move-form select[name="target_line_index"]')) {
            handleMoveSelectChange(target);
        }
    });

    // Delegated Click Listener: Clear search buttons on Roster and Check-In screens
    document.addEventListener('click', function(event) {
        const clearBtn = event.target.closest('[data-sywat-action="clear-roster-search"], .sywat-search-input-group button');
        if (!clearBtn) return;

        const rosterSearch = document.getElementById('search_shooter');
        const deskSearch = document.getElementById('sywat_search_input');

        if (rosterSearch && !rosterSearch.value) {
            applyWaitlistSearchFilter('');
        } else if (deskSearch && !deskSearch.value) {
            applyWaitlistSearchFilter('');
        }
    });

    // Delegated Submit Listener: Confirmations & double-submit protection
    document.addEventListener('submit', handleWaitlistFormSubmit);

    /**
     * Initialize waitlist search filter state on DOM ready.
     *
     * @return {void}
     */
    function initWaitlistModule() {
        const rosterSearch = document.getElementById('search_shooter');
        const deskSearch = document.getElementById('sywat_search_input');

        if (rosterSearch && rosterSearch.value) {
            applyWaitlistSearchFilter(rosterSearch.value);
            return;
        }

        if (deskSearch) {
            const initialDeskQuery = deskSearch.value || sessionStorage.getItem('sywat_checkin_search_query') || '';
            if (initialDeskQuery) {
                applyWaitlistSearchFilter(initialDeskQuery);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWaitlistModule);
    } else {
        initWaitlistModule();
    }

})();
