/**
 * Sub-Module: Historical TFAA Results Importer Client Controller
 * Path: assets/js/sywat-history-import.js
 * Description: Vanilla JS client controller for permanently persisted TFAA seasons and per-season
 *              tournament manifests, live import status tracking (#sywat_history_events_modal),
 *              chunked single-event and multi-season AJAX imports (#sywat_history_progress_modal),
 *              and archived season purging.
 * Compliance: Zero jQuery, namespaced under window.SYWAT.historyImport, delegated data-sywat-action
 *             listeners across document (survives modal relocation to <body>), Modal Markup Contract
 *             compliant (.active class + data-sywat-static="true" while running), zero element.style.*
 *             mutations except progress bar width.
 * Author: SYWAT Archery
 * Version: 1.2.1
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

    function getConfig() {
        var cfg = window.sywatHistoryData || {};
        return {
            ajaxUrl: cfg.ajaxUrl || window.ajaxurl || '',
            nonce: cfg.nonce || '',
            savedSeasons: Array.isArray(cfg.savedSeasons) ? cfg.savedSeasons : [],
            savedEvents: (cfg.savedEvents && typeof cfg.savedEvents === 'object') ? cfg.savedEvents : {}
        };
    }

    function escapeHtml(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getSeasonRowKey(season) {
        if (!season || typeof season !== 'object') {
            return '';
        }
        var sid = parseInt(season.season_id, 10) || 0;
        return sid > 0 ? String(sid) : String(season.slug || '');
    }

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
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
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
                    throw new Error((json && json.data && json.data.message) ? json.data.message : ('Request failed (HTTP ' + response.status + ').'));
                }
                return json.data || {};
            });
        });
    }

    function setModalOpen(modalId, isOpen) {
        if (!isOpen && modalId === 'sywat_history_progress_modal' && state.isRunning) {
            return;
        }
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.toggle('active', Boolean(isOpen));
        }
    }

    function setBanner(elementId, type, message) {
        var el = document.getElementById(elementId);
        if (!el) {
            return;
        }
        el.classList.remove('sywat-hidden', 'sywat-alert-success', 'sywat-alert-danger', 'sywat-alert-info');
        if (!type) {
            el.classList.add('sywat-hidden');
            return;
        }
        el.classList.add(type === 'success' ? 'sywat-alert-success' : (type === 'danger' ? 'sywat-alert-danger' : 'sywat-alert-info'));
        el.textContent = message;
    }

    function showBanner(type, message, syncModal) {
        setBanner('sywat_history_status_banner', type, message);
        if (syncModal) {
            setBanner('sywat_history_modal_banner', type, message);
        }
    }

    function appendLog(level, message) {
        var logList = document.getElementById('sywat_history_log_list');
        if (!logList) {
            return;
        }
        if (!state.hasLogEntries) {
            logList.innerHTML = '';
            state.hasLogEntries = true;
        }
        var stamp = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        var prefix = level === 'success' ? '[OK]' : (level === 'error' ? '[ERROR]' : '[INFO]');
        var line = document.createElement('div');
        line.textContent = stamp + ' ' + prefix + ' ' + message;
        logList.appendChild(line);
        logList.scrollTop = logList.scrollHeight;
    }

    function clearLog() {
        var logList = document.getElementById('sywat_history_log_list');
        if (logList) {
            logList.innerHTML = '<div>No migration activity recorded yet.</div>';
        }
        if (!state.isRunning) {
            setBanner('sywat_history_modal_banner', '', '');
        }
        state.hasLogEntries = false;
    }

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
            fillEl.style.width = (total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0) + '%';
        }
    }

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
            stopBtn.classList.toggle('sywat-hidden', !running);
        }
        if (discoverBtn) {
            discoverBtn.disabled = running;
        }
        if (checkAll) {
            checkAll.disabled = running;
        }

        document.querySelectorAll(
            '#sywat_history_importer_app [data-sywat-action="select-unimported"],' +
            '#sywat_history_importer_app [data-sywat-action="select-all"],' +
            '#sywat_history_importer_app [data-sywat-action="clear-selection"],' +
            '#sywat_history_importer_app [data-sywat-action="import-single-season"],' +
            '#sywat_history_importer_app [data-sywat-action="purge-season"],' +
            '#sywat_history_events_modal [data-sywat-action="refresh-season-events"],' +
            '#sywat_history_events_modal [data-sywat-action="import-modal-season"],' +
            '#sywat_history_events_modal [data-sywat-action="import-single-event"],' +
            '.sywat-history-season-cb'
        ).forEach(function (el) {
            el.disabled = running;
        });

        if (runBtn) {
            runBtn.disabled = running || getSelectedSeasons().length === 0;
        }
    }

    function syncSelectionState() {
        var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.sywat-history-season-cb'));
        var checkedCount = checkboxes.filter(function (cb) { return cb.checked; }).length;

        var runBtn = document.getElementById('sywat_history_run_btn');
        if (runBtn) {
            runBtn.disabled = state.isRunning || checkedCount === 0;
            runBtn.lastChild.textContent = ' Import Selected Seasons' + (checkedCount > 0 ? ' (' + checkedCount + ')' : '');
        }

        var checkAll = document.getElementById('sywat_history_check_all');
        if (checkAll) {
            checkAll.checked = checkboxes.length > 0 && checkedCount === checkboxes.length;
            checkAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }
    }

    function getSelectedSeasons() {
        var selected = [];
        document.querySelectorAll('.sywat-history-season-cb:checked').forEach(function (cb) {
            var season = state.seasonMap[String(cb.value || '')];
            if (season) {
                selected.push(season);
            }
        });
        return selected;
    }

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

        tbody.innerHTML = state.seasons.map(function (s) {
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

            var countBadge = discoveredCount > 0
                ? ('<strong>' + existingCount + '</strong> / ' + discoveredCount)
                : (existingCount > 0 ? ('<strong>' + existingCount + '</strong>') : '<span class="sywat-label-subtext">0</span>');

            var purgeBtn = isImported
                ? ' <button type="button" class="sywat-btn sywat-btn-danger" data-sywat-action="purge-season" data-season-slug="' + slug + '" data-season-name="' + name + '">Purge</button>'
                : '';

            return (
                '<tr data-season-row-id="' + rowKey + '" data-season-slug="' + slug + '">' +
                    '<td class="sywat-col-center"><input type="checkbox" class="sywat-history-season-cb" data-sywat-action="toggle-season-checkbox" value="' + rowKey + '" aria-label="Select ' + name + '"></td>' +
                    '<td><strong>' + name + '</strong></td>' +
                    '<td class="sywat-col-center"><span class="sywat-badge">' + type + '</span></td>' +
                    '<td class="sywat-col-center sywat-text-muted-sm">' + windowText + '</td>' +
                    '<td class="sywat-col-center" data-role="status-cell">' + statusBadge + '</td>' +
                    '<td class="sywat-col-center" data-role="count-cell">' + countBadge + '</td>' +
                    '<td class="sywat-col-right"><div class="sywat-table-actions">' +
                        '<button type="button" class="sywat-btn sywat-btn-secondary" data-sywat-action="view-season-events" data-season-id="' + sid + '" data-season-slug="' + slug + '" data-season-name="' + name + '">Tournaments</button>' +
                        '<button type="button" class="sywat-btn sywat-btn-primary" data-sywat-action="import-single-season" data-season-id="' + rowKey + '" data-season-slug="' + slug + '">Import</button>' +
                        purgeBtn +
                    '</div></td>' +
                '</tr>'
            );
        }).join('');

        syncSelectionState();
    }

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

        tbody.innerHTML = events.map(function (ev) {
            var eid = parseInt(ev.event_id, 10) || 0;
            var isImported = Boolean(ev.already_imported);
            var archerCount = parseInt(ev.archer_count, 10) || 0;
            return (
                '<tr data-event-id="' + eid + '">' +
                    '<td class="sywat-text-muted-sm">' + escapeHtml(ev.start_date || '—') + '</td>' +
                    '<td><strong>' + escapeHtml(ev.title || '') + '</strong></td>' +
                    '<td class="sywat-col-center"><span class="sywat-badge">' + escapeHtml(ev.event_type || 'SYWAT') + '</span></td>' +
                    '<td class="sywat-col-center">' + (isImported ? '<span class="sywat-badge sywat-badge-open">Imported</span>' : '<span class="sywat-badge sywat-badge-prereg">Not Imported</span>') + '</td>' +
                    '<td class="sywat-col-center">' + (isImported ? ('<strong>' + archerCount + '</strong>') : '<span class="sywat-label-subtext">—</span>') + '</td>' +
                    '<td class="sywat-col-right"><button type="button" class="' + (isImported ? 'sywat-btn sywat-btn-secondary' : 'sywat-btn sywat-btn-primary') + '" data-sywat-action="import-single-event" data-event-id="' + eid + '" data-season-slug="' + escapeHtml(seasonSlug) + '">' + (isImported ? 'Re-Import' : 'Import') + '</button></td>' +
                '</tr>'
            );
        }).join('');
    }

    function fetchSeasonEvents(seasonId, seasonSlug, seasonName, forceRefresh) {
        var tbody = document.getElementById('sywat_history_events_tbody');
        var refreshBtn = document.getElementById('sywat_history_refresh_events_btn');

        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="sywat-col-center">Loading discovered tournaments for ' + escapeHtml(seasonName || seasonSlug) + '...</td></tr>';
        }
        if (refreshBtn) {
            refreshBtn.disabled = true;
        }
        setBanner('sywat_history_events_banner', 'info', forceRefresh ? 'Refreshing tournament list from texasfieldarchery.org...' : 'Loading discovered tournaments...');

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
                var importedCount = list.filter(function (ev) { return Boolean(ev.already_imported); }).length;
                state.seasonSlugMap[slug].discovered_events = list.length;
                state.seasonSlugMap[slug].existing_events = importedCount;
                state.seasonSlugMap[slug].imported = importedCount > 0;
                renderSeasonsTable();
            }

            renderSeasonEventsTable(slug);
            setBanner('sywat_history_events_banner', 'success', 'Loaded ' + list.length + ' discovered tournament(s) for ' + (seasonName || slug) + '.');
        }).catch(function (err) {
            setBanner('sywat_history_events_banner', 'danger', err.message || 'Failed to load season tournaments.');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="6" class="sywat-col-center">Unable to load tournaments for this season.</td></tr>';
            }
        }).finally(function () {
            if (refreshBtn) {
                refreshBtn.disabled = state.isRunning;
            }
        });
    }

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

        setBanner('sywat_history_events_banner', '', '');
        setModalOpen('sywat_history_events_modal', true);

        var cached = state.eventsBySeason[state.activeEventsSeason.season_slug];
        if (Array.isArray(cached) && cached.length > 0) {
            renderSeasonEventsTable(state.activeEventsSeason.season_slug);
            var importedCount = cached.filter(function (ev) { return Boolean(ev.already_imported); }).length;
            setBanner('sywat_history_events_banner', 'info', importedCount + ' of ' + cached.length + ' discovered tournament(s) imported.');
        } else {
            fetchSeasonEvents(state.activeEventsSeason.season_id, state.activeEventsSeason.season_slug, state.activeEventsSeason.season_name, false);
        }
    }

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
                state.seasons = Array.isArray(data.seasons) ? data.seasons : [];
                rebuildSeasonMaps();
                renderSeasonsTable();
                showBanner('success', 'Discovered and permanently saved ' + state.seasons.length + ' historical season(s) from TFAA. Click "Tournaments" on any season to view its events or "Import" to migrate.', false);
                appendLog('success', 'Discovered and saved ' + state.seasons.length + ' TFAA season(s).');
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
     * Sequentially import an array of normalized event descriptors (1 event per AJAX tick).
     */
    function runEventQueue(eventQueue, priorErrors) {
        var totalImportedEvents = 0;
        var totalImportedArchers = 0;
        var totalErrors = priorErrors || 0;

        if (eventQueue.length === 0) {
            appendLog('info', 'No tournaments queued for import.');
            return Promise.resolve({ events: 0, archers: 0, errors: totalErrors });
        }

        updateProgress('Importing historical tournaments...', 0, eventQueue.length);

        var chain = Promise.resolve();
        eventQueue.forEach(function (ev, idx) {
            chain = chain.then(function () {
                if (state.stopRequested) {
                    return;
                }
                updateProgress('Importing: ' + ev.title, idx, eventQueue.length);

                return postAjax('sywat_history_import_tournament', {
                    event_id: ev.event_id,
                    raw_title: ev.raw_title || ev.title,
                    title: ev.title,
                    season_id: ev.season_id || 0,
                    season_name: ev.season_name || ev.season_slug,
                    season_slug: ev.season_slug,
                    start_date: ev.start_date || '',
                    end_date: ev.end_date || ev.start_date || '',
                    event_type: ev.event_type || 'SYWAT'
                }).then(function (res) {
                    totalImportedEvents++;
                    var archers = parseInt(res.archer_count, 10) || 0;
                    totalImportedArchers += archers;

                    var sSlug = String(res.season_slug || ev.season_slug || '');
                    if (sSlug && Array.isArray(res.events)) {
                        state.eventsBySeason[sSlug] = res.events;
                    }
                    var seasonObj = state.seasonSlugMap[sSlug] || state.seasonMap[String(ev._rowKey || '')];
                    if (seasonObj) {
                        var evList = state.eventsBySeason[sSlug] || [];
                        var impCnt = evList.filter(function (item) { return Boolean(item.already_imported); }).length;
                        seasonObj.imported = true;
                        seasonObj.existing_events = impCnt > 0 ? impCnt : (parseInt(seasonObj.existing_events, 10) || 0) + 1;
                        if (evList.length > 0) {
                            seasonObj.discovered_events = evList.length;
                        }
                        seasonObj.local_status = 'archived';
                    }

                    appendLog('success', 'Imported "' + (res.title || ev.title) + '" (' + archers + ' archer scorecards).');
                    updateProgress('Imported ' + (idx + 1) + ' of ' + eventQueue.length + ' tournaments', idx + 1, eventQueue.length);
                }).catch(function (err) {
                    totalErrors++;
                    appendLog('error', 'Error importing "' + ev.title + '": ' + (err.message || 'Unknown error'));
                    updateProgress('Processed ' + (idx + 1) + ' of ' + eventQueue.length + ' tournaments', idx + 1, eventQueue.length);
                });
            });
        });

        return chain.then(function () {
            return { events: totalImportedEvents, archers: totalImportedArchers, errors: totalErrors };
        });
    }

    function finishBatch(summary) {
        setControlsLocked(false);
        renderSeasonsTable();

        if (state.stopRequested) {
            showBanner('info', 'Migration stopped by user. Imported ' + summary.events + ' tournament(s) and ' + summary.archers + ' archer scorecards before stopping.', true);
            appendLog('info', 'Batch stopped cleanly after current event.');
        } else if (summary.errors > 0) {
            showBanner('danger', 'Migration finished with ' + summary.errors + ' error(s). Imported ' + summary.events + ' tournament(s) and ' + summary.archers + ' archer scorecards. Review the log below.', true);
        } else {
            showBanner('success', 'Migration complete! Imported ' + summary.events + ' tournament(s) and ' + summary.archers + ' independent archer scorecards.', true);
        }
    }

    function runMigrationBatch(targetSeasons) {
        if (state.isRunning || !Array.isArray(targetSeasons) || targetSeasons.length === 0) {
            return;
        }

        setBanner('sywat_history_status_banner', '', '');
        setBanner('sywat_history_modal_banner', '', '');
        state.stopRequested = false;
        setControlsLocked(true);
        setModalOpen('sywat_history_progress_modal', true);

        var eventQueue = [];
        var scanErrors = 0;

        updateProgress('Scanning selected season schedules...', 0, targetSeasons.length);
        appendLog('info', 'Starting migration for ' + targetSeasons.length + ' season(s)...');

        var scanChain = Promise.resolve();
        targetSeasons.forEach(function (season, idx) {
            scanChain = scanChain.then(function () {
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
                    scanErrors++;
                    appendLog('error', 'Failed scanning "' + season.name + '": ' + (err.message || 'Unknown error'));
                });
            });
        });

        scanChain
            .then(function () {
                return state.stopRequested ? { events: 0, archers: 0, errors: scanErrors } : runEventQueue(eventQueue, scanErrors);
            })
            .then(finishBatch);
    }

    function importSingleEvent(seasonSlug, eventId) {
        if (state.isRunning) {
            return;
        }
        var events = Array.isArray(state.eventsBySeason[seasonSlug]) ? state.eventsBySeason[seasonSlug] : [];
        var targetEv = events.filter(function (ev) { return parseInt(ev.event_id, 10) === parseInt(eventId, 10); })[0];
        if (!targetEv) {
            return;
        }

        var seasonObj = state.seasonSlugMap[seasonSlug] || {};
        var queuedEv = Object.assign({}, targetEv, {
            season_id: seasonObj.season_id || targetEv.season_id || 0,
            season_slug: seasonSlug,
            season_name: seasonObj.name || targetEv.season_name || seasonSlug,
            _rowKey: getSeasonRowKey(seasonObj)
        });

        setModalOpen('sywat_history_events_modal', false);
        setBanner('sywat_history_status_banner', '', '');
        setBanner('sywat_history_modal_banner', '', '');
        state.stopRequested = false;
        setControlsLocked(true);
        setModalOpen('sywat_history_progress_modal', true);
        appendLog('info', 'Importing single tournament "' + queuedEv.title + '"...');

        runEventQueue([queuedEv], 0).then(finishBatch);
    }

    function purgeSeason(seasonSlug, seasonName) {
        if (state.isRunning || !seasonSlug) {
            return;
        }
        if (!window.confirm('Purge all imported historical tournaments and scorecards for "' + (seasonName || seasonSlug) + '"?\n\nYour discovered season and tournament manifest will remain saved so you can re-import anytime. Live active seasons and Member Directory accounts are unaffected.')) {
            return;
        }

        setBanner('sywat_history_status_banner', '', '');
        setControlsLocked(true);
        setModalOpen('sywat_history_progress_modal', true);

        var stopBtn = document.getElementById('sywat_history_stop_btn');
        if (stopBtn) {
            stopBtn.classList.add('sywat-hidden');
        }

        updateProgress('Purging historical records for "' + (seasonName || seasonSlug) + '"...', 0, 1);
        showBanner('info', 'Purging historical records for "' + (seasonName || seasonSlug) + '"...', true);
        appendLog('info', 'Purging historical season "' + (seasonName || seasonSlug) + '"...');

        postAjax('sywat_history_purge_season', { season_slug: seasonSlug })
            .then(function (res) {
                if (state.seasonSlugMap[seasonSlug]) {
                    state.seasonSlugMap[seasonSlug].imported = false;
                    state.seasonSlugMap[seasonSlug].existing_events = 0;
                    state.seasonSlugMap[seasonSlug].local_status = 'new';
                }
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
            })
            .catch(function (err) {
                showBanner('danger', err.message || 'Failed to purge historical season.', true);
                appendLog('error', err.message || 'Purge failed.');
            })
            .finally(function () {
                setControlsLocked(false);
            });
    }

    function init() {
        var cfg = getConfig();
        state.seasons = cfg.savedSeasons;
        state.eventsBySeason = cfg.savedEvents;
        rebuildSeasonMaps();
        if (state.seasons.length > 0) {
            renderSeasonsTable();
        }
    }

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
            setModalOpen('sywat_history_progress_modal', true);
        } else if (action === 'close-history-modal') {
            event.preventDefault();
            setModalOpen('sywat_history_progress_modal', false);
        } else if (action === 'view-season-events') {
            event.preventDefault();
            viewSeasonEvents(
                parseInt(trigger.getAttribute('data-season-id'), 10) || 0,
                trigger.getAttribute('data-season-slug') || '',
                trigger.getAttribute('data-season-name') || ''
            );
        } else if (action === 'close-events-modal') {
            event.preventDefault();
            setModalOpen('sywat_history_events_modal', false);
        } else if (action === 'refresh-season-events') {
            event.preventDefault();
            if (state.activeEventsSeason) {
                fetchSeasonEvents(state.activeEventsSeason.season_id, state.activeEventsSeason.season_slug, state.activeEventsSeason.season_name, true);
            }
        } else if (action === 'import-modal-season') {
            event.preventDefault();
            if (state.activeEventsSeason) {
                var modalSeason = state.seasonSlugMap[state.activeEventsSeason.season_slug] || state.seasonMap[String(state.activeEventsSeason.season_id)];
                if (modalSeason) {
                    setModalOpen('sywat_history_events_modal', false);
                    runMigrationBatch([modalSeason]);
                }
            }
        } else if (action === 'import-single-event') {
            event.preventDefault();
            var singleEvId = parseInt(trigger.getAttribute('data-event-id'), 10) || 0;
            var singleEvSlug = trigger.getAttribute('data-season-slug') || (state.activeEventsSeason ? state.activeEventsSeason.season_slug : '');
            if (singleEvId > 0 && singleEvSlug) {
                importSingleEvent(singleEvSlug, singleEvId);
            }
        } else if (action === 'select-all' || action === 'select-unimported' || action === 'clear-selection') {
            event.preventDefault();
            document.querySelectorAll('.sywat-history-season-cb').forEach(function (cb) {
                if (action === 'select-all') {
                    cb.checked = true;
                } else if (action === 'clear-selection') {
                    cb.checked = false;
                } else {
                    var s = state.seasonMap[String(cb.value || '')];
                    cb.checked = Boolean(s && !s.imported && (parseInt(s.existing_events, 10) || 0) === 0);
                }
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
            var target = (sid && state.seasonMap[sid]) ? state.seasonMap[sid] : (sSlug ? state.seasonSlugMap[sSlug] : null);
            if (target) {
                runMigrationBatch([target]);
            }
        } else if (action === 'purge-season') {
            event.preventDefault();
            purgeSeason(trigger.getAttribute('data-season-slug') || '', trigger.getAttribute('data-season-name') || '');
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

    document.addEventListener('change', function (event) {
        var target = event.target.closest('#sywat_history_importer_app [data-sywat-action]');
        if (!target) {
            return;
        }
        var action = target.getAttribute('data-sywat-action');
        if (action === 'toggle-all-checkboxes') {
            var checked = Boolean(target.checked);
            document.querySelectorAll('.sywat-history-season-cb').forEach(function (cb) { cb.checked = checked; });
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
        openProgressModal: function () { setModalOpen('sywat_history_progress_modal', true); },
        closeProgressModal: function () { setModalOpen('sywat_history_progress_modal', false); },
        openEventsModal: function () { setModalOpen('sywat_history_events_modal', true); },
        closeEventsModal: function () { setModalOpen('sywat_history_events_modal', false); },
        runMigrationBatch: runMigrationBatch,
        purgeSeason: purgeSeason,
        clearLog: clearLog
    };
})();
