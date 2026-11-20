/**
 * Sub-Module: Historical TFAA Results Importer Client Controller
 * Path: assets/js/sywat-history-import.js
 * Description: Vanilla JS client controller for permanently persisted TFAA seasons and per-season
 *              tournament manifests, live import status tracking (#sywat_history_events_modal),
 *              chunked single-event and multi-season AJAX imports (#sywat_history_progress_modal),
 *              and archived season purging.
 * Compliance: Zero jQuery, namespaced under window.SYWAT.historyImport, delegated data-sywat-action
 *             listeners across document (supports modal relocation to <body>), Modal Markup Contract
 *             compliant (.active class + data-sywat-static="true" while running), zero element.style.*
 *             mutations except progress bar width.
 * Author: SYWAT Archery
 * Version: 1.2.0
 */

(function () {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    var state = {
        seasons: [],
        seasonMap: {},
        seasonSlugMap: {},
        eventsBySeason: {},
        activeEventsSeason: null,
        isRunning: false,
        stopRequested: false,
        hasLogEntries: false
    };

    /**
     * Retrieve localized AJAX URL, security nonce, and permanently saved manifests safely.
     *
     * @return {Object} Localized configuration object.
     */
    function getConfig() {
        var cfg = window.sywatHistoryData || {};
        return {
            ajaxUrl: cfg.ajaxUrl || window.ajaxurl || '',
            nonce: cfg.nonce || '',
            savedSeasons: Array.isArray(cfg.savedSeasons) ? cfg.savedSeasons : [],
            savedEvents: (cfg.savedEvents && typeof cfg.savedEvents === 'object') ? cfg.savedEvents : {}
        };
    }

    /**
     * Escape HTML entities to prevent XSS when rendering remote TFAA titles or messages.
     *
     * @param {*} str Raw value.
     * @return {string} Escaped HTML string.
     */
    function escapeHtml(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Compute canonical row lookup key matching server-side PHP rendering.
     *
     * @param {Object} season Season descriptor object.
     * @return {string} Canonical row key.
     */
    function getSeasonRowKey(season) {
        if (!season || typeof season !== 'object') {
            return '';
        }
        var sid = parseInt(season.season_id, 10) || 0;
        if (sid > 0) {
            return String(sid);
        }
        return String(season.slug || '');
    }

    /**
     * Rebuild O(1) lookup maps for state.seasons.
     */
    function rebuildSeasonMaps() {
        state.seasonMap = {};
        state.seasonSlugMap = {};

        state.seasons.forEach(function (item) {
            if (!item || typeof item !== 'object') {
                return;
            }
            var rowKey = getSeasonRowKey(item);
            var slug = String(item.slug || '');
            if (rowKey) {
                state.seasonMap[rowKey] = item;
            }
            if (slug) {
                state.seasonSlugMap[slug] = item;
            }
        });
    }

    /**
     * Send a URL-encoded POST AJAX request to WordPress admin-ajax.php and parse JSON.
     *
     * @param {string} action WordPress AJAX action slug.
     * @param {Object} params Key-value payload parameters.
     * @return {Promise<Object>} Parsed JSON response payload.
     */
    function postAjax(action, params) {
        var cfg = getConfig();
        if (!cfg.ajaxUrl) {
            return Promise.reject(new Error('AJAX endpoint URL is not configured.'));
        }

        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', cfg.nonce);

        if (params && typeof params === 'object') {
            Object.keys(params).forEach(function (key) {
                if (params[key] !== undefined && params[key] !== null) {
                    body.append(key, String(params[key]));
                }
            });
        }

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.text().then(function (rawText) {
                var json = null;
                try {
                    json = JSON.parse(rawText);
                } catch (e) {
                    throw new Error('Server returned an unexpected non-JSON response (HTTP ' + response.status + ').');
                }

                if (!response.ok || !json || json.success !== true) {
                    var errMsg = (json && json.data && json.data.message)
                        ? json.data.message
                        : ('Request failed (HTTP ' + response.status + ').');
                    throw new Error(errMsg);
                }

                return json.data || {};
            });
        });
    }

    /**
     * Open the Progress & Activity Log modal (#sywat_history_progress_modal) via the .active class.
     */
    function openProgressModal() {
        var modal = document.getElementById('sywat_history_progress_modal');
        if (modal) {
            modal.classList.add('active');
        }
    }

    /**
     * Close the Progress & Activity Log modal (#sywat_history_progress_modal) when no batch is running.
     */
    function closeProgressModal() {
        if (state.isRunning) {
            return;
        }
        var modal = document.getElementById('sywat_history_progress_modal');
        if (modal) {
            modal.classList.remove('active');
        }
    }

    /**
     * Open the Discovered Season Tournaments modal (#sywat_history_events_modal).
     */
    function openEventsModal() {
        var modal = document.getElementById('sywat_history_events_modal');
        if (modal) {
            modal.classList.add('active');
        }
    }

    /**
     * Close the Discovered Season Tournaments modal (#sywat_history_events_modal).
     */
    function closeEventsModal() {
        var modal = document.getElementById('sywat_history_events_modal');
        if (modal) {
            modal.classList.remove('active');
        }
    }

    /**
     * Apply alert styling and message to a target banner element.
     *
     * @param {HTMLElement|null} bannerEl Target banner element.
     * @param {string} type               Alert modifier: 'success', 'danger', or 'info'.
     * @param {string} message            Plain-text message to display.
     */
    function applyBannerState(bannerEl, type, message) {
        if (!bannerEl) {
            return;
        }

        bannerEl.classList.remove('sywat-hidden', 'sywat-alert-success', 'sywat-alert-danger', 'sywat-alert-info');
        if (type === 'success') {
            bannerEl.classList.add('sywat-alert-success');
        } else if (type === 'danger') {
            bannerEl.classList.add('sywat-alert-danger');
        } else {
            bannerEl.classList.add('sywat-alert-info');
        }

        bannerEl.textContent = message;
    }

    /**
     * Display status alert banner on the main card and/or inside the progress modal.
     *
     * @param {string} type        Alert modifier: 'success', 'danger', or 'info'.
     * @param {string} message     Plain-text message to display.
     * @param {boolean} syncModal  Whether to also update #sywat_history_modal_banner.
     */
    function showBanner(type, message, syncModal) {
        applyBannerState(document.getElementById('sywat_history_status_banner'), type, message);
        if (syncModal) {
            applyBannerState(document.getElementById('sywat_history_modal_banner'), type, message);
        }
    }

    /**
     * Display or hide status banner inside the Season Tournaments modal.
     *
     * @param {string} type    Alert modifier or empty string to hide.
     * @param {string} message Plain-text message.
     */
    function setEventsModalBanner(type, message) {
        var banner = document.getElementById('sywat_history_events_banner');
        if (!banner) {
            return;
        }
        if (!type) {
            banner.classList.add('sywat-hidden');
            return;
        }
        applyBannerState(banner, type, message);
    }

    /**
     * Hide status alert banners on card and progress modal.
     */
    function hideBanners() {
        var cardBanner = document.getElementById('sywat_history_status_banner');
        var modalBanner = document.getElementById('sywat_history_modal_banner');
        if (cardBanner) {
            cardBanner.classList.add('sywat-hidden');
        }
        if (modalBanner) {
            modalBanner.classList.add('sywat-hidden');
        }
    }

    /**
     * Append a timestamped entry to the Migration Activity Log inside #sywat_history_progress_modal.
     *
     * @param {string} level   Entry severity ('info', 'success', 'error').
     * @param {string} message Log message text.
     */
    function appendLog(level, message) {
        var logList = document.getElementById('sywat_history_log_list');
        if (!logList) {
            return;
        }

        if (!state.hasLogEntries) {
            logList.innerHTML = '';
            state.hasLogEntries = true;
        }

        var now = new Date();
        var stamp = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        var prefix = '[INFO]';
        if (level === 'success') {
            prefix = '[OK]';
        } else if (level === 'error') {
            prefix = '[ERROR]';
        }

        var line = document.createElement('div');
        line.textContent = stamp + ' ' + prefix + ' ' + message;
        logList.appendChild(line);
        logList.scrollTop = logList.scrollHeight;
    }

    /**
     * Clear all entries from the Migration Activity Log and restore placeholder text.
     */
    function clearLog() {
        var logList = document.getElementById('sywat_history_log_list');
        var modalBanner = document.getElementById('sywat_history_modal_banner');
        if (logList) {
            logList.innerHTML = '<div>No migration activity recorded yet.</div>';
        }
        if (modalBanner && !state.isRunning) {
            modalBanner.classList.add('sywat-hidden');
        }
        state.hasLogEntries = false;
    }

    /**
     * Update the live progress bar and status counters inside #sywat_history_progress_modal.
     *
     * @param {string} label   Current operation description.
     * @param {number} current Completed items count.
     * @param {number} total   Total queued items count.
     */
    function updateProgress(label, current, total) {
        var box = document.getElementById('sywat_history_progress_box');
        var labelEl = document.getElementById('sywat_history_progress_label');
        var statsEl = document.getElementById('sywat_history_progress_stats');
        var fillEl = document.getElementById('sywat_history_progress_fill');

        if (box) {
            box.classList.remove('sywat-hidden');
        }
        if (labelEl) {
            labelEl.textContent = label;
        }
        if (statsEl) {
            statsEl.textContent = current + ' / ' + total + ' events';
        }
        if (fillEl) {
            var pct = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;
            fillEl.style.width = pct + '%';
        }
    }

    /**
     * Enable or disable interactive controls and lock/unlock the modal while a batch is running.
     *
     * @param {boolean} running True when import or purge operation is active.
     */
    function setControlsLocked(running) {
        state.isRunning = running;

        var modal = document.getElementById('sywat_history_progress_modal');
        var modalXBtn = document.getElementById('sywat_history_modal_x_btn');
        var modalCloseBtn = document.getElementById('sywat_history_close_modal_btn');
        var stopBtn = document.getElementById('sywat_history_stop_btn');
        var discoverBtn = document.getElementById('sywat_history_discover_btn');
        var runBtn = document.getElementById('sywat_history_run_btn');
        var checkAll = document.getElementById('sywat_history_check_all');

        if (modal) {
            if (running) {
                modal.setAttribute('data-sywat-static', 'true');
            } else {
                modal.removeAttribute('data-sywat-static');
            }
        }

        if (modalXBtn) {
            modalXBtn.disabled = running;
        }
        if (modalCloseBtn) {
            modalCloseBtn.disabled = running;
        }
        if (stopBtn) {
            stopBtn.disabled = !running;
            if (running) {
                stopBtn.classList.remove('sywat-hidden');
            } else {
                stopBtn.classList.add('sywat-hidden');
            }
        }

        if (discoverBtn) {
            discoverBtn.disabled = running;
        }
        if (checkAll) {
            checkAll.disabled = running;
        }

        var actionBtns = document.querySelectorAll(
            '#sywat_history_importer_app [data-sywat-action="select-unimported"],' +
            '#sywat_history_importer_app [data-sywat-action="select-all"],' +
            '#sywat_history_importer_app [data-sywat-action="clear-selection"],' +
            '#sywat_history_importer_app [data-sywat-action="import-single-season"],' +
            '#sywat_history_importer_app [data-sywat-action="purge-season"],' +
            '#sywat_history_events_modal [data-sywat-action="refresh-season-events"],' +
            '#sywat_history_events_modal [data-sywat-action="import-modal-season"],' +
            '#sywat_history_events_modal [data-sywat-action="import-single-event"]'
        );
        actionBtns.forEach(function (btn) {
            btn.disabled = running;
        });

        var checkboxes = document.querySelectorAll('.sywat-history-season-cb');
        checkboxes.forEach(function (cb) {
            cb.disabled = running;
        });

        if (runBtn) {
            runBtn.disabled = running || getSelectedSeasons().length === 0;
        }
    }

    /**
     * Synchronize the "Import Selected Seasons" button and header checkbox state.
     */
    function syncSelectionState() {
        var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.sywat-history-season-cb'));
        var checkedCount = checkboxes.filter(function (cb) {
            return cb.checked;
        }).length;

        var runBtn = document.getElementById('sywat_history_run_btn');
        if (runBtn) {
            runBtn.disabled = state.isRunning || checkedCount === 0;
            var labelSuffix = checkedCount > 0 ? ' (' + checkedCount + ')' : '';
            runBtn.lastChild.textContent = ' Import Selected Seasons' + labelSuffix;
        }

        var checkAll = document.getElementById('sywat_history_check_all');
        if (checkAll) {
            checkAll.checked = checkboxes.length > 0 && checkedCount === checkboxes.length;
            checkAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }
    }

    /**
     * Return array of selected season objects from state.
     *
     * @return {Array<Object>} Selected season descriptors.
     */
    function getSelectedSeasons() {
        var selected = [];
        var checkboxes = document.querySelectorAll('.sywat-history-season-cb:checked');
        checkboxes.forEach(function (cb) {
            var key = String(cb.value || '');
            if (state.seasonMap[key]) {
                selected.push(state.seasonMap[key]);
            }
        });
        return selected;
    }

    /**
     * Render discovered seasons into #sywat_history_seasons_tbody.
     */
    function renderSeasonsTable() {
        var tbody = document.getElementById('sywat_history_seasons_tbody');
        var countEl = document.getElementById('sywat_history_season_count');
        if (!tbody) {
            return;
        }

        if (countEl) {
            countEl.textContent = String(state.seasons.length);
        }

        if (!state.seasons.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="sywat-col-center">Click <strong>Discover TFAA Seasons</strong> above to load and permanently store available seasons from texasfieldarchery.org.</td></tr>';
            syncSelectionState();
            return;
        }

        var rowsHtml = state.seasons.map(function (s) {
            var sid = parseInt(s.season_id, 10) || 0;
            var rowKey = escapeHtml(getSeasonRowKey(s));
            var slug = escapeHtml(s.slug || '');
            var name = escapeHtml(s.name || s.slug || '');
            var type = escapeHtml(s.type || 'Indoor');
            var windowText = escapeHtml((s.start_date || '—') + ' to ' + (s.end_date || '—'));
            var existingCount = parseInt(s.existing_events, 10) || 0;
            var discoveredCount = parseInt(s.discovered_events, 10) || 0;
            var isImported = Boolean(s.imported) || existingCount > 0;

            var statusBadge = isImported
                ? '<span class="sywat-badge sywat-badge-open">Imported (' + escapeHtml(s.local_status || 'archived') + ')</span>'
                : '<span class="sywat-badge sywat-badge-prereg">Not Imported</span>';

            var countBadge = '<span class="sywat-label-subtext">0</span>';
            if (discoveredCount > 0) {
                countBadge = '<strong>' + existingCount + '</strong> / ' + discoveredCount;
            } else if (existingCount > 0) {
                countBadge = '<strong>' + existingCount + '</strong>';
            }

            var purgeBtnHtml = isImported
                ? ' <button type="button" class="sywat-btn sywat-btn-danger" data-sywat-action="purge-season" data-season-slug="' + slug + '" data-season-name="' + name + '">Purge</button>'
                : '';

            return (
                '<tr data-season-row-id="' + rowKey + '" data-season-slug="' + slug + '">' +
                    '<td class="sywat-col-center">' +
                        '<input type="checkbox" class="sywat-history-season-cb" data-sywat-action="toggle-season-checkbox" value="' + rowKey + '" aria-label="Select ' + name + '">' +
                    '</td>' +
                    '<td><strong>' + name + '</strong></td>' +
                    '<td class="sywat-col-center"><span class="sywat-badge">' + type + '</span></td>' +
                    '<td class="sywat-col-center sywat-text-muted-sm">' + windowText + '</td>' +
                    '<td class="sywat-col-center" data-role="status-cell">' + statusBadge + '</td>' +
                    '<td class="sywat-col-center" data-role="count-cell">' + countBadge + '</td>' +
                    '<td class="sywat-col-right">' +
                        '<div class="sywat-table-actions">' +
                            '<button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="view-season-events" data-season-id="' + sid + '" data-season-slug="' + slug + '" data-season-name="' + name + '">Tournaments</button>' +
                            '<button type="button" class="sywat-btn sywat-btn-primary" data-sywat-action="import-single-season" data-season-id="' + rowKey + '" data-season-slug="' + slug + '">Import</button>' +
                            purgeBtnHtml +
                        '</div>' +
                    '</td>' +
                '</tr>'
            );
        }).join('');

        tbody.innerHTML = rowsHtml;
        syncSelectionState();
    }

    /**
     * Render discovered tournaments for a season inside #sywat_history_events_tbody.
     *
     * @param {string} seasonSlug Target season slug.
     */
    function renderSeasonEventsTable(seasonSlug) {
        var tbody = document.getElementById('sywat_history_events_tbody');
        if (!tbody) {
            return;
        }

        var events = Array.isArray(state.eventsBySeason[seasonSlug]) ? state.eventsBySeason[seasonSlug] : [];
        if (!events.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="sywat-col-center">No tournaments discovered for this season yet. Click <strong>Refresh Tournament List</strong> below to fetch them from TFAA.</td></tr>';
            return;
        }

        var rowsHtml = events.map(function (ev) {
            var eid = parseInt(ev.event_id, 10) || 0;
            var title = escapeHtml(ev.title || '');
            var dateStr = escapeHtml(ev.start_date || '—');
            var evType = escapeHtml(ev.event_type || 'SYWAT');
            var isImported = Boolean(ev.already_imported);
            var archerCount = parseInt(ev.archer_count, 10) || 0;

            var statusBadge = isImported
                ? '<span class="sywat-badge sywat-badge-open">Imported</span>'
                : '<span class="sywat-badge sywat-badge-prereg">Not Imported</span>';

            var scorecardText = isImported
                ? '<strong>' + archerCount + '</strong>'
                : '<span class="sywat-label-subtext">—</span>';

            var btnLabel = isImported ? 'Re-Import' : 'Import';
            var btnClass = isImported ? 'sywat-btn sywat-btn-secondary' : 'sywat-btn sywat-btn-primary';

            return (
                '<tr data-event-id="' + eid + '">' +
                    '<td class="sywat-text-muted-sm">' + dateStr + '</td>' +
                    '<td><strong>' + title + '</strong></td>' +
                    '<td class="sywat-col-center"><span class="sywat-badge">' + evType + '</span></td>' +
                    '<td class="sywat-col-center">' + statusBadge + '</td>' +
                    '<td class="sywat-col-center">' + scorecardText + '</td>' +
                    '<td class="sywat-col-right">' +
                        '<button type="button" class="' + btnClass + '" data-sywat-action="import-single-event" data-event-id="' + eid + '" data-season-slug="' + escapeHtml(seasonSlug) + '">' + btnLabel + '</button>' +
                    '</td>' +
                '</tr>'
            );
        }).join('');

        tbody.innerHTML = rowsHtml;
    }

    /**
     * Fetch (or refresh) the permanent discovered tournament manifest for a season.
     *
     * @param {number}  seasonId     TFAA numeric season ID.
     * @param {string}  seasonSlug   Canonical season slug.
     * @param {string}  seasonName   Human-readable season title.
     * @param {boolean} forceRefresh True to query TFAA live; false to use saved cache if present.
     */
    function fetchSeasonEvents(seasonId, seasonSlug, seasonName, forceRefresh) {
        var tbody = document.getElementById('sywat_history_events_tbody');
        var refreshBtn = document.getElementById('sywat_history_refresh_events_btn');

        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="sywat-col-center">Loading discovered tournaments for ' + escapeHtml(seasonName || seasonSlug) + '...</td></tr>';
        }
        if (refreshBtn) {
            refreshBtn.disabled = true;
        }
        setEventsModalBanner('info', (forceRefresh ? 'Refreshing tournament list from texasfieldarchery.org...' : 'Loading discovered tournaments...'));

        postAjax('sywat_history_get_season_events', {
            season_id: seasonId,
            season_slug: seasonSlug,
            season_label: seasonName,
            force_refresh: forceRefresh ? '1' : '0'
        }).then(function (data) {
            var slug = String(data.season_slug || seasonSlug);
            var list = Array.isArray(data.events) ? data.events : [];
            state.eventsBySeason[slug] = list;

            if (state.seasonSlugMap[slug]) {
                var importedCount = list.filter(function (ev) {
                    return Boolean(ev.already_imported);
                }).length;
                state.seasonSlugMap[slug].discovered_events = list.length;
                state.seasonSlugMap[slug].existing_events = importedCount;
                state.seasonSlugMap[slug].imported = importedCount > 0;
                renderSeasonsTable();
            }

            renderSeasonEventsTable(slug);
            setEventsModalBanner('success', 'Loaded ' + list.length + ' discovered tournament(s) for ' + (seasonName || slug) + '.');
        }).catch(function (err) {
            setEventsModalBanner('danger', err.message || 'Failed to load season tournaments.');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="6" class="sywat-col-center">Unable to load tournaments for this season.</td></tr>';
            }
        }).finally(function () {
            if (refreshBtn) {
                refreshBtn.disabled = state.isRunning;
            }
        });
    }

    /**
     * Open the Season Tournaments modal for a specific season row.
     *
     * @param {number} seasonId   TFAA numeric season ID.
     * @param {string} seasonSlug Canonical season slug.
     * @param {string} seasonName Human-readable season title.
     */
    function viewSeasonEvents(seasonId, seasonSlug, seasonName) {
        state.activeEventsSeason = {
            season_id: parseInt(seasonId, 10) || 0,
            season_slug: String(seasonSlug || ''),
            season_name: String(seasonName || seasonSlug || '')
        };

        var titleEl = document.getElementById('sywat_history_events_modal_title');
        if (titleEl) {
            titleEl.textContent = state.activeEventsSeason.season_name + ' — Discovered Tournaments';
        }

        var importSeasonBtn = document.getElementById('sywat_history_import_modal_season_btn');
        if (importSeasonBtn) {
            importSeasonBtn.disabled = state.isRunning || state.activeEventsSeason.season_id <= 0;
        }

        setEventsModalBanner('', '');
        openEventsModal();

        var cached = state.eventsBySeason[state.activeEventsSeason.season_slug];
        if (Array.isArray(cached) && cached.length > 0) {
            renderSeasonEventsTable(state.activeEventsSeason.season_slug);
            var importedCount = cached.filter(function (ev) {
                return Boolean(ev.already_imported);
            }).length;
            setEventsModalBanner('info', importedCount + ' of ' + cached.length + ' discovered tournament(s) imported.');
        } else {
            fetchSeasonEvents(
                state.activeEventsSeason.season_id,
                state.activeEventsSeason.season_slug,
                state.activeEventsSeason.season_name,
                false
            );
        }
    }

    /**
     * Fetch the available historical seasons list from texasfieldarchery.org and persist permanently.
     */
    function discoverSeasons() {
        if (state.isRunning) {
            return;
        }

        var discoverBtn = document.getElementById('sywat_history_discover_btn');
        if (discoverBtn) {
            discoverBtn.disabled = true;
        }

        showBanner('info', 'Connecting to texasfieldarchery.org to discover and save available seasons...', false);

        postAjax('sywat_history_discover_seasons', {})
            .then(function (data) {
                var list = Array.isArray(data.seasons) ? data.seasons : [];
                state.seasons = list;
                rebuildSeasonMaps();

                renderSeasonsTable();
                showBanner('success', 'Discovered and permanently saved ' + list.length + ' historical season(s) from TFAA. Click "Tournaments" on any season to view its events or "Import" to migrate.', false);
                appendLog('success', 'Discovered and saved ' + list.length + ' TFAA season(s).');
            })
            .catch(function (err) {
                showBanner('danger', err.message || 'Failed to discover TFAA seasons.', false);
                appendLog('error', err.message || 'Season discovery failed.');
            })
            .finally(function () {
                if (discoverBtn) {
                    discoverBtn.disabled = false;
                }
            });
    }

    /**
     * Import a single individual tournament event from the Season Tournaments modal.
     *
     * @param {string} seasonSlug Target season slug.
     * @param {number} eventId    TFAA numeric event ID.
     */
    function importSingleEvent(seasonSlug, eventId) {
        if (state.isRunning) {
            return;
        }

        var events = Array.isArray(state.eventsBySeason[seasonSlug]) ? state.eventsBySeason[seasonSlug] : [];
        var targetEv = null;
        for (var i = 0; i < events.length; i++) {
            if (parseInt(events[i].event_id, 10) === parseInt(eventId, 10)) {
                targetEv = events[i];
                break;
            }
        }

        if (!targetEv) {
            return;
        }

        var seasonObj = state.seasonSlugMap[seasonSlug] || {};
        closeEventsModal();
        hideBanners();
        state.stopRequested = false;
        setControlsLocked(true);
        openProgressModal();

        updateProgress('Importing: ' + targetEv.title, 0, 1);
        appendLog('info', 'Importing single tournament "' + targetEv.title + '"...');

        postAjax('sywat_history_import_tournament', {
            event_id: targetEv.event_id,
            raw_title: targetEv.raw_title || targetEv.title,
            title: targetEv.title,
            season_id: seasonObj.season_id || targetEv.season_id || 0,
            season_name: seasonObj.name || targetEv.season_name || seasonSlug,
            season_slug: seasonSlug,
            start_date: targetEv.start_date || '',
            end_date: targetEv.end_date || targetEv.start_date || '',
            event_type: targetEv.event_type || 'SYWAT'
        }).then(function (res) {
            var archers = parseInt(res.archer_count, 10) || 0;
            if (Array.isArray(res.events)) {
                state.eventsBySeason[seasonSlug] = res.events;
            }
            if (state.seasonSlugMap[seasonSlug]) {
                var updatedList = state.eventsBySeason[seasonSlug] || [];
                var importedCnt = updatedList.filter(function (item) {
                    return Boolean(item.already_imported);
                }).length;
                state.seasonSlugMap[seasonSlug].imported = true;
                state.seasonSlugMap[seasonSlug].existing_events = importedCnt > 0 ? importedCnt : 1;
                if (updatedList.length > 0) {
                    state.seasonSlugMap[seasonSlug].discovered_events = updatedList.length;
                }
                state.seasonSlugMap[seasonSlug].local_status = 'archived';
            }

            updateProgress('Imported: ' + (res.title || targetEv.title), 1, 1);
            appendLog('success', 'Imported "' + (res.title || targetEv.title) + '" (' + archers + ' archer scorecards).');
            showBanner('success', 'Imported "' + (res.title || targetEv.title) + '" (' + archers + ' archer scorecards).', true);
        }).catch(function (err) {
            appendLog('error', 'Error importing "' + targetEv.title + '": ' + (err.message || 'Unknown error'));
            showBanner('danger', err.message || 'Failed to import tournament.', true);
        }).finally(function () {
            setControlsLocked(false);
            renderSeasonsTable();
        });
    }

    /**
     * Execute the two-stage chunked AJAX migration for an array of season objects inside #sywat_history_progress_modal:
     * Stage 1: Scan each selected season manifest (/results/season/{id}) and persist discovered tournaments
     * Stage 2: Import 1 tournament scorecard (/results/{id}) per AJAX request
     *
     * @param {Array<Object>} targetSeasons Seasons to migrate.
     */
    function runMigrationBatch(targetSeasons) {
        if (state.isRunning || !Array.isArray(targetSeasons) || targetSeasons.length === 0) {
            return;
        }

        hideBanners();
        state.stopRequested = false;
        setControlsLocked(true);
        openProgressModal();

        var eventQueue = [];
        var seasonCounts = {};
        var totalImportedEvents = 0;
        var totalImportedArchers = 0;
        var totalErrors = 0;

        updateProgress('Scanning selected season schedules...', 0, targetSeasons.length);
        appendLog('info', 'Starting migration for ' + targetSeasons.length + ' season(s)...');

        // Stage 1: Sequentially scan selected season manifests
        var scanPromise = Promise.resolve();
        targetSeasons.forEach(function (season, idx) {
            scanPromise = scanPromise.then(function () {
                if (state.stopRequested) {
                    return;
                }

                updateProgress('Scanning season: ' + season.name + '...', idx, targetSeasons.length);

                return postAjax('sywat_history_scan_season', {
                    season_id: season.season_id,
                    season_label: season.name
                }).then(function (scanData) {
                    var events = Array.isArray(scanData.events) ? scanData.events : [];
                    var savedSeason = scanData.season || season;
                    var sSlug = String(savedSeason.slug || season.slug || '');
                    var rowKey = getSeasonRowKey(season);

                    seasonCounts[rowKey] = 0;
                    if (sSlug) {
                        state.eventsBySeason[sSlug] = events;
                    }
                    if (state.seasonMap[rowKey]) {
                        state.seasonMap[rowKey].discovered_events = events.length;
                    }

                    events.forEach(function (ev) {
                        ev.season_id = season.season_id;
                        ev.season_slug = sSlug;
                        ev.season_name = savedSeason.name || season.name;
                        ev._rowKey = rowKey;
                        eventQueue.push(ev);
                    });

                    appendLog('info', 'Scanned "' + season.name + '": ' + events.length + ' tournament(s) saved & queued.');
                    updateProgress('Scanned ' + (idx + 1) + ' of ' + targetSeasons.length + ' season(s)...', idx + 1, targetSeasons.length);
                }).catch(function (err) {
                    totalErrors++;
                    appendLog('error', 'Failed scanning "' + season.name + '": ' + (err.message || 'Unknown error'));
                });
            });
        });

        // Stage 2: Sequentially import 1 tournament scorecard per AJAX tick
        scanPromise.then(function () {
            if (state.stopRequested) {
                return;
            }

            if (eventQueue.length === 0) {
                appendLog('info', 'No tournaments found in selected season(s).');
                return;
            }

            updateProgress('Importing historical tournaments...', 0, eventQueue.length);

            var importPromise = Promise.resolve();
            eventQueue.forEach(function (ev, evIdx) {
                importPromise = importPromise.then(function () {
                    if (state.stopRequested) {
                        return;
                    }

                    updateProgress('Importing: ' + ev.title, evIdx, eventQueue.length);

                    return postAjax('sywat_history_import_tournament', {
                        event_id: ev.event_id,
                        raw_title: ev.raw_title,
                        title: ev.title,
                        season_id: ev.season_id,
                        season_name: ev.season_name,
                        season_slug: ev.season_slug,
                        start_date: ev.start_date,
                        end_date: ev.end_date,
                        event_type: ev.event_type
                    }).then(function (res) {
                        totalImportedEvents++;
                        var archers = parseInt(res.archer_count, 10) || 0;
                        totalImportedArchers += archers;

                        var rowKey = String(ev._rowKey || ev.season_id || ev.season_slug || '');
                        var sSlug = String(res.season_slug || ev.season_slug || '');
                        seasonCounts[rowKey] = (seasonCounts[rowKey] || 0) + 1;

                        if (sSlug && Array.isArray(res.events)) {
                            state.eventsBySeason[sSlug] = res.events;
                        }

                        if (state.seasonMap[rowKey]) {
                            state.seasonMap[rowKey].imported = true;
                            state.seasonMap[rowKey].existing_events = seasonCounts[rowKey];
                            state.seasonMap[rowKey].local_status = 'archived';
                        }

                        appendLog('success', 'Imported "' + (res.title || ev.title) + '" (' + archers + ' archer scorecards).');
                        updateProgress('Imported ' + (evIdx + 1) + ' of ' + eventQueue.length + ' tournaments', evIdx + 1, eventQueue.length);
                    }).catch(function (err) {
                        totalErrors++;
                        appendLog('error', 'Error importing "' + ev.title + '": ' + (err.message || 'Unknown error'));
                        updateProgress('Processed ' + (evIdx + 1) + ' of ' + eventQueue.length + ' tournaments', evIdx + 1, eventQueue.length);
                    });
                });
            });

            return importPromise;
        }).finally(function () {
            setControlsLocked(false);
            renderSeasonsTable();

            if (state.stopRequested) {
                showBanner('info', 'Migration stopped by user. Imported ' + totalImportedEvents + ' tournament(s) and ' + totalImportedArchers + ' archer scorecards before stopping.', true);
                appendLog('info', 'Batch stopped cleanly after current event.');
            } else if (totalErrors > 0) {
                showBanner('danger', 'Migration finished with ' + totalErrors + ' error(s). Imported ' + totalImportedEvents + ' tournament(s) and ' + totalImportedArchers + ' archer scorecards. Review the log below.', true);
            } else {
                showBanner('success', 'Migration complete! Imported ' + totalImportedEvents + ' tournament(s) and ' + totalImportedArchers + ' independent archer scorecards.', true);
            }
        });
    }

    /**
     * Purge all imported historical tournaments and score rows for a season
     * while keeping permanent discovered manifests intact.
     *
     * @param {string} seasonSlug Target season slug.
     * @param {string} seasonName Human-readable season name.
     */
    function purgeSeason(seasonSlug, seasonName) {
        if (state.isRunning || !seasonSlug) {
            return;
        }

        var confirmed = window.confirm(
            'Purge all imported historical tournaments and scorecards for "' + (seasonName || seasonSlug) + '"?\n\nYour discovered season and tournament manifest will remain saved so you can re-import anytime. Live active seasons and Member Directory accounts are unaffected.'
        );
        if (!confirmed) {
            return;
        }

        hideBanners();
        setControlsLocked(true);
        openProgressModal();

        var stopBtn = document.getElementById('sywat_history_stop_btn');
        if (stopBtn) {
            stopBtn.classList.add('sywat-hidden');
        }

        updateProgress('Purging historical records for "' + (seasonName || seasonSlug) + '"...', 0, 1);
        showBanner('info', 'Purging historical records for "' + (seasonName || seasonSlug) + '"...', true);
        appendLog('info', 'Purging historical season "' + (seasonName || seasonSlug) + '"...');

        postAjax('sywat_history_purge_season', {
            season_slug: seasonSlug
        }).then(function (res) {
            state.seasons.forEach(function (s) {
                if (s.slug === seasonSlug) {
                    s.imported = false;
                    s.existing_events = 0;
                    s.local_status = 'new';
                }
            });

            if (Array.isArray(state.eventsBySeason[seasonSlug])) {
                state.eventsBySeason[seasonSlug].forEach(function (ev) {
                    ev.already_imported = false;
                    ev.archer_count = 0;
                });
            }

            updateProgress('Purge complete for "' + (seasonName || seasonSlug) + '"', 1, 1);
            renderSeasonsTable();
            showBanner('success', res.message || 'Historical season purged.', true);
            appendLog('success', (seasonName || seasonSlug) + ': ' + (res.message || 'Purged.'));
        }).catch(function (err) {
            showBanner('danger', err.message || 'Failed to purge historical season.', true);
            appendLog('error', err.message || 'Purge failed.');
        }).finally(function () {
            setControlsLocked(false);
        });
    }

    /**
     * Initialize client state from localized permanent manifests on page load.
     */
    function init() {
        var cfg = getConfig();
        state.seasons = cfg.savedSeasons;
        state.eventsBySeason = cfg.savedEvents;
        rebuildSeasonMaps();

        if (state.seasons.length > 0) {
            renderSeasonsTable();
        }
    }

    /**
     * Delegated click listener across #sywat_history_importer_app, #sywat_history_progress_modal,
     * and #sywat_history_events_modal (modals are relocated to <body> by sywat-modal-manager.js when active).
     */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest(
            '#sywat_history_importer_app [data-sywat-action],' +
            '#sywat_history_progress_modal [data-sywat-action],' +
            '#sywat_history_events_modal [data-sywat-action]'
        );
        if (!trigger) {
            return;
        }

        var action = trigger.getAttribute('data-sywat-action');

        if (action === 'discover-seasons') {
            event.preventDefault();
            discoverSeasons();
        } else if (action === 'open-history-modal') {
            event.preventDefault();
            openProgressModal();
        } else if (action === 'close-history-modal') {
            event.preventDefault();
            closeProgressModal();
        } else if (action === 'view-season-events') {
            event.preventDefault();
            var evSeasonId = parseInt(trigger.getAttribute('data-season-id'), 10) || 0;
            var evSeasonSlug = trigger.getAttribute('data-season-slug') || '';
            var evSeasonName = trigger.getAttribute('data-season-name') || evSeasonSlug;
            viewSeasonEvents(evSeasonId, evSeasonSlug, evSeasonName);
        } else if (action === 'close-events-modal') {
            event.preventDefault();
            closeEventsModal();
        } else if (action === 'refresh-season-events') {
            event.preventDefault();
            if (state.activeEventsSeason) {
                fetchSeasonEvents(
                    state.activeEventsSeason.season_id,
                    state.activeEventsSeason.season_slug,
                    state.activeEventsSeason.season_name,
                    true
                );
            }
        } else if (action === 'import-modal-season') {
            event.preventDefault();
            if (state.activeEventsSeason) {
                var targetSeason = state.seasonSlugMap[state.activeEventsSeason.season_slug]
                    || state.seasonMap[String(state.activeEventsSeason.season_id)];
                if (targetSeason) {
                    closeEventsModal();
                    runMigrationBatch([targetSeason]);
                }
            }
        } else if (action === 'import-single-event') {
            event.preventDefault();
            var singleEvId = parseInt(trigger.getAttribute('data-event-id'), 10) || 0;
            var singleEvSlug = trigger.getAttribute('data-season-slug') || (state.activeEventsSeason ? state.activeEventsSeason.season_slug : '');
            if (singleEvId > 0 && singleEvSlug) {
                importSingleEvent(singleEvSlug, singleEvId);
            }
        } else if (action === 'select-all') {
            event.preventDefault();
            document.querySelectorAll('.sywat-history-season-cb').forEach(function (cb) {
                cb.checked = true;
            });
            syncSelectionState();
        } else if (action === 'select-unimported') {
            event.preventDefault();
            document.querySelectorAll('.sywat-history-season-cb').forEach(function (cb) {
                var season = state.seasonMap[String(cb.value || '')];
                cb.checked = Boolean(season && !season.imported && (parseInt(season.existing_events, 10) || 0) === 0);
            });
            syncSelectionState();
        } else if (action === 'clear-selection') {
            event.preventDefault();
            document.querySelectorAll('.sywat-history-season-cb').forEach(function (cb) {
                cb.checked = false;
            });
            syncSelectionState();
        } else if (action === 'start-import') {
            event.preventDefault();
            var selected = getSelectedSeasons();
            if (selected.length > 0) {
                runMigrationBatch(selected);
            }
        } else if (action === 'import-single-season') {
            event.preventDefault();
            var sid = String(trigger.getAttribute('data-season-id') || '');
            var sSlug = String(trigger.getAttribute('data-season-slug') || '');
            var singleTarget = (sid && state.seasonMap[sid]) ? state.seasonMap[sid] : (sSlug ? state.seasonSlugMap[sSlug] : null);
            if (singleTarget) {
                runMigrationBatch([singleTarget]);
            }
        } else if (action === 'purge-season') {
            event.preventDefault();
            var slug = trigger.getAttribute('data-season-slug') || '';
            var name = trigger.getAttribute('data-season-name') || slug;
            purgeSeason(slug, name);
        } else if (action === 'stop-import') {
            event.preventDefault();
            if (state.isRunning) {
                state.stopRequested = true;
                trigger.disabled = true;
                showBanner('info', 'Stopping after the current tournament finishes...', true);
            }
        } else if (action === 'clear-log') {
            event.preventDefault();
            clearLog();
        }
    });

    /**
     * Delegated change listener for season selection checkboxes.
     */
    document.addEventListener('change', function (event) {
        var target = event.target.closest('#sywat_history_importer_app [data-sywat-action]');
        if (!target) {
            return;
        }

        var action = target.getAttribute('data-sywat-action');
        if (action === 'toggle-all-checkboxes') {
            var checked = Boolean(target.checked);
            document.querySelectorAll('.sywat-history-season-cb').forEach(function (cb) {
                cb.checked = checked;
            });
            syncSelectionState();
        } else if (action === 'toggle-season-checkbox') {
            syncSelectionState();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.SYWAT.historyImport = {
        discoverSeasons: discoverSeasons,
        viewSeasonEvents: viewSeasonEvents,
        openProgressModal: openProgressModal,
        closeProgressModal: closeProgressModal,
        openEventsModal: openEventsModal,
        closeEventsModal: closeEventsModal,
        runMigrationBatch: runMigrationBatch,
        purgeSeason: purgeSeason,
        clearLog: clearLog
    };
})();
