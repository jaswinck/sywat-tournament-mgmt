<?php
/**
 * Sub-Module: Registration Engine Client Scripts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'sywat_render_registration_scripts' ) ) {
    function sywat_render_registration_scripts() {
        ?>
        <script>
        let archerRowCount = 1;

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
                if (divSelect.value === 'Cub (8 - 11)') {
                    divSelect.value = 'Adult (18 - 49)';
                }
            }
            recalculateGroupTotal();
        }

        function addArcherRow() {
            const container = document.getElementById('sywat_archer_rows_container');
            if (!container) return;

            const defaultLineIdx = document.getElementById('sywat_default_line_idx')?.value || '0';
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
                <input type="text" name="archers[${archerRowCount}][name]" placeholder="Archer Full Name *" style="flex:2; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" required oninput="recalculateGroupTotal()">
                
                <select name="archers[${archerRowCount}][distance]" class="sywat-distance-select" style="flex:1; min-width:100px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="handleDistanceChange(this)">
                    <option value="20yd" selected>20yd</option>
                    <option value="Cub">Cub (10yd)</option>
                </select>

                <select name="archers[${archerRowCount}][division]" class="sywat-division-select" style="flex:1.5; min-width:160px; padding:6px; border:1px solid #ccc; border-radius:4px; height:32px; box-sizing:border-box;" onchange="this.dataset.userChosen='true'; recalculateGroupTotal();">
                    <option value="Adult (18 - 49)" selected>Adult (18 - 49) [$30]</option>
                    <option value="Cub (8 - 11)">Cub (8 - 11) [$25]</option>
                    <option value="Youth (12 - 14)">Youth (12 - 14) [$25]</option>
                    <option value="Young Adult (15 - 17)">Young Adult (15 - 17) [$25]</option>
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

            // Group rows by line_idx for per-line family capping
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

        document.addEventListener('DOMContentLoaded', function() {
            recalculateGroupTotal();
        });
        </script>
        <?php
    }
}
