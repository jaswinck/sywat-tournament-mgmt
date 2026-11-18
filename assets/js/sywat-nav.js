/**
 * SYWAT Archery - Responsive Navigation & Workflow Tab Controller
 * Path: assets/js/sywat-nav.js
 * Description: Manages the mobile authentication header drawer toggle, tap-to-expand accordion dropdowns, outside-click dismissal, and horizontal auto-centering for the active workflow tab inside .sywat-nav-tabs.
 * Compliance: Vanilla JS only (zero jQuery), zero element.style.* mutations, class-based state (.is-open), delegated event listeners, namespaced under window.SYWAT.nav.
 * Version: 1.0.0
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
     * Center the active workflow tab horizontally inside scrollable .sywat-nav-tabs strips.
     * Uses horizontal scrollLeft math so the outer page never shifts vertically.
     *
     * @return {void}
     */
    function centerActiveWorkflowTabs() {
        const tabTracks = document.querySelectorAll('.sywat-nav-tabs');
        tabTracks.forEach(function(track) {
            const activeTab = track.querySelector('.sywat-tab-item.active');
            if (!activeTab) {
                return;
            }
            if (track.scrollWidth <= track.clientWidth) {
                return;
            }
            const targetScroll = activeTab.offsetLeft - (track.clientWidth / 2) + (activeTab.offsetWidth / 2);
            track.scrollLeft = Math.max(0, targetScroll);
        });
    }

    /**
     * Delegated click handler for header drawer & dropdown controls.
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
     * Reset mobile drawer/accordion open states when resizing back to desktop width.
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
            } else {
                centerActiveWorkflowTabs();
            }
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', centerActiveWorkflowTabs);
    } else {
        centerActiveWorkflowTabs();
    }

    window.SYWAT.nav = {
        closeAuthDrawers: closeAuthDrawers,
        closeAuthDropdowns: closeAuthDropdowns,
        centerActiveWorkflowTabs: centerActiveWorkflowTabs
    };
})();
