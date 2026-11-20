/**
 * Sub-Module: Historical TFAA Results Importer Client Controller
 * Path: assets/js/sywat-history-import.js
 * Description: Vanilla JS chunked queue controller for discovering historical TFAA seasons,
 *              scanning per-season event manifests, importing 1 tournament scorecard per AJAX
 *              request with live progress tracking and activity logging inside #sywat_history_progress_modal,
 *              and purging imported archived seasons.
 * Compliance: Zero jQuery, namespaced under window.SYWAT.historyImport, delegated data-sywat-action
 *             listeners across document (supports modal relocation to <body>), Modal Markup Contract
 *             compliant (.active class + data-sywat-static="true" while running), zero element.style.*
 *             mutations except progress bar width.
 * Author: SYWAT Archery
 * Version: 1.1.0
 */

(function () {
    'use strict';

    window.SYWAT = window.SYWAT || {};

    var state = {
        seasons: [],
        seasonMap: {},
        isRunning: false,
        stopRequested: false,
        hasLogEntries: false
    };

    /**
     * Retrieve localized AJAX URL and security nonce safely.
     *
     * @return {Object} Object with ajaxUrl and nonce strings.
     */
    function getConfig() {
        var cfg = window.sywatHistoryData || {};
        return {
            ajaxUrl: cfg.ajaxUrl || window.ajaxurl || '',
            nonce: cfg.nonce || ''
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
     * Hide status alert banners.
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
            '#sywat_history_importer_app [data-sywat-action="purge-season"]'
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
            var id = String(cb.value || '');
            if (state.seasonMap[id]) {
                selected.push(state.seasonMap[id]);
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
            tbody.innerHTML = '<tr><td colspan="7" class="sywat-col-center">No historical seasons found.</td></tr>';
            syncSelectionState();
            return;
        }

        var rowsHtml = state.seasons.map(function (s) {
            var sid = escapeHtml(s.season_id);
            var slug = escapeHtml(s.slug);
            var name = escapeHtml(s.name);
            var type = escapeHtml(s.type || 'Indoor');
            var windowText = escapeHtml((s.start_date || '—') + ' to ' + (s.end_date || '—'));
            var existingCount = parseInt(s.existing_events, 10) || 0;
            var isImported = Boolean(s.imported) || existingCount > 0;

            var statusBadge = isImported
                ? '<span class="sywat-badge sywat-badge-open">Imported (' + escapeHtml(s.local_status || 'archived') + ')</span>'
                : '<span class="sywat-badge sywat-badge-prereg">Not Imported</span>';

            var countBadge = existingCount > 0
                ? '<strong>' + existingCount + '</strong>'
                : '<span class="sywat-label-subtext">0</span>';

            var purgeBtnHtml = isImported
                ? ' <button type="button" class="sywat-btn sywat-btn-danger" data-sywat-action="purge-season" data-season-slug="' + slug + '" data-season-name="' + name + '">Purge</button>'
                : '';

            return (
                '<tr data-season-row-id="' + sid + '">' +
                    '<td class="sywat-col-center">' +
                        '<input type="checkbox" class="sywat-history-season-cb" data-sywat-action="toggle-season-checkbox" value="' + sid + '" aria-label="Select ' + name + '">' +
                    '</td>' +
                    '<td><strong>' + name + '</strong></td>' +
                    '<td class="sywat-col-center"><span class="sywat-badge">' + type + '</span></td>' +
                    '<td class="sywat-col-center sywat-text-muted-sm">' + windowText + '</td>' +
                    '<td class="sywat-col-center" data-role="status-cell">' + statusBadge + '</td>' +
                    '<td class="sywat-col-center" data-role="count-cell">' + countBadge + '</td>' +
                    '<td class="sywat-col-right">' +
                        '<div class="sywat-table-actions">' +
                            '<button type="button" class="sywat-btn sywat-btn-primary" data-sywat-action="import-single-season" data-season-id="' + sid + '">Import</button>' +
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
     * Fetch the available historical seasons list from texasfieldarchery.org.
     */
    function discoverSeasons() {
        if (state.isRunning) {
            return;
        }

        var discoverBtn = document.getElementById('sywat_history_discover_btn');
        if (discoverBtn) {
            discoverBtn.disabled = true;
        }

        showBanner('info', 'Connecting to texasfieldarchery.org to discover available seasons...', false);

        postAjax('sywat_history_discover_seasons', {})
            .then(function (data) {
                var list = Array.isArray(data.seasons) ? data.seasons : [];
                state.seasons = list;
                state.seasonMap = {};

                list.forEach(function (item) {
                    state.seasonMap[String(item.season_id)] = item;
                });

                renderSeasonsTable();
                showBanner('success', 'Discovered ' + list.length + ' historical season(s) from TFAA. Select one season to test or select multiple seasons to batch import.', false);
                appendLog('success', 'Discovered ' + list.length + ' TFAA season(s).');
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
     * Execute the two-stage chunked AJAX migration for an array of season objects inside #sywat_history_progress_modal:
     * Stage 1: Scan each selected season manifest (/results/season/{id})
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
                    seasonCounts[String(season.season_id)] = 0;

                    events.forEach(function (ev) {
                        ev.season_id = season.season_id;
                        ev.season_slug = savedSeason.slug || season.slug;
                        ev.season_name = savedSeason.name || season.name;
                        eventQueue.push(ev);
                    });

                    appendLog('info', 'Scanned "' + season.name + '": ' + events.length + ' tournament(s) queued.');
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

                        var sidKey = String(ev.season_id);
                        seasonCounts[sidKey] = (seasonCounts[sidKey] || 0) + 1;
                        if (state.seasonMap[sidKey]) {
                            state.seasonMap[sidKey].imported = true;
                            state.seasonMap[sidKey].existing_events = seasonCounts[sidKey];
                            state.seasonMap[sidKey].local_status = 'archived';
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
     * Purge all imported historical tournaments and score rows for a season.
     *
     * @param {string} seasonSlug Target season slug.
     * @param {string} seasonName Human-readable season name.
     */
    function purgeSeason(seasonSlug, seasonName) {
        if (state.isRunning || !seasonSlug) {
            return;
        }

        var confirmed = window.confirm(
            'Purge all imported historical tournaments and scorecards for "' + (seasonName || seasonSlug) + '"?\n\nThis will not affect live active seasons or Member Directory accounts.'
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
     * Delegated click listener across both #sywat_history_importer_app and #sywat_history_progress_modal
     * (modal is relocated to <body> by sywat-modal-manager.js when active).
     */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('#sywat_history_importer_app [data-sywat-action], #sywat_history_progress_modal [data-sywat-action]');
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
            if (sid && state.seasonMap[sid]) {
                runMigrationBatch([state.seasonMap[sid]]);
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

    window.SYWAT.historyImport = {
        discoverSeasons: discoverSeasons,
        openProgressModal: openProgressModal,
        closeProgressModal: closeProgressModal,
        runMigrationBatch: runMigrationBatch,
        purgeSeason: purgeSeason,
        clearLog: clearLog
    };
})();
