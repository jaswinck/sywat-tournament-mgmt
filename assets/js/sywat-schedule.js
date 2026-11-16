/**
 * Sub-Module: Public Season Schedule Client Engine
 * Path: assets/js/sywat-schedule.js
 * Description: Vanilla JS engine for live filtering and UI interactions on the public season schedule.
 * Compliance: Zero jQuery, singleton IIFE encapsulation, zero style.* DOM mutations (uses .sywat-hidden class toggling only).
 * Author: SYWAT Archery
 * Version: 1.0.0
 */

(function() {
    'use strict';

    // Global Namespace Initialization & Singleton Guard
    window.SYWAT = window.SYWAT || {};
    if ( window.SYWAT._scheduleInitialized ) {
        return;
    }
    window.SYWAT._scheduleInitialized = true;

    const scheduleEngine = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // 1. Season Selector Form Submission (Replaces inline onchange)
            const seasonSelect = document.getElementById('sywat_schedule_season_select');
            if ( seasonSelect ) {
                seasonSelect.addEventListener('change', function(e) {
                    const form = document.getElementById('sywat_schedule_season_form');
                    if ( form ) {
                        form.submit();
                    }
                });
            }

            // 2. Filter Inputs & Toggles
            const districtFilter = document.getElementById('sywat_schedule_filter_district');
            const shopFilter     = document.getElementById('sywat_schedule_filter_shop');
            const searchInput    = document.getElementById('sywat_schedule_search');
            const clearBtn       = document.getElementById('sywat_schedule_search_clear');

            const applyFilters = this.applyFilters.bind(this);

            if ( districtFilter ) districtFilter.addEventListener('change', applyFilters);
            if ( shopFilter )     shopFilter.addEventListener('change', applyFilters);
            if ( searchInput )    searchInput.addEventListener('input', applyFilters);

            // 3. Clear/Reset Button Handler
            if ( clearBtn ) {
                clearBtn.addEventListener('click', function() {
                    if ( districtFilter ) districtFilter.value = 'all';
                    if ( shopFilter )     shopFilter.value = 'all';
                    if ( searchInput )    searchInput.value = '';
                    applyFilters();
                });
            }
        },

        applyFilters: function() {
            const districtEl = document.getElementById('sywat_schedule_filter_district');
            const shopEl     = document.getElementById('sywat_schedule_filter_shop');
            const searchEl   = document.getElementById('sywat_schedule_search');
            const counterEl  = document.getElementById('sywat_schedule_counter');

            const distVal   = districtEl ? districtEl.value.toLowerCase().trim() : 'all';
            const shopVal   = shopEl ? shopEl.value.toLowerCase().trim() : 'all';
            const searchVal = searchEl ? searchEl.value.toLowerCase().trim() : '';

            const rows = document.querySelectorAll('.sywat-schedule-row');
            let totalCount   = 0;
            let visibleCount = 0;

            rows.forEach(function(row) {
                totalCount++;
                const rowDist   = (row.getAttribute('data-district') || '').toLowerCase().trim();
                const rowShop   = (row.getAttribute('data-shop') || '').toLowerCase().trim();
                const rowSearch = (row.getAttribute('data-name') || '').toLowerCase().trim();

                const matchDist   = (distVal === 'all' || rowDist === distVal);
                const matchShop   = (shopVal === 'all' || rowShop === shopVal);
                const matchSearch = (searchVal === '' || rowSearch.includes(searchVal));

                if ( matchDist && matchShop && matchSearch ) {
                    row.classList.remove('sywat-hidden');
                    visibleCount++;
                } else {
                    row.classList.add('sywat-hidden');
                }
            });

            // Update Dynamic Counter Banner
            if ( counterEl ) {
                const isFiltered = (distVal !== 'all' || shopVal !== 'all' || searchVal !== '');
                const iconTarget = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>';
                
                if ( ! isFiltered || visibleCount === totalCount ) {
                    counterEl.classList.remove('is-filtered');
                    counterEl.innerHTML = iconTarget + ' Total Scheduled Events: <strong>' + totalCount + '</strong>';
                } else {
                    counterEl.classList.add('is-filtered');
                    counterEl.innerHTML = iconTarget + ' Displaying <strong>' + visibleCount + '</strong> of <strong>' + totalCount + '</strong> Scheduled Events';
                }
            }
        }
    };

    // Attach to Global Object
    window.SYWAT.schedule = scheduleEngine;

    // Execute Safely
    if ( document.readyState === 'loading' ) {
        document.addEventListener('DOMContentLoaded', function() {
            window.SYWAT.schedule.init();
        });
    } else {
        window.SYWAT.schedule.init();
    }

})();
