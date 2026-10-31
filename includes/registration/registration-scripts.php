<?php
/**
 * Sub-Module: Registration Engine Client Scripts
 * Path: includes/registration/registration-scripts.php
 * Author: SYWAT Archery
 * Version: 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_registration_scripts' ) ) {
    function sywat_render_registration_scripts() {
        $tfaa_cache = get_option( 'sywat_tfaa_cache', array() );
        $tfaa_shooters = isset( $tfaa_cache['shooters'] ) && is_array( $tfaa_cache['shooters'] ) 
            ? $tfaa_cache['shooters'] 
            : ( is_array( $tfaa_cache ) ? $tfaa_cache : array() );

        $js_tfaa_data = array();
        foreach ( $tfaa_shooters as $s ) {
            $name = isset( $s['name'] ) ? $s['name'] : ( isset( $s['full_name'] ) ? $s['full_name'] : '' );
            if ( ! empty( $name ) ) {
                $js_tfaa_data[] = array(
                    'name'     => $name,
                    'division' => isset( $s['division'] ) ? $s['division'] : '',
                    'gender'   => isset( $s['gender'] ) ? $s['gender'] : '',
                    'style'    => isset( $s['style'] ) ? $s['style'] : '',
                );
            }
        }
        ?>
        <script>
        const sywatTfaaShooters = <?php echo json_encode( array_values($js_tfaa_data) ); ?>;
        let archerRowCount = 1;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function matchTfaaDivision(tfaaDiv, selectEl) {
            if (!tfaaDiv || !selectEl) return;
            const cleanTfaa = tfaaDiv.trim().toLowerCase();

            for (let opt of selectEl.options) {
                if (opt.value && opt.value.trim().toLowerCase() === cleanTfaa) {
                    selectEl.value = opt.value;
                    selectEl.dataset.userChosen = 'true';
                    return;
                }
            }

            for (let opt of selectEl.options) {
                if (!opt.value) continue;
                const optLower = opt.value.trim().toLowerCase();
                if (optLower.includes(cleanTfaa) || cleanTfaa.includes(optLower.split(' ')[0])) {
                    selectEl.value = opt.value;
                    selectEl.dataset.userChosen = 'true';
                    return;
                }
            }
        }

        function handleTfaaAutocomplete(input) {
            const query = input.value.trim().toLowerCase();
            closeAllTfaaAutocompletes();

            if (query.length < 2 || !Array.isArray(sywatTfaaShooters) || sywatTfaaShooters.length === 0) {
                return;
            }

            const matches = sywatTfaaShooters.filter(s => s.name && s.name.toLowerCase().includes(query)).slice(0, 8);
            if (matches.length === 0) return;

            const parent = input.parentElement;
            if (getComputedStyle(parent).position === 'static') {
                parent.style.position = 'relative';
            }

            const dropdown = document.createElement('div');
            dropdown.className = 'sywat-tfaa-autocomplete-dropdown';
            dropdown.style.cssText = 'position:absolute; top:100%; left:0; width:100%; min-width:220px; z-index:99999; background:#fff; border:1px solid #94a3b8; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.15); max-height:180px; overflow-y:auto; margin-top:2px;';

            matches.forEach(m => {
                const item = document.createElement('div');
                item.style.cssText = 'padding:6px 10px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:12px; display:flex; justify-content:space-between; align-items:center; color:#1e293b;';
                item.innerHTML = `<strong>${escapeHtml(m.name)}</strong> <span style="font-size:11px; color:#0284c7; background:#e0f2fe; padding:1px 6px; border-radius:3px;">${escapeHtml(m.division || '')}</span>`;

                item.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    input.value = m.name;
                    closeAllTfaaAutocompletes();

                    const row = input.closest('.sywat-archer-input-row');
                    if (row) {
                        const divSelect = row.querySelector('.sywat-division-select');
                        if (divSelect && m.division) {
                            matchTfaaDivision(m.division, divSelect);
                        }
                    }
                    recalculateGroupTotal();
                });

                dropdown.appendChild(item);
            });

            parent.appendChild(dropdown);
        }

        function closeAllTfaaAutocompletes() {
            document.querySelectorAll('.sywat-tfaa-autocomplete-dropdown').forEach(el => el.remove());
        }

        function formatPhoneNumber(input) {
            let digits = input.value.replace(/\D/g, '');
            if (digits.length > 10) digits = digits.substring(0, 10);

            if (digits.length > 6) {
                input.value = digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6);
            } else if (digits.length > 3) {
                input.value = digits.substring(0, 3) + '-' + digits.substring(3);
            } else {
                input.value = digits;
            }
        }

        function updateAllArcherLines(selectedLineIdx) {
            if (selectedLineIdx !== undefined && selectedLineIdx !== null && selectedLineIdx !== '') {
                sessionStorage.setItem('sywat_last_line_idx', selectedLineIdx);
            }
            const lineSelects = document.querySelectorAll('.sywat-line-override-select');
            lineSelects.forEach(select => {
                select.value = selectedLineIdx;
            });
            recalculateGroupTotal();
        }

        function handleDistanceChange(distSelect) {
            const row = distSelect.closest('.sywat-archer-input-row');
            if (!row) return;
            const divSelect = row.querySelector('.sywat-division-select');
            if (!divSelect) return;

            if (distSelect.value === 'Cub') {
                divSelect.value = 'Cub (8 - 11)';
            } else if (distSelect.value === '20yd') {
                divSelect.value = '';
            }
            recalculateGroupTotal();
        }

        function addArcherRow() {
            const container = document.getElementById('sywat_archer_rows_container');
            if (!container) return;

            const savedLineIdx = sessionStorage.getItem('sywat_last_line_idx');
            const defaultLineIdx = savedLineIdx !== null 
                ? savedLineIdx 
                : (document.getElementById('sywat_default_line_idx')?.value || '0');

            const rowDiv = document.createElement('div');
            rowDiv.className = 'sywat-archer-input-row';
            rowDiv.style.cssText = 'display:flex; gap:10px; align-items:center; margin-bottom:8px;';

            let lineOptionsHtml = '';
            const defaultLineSelect = document.getElementById('sywat_default_line_idx');
            if (defaultLineSelect) {
                Array.from(defaultLineSelect.options).forEach(opt => {
                    const isSel = (opt.value === defaultLineIdx) ? 'selected' : '';
                    lineOptionsHtml += `<option value="${opt.value}" ${isSel}>${opt.text}</option>`;
                });
            }

            rowDiv.innerHTML = `
                <input type="text" name="archers[${archerRowCount}][name]" class="sywat-archer-name-input" placeholder="Archer Full Name *" autocomplete="off" style="flex:2; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" required oninput="handleTfaaAutocomplete(this); recalculateGroupTotal();">
                
                <select name="archers[${archerRowCount}][distance]" class="sywat-distance-select" style="flex:1; min-width:100px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="handleDistanceChange(this)">
                    <option value="20yd" selected>20yd</option>
                    <option value="Cub">Cub (10yd)</option>
                </select>

                <select name="archers[${archerRowCount}][division]" class="sywat-division-select" style="flex:1.5; min-width:160px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="this.dataset.userChosen='true'; recalculateGroupTotal();">
                    <option value="" disabled selected hidden>Select Division</option>
                    <option value="Cub (8 - 11)">Cub (8 - 11) [$25]</option>
                    <option value="Youth (12 - 14)">Youth (12 - 14) [$25]</option>
                    <option value="Young Adult (15 - 17)">Young Adult (15 - 17) [$25]</option>
                    <option value="Adult (18 - 49)">Adult (18 - 49) [$30]</option>
                    <option value="Senior (50 - 59)">Senior (50 - 59) [$30]</option>
                    <option value="Silver Senior (60 - 69)">Silver Senior (60 - 69) [$30]</option>
                    <option value="Master Senior (70+) ">Master Senior (70+) [$30]</option>
                </select>

                <select name="archers[${archerRowCount}][line_idx]" class="sywat-line-override-select" style="flex:1.2; min-width:130px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="recalculateGroupTotal()">
                    ${lineOptionsHtml}
                </select>

                <button type="button" class="sywat-btn sywat-btn-danger sywat-btn-icon-clear" style="height:32px; padding:2px 8px; font-size:12px;" onclick="removeArcherRow(this)" title="Remove Archer"></button>
            `;

            container.appendChild(rowDiv);
            archerRowCount++;
            recalculateGroupTotal();
        }

        function removeArcherRow(btn) {
            const rows = document.querySelectorAll('.sywat-archer-input-row');
            if (rows.length <= 1) {
                alert('At least one archer row is required.');
                return;
            }
            btn.closest('.sywat-archer-input-row').remove();
            recalculateGroupTotal();
        }

        function recalculateGroupTotal() {
            const rows = document.querySelectorAll('.sywat-archer-input-row');
            const youthDivisions = ['Cub (8 - 11)', 'Youth (12 - 14)', 'Young Adult (15 - 17)'];

            const lineMap = {};

            rows.forEach(row => {
                const nameInput  = row.querySelector('input[type="text"]');
                const distSelect = row.querySelector('.sywat-distance-select');
                const divSelect  = row.querySelector('.sywat-division-select');
                const lineSelect = row.querySelector('.sywat-line-override-select');

                if (nameInput && nameInput.value.trim() !== '') {
                    const lIdx = lineSelect ? lineSelect.value : '0';
                    const dist = distSelect ? distSelect.value : '20yd';
                    const div  = divSelect ? divSelect.value : '';

                    if (!lineMap[lIdx]) {
                        lineMap[lIdx] = { count: 0, rawTotal: 0 };
                    }

                    lineMap[lIdx].count++;
                    if (dist === 'Cub' || youthDivisions.includes(div)) {
                        lineMap[lIdx].rawTotal += 25;
                    } else {
                        lineMap[lIdx].rawTotal += 30;
                    }
                }
            });

            let overallTotal = 0;
            let anyCapped = false;
            let cappedLinesCount = 0;

            Object.keys(lineMap).forEach(lIdx => {
                const grp = lineMap[lIdx];
                const isCapped = (grp.rawTotal >= 75 || grp.count >= 3);
                if (isCapped) {
                    anyCapped = true;
                    cappedLinesCount++;
                    overallTotal += 75.00;
                } else {
                    overallTotal += grp.rawTotal;
                }
            });

            const totalDisp = document.getElementById('sywat_group_total_disp');
            const capBadge  = document.getElementById('sywat_family_cap_badge');
            const paidInput = document.getElementById('sywat_amount_paid_today');
            const dueDisp   = document.getElementById('sywat_balance_due_disp');

            if (totalDisp) totalDisp.textContent = '$' + overallTotal.toFixed(2);
            if (capBadge) {
                capBadge.style.display = anyCapped ? 'inline-block' : 'none';
                if (anyCapped) {
                    capBadge.textContent = '🟢 Family Cap Applied (' + cappedLinesCount + ' Line' + (cappedLinesCount > 1 ? 's' : '') + ')';
                }
            }

            const paidVal = paidInput ? parseFloat(paidInput.value) || 0 : 0;
            const balanceDue = Math.max(0, overallTotal - paidVal);

            if (dueDisp) dueDisp.textContent = '$' + balanceDue.toFixed(2);
        }

        document.addEventListener('input', function(e) {
            if (e.target && e.target.name && e.target.name.includes('[name]')) {
                handleTfaaAutocomplete(e.target);
            }
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.sywat-tfaa-autocomplete-dropdown') && (!e.target.name || !e.target.name.includes('[name]'))) {
                closeAllTfaaAutocompletes();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const defaultLineSelect = document.getElementById('sywat_default_line_idx');
            if (defaultLineSelect) {
                const savedLineIdx = sessionStorage.getItem('sywat_last_line_idx');
                if (savedLineIdx !== null && defaultLineSelect.querySelector('option[value="' + savedLineIdx + '"]')) {
                    defaultLineSelect.value = savedLineIdx;
                    updateAllArcherLines(savedLineIdx);
                }
            }
            recalculateGroupTotal();
        });
        </script>
        <?php
    }
}
