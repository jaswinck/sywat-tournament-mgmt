/**
 * SYWAT Archery - Modal Manager
 * Path: assets/js/sywat-modal-manager.js
 *
 * Makes the existing .sywat-modal-backdrop modals behave correctly under any
 * theme without changing how the modules open or close them (they still add or
 * remove the "active" / "sywat-modal-active" class):
 *
 *  1. Portal: top-level modals are moved to <body>. Page builders such as Divi
 *     put transforms, filters and stacking contexts on sections; a fixed-position
 *     modal inside one is positioned relative to that section and can end up
 *     under the site header. Directly under <body> it always covers the window.
 *  2. Accessibility: role="dialog", aria-modal, aria-labelledby, focus moved
 *     into the dialog, Tab kept inside it, focus restored on close.
 *  3. Escape closes the top-most modal by activating its own close control
 *     (the same button a user would click), unless the modal is marked
 *     data-sywat-static="true".
 *  4. Page scroll is locked while a modal is open (body.sywat-modal-open),
 *     which the print stylesheet also uses to print only the open modal.
 */
(function () {
    'use strict';

    if (window.SYWAT && window.SYWAT.modalManager) {
        return;
    }

    var OPEN_CLASSES = ['active', 'sywat-modal-active'];
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var STATIC_IDS = ['sywat_capacity_overflow_modal', 'sywat_progress_modal'];
    var lastOpener = null;
    var openStack = [];
    var labelSeq = 0;

    function isOpen(modal) {
        if (!modal || !modal.classList) return false;
        for (var i = 0; i < OPEN_CLASSES.length; i++) {
            if (modal.classList.contains(OPEN_CLASSES[i])) return true;
        }
        // Some modules toggle style.display directly.
        return modal.style && modal.style.display && modal.style.display !== 'none';
    }

    function isVisible(el) {
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }

    function enhance(modal) {
        if (modal.getAttribute('data-sywat-enhanced')) return;
        modal.setAttribute('data-sywat-enhanced', '1');

        // 1. Portal top-level modals to <body> (nested modals travel with their parent).
        var parentModal = modal.parentElement ? modal.parentElement.closest('.sywat-modal-backdrop') : null;
        if (!parentModal && modal.parentElement !== document.body) {
            if (modal.closest('form')) {
                // Moving it would detach its fields from the enclosing form.
                modal.setAttribute('data-sywat-portal', 'skipped-inside-form');
            } else {
                document.body.appendChild(modal);
                modal.setAttribute('data-sywat-portal', 'body');
            }
        }

        // 2. Dialog semantics on the content box.
        var box = modal.querySelector('.sywat-modal-content') || modal;
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        if (!box.hasAttribute('tabindex')) box.setAttribute('tabindex', '-1');
        var title = box.querySelector('.sywat-modal-header h1, .sywat-modal-header h2, .sywat-modal-header h3, .sywat-modal-header h4, h2, h3');
        if (title && !box.hasAttribute('aria-labelledby')) {
            if (!title.id) title.id = 'sywat-modal-title-' + (++labelSeq);
            box.setAttribute('aria-labelledby', title.id);
        }
        var closeBtn = modal.querySelector('.sywat-modal-close');
        if (closeBtn && !closeBtn.hasAttribute('aria-label')) closeBtn.setAttribute('aria-label', 'Close');
    }

    function topmost() {
        for (var i = openStack.length - 1; i >= 0; i--) {
            if (document.body.contains(openStack[i]) && isOpen(openStack[i])) return openStack[i];
        }
        return null;
    }

    function focusInto(modal) {
        if (modal.contains(document.activeElement)) return;
        var box = modal.querySelector('.sywat-modal-content') || modal;
        var body = box.querySelector('.sywat-modal-body') || box;
        var target = null;
        var candidates = body.querySelectorAll(FOCUSABLE);
        for (var i = 0; i < candidates.length; i++) {
            if (isVisible(candidates[i]) && !candidates[i].classList.contains('sywat-modal-close')) { target = candidates[i]; break; }
        }
        var el = target || box;
        try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
        // The open transition may still be running (visibility hidden), retry once.
        if (!modal.contains(document.activeElement)) {
            setTimeout(function () {
                if (isOpen(modal) && !modal.contains(document.activeElement)) {
                    try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
                }
            }, 250);
        }
    }

    function sync() {
        var modals = document.querySelectorAll('.sywat-modal-backdrop');
        for (var i = 0; i < modals.length; i++) enhance(modals[i]);

        // Maintain open order: keep existing order, append newly opened.
        openStack = openStack.filter(function (m) { return document.body.contains(m) && isOpen(m); });
        for (var j = 0; j < modals.length; j++) {
            if (isOpen(modals[j]) && openStack.indexOf(modals[j]) === -1) openStack.push(modals[j]);
        }

        var anyOpen = openStack.length > 0;
        document.body.classList.toggle('sywat-modal-open', anyOpen);
        document.documentElement.classList.toggle('sywat-modal-open', anyOpen);

        var top = topmost();
        if (top) {
            focusInto(top);
        } else if (lastOpener && document.body.contains(lastOpener)) {
            try { lastOpener.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
            lastOpener = null;
        }
    }

    var scheduled = false;
    function scheduleSync() {
        if (scheduled) return;
        scheduled = true;
        (window.requestAnimationFrame || setTimeout)(function () { scheduled = false; sync(); });
    }

    function closeModal(modal) {
        if (modal.getAttribute('data-sywat-static') === 'true' || STATIC_IDS.indexOf(modal.id) !== -1) return;
        var header = modal.querySelector('.sywat-modal-header') || modal;
        var ctrl = header.querySelector('.sywat-modal-close') || modal.querySelector('.sywat-modal-close');
        if (!ctrl) {
            // Fall back to a footer Cancel/Close control belonging to this modal.
            var buttons = modal.querySelectorAll('button, a.sywat-btn');
            for (var i = 0; i < buttons.length; i++) {
                var b = buttons[i];
                if (b.closest('.sywat-modal-backdrop') !== modal) continue;
                var txt = (b.textContent || '').replace(/[^A-Za-z]/g, '').toLowerCase();
                if (txt === 'cancel' || txt === 'close') { ctrl = b; break; }
            }
        }
        if (ctrl) ctrl.click();
    }

    document.addEventListener('keydown', function (e) {
        var top = topmost();
        if (!top) return;
        if (e.key === 'Escape' || e.key === 'Esc') {
            // Let an open autocomplete list or native picker handle Escape first.
            if (e.defaultPrevented) return;
            e.preventDefault();
            closeModal(top);
            return;
        }
        if (e.key === 'Tab') {
            var box = top.querySelector('.sywat-modal-content') || top;
            var items = Array.prototype.filter.call(box.querySelectorAll(FOCUSABLE), isVisible);
            if (!items.length) { e.preventDefault(); return; }
            var first = items[0], last = items[items.length - 1];
            if (e.shiftKey && (document.activeElement === first || !box.contains(document.activeElement))) {
                e.preventDefault(); last.focus();
            } else if (!e.shiftKey && (document.activeElement === last || !box.contains(document.activeElement))) {
                e.preventDefault(); first.focus();
            }
        }
    });

    // Remember what opened a modal so focus can return to it.
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('button, a') : null;
        if (t && !t.closest('.sywat-modal-backdrop')) lastOpener = t;
    }, true);

    function start() {
        sync();
        if (window.MutationObserver) {
            new MutationObserver(function (records) {
                for (var i = 0; i < records.length; i++) {
                    var r = records[i];
                    if (r.type === 'attributes') {
                        if (r.target.classList && r.target.classList.contains('sywat-modal-backdrop')) { scheduleSync(); return; }
                    } else if (r.addedNodes.length || r.removedNodes.length) {
                        scheduleSync(); return;
                    }
                }
            }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style'] });
        }
    }

    window.SYWAT = window.SYWAT || {};
    window.SYWAT.modalManager = { sync: sync, close: closeModal };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
