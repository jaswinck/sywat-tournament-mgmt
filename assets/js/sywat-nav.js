/**
 * SYWAT Archery - Responsive Navigation & Workflow Tab Controller
 * Path: assets/js/sywat-nav.js
 * Description: Manages the mobile authentication header drawer toggle, tap-to-expand accordion dropdowns, outside-click dismissal, and single-bar mobile dropdown behavior for .sywat-nav-tabs workflow bars.
 * Compliance: Vanilla JS only (zero jQuery), zero element.style.* mutations, class-based state (.is-open, .has-mobile-dropdown), delegated event listeners, namespaced under window.SYWAT.nav.
 * Version: 1.1.0
 */

(function() {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    const MOBILE_BREAKPOINT = 768;

    /**
     * Close all open dropdown menus within a given root element (or document).
     *
     * @param {ParentNode} [root=document]
     * @param {Element|null} [exceptDropdown=null]
     * @return {void}
     */
    function closeAuthDropdowns(root, exceptDropdown) {
        const scope = root || document;
        const openDropdowns = scope.querySelectorAll('.sywat-auth-dropdown.is-open');

        openDropdowns.forEach(function(dropdown) {
            if (exceptDropdown && dropdown === exceptDropdown) {
                return;
            }
            dropdown.classList.remove('is-open');
            const toggle = dropdown.querySelector('.sywat-auth-dropdown-toggle');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /**
     * Close all open mobile navigation drawers within a given root element (or document).
     *
     * @param {ParentNode} [root=document]
     * @return {void}
     */
    function closeAuthDrawers(root) {
        const scope = root || document;
        const openDrawers = scope.querySelectorAll('.sywat-auth-drawer.is-open');

        openDrawers.forEach(function(drawer) {
            drawer.classList.remove('is-open');
            closeAuthDropdowns(drawer, null);

            const authBar = drawer.closest('.sywat-auth-bar');
            if (authBar) {
                const menuToggle = authBar.querySelector('.sywat-auth-menu-toggle');
                if (menuToggle) {
                    menuToggle.classList.remove('is-open');
                    menuToggle.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }

    /**
     * Close all open workflow tab dropdowns (.sywat-nav-tabs.is-open).
     *
     * @param {ParentNode} [root=document]
     * @param {Element|null} [exceptTrack=null]
     * @return {void}
     */
    function closeWorkflowTabMenus(root, exceptTrack) {
        const scope = root || document;
        const openTracks = scope.querySelectorAll('.sywat-nav-tabs.is-open');

        openTracks.forEach(function(track) {
            if (exceptTrack && track === exceptTrack) {
                return;
            }
            track.classList.remove('is-open');
            const toggle = track.querySelector('.sywat-nav-tabs-toggle');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /**
     * Toggle the mobile header navigation drawer for the clicked header bar.
     *
     * @param {Element} toggleBtn
     * @return {void}
     */
    function toggleAuthDrawer(toggleBtn) {
        if (!toggleBtn) {
            return;
        }
        const authBar = toggleBtn.closest('.sywat-auth-bar');
        if (!authBar) {
            return;
        }
        const drawer = authBar.querySelector('.sywat-auth-drawer');
        if (!drawer) {
            return;
        }

        const willOpen = !drawer.classList.contains('is-open');
        if (willOpen) {
            closeAuthDrawers(document);
            closeWorkflowTabMenus(document, null);
            drawer.classList.add('is-open');
            toggleBtn.classList.add('is-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
        } else {
            drawer.classList.remove('is-open');
            toggleBtn.classList.remove('is-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
            closeAuthDropdowns(drawer, null);
        }
    }

    /**
     * Toggle an individual navigation dropdown accordion.
     *
     * @param {Element} toggleBtn
     * @return {void}
     */
    function toggleAuthDropdown(toggleBtn) {
        if (!toggleBtn) {
            return;
        }
        const dropdown = toggleBtn.closest('.sywat-auth-dropdown');
        if (!dropdown) {
            return;
        }
        const authBar = dropdown.closest('.sywat-auth-bar') || document;
        const willOpen = !dropdown.classList.contains('is-open');

        closeAuthDropdowns(authBar, willOpen ? dropdown : null);

        if (willOpen) {
            dropdown.classList.add('is-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
        } else {
            dropdown.classList.remove('is-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    }

    /**
     * Toggle the single-bar mobile workflow tab dropdown (.sywat-nav-tabs).
     *
     * @param {Element} toggleBtn
     * @return {void}
     */
    function toggleWorkflowTabMenu(toggleBtn) {
        if (!toggleBtn) {
            return;
        }
        const track = toggleBtn.closest('.sywat-nav-tabs');
        if (!track) {
            return;
        }

        const willOpen = !track.classList.contains('is-open');
        closeWorkflowTabMenus(document, willOpen ? track : null);

        if (willOpen) {
            closeAuthDrawers(document);
            track.classList.add('is-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
        } else {
            track.classList.remove('is-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    }

    /**
     * Enhance .sywat-nav-tabs bars with an accessible single-row mobile dropdown toggle
     * displaying the active workflow tab label and icon. Idempotent.
     *
     * @return {void}
     */
    function initWorkflowTabDropdowns() {
        const tabTracks = document.querySelectorAll('.sywat-nav-tabs');

        tabTracks.forEach(function(track, index) {
            const items = track.querySelectorAll('.sywat-tab-item');
            if (items.length < 2) {
                return;
            }

            if (!track.id) {
                track.id = 'sywat_nav_tabs_' + (index + 1);
            }

            track.classList.add('has-mobile-dropdown');

            if (track.querySelector('.sywat-nav-tabs-toggle')) {
                return;
            }

            const activeTab = track.querySelector('.sywat-tab-item.active') || items[0];
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'sywat-nav-tabs-toggle';
            toggleBtn.setAttribute('data-sywat-action', 'toggle-nav-tabs');
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.setAttribute('aria-controls', track.id);
            toggleBtn.setAttribute('aria-label', 'Switch section: ' + activeTab.textContent.trim());

            const labelSpan = document.createElement('span');
            labelSpan.className = 'sywat-nav-tabs-toggle-label';
            activeTab.classList.forEach(function(cls) {
                if (cls.indexOf('sywat-tab-icon-') === 0) {
                    labelSpan.classList.add(cls);
                }
            });
            labelSpan.textContent = activeTab.textContent.trim();

            const caretSpan = document.createElement('span');
            caretSpan.className = 'sywat-nav-tabs-caret';
            caretSpan.setAttribute('aria-hidden', 'true');
            caretSpan.textContent = '▼';

            toggleBtn.appendChild(labelSpan);
            toggleBtn.appendChild(caretSpan);
            track.insertBefore(toggleBtn, track.firstChild);
        });
    }

    /**
     * Delegated click handler for header drawer, dropdown & workflow tab controls.
     */
    document.addEventListener('click', function(event) {
        const target = event.target;
        if (!target || typeof target.closest !== 'function') {
            return;
        }

        const drawerToggle = target.closest('[data-sywat-action="toggle-auth-drawer"]');
        if (drawerToggle) {
            event.preventDefault();
            toggleAuthDrawer(drawerToggle);
            return;
        }

        const dropdownToggle = target.closest('[data-sywat-action="toggle-auth-dropdown"]');
        if (dropdownToggle) {
            event.preventDefault();
            toggleAuthDropdown(dropdownToggle);
            return;
        }

        const navTabsToggle = target.closest('[data-sywat-action="toggle-nav-tabs"]');
        if (navTabsToggle) {
            event.preventDefault();
            toggleWorkflowTabMenu(navTabsToggle);
            return;
        }

        if (!target.closest('.sywat-nav-tabs')) {
            closeWorkflowTabMenus(document, null);
        }

        if (!target.closest('.sywat-auth-bar')) {
            closeAuthDrawers(document);
            closeAuthDropdowns(document, null);
            return;
        }

        if (!target.closest('.sywat-auth-dropdown')) {
            const currentBar = target.closest('.sywat-auth-bar');
            closeAuthDropdowns(currentBar, null);
        }
    });

    /**
     * Reset mobile drawer/accordion/tab-dropdown open states when resizing back to desktop width.
     */
    let resizeRafPending = false;
    window.addEventListener('resize', function() {
        if (resizeRafPending) {
            return;
        }
        resizeRafPending = true;
        window.requestAnimationFrame(function() {
            resizeRafPending = false;
            if (window.innerWidth > MOBILE_BREAKPOINT) {
                closeAuthDrawers(document);
                closeAuthDropdowns(document, null);
                closeWorkflowTabMenus(document, null);
            }
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWorkflowTabDropdowns);
    } else {
        initWorkflowTabDropdowns();
    }

    window.SYWAT.nav = {
        closeAuthDrawers: closeAuthDrawers,
        closeAuthDropdowns: closeAuthDropdowns,
        closeWorkflowTabMenus: closeWorkflowTabMenus,
        initWorkflowTabDropdowns: initWorkflowTabDropdowns
    };
})();
