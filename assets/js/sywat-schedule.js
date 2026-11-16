/**
 * Sub-Module: Public Season Schedule Client Engine
 * Path: assets/js/sywat-schedule.js
 * Description: Vanilla JS engine for live filtering, automatic initial positioning on the current weekend, jump-to-weekend navigation, and UI interactions on the public season schedule.
 * Compliance: Zero jQuery, singleton IIFE encapsulation, zero style.* DOM mutations (uses .sywat-hidden class toggling only), zero theme-specific selectors.
 * Author: SYWAT Archery
 * Version: 1.1.0
 */

(function() {
    'use strict';

    // Global Namespace Initialization & Singleton Guard
    window.SYWAT = window.SYWAT || {};
    if ( window.SYWAT._scheduleInitialized ) {
        return;
    }
    window.SYWAT._scheduleInitialized = true;

    const STATUS_PRIORITY = [ 'active', 'this_weekend', 'upcoming' ];

    const scheduleEngine = {
        init: function() {
            this.bindEvents();
            this.updateJumpButtonVisibility();

            const self = this;
            window.requestAnimationFrame(function() {
                self.scrollToCurrentWeekend( true );
            });
        },

        bindEvents: function() {
            // 1. Season Selector Form Submission
            const seasonSelect = document.getElementById('sywat_schedule_season_select');
            if ( seasonSelect ) {
                seasonSelect.addEventListener('change', function() {
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
            const jumpBtn        = document.getElementById('sywat_schedule_jump_weekend');

            const applyFilters = this.applyFilters.bind(this);
            const self         = this;

            if ( districtFilter ) districtFilter.addEventListener('change', applyFilters);
            if ( shopFilter )     shopFilter.addEventListener('change', applyFilters);
            if ( searchInput )    searchInput.addEventListener('input', applyFilters);

            // 3. Jump to Current Weekend Button Handler
            if ( jumpBtn ) {
                jumpBtn.addEventListener('click', function() {
                    self.scrollToCurrentWeekend( false );
                });
            }

            // 4. Clear/Reset Button Handler
            if ( clearBtn ) {
                clearBtn.addEventListener('click', function() {
                    if ( districtFilter ) districtFilter.value = 'all';
                    if ( shopFilter )     shopFilter.value = 'all';
                    if ( searchInput )    searchInput.value = '';
                    applyFilters();
                });
            }
        },

        /**
         * Returns the first visible schedule row matching priority:
         * active ("In Progress") -> this_weekend ("This Weekend") -> upcoming ("Upcoming").
         */
        findCurrentWeekendRow: function() {
            const rows = Array.prototype.slice.call(
                document.querySelectorAll('.sywat-schedule-row:not(.sywat-hidden)')
            );
            if ( ! rows.length ) {
                return null;
            }

            for ( let p = 0; p < STATUS_PRIORITY.length; p++ ) {
                const targetStatus = STATUS_PRIORITY[ p ];
                for ( let i = 0; i < rows.length; i++ ) {
                    const rowStatus = (rows[ i ].getAttribute('data-status') || '').toLowerCase().trim();
                    if ( rowStatus === targetStatus ) {
                        return rows[ i ];
                    }
                }
            }

            return null;
        },

        /**
         * Checks whether any visible completed rows appear ahead of the target row.
         */
        hasCompletedRowsBefore: function( targetRow ) {
            if ( ! targetRow ) {
                return false;
            }

            const rows = document.querySelectorAll('.sywat-schedule-row:not(.sywat-hidden)');
            for ( let i = 0; i < rows.length; i++ ) {
                if ( rows[ i ] === targetRow ) {
                    return false;
                }
                const rowStatus = (rows[ i ].getAttribute('data-status') || '').toLowerCase().trim();
                if ( rowStatus === 'completed' ) {
                    return true;
                }
            }

            return false;
        },

        /**
         * Shows or hides the "This Weekend" jump button based on whether a visible target row exists.
         */
        updateJumpButtonVisibility: function() {
            const jumpBtn = document.getElementById('sywat_schedule_jump_weekend');
            if ( ! jumpBtn ) {
                return;
            }

            const hasTarget = Boolean( this.findCurrentWeekendRow() );
            jumpBtn.classList.toggle('sywat-hidden', ! hasTarget);
        },

        /**
         * Scrolls the schedule container or window to the current weekend row.
         * On initial load, scrolls only when completed rows precede the target row and no URL hash is set.
         */
        scrollToCurrentWeekend: function( isInitialLoad ) {
            if ( isInitialLoad && window.location.hash ) {
                return;
            }

            const targetRow = this.findCurrentWeekendRow();
            if ( ! targetRow ) {
                return;
            }

            if ( isInitialLoad && ! this.hasCompletedRowsBefore( targetRow ) ) {
                return;
            }

            const behavior     = isInitialLoad ? 'auto' : 'smooth';
            const tableWrapper = targetRow.closest('.sywat-table-responsive');

            // If the table wrapper itself scrolls vertically, position inside the wrapper
            if ( tableWrapper ) {
                const wrapperStyle = window.getComputedStyle( tableWrapper );
                const canScrollY   = ( wrapperStyle.overflowY === 'auto' || wrapperStyle.overflowY === 'scroll' )
                    && ( tableWrapper.scrollHeight > tableWrapper.clientHeight + 4 );

                if ( canScrollY ) {
                    const thead        = tableWrapper.querySelector('thead');
                    const headerHeight = thead ? thead.offsetHeight : 0;
                    const wrapperRect  = tableWrapper.getBoundingClientRect();
                    const rowRect      = targetRow.getBoundingClientRect();
                    const nextTop      = tableWrapper.scrollTop + ( rowRect.top - wrapperRect.top ) - headerHeight;

                    tableWrapper.scrollTo({
                        top: Math.max( 0, nextTop ),
                        behavior: behavior
                    });

                    if ( ! isInitialLoad ) {
                        targetRow.setAttribute('tabindex', '-1');
                        targetRow.focus({ preventScroll: true });
                    }
                    return;
                }
            }

            // Standard document scroll with theme-neutral top clearance for sticky headers / WP admin bar
            const adminBarOffset = document.body ? ( document.body.offsetTop || 0 ) : 0;
            const topClearance   = adminBarOffset + 110;
            const targetY        = targetRow.getBoundingClientRect().top + window.pageYOffset - topClearance;

            window.scrollTo({
                top: Math.max( 0, targetY ),
                behavior: behavior
            });

            if ( ! isInitialLoad ) {
                targetRow.setAttribute('tabindex', '-1');
                targetRow.focus({ preventScroll: true });
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

            this.updateJumpButtonVisibility();
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
