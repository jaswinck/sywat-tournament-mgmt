/**
 * Sub-Module: Public Season Schedule Client Engine (List View & Monthly Calendar View)
 * Path: assets/js/sywat-schedule.js
 * Description: Vanilla JS engine for live filtering, List/Calendar view switching, instant month navigation, event details modal, automatic initial positioning on the current weekend, and jump-to-weekend navigation.
 * Compliance: Zero jQuery, singleton IIFE encapsulation, zero style.* DOM mutations (uses .sywat-hidden / .active / .is-active class toggling only), zero theme-specific selectors.
 * Author: SYWAT Archery
 * Version: 1.2.0
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
    const MAX_CHIPS_PER_DAY = 3;

    const scheduleEngine = {
        viewMode: 'list',
        activeYm: '',
        defaultYm: '',
        eventsMap: {},
        lastTriggerEl: null,

        init: function() {
            this.loadCalendarMetadata();
            this.mountEventModal();
            this.bindEvents();
            this.syncCalendarHeader();
            this.updateJumpButtonVisibility();

            const self = this;
            window.requestAnimationFrame(function() {
                self.scrollToCurrentWeekend( true );
            });
        },

        loadCalendarMetadata: function() {
            const calPanel = document.getElementById('sywat_schedule_calendar_panel');
            if ( ! calPanel ) {
                return;
            }

            this.defaultYm = (calPanel.getAttribute('data-default-ym') || '').trim();
            this.activeYm  = this.defaultYm;

            if ( ! this.activeYm ) {
                const firstMonth = calPanel.querySelector('.sywat-cal-month');
                if ( firstMonth ) {
                    this.activeYm = (firstMonth.getAttribute('data-ym') || '').trim();
                }
            }

            const rawJson = calPanel.getAttribute('data-events') || '{}';
            try {
                const parsed = JSON.parse( rawJson );
                if ( parsed && typeof parsed === 'object' ) {
                    this.eventsMap = parsed;
                }
            } catch ( e ) {
                this.eventsMap = {};
            }
        },

        /**
         * Moves #sywat_schedule_event_modal to document.body so no theme transform/overflow clips it.
         */
        mountEventModal: function() {
            const modalEl = document.getElementById('sywat_schedule_event_modal');
            if ( modalEl && document.body && modalEl.parentNode !== document.body ) {
                document.body.appendChild( modalEl );
            }
        },

        bindEvents: function() {
            const self = this;

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

            // 2. View Mode Switcher (List View vs. Calendar View)
            const listViewBtn = document.getElementById('sywat_schedule_view_list');
            const calViewBtn  = document.getElementById('sywat_schedule_view_calendar');

            if ( listViewBtn ) {
                listViewBtn.addEventListener('click', function() {
                    self.setViewMode('list');
                });
            }
            if ( calViewBtn ) {
                calViewBtn.addEventListener('click', function() {
                    self.setViewMode('calendar');
                });
            }

            // 3. Filter Inputs & Toggles
            const districtFilter = document.getElementById('sywat_schedule_filter_district');
            const shopFilter     = document.getElementById('sywat_schedule_filter_shop');
            const searchInput    = document.getElementById('sywat_schedule_search');
            const clearBtn       = document.getElementById('sywat_schedule_search_clear');
            const jumpBtn        = document.getElementById('sywat_schedule_jump_weekend');

            const applyFilters = this.applyFilters.bind(this);

            if ( districtFilter ) districtFilter.addEventListener('change', applyFilters);
            if ( shopFilter )     shopFilter.addEventListener('change', applyFilters);
            if ( searchInput )    searchInput.addEventListener('input', applyFilters);

            // 4. Jump to Current Weekend Button Handler (List View)
            if ( jumpBtn ) {
                jumpBtn.addEventListener('click', function() {
                    self.scrollToCurrentWeekend( false );
                });
            }

            // 5. Clear/Reset Button Handler
            if ( clearBtn ) {
                clearBtn.addEventListener('click', function() {
                    if ( districtFilter ) districtFilter.value = 'all';
                    if ( shopFilter )     shopFilter.value = 'all';
                    if ( searchInput )    searchInput.value = '';
                    applyFilters();
                });
            }

            // 6. Calendar Month Navigation Controls
            const prevBtn  = document.getElementById('sywat_cal_prev_btn');
            const nextBtn  = document.getElementById('sywat_cal_next_btn');
            const todayBtn = document.getElementById('sywat_cal_today_btn');

            if ( prevBtn ) {
                prevBtn.addEventListener('click', function() {
                    self.stepCalendarMonth( -1 );
                });
            }
            if ( nextBtn ) {
                nextBtn.addEventListener('click', function() {
                    self.stepCalendarMonth( 1 );
                });
            }
            if ( todayBtn ) {
                todayBtn.addEventListener('click', function() {
                    if ( self.defaultYm ) {
                        self.setCalendarMonth( self.defaultYm );
                    }
                });
            }

            // 7. Calendar Day Cell Click Delegation (Event Chips & "+N more" Overflow Buttons)
            const calPanel = document.getElementById('sywat_schedule_calendar_panel');
            if ( calPanel ) {
                calPanel.addEventListener('click', function( e ) {
                    const chipBtn = e.target.closest('.sywat-cal-event-chip');
                    if ( chipBtn ) {
                        const evtId = chipBtn.getAttribute('data-event-id');
                        self.openSingleEventModal( evtId, chipBtn );
                        return;
                    }

                    const moreBtn = e.target.closest('.sywat-cal-more-btn');
                    if ( moreBtn ) {
                        const dateStr = moreBtn.getAttribute('data-date') || '';
                        self.openDayEventsModal( dateStr, moreBtn );
                    }
                });
            }

            // 8. Event Details Modal Close Controls
            const modalEl       = document.getElementById('sywat_schedule_event_modal');
            const modalCloseBtn = document.getElementById('sywat_schedule_event_modal_close');
            const modalDoneBtn  = document.getElementById('sywat_schedule_event_modal_done');

            if ( modalCloseBtn ) {
                modalCloseBtn.addEventListener('click', function() {
                    self.closeEventModal();
                });
            }
            if ( modalDoneBtn ) {
                modalDoneBtn.addEventListener('click', function() {
                    self.closeEventModal();
                });
            }
            if ( modalEl ) {
                modalEl.addEventListener('click', function( e ) {
                    if ( e.target === modalEl ) {
                        self.closeEventModal();
                    }
                });
            }

            document.addEventListener('keydown', function( e ) {
                if ( e.key === 'Escape' ) {
                    const activeModal = document.getElementById('sywat_schedule_event_modal');
                    if ( activeModal && activeModal.classList.contains('active') ) {
                        self.closeEventModal();
                    }
                }
            });
        },

        setViewMode: function( mode ) {
            const nextMode    = (mode === 'calendar') ? 'calendar' : 'list';
            const listPanel   = document.getElementById('sywat_schedule_list_panel');
            const calPanel    = document.getElementById('sywat_schedule_calendar_panel');
            const listViewBtn = document.getElementById('sywat_schedule_view_list');
            const calViewBtn  = document.getElementById('sywat_schedule_view_calendar');

            this.viewMode = nextMode;

            if ( listPanel ) {
                listPanel.classList.toggle('sywat-hidden', nextMode !== 'list');
            }
            if ( calPanel ) {
                calPanel.classList.toggle('sywat-hidden', nextMode !== 'calendar');
            }

            if ( listViewBtn ) {
                const isList = (nextMode === 'list');
                listViewBtn.classList.toggle('is-active', isList);
                listViewBtn.setAttribute('aria-pressed', isList ? 'true' : 'false');
            }
            if ( calViewBtn ) {
                const isCal = (nextMode === 'calendar');
                calViewBtn.classList.toggle('is-active', isCal);
                calViewBtn.setAttribute('aria-pressed', isCal ? 'true' : 'false');
            }

            if ( nextMode === 'calendar' ) {
                this.syncCalendarHeader();
            }

            this.updateJumpButtonVisibility();
        },

        getCalendarMonthPanels: function() {
            const calPanel = document.getElementById('sywat_schedule_calendar_panel');
            if ( ! calPanel ) {
                return [];
            }
            return Array.prototype.slice.call( calPanel.querySelectorAll('.sywat-cal-month') );
        },

        setCalendarMonth: function( targetYm ) {
            const panels = this.getCalendarMonthPanels();
            if ( ! panels.length ) {
                return;
            }

            let found = false;
            panels.forEach(function( panel ) {
                const ym    = (panel.getAttribute('data-ym') || '').trim();
                const match = (ym === targetYm);
                panel.classList.toggle('sywat-hidden', ! match);
                if ( match ) {
                    found = true;
                }
            });

            if ( found ) {
                this.activeYm = targetYm;
            } else {
                panels[ 0 ].classList.remove('sywat-hidden');
                this.activeYm = (panels[ 0 ].getAttribute('data-ym') || '').trim();
            }

            this.syncCalendarHeader();
        },

        stepCalendarMonth: function( delta ) {
            const panels = this.getCalendarMonthPanels();
            if ( ! panels.length ) {
                return;
            }

            let curIdx = 0;
            for ( let i = 0; i < panels.length; i++ ) {
                if ( (panels[ i ].getAttribute('data-ym') || '').trim() === this.activeYm ) {
                    curIdx = i;
                    break;
                }
            }

            const nextIdx = curIdx + delta;
            if ( nextIdx < 0 || nextIdx >= panels.length ) {
                return;
            }

            const nextYm = (panels[ nextIdx ].getAttribute('data-ym') || '').trim();
            this.setCalendarMonth( nextYm );
        },

        syncCalendarHeader: function() {
            const panels   = this.getCalendarMonthPanels();
            const titleEl  = document.getElementById('sywat_cal_month_title');
            const prevBtn  = document.getElementById('sywat_cal_prev_btn');
            const nextBtn  = document.getElementById('sywat_cal_next_btn');
            const todayBtn = document.getElementById('sywat_cal_today_btn');

            if ( ! panels.length ) {
                return;
            }

            let curIdx   = 0;
            let curLabel = '';

            for ( let i = 0; i < panels.length; i++ ) {
                const ym = (panels[ i ].getAttribute('data-ym') || '').trim();
                if ( ym === this.activeYm ) {
                    curIdx   = i;
                    curLabel = panels[ i ].getAttribute('data-label') || ym;
                    break;
                }
            }

            if ( ! curLabel ) {
                curLabel = panels[ 0 ].getAttribute('data-label') || '';
                this.activeYm = (panels[ 0 ].getAttribute('data-ym') || '').trim();
            }

            if ( titleEl ) {
                titleEl.textContent = curLabel;
            }
            if ( prevBtn ) {
                prevBtn.disabled = (curIdx <= 0);
            }
            if ( nextBtn ) {
                nextBtn.disabled = (curIdx >= panels.length - 1);
            }
            if ( todayBtn ) {
                todayBtn.classList.toggle('sywat-hidden', ! this.defaultYm);
                todayBtn.disabled = (this.activeYm === this.defaultYm);
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
         * Shows or hides the "This Weekend" jump button based on view mode and visible targets.
         */
        updateJumpButtonVisibility: function() {
            const jumpBtn = document.getElementById('sywat_schedule_jump_weekend');
            if ( ! jumpBtn ) {
                return;
            }

            const shouldShow = (this.viewMode === 'list') && Boolean( this.findCurrentWeekendRow() );
            jumpBtn.classList.toggle('sywat-hidden', ! shouldShow);
        },

        /**
         * Scrolls the schedule container or window to the current weekend row in List View.
         */
        scrollToCurrentWeekend: function( isInitialLoad ) {
            if ( this.viewMode !== 'list' ) {
                return;
            }
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

            // 1. Filter List View Rows
            const rows = document.querySelectorAll('.sywat-schedule-row');
            let totalCount   = 0;
            let visibleCount = 0;

            rows.forEach(function( row ) {
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

            // 2. Synchronize Calendar View Day Cells & "+N more" Overflow Counters
            const dayCells = document.querySelectorAll('.sywat-cal-day');
            dayCells.forEach(function( cell ) {
                const chips   = cell.querySelectorAll('.sywat-cal-event-chip');
                const moreBtn = cell.querySelector('.sywat-cal-more-btn');
                let matchingInDay = 0;

                chips.forEach(function( chip ) {
                    const chipDist   = (chip.getAttribute('data-district') || '').toLowerCase().trim();
                    const chipShop   = (chip.getAttribute('data-shop') || '').toLowerCase().trim();
                    const chipSearch = (chip.getAttribute('data-name') || '').toLowerCase().trim();

                    const matchDist   = (distVal === 'all' || chipDist === distVal);
                    const matchShop   = (shopVal === 'all' || chipShop === shopVal);
                    const matchSearch = (searchVal === '' || chipSearch.includes(searchVal));

                    if ( matchDist && matchShop && matchSearch ) {
                        if ( matchingInDay < MAX_CHIPS_PER_DAY ) {
                            chip.classList.remove('sywat-hidden');
                        } else {
                            chip.classList.add('sywat-hidden');
                        }
                        matchingInDay++;
                    } else {
                        chip.classList.add('sywat-hidden');
                    }
                });

                if ( moreBtn ) {
                    const overflow = matchingInDay - MAX_CHIPS_PER_DAY;
                    if ( overflow > 0 ) {
                        moreBtn.textContent = '+' + overflow + ' more';
                        moreBtn.classList.remove('sywat-hidden');
                    } else {
                        moreBtn.textContent = '';
                        moreBtn.classList.add('sywat-hidden');
                    }
                }
            });

            // 3. Update Dynamic Counter Banner
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
        },

        escapeHtml: function( value ) {
            return String( value === null || value === undefined ? '' : value )
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        sanitizeUrl: function( rawUrl ) {
            const url = String( rawUrl || '' ).trim();
            if ( ! url ) {
                return '';
            }
            if ( url.startsWith('https://') || url.startsWith('http://') || url.startsWith('/') ) {
                return url;
            }
            return '';
        },

        formatHumanDate: function( dateStr ) {
            const parts = String( dateStr || '' ).split('-');
            if ( parts.length !== 3 ) {
                return dateStr;
            }
            const dt = new Date(
                parseInt( parts[ 0 ], 10 ),
                parseInt( parts[ 1 ], 10 ) - 1,
                parseInt( parts[ 2 ], 10 ),
                12, 0, 0
            );
            if ( isNaN( dt.getTime() ) ) {
                return dateStr;
            }
            return dt.toLocaleDateString('en-US', {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });
        },

        renderEventCardHtml: function( evt ) {
            const self = this;
            let badgeClass = 'sywat-badge-prereg';
            if ( evt.status_code === 'completed' ) {
                badgeClass = 'sywat-badge-muted';
            } else if ( evt.status_code === 'active' || evt.status_code === 'this_weekend' ) {
                badgeClass = 'sywat-badge-open';
            }

            const iconPin     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
            const iconExtLink = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="sywat-icon"><line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline></svg>';

            let html = '<div class="sywat-cal-modal-card">';
            html += '<div class="sywat-cal-modal-card-top">';
            html += '<div>';
            html += '<div class="sywat-cal-modal-date">' + self.escapeHtml( evt.display_dates || '' ) + '</div>';
            html += '<div class="sywat-cal-modal-title">' + self.escapeHtml( evt.name || '' ) + '</div>';
            html += '</div>';
            html += '<span class="sywat-badge ' + badgeClass + '">' + self.escapeHtml( evt.status_label || '' ) + '</span>';
            html += '</div>';

            // Type & District Pills
            const hasSpecialType = evt.event_type && evt.event_type !== 'SYWAT';
            if ( hasSpecialType || evt.district ) {
                html += '<div class="sywat-cal-modal-badges">';
                if ( hasSpecialType ) {
                    html += '<span class="sywat-badge sywat-badge-open">' + self.escapeHtml( evt.event_type ) + '</span>';
                }
                if ( evt.district ) {
                    html += '<span class="sywat-badge sywat-badge-prereg">' + self.escapeHtml( evt.district ) + '</span>';
                }
                html += '</div>';
            }

            // Host Shop & Address
            if ( evt.shop_name ) {
                html += '<div class="sywat-tourn-shop"><strong>' + self.escapeHtml( evt.shop_name ) + '</strong></div>';
            }
            if ( evt.address ) {
                const safeMaps = self.sanitizeUrl( evt.maps_url );
                html += '<div class="sywat-label-subtext sywat-mt-1">' + iconPin + ' ';
                if ( safeMaps ) {
                    html += '<a href="' + self.escapeHtml( safeMaps ) + '" target="_blank" rel="noopener noreferrer" class="sywat-table-link">' + self.escapeHtml( evt.address ) + '</a>';
                } else {
                    html += self.escapeHtml( evt.address );
                }
                html += '</div>';
            }

            // Grouped Shooting Lines
            if ( evt.grouped_lines && typeof evt.grouped_lines === 'object' ) {
                const days = Object.keys( evt.grouped_lines );
                if ( days.length > 0 ) {
                    html += '<div class="sywat-cal-modal-lines">';
                    days.forEach(function( day ) {
                        const times = Array.isArray( evt.grouped_lines[ day ] ) ? evt.grouped_lines[ day ] : [];
                        if ( times.length > 0 ) {
                            html += '<div class="sywat-cal-modal-line-row">';
                            html += '<strong class="sywat-text-subtle">' + self.escapeHtml( day ) + ':</strong> ';
                            times.forEach(function( time ) {
                                html += '<span class="sywat-badge sywat-badge-muted">' + self.escapeHtml( time ) + '</span> ';
                            });
                            html += '</div>';
                        }
                    });
                    html += '</div>';
                }
            }

            // Registration / Results Actions
            html += '<div class="sywat-cal-modal-actions">';
            if ( evt.status_code === 'completed' ) {
                const safeResults = self.sanitizeUrl( evt.results_url );
                if ( (evt.scores_published || evt.has_tfaa_url) && safeResults ) {
                    html += '<a href="' + self.escapeHtml( safeResults ) + '" class="sywat-btn sywat-btn-primary">🏆 View Results</a>';
                } else {
                    html += '<span class="sywat-badge sywat-badge-pending">⏳ Results Pending</span>';
                }
            } else {
                const regLinks = Array.isArray( evt.reg_links ) ? evt.reg_links : [];
                let renderedLink = false;
                regLinks.forEach(function( link ) {
                    const safeHref = self.sanitizeUrl( link && link.url );
                    if ( safeHref ) {
                        const btnLabel = (link && link.label) ? link.label : 'Register Online';
                        html += '<a href="' + self.escapeHtml( safeHref ) + '" target="_blank" rel="noopener noreferrer" class="sywat-btn sywat-btn-success">' + iconExtLink + ' ' + self.escapeHtml( btnLabel ) + '</a>';
                        renderedLink = true;
                    }
                });

                if ( ! renderedLink ) {
                    if ( evt.shop_phone ) {
                        html += '<span class="sywat-label-subtext">📞 <strong>Contact Shop:</strong> ' + self.escapeHtml( evt.shop_phone ) + '</span>';
                    } else {
                        html += '<span class="sywat-label-subtext">📞 <strong>Contact Host to Register</strong></span>';
                    }
                }
            }
            html += '</div>';
            html += '</div>';

            return html;
        },

        openSingleEventModal: function( eventId, triggerEl ) {
            const evt = this.eventsMap[ String( eventId ) ];
            if ( ! evt ) {
                return;
            }
            this.openEventModal(
                evt.name || 'Tournament Details',
                this.renderEventCardHtml( evt ),
                triggerEl
            );
        },

        openDayEventsModal: function( dateStr, triggerEl ) {
            const calPanel = document.getElementById('sywat_schedule_calendar_panel');
            if ( ! calPanel ) {
                return;
            }

            const districtEl = document.getElementById('sywat_schedule_filter_district');
            const shopEl     = document.getElementById('sywat_schedule_filter_shop');
            const searchEl   = document.getElementById('sywat_schedule_search');

            const distVal   = districtEl ? districtEl.value.toLowerCase().trim() : 'all';
            const shopVal   = shopEl ? shopEl.value.toLowerCase().trim() : 'all';
            const searchVal = searchEl ? searchEl.value.toLowerCase().trim() : '';

            const dayCell = calPanel.querySelector('.sywat-cal-day[data-date="' + dateStr + '"]');
            if ( ! dayCell ) {
                return;
            }

            const chips = dayCell.querySelectorAll('.sywat-cal-event-chip');
            const matchingEvents = [];
            const self = this;

            chips.forEach(function( chip ) {
                const chipDist   = (chip.getAttribute('data-district') || '').toLowerCase().trim();
                const chipShop   = (chip.getAttribute('data-shop') || '').toLowerCase().trim();
                const chipSearch = (chip.getAttribute('data-name') || '').toLowerCase().trim();

                const matchDist   = (distVal === 'all' || chipDist === distVal);
                const matchShop   = (shopVal === 'all' || chipShop === shopVal);
                const matchSearch = (searchVal === '' || chipSearch.includes(searchVal));

                if ( matchDist && matchShop && matchSearch ) {
                    const eid = chip.getAttribute('data-event-id');
                    if ( self.eventsMap[ String( eid ) ] ) {
                        matchingEvents.push( self.eventsMap[ String( eid ) ] );
                    }
                }
            });

            if ( ! matchingEvents.length ) {
                return;
            }

            let bodyHtml = '';
            matchingEvents.forEach(function( evt ) {
                bodyHtml += self.renderEventCardHtml( evt );
            });

            this.openEventModal(
                this.formatHumanDate( dateStr ),
                bodyHtml,
                triggerEl
            );
        },

        openEventModal: function( titleText, bodyHtml, triggerEl ) {
            const modalEl  = document.getElementById('sywat_schedule_event_modal');
            const titleEl  = document.getElementById('sywat_schedule_event_modal_title');
            const bodyEl   = document.getElementById('sywat_schedule_event_modal_body');
            const closeBtn = document.getElementById('sywat_schedule_event_modal_close');

            if ( ! modalEl || ! bodyEl ) {
                return;
            }

            this.lastTriggerEl = triggerEl || document.activeElement;

            if ( titleEl ) {
                titleEl.textContent = titleText;
            }
            bodyEl.innerHTML = bodyHtml;
            bodyEl.scrollTop = 0;

            modalEl.classList.add('active');
            document.documentElement.classList.add('sywat-modal-open');
            if ( document.body ) {
                document.body.classList.add('sywat-modal-open');
            }

            if ( closeBtn ) {
                closeBtn.focus();
            }
        },

        closeEventModal: function() {
            const modalEl = document.getElementById('sywat_schedule_event_modal');
            if ( ! modalEl ) {
                return;
            }

            modalEl.classList.remove('active');
            document.documentElement.classList.remove('sywat-modal-open');
            if ( document.body ) {
                document.body.classList.remove('sywat-modal-open');
            }

            if ( this.lastTriggerEl && typeof this.lastTriggerEl.focus === 'function' ) {
                this.lastTriggerEl.focus();
            }
            this.lastTriggerEl = null;
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
