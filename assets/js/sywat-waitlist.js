/**
 * SYWAT Archery - Dedicated Waitlist Modal Client Controller
 * Path: assets/js/sywat-waitlist.js
 * Description: Namespaced vanilla JS module managing #sywat_waitlist_modal lifecycle, line filter navigation, live search filtering, family/member checkbox synchronization, top-bar Move Selected validation, single-prompt confirmations, and double-submit protection.
 * Compliance: Zero jQuery, zero inline style.* mutations, delegated event listeners, guarded DOM initialization.
 * Author: SYWAT Archery
 * Version: 2.0.0
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    /**
     * Close the dedicated Waitlist Management Modal and strip transient modal query params.
     *
     * @param {string} explicitCloseUrl Optional explicit destination URL.
     * @return {void}
     */
    function closeWaitlistModal(explicitCloseUrl) {
        const modal = document.getElementById('sywat_waitlist_modal');
        const targetUrl = explicitCloseUrl || (modal ? modal.getAttribute('data-sywat-close-url') : '');

        if (targetUrl) {
            window.location.href = targetUrl;
            return;
        }

        try {
            const urlObj = new URL(window.location.href);
            urlObj.searchParams.delete('waitlist_modal');
            urlObj.searchParams.delete('waitlist_tournament');
            urlObj.searchParams.delete('waitlist_line');
            window.location.href = urlObj.toString();
        } catch (err) {
            if (modal) {
                modal.classList.remove('active');
            }
        }
    }

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
     * Synchronize family group parent checkboxes, update the live selected count pill,
     * and enable/disable the top-bar "Move Selected to Line" submit button.
     *
     * @return {void}
     */
    function updateSelectionState() {
        const memberCheckboxes = document.querySelectorAll('[data-waitlist-member-checkbox]');
        const groupCheckboxes = document.querySelectorAll('[data-waitlist-group-checkbox]');
        const countEl = document.getElementById('sywat_waitlist_selected_count');
        const lineSelect = document.getElementById('sywat_waitlist_target_line');
        const moveBtn = document.getElementById('sywat_waitlist_move_btn');

        let totalChecked = 0;
        const groupStats = {};

        memberCheckboxes.forEach(function(cb) {
            const grpId = cb.getAttribute('data-waitlist-member-checkbox') || '';
            if (grpId) {
                if (!groupStats[grpId]) {
                    groupStats[grpId] = { total: 0, checked: 0 };
                }
                groupStats[grpId].total++;
                if (cb.checked) {
                    groupStats[grpId].checked++;
                }
            }
            if (cb.checked) {
                totalChecked++;
            }
        });

        groupCheckboxes.forEach(function(gcb) {
            const grpId = gcb.getAttribute('data-waitlist-group-checkbox') || '';
            const stat = groupStats[grpId];
            if (!stat || stat.total === 0) {
                gcb.checked = false;
                gcb.indeterminate = false;
            } else if (stat.checked === stat.total) {
                gcb.checked = true;
                gcb.indeterminate = false;
            } else if (stat.checked > 0) {
                gcb.checked = false;
                gcb.indeterminate = true;
            } else {
                gcb.checked = false;
                gcb.indeterminate = false;
            }
        });

        if (countEl) {
            countEl.textContent = String(totalChecked);
        }

        if (moveBtn) {
            const hasDestination = Boolean(lineSelect && lineSelect.value);
            moveBtn.disabled = !(totalChecked > 0 && hasDestination);
        }
    }

    /**
     * Toggle all member checkboxes belonging to a family group header checkbox.
     *
     * @param {HTMLInputElement} groupCheckbox Group header checkbox element.
     * @return {void}
     */
    function handleGroupCheckboxToggle(groupCheckbox) {
        const grpId = groupCheckbox.getAttribute('data-waitlist-group-checkbox');
        if (!grpId) return;

        const isChecked = groupCheckbox.checked;
        const members = document.querySelectorAll('[data-waitlist-member-checkbox="' + grpId + '"]');
        members.forEach(function(cb) {
            cb.checked = isChecked;
        });

        updateSelectionState();
    }

    /**
     * Select all currently visible waitlisted archers or clear all selections.
     *
     * @param {boolean} shouldSelect True to select all visible rows; false to clear all.
     * @return {void}
     */
    function setAllVisibleSelection(shouldSelect) {
        const memberCheckboxes = document.querySelectorAll('[data-waitlist-member-checkbox]');
        memberCheckboxes.forEach(function(cb) {
            if (!shouldSelect) {
                cb.checked = false;
                return;
            }
            const parentRow = cb.closest('tr');
            if (!parentRow || !parentRow.classList.contains('sywat-hidden')) {
                cb.checked = true;
            }
        });

        updateSelectionState();
    }

    /**
     * Validate waitlist form submissions, confirm promote/move/remove actions without
     * duplicate prompts when sywat-roster.js is active, and prevent double-submits.
     *
     * @param {SubmitEvent} event Delegated submit event.
     * @return {void}
     */
    function handleWaitlistFormSubmit(event) {
        const modal = document.getElementById('sywat_waitlist_modal');
        const form = event.target.closest('#sywat_waitlist_modal form');
        if (!modal || !form || event.defaultPrevented) {
            return;
        }

        // Prevent duplicate submissions while POST request is in flight
        if (form.dataset.sywatSubmitting === '1') {
            event.preventDefault();
            return;
        }

        // Top-of-modal "Move Selected to Open Line" form validation & confirmation
        if (form.id === 'sywat_waitlist_move_selected_form') {
            const checkedMembers = Array.prototype.slice.call(
                document.querySelectorAll('[data-waitlist-member-checkbox]:checked')
            );
            const lineSelect = document.getElementById('sywat_waitlist_target_line');

            if (checkedMembers.length === 0) {
                event.preventDefault();
                window.alert('Please select at least one waitlisted archer or family group to move.');
                return;
            }

            if (!lineSelect || !lineSelect.value) {
                event.preventDefault();
                window.alert('Please choose a destination open shooting line first.');
                return;
            }

            const selectedOpt = lineSelect.options[lineSelect.selectedIndex];
            const destLabel = selectedOpt ? selectedOpt.text.trim() : 'the selected shooting line';
            const open20 = selectedOpt ? parseInt(selectedOpt.getAttribute('data-open-20yd') || '0', 10) : 0;
            const openCub = selectedOpt ? parseInt(selectedOpt.getAttribute('data-open-cub') || '0', 10) : 0;

            let req20 = 0;
            let reqCub = 0;
            checkedMembers.forEach(function(cb) {
                if ((cb.getAttribute('data-distance') || '20yd') === 'Cub') {
                    reqCub++;
                } else {
                    req20++;
                }
            });

            let confirmMsg = 'Move ' + checkedMembers.length + ' selected waitlisted archer(s) to ' + destLabel + ' as Pre-Reg?';
            if (req20 > open20 || reqCub > openCub) {
                confirmMsg = 'WARNING: Selected archers need ' + req20 + ' 20yd / ' + reqCub + ' Cub spot(s), which exceeds open capacity on ' + destLabel + '. Proceed anyway?';
            }

            if (!window.confirm(confirmMsg)) {
                event.preventDefault();
                return;
            }
        } else if (form.hasAttribute('data-sywat-confirm')) {
            // Avoid double confirmation prompt if sywat-roster.js is also loaded on the page
            const rosterHandlesConfirm = Boolean(window.SYWAT && window.SYWAT.roster);
            if (!rosterHandlesConfirm && !event._sywatConfirmHandled) {
                event._sywatConfirmHandled = true;
                const confirmText = form.getAttribute('data-sywat-confirm');
                if (confirmText && !window.confirm(confirmText)) {
                    event.preventDefault();
                    return;
                }
            }
        }

        // Lock form against accidental double-clicks once validated
        form.dataset.sywatSubmitting = '1';
        const activeSubmitBtn = form.querySelector('button[type="submit"]');
        if (activeSubmitBtn) {
            activeSubmitBtn.disabled = true;
        }
    }

    // Namespaced Module API
    window.SYWAT.waitlist = {
        closeModal: closeWaitlistModal,
        applySearchFilter: applyWaitlistSearchFilter,
        updateSelectionState: updateSelectionState
    };

    // Delegated Input Listener: Live search inside Waitlist Modal
    document.addEventListener('input', function(event) {
        const target = event.target;
        if (!target) return;

        if (target.id === 'sywat_waitlist_search_input') {
            applyWaitlistSearchFilter(target.value);
        }
    });

    // Delegated Change Listener: Line filter navigation, group/member checkboxes & destination line select
    document.addEventListener('change', function(event) {
        const target = event.target;
        if (!target) return;

        if (target.matches('[data-sywat-action="waitlist-line-filter"]')) {
            if (target.value) {
                window.location.href = target.value;
            }
            return;
        }

        if (target.hasAttribute('data-waitlist-group-checkbox')) {
            handleGroupCheckboxToggle(target);
            return;
        }

        if (target.hasAttribute('data-waitlist-member-checkbox')) {
            updateSelectionState();
            return;
        }

        if (target.id === 'sywat_waitlist_target_line') {
            updateSelectionState();
        }
    });

    // Delegated Click Listener: Close modal, backdrop click, clear search, select all, clear selection
    document.addEventListener('click', function(event) {
        const closeTrigger = event.target.closest('[data-sywat-action="close-waitlist-modal"]');
        if (closeTrigger) {
            event.preventDefault();
            closeWaitlistModal(closeTrigger.getAttribute('data-close-url') || '');
            return;
        }

        if (event.target && event.target.id === 'sywat_waitlist_modal') {
            closeWaitlistModal(event.target.getAttribute('data-sywat-close-url') || '');
            return;
        }

        const clearSearchBtn = event.target.closest('[data-sywat-action="clear-waitlist-search"]');
        if (clearSearchBtn) {
            const searchInput = document.getElementById('sywat_waitlist_search_input');
            if (searchInput) {
                searchInput.value = '';
                applyWaitlistSearchFilter('');
                searchInput.focus();
            }
            return;
        }

        if (event.target.closest('[data-sywat-action="waitlist-select-all"]')) {
            setAllVisibleSelection(true);
            return;
        }

        if (event.target.closest('[data-sywat-action="waitlist-clear-selection"]')) {
            setAllVisibleSelection(false);
        }
    });

    // Delegated Keydown Listener: Escape closes active Waitlist Modal; Enter inside modal search does not submit
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const modal = document.getElementById('sywat_waitlist_modal');
            if (modal && modal.classList.contains('active')) {
                event.preventDefault();
                closeWaitlistModal(modal.getAttribute('data-sywat-close-url') || '');
            }
            return;
        }

        if (event.key === 'Enter' && event.target && event.target.id === 'sywat_waitlist_search_input') {
            event.preventDefault();
        }
    });

    // Delegated Submit Listener: Confirmations & double-submit protection
    document.addEventListener('submit', handleWaitlistFormSubmit);

    /**
     * Initialize waitlist modal state on DOM ready.
     *
     * @return {void}
     */
    function initWaitlistModule() {
        updateSelectionState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWaitlistModule);
    } else {
        initWaitlistModule();
    }

})();
