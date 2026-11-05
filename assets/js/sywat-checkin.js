/**
 * SYWAT Archery - Check-In Desk Client Engine
 * Path: assets/js/sywat-checkin.js
 * Version: 6.2.0
 */

function escapeHtmlDesk(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function updateWalkinFamilyOptions(lineIdx) {
    const famSelect = document.getElementById('walkin_family_group_id');
    if (!famSelect) return;

    const currentVal = famSelect.value;
    famSelect.innerHTML = '<option value="">[ + Start New Family Group for This Line ]</option>';

    const fams = (window.existingFamiliesByLine && window.existingFamiliesByLine[lineIdx]) ? window.existingFamiliesByLine[lineIdx] : {};

    Object.keys(fams).forEach(fid => {
        const opt = document.createElement('option');
        opt.value = fid;
        opt.textContent = fams[fid];
        if (fid === currentVal) opt.selected = true;
        famSelect.appendChild(opt);
    });
}

function updateEditFamilyOptions(lineIdx) {
    const famSelect = document.getElementById('edit_family_group_id');
    if (!famSelect) return;

    famSelect.innerHTML = '<option value="">[ + Start New Family Group for This Line ]</option>';

    const fams = (window.existingFamiliesByLine && window.existingFamiliesByLine[lineIdx]) ? window.existingFamiliesByLine[lineIdx] : {};

    Object.keys(fams).forEach(fid => {
        const opt = document.createElement('option');
        opt.value = fid;
        opt.textContent = fams[fid];
        famSelect.appendChild(opt);
    });
}

function toggleUnpaidBanner(isChecked) {
    const banner = document.getElementById('sywat_unpaid_warning_banner');
    if (banner) {
        if (isChecked) {
            banner.classList.add('sywat-hidden');
        } else {
            banner.classList.remove('sywat-hidden');
        }
    }
}

function findTfaaMatch(typedName) {
    if (!typedName || !window.tfaaData) return null;
    const clean = typedName.trim().toLowerCase().replace(/\s+/g, ' ');
    if (!clean || clean.length < 2) return null;

    let matches = null;

    if (window.tfaaData.exact && window.tfaaData.exact[clean]) {
        matches = window.tfaaData.exact[clean];
    } else {
        const parts = clean.split(' ');
        if (parts.length > 1) {
            const flKey = parts[0] + '___' + parts[parts.length - 1];
            if (window.tfaaData.fl && window.tfaaData.fl[flKey]) matches = window.tfaaData.fl[flKey];
        }
    }

    if (!matches && window.tfaaData.exact) {
        let partialMatches = [];
        const keys = Object.keys(window.tfaaData.exact);
        for (let i = 0; i < keys.length; i++) {
            if (keys[i].includes(clean)) {
                const arr = window.tfaaData.exact[keys[i]];
                if (Array.isArray(arr)) {
                    partialMatches = partialMatches.concat(arr);
                }
            }
        }
        if (partialMatches.length > 0) matches = partialMatches;
    }

    if (Array.isArray(matches) && matches.length > 1) {
        matches.sort((a, b) => {
            const aPro = (a.style && a.style.indexOf('FS Pro') !== -1) ? 1 : 0;
            const bPro = (b.style && b.style.indexOf('FS Pro') !== -1) ? 1 : 0;
            return bPro - aPro;
        });
    }

    return matches;
}

function findShopHistoryMatch(typedName) {
    if (!typedName || !window.sywatHistory || !window.sywatHistory.exact) return null;
    const clean = typedName.trim().toLowerCase().replace(/\s+/g, ' ');
    if (!clean) return null;

    if (window.sywatHistory.exact[clean]) return window.sywatHistory.exact[clean];

    const parts = clean.split(' ');
    if (parts.length > 1) {
        const flKey = parts[0] + '___' + parts[parts.length - 1];
        if (window.sywatHistory.fl && window.sywatHistory.fl[flKey]) return window.sywatHistory.fl[flKey];
    }
    return null;
}

function matchDeskTfaaSelect(value, selectEl) {
    if (!value || !selectEl) return;
    const clean = value.trim().toLowerCase();

    for (let opt of selectEl.options) {
        if (!opt.value) continue;
        const optLower = opt.value.trim().toLowerCase();
        
        if (optLower === clean) {
            selectEl.value = opt.value;
            return;
        }
    }

    if (clean === 'm' || clean === 'male') {
        for (let opt of selectEl.options) {
            if (opt.value.toLowerCase().startsWith('male')) { selectEl.value = opt.value; return; }
        }
    }
    if (clean === 'f' || clean === 'female') {
        for (let opt of selectEl.options) {
            if (opt.value.toLowerCase().startsWith('female')) { selectEl.value = opt.value; return; }
        }
    }

    for (let opt of selectEl.options) {
        if (!opt.value) continue;
        const optLower = opt.value.trim().toLowerCase();
        
        const cleanPrefix = clean.split(' –')[0].split(' (')[0].trim();
        const optPrefix   = optLower.split(' –')[0].split(' (')[0].trim();

        if (cleanPrefix === optPrefix && cleanPrefix.length > 1) {
            selectEl.value = opt.value;
            return;
        }

        if (optLower.includes(clean) || clean.includes(optPrefix)) {
            selectEl.value = opt.value;
            return;
        }
    }
}

function handleDeskTfaaAutocomplete(input) {
    const query = input.value.trim().toLowerCase();
    closeAllDeskTfaaAutocompletes();

    if (query.length < 2) return;

    let matches = [];
    if (Array.isArray(window.sywatDeskTfaaShooters) && window.sywatDeskTfaaShooters.length > 0) {
        matches = window.sywatDeskTfaaShooters.filter(s => s.name && s.name.toLowerCase().includes(query)).slice(0, 8);
    } else if (window.tfaaData && window.tfaaData.exact) {
        const keys = Object.keys(window.tfaaData.exact);
        for (let i = 0; i < keys.length; i++) {
            if (keys[i].includes(query)) {
                const arr = window.tfaaData.exact[keys[i]];
                if (Array.isArray(arr) && arr.length > 0) {
                    let best = arr[0];
                    for (let k = 0; k < arr.length; k++) {
                        if (arr[k].style && arr[k].style.indexOf('FS Pro') !== -1) {
                            best = arr[k];
                            break;
                        }
                    }
                    matches.push({
                        name: best.full_name || keys[i],
                        division: best.division || '',
                        gender: best.gender || '',
                        style: best.style || ''
                    });
                }
                if (matches.length >= 8) break;
            }
        }
    }

    if (matches.length === 0) return;

    const parent = input.parentElement;
    if (getComputedStyle(parent).position === 'static') {
        parent.classList.add('sywat-field-relative');
    }

    const dropdown = document.createElement('div');
    dropdown.className = 'sywat-desk-tfaa-dropdown';

    matches.forEach(m => {
        const item = document.createElement('div');
        item.className = 'sywat-desk-tfaa-item';
        item.innerHTML = `<strong>${escapeHtmlDesk(m.name)}</strong> <span class="sywat-tfaa-item-badge">${escapeHtmlDesk(m.division || '')}</span>`;

        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            input.value = m.name;
            closeAllDeskTfaaAutocompletes();

            const form = input.closest('form');
            let prefix = 'walkin';
            if (input.id === 'checkin_archer_name' || input.name === 'checkin_name') {
                prefix = 'checkin';
            } else if (input.id === 'edit_archer_name' || input.name === 'edit_name') {
                prefix = 'edit';
            }

            if (form) {
                const divEl   = form.querySelector('select[name="walkin_division"], select[name="checkin_division"], select[name="edit_division"]');
                const genEl   = form.querySelector('select[name="walkin_gender"], select[name="checkin_gender"], select[name="edit_gender"]');
                const styleEl = form.querySelector('select[name="walkin_style"], select[name="checkin_style"], select[name="edit_style"]');

                if (divEl && m.division) matchDeskTfaaSelect(m.division, divEl);
                if (genEl && m.gender) matchDeskTfaaSelect(m.gender, genEl);
                if (styleEl && m.style) matchDeskTfaaSelect(styleEl, styleEl);
            }

            if (typeof autoFillOnNameChange === 'function' && (prefix === 'walkin' || prefix === 'checkin')) {
                autoFillOnNameChange(prefix);
            }
        });

        dropdown.appendChild(item);
    });

    parent.appendChild(dropdown);
}

function closeAllDeskTfaaAutocompletes() {
    document.querySelectorAll('.sywat-desk-tfaa-dropdown').forEach(el => el.remove());
}

function loadTfaaCategory(prefix, divVal, genVal, styleVal) {
    const divEl   = document.getElementById(prefix + '_division');
    const genEl   = document.getElementById(prefix + '_gender');
    const styleEl = document.getElementById(prefix + '_style');

    if (divEl && divVal) matchDeskTfaaSelect(divVal, divEl);
    if (genEl && genVal) matchDeskTfaaSelect(genVal, genEl);
    if (styleEl && styleVal) matchDeskTfaaSelect(styleVal, styleEl);

    handleStyleRestrictions(prefix);
    evaluateTfaaStatus(prefix);
}

function updateNameToOfficial(prefix, officialName) {
    const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
    if (nameInput && officialName) {
        nameInput.value = officialName;
        autoFillOnNameChange(prefix);
    }
}

function evaluateTfaaStatus(prefix) {
    const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
    const badgeEl   = document.getElementById(prefix + '_tfaa_badge');
    const pillsEl   = document.getElementById(prefix + '_tfaa_pills');

    if (!nameInput || !badgeEl || !pillsEl) return;

    const typedName = nameInput.value;
    const tfaaMatches = findTfaaMatch(typedName);

    pillsEl.innerHTML = '';

    if (!tfaaMatches || !Array.isArray(tfaaMatches) || tfaaMatches.length === 0) {
        badgeEl.className = 'sywat-badge-none';
        badgeEl.innerHTML = '⚪ No TFAA History (Current Season)';
        return;
    }

    const currDiv   = document.getElementById(prefix + '_division')?.value || '';
    const currGen   = document.getElementById(prefix + '_gender')?.value || '';
    const currStyle = document.getElementById(prefix + '_style')?.value || '';

    let isExactMatch = false;
    let knownStyles = [];

    let buttonContainer = document.createElement('div');
    buttonContainer.className = 'sywat-pill-box';

    tfaaMatches.forEach(cat => {
        if (cat.style) {
            let shortSty = cat.style.split(' – ')[0];
            if (!knownStyles.includes(shortSty)) knownStyles.push(shortSty);
        }

        const cDiv = (cat.division || '').toLowerCase();
        const cGen = (cat.gender || '').toLowerCase();
        const cSty = (cat.style || '').toLowerCase();

        const uDiv = currDiv.toLowerCase();
        const uGen = currGen.toLowerCase();
        const uSty = currStyle.toLowerCase();

        const divMatch   = (cDiv === uDiv) || (cDiv && uDiv && (cDiv.includes(uDiv.split(' (')[0].trim()) || uDiv.includes(cDiv.split(' (')[0].trim())));
        const genMatch   = (cGen === uGen) || (cGen && uGen && cGen.charAt(0) === uGen.charAt(0));
        
        const cStylePrefix = cSty.split(' –')[0].trim();
        const uStylePrefix = uSty.split(' –')[0].trim();
        const styleMatch   = (cSty === uSty) || (cStylePrefix === uStylePrefix) || (cSty && uSty && (uSty.includes(cSty) || cSty.includes(uStylePrefix)));

        if (divMatch && genMatch && styleMatch) {
            isExactMatch = true;
        }

        const divShort = cat.division ? cat.division.split(' ')[0] : '';
        const genShort = cat.gender ? cat.gender.charAt(0) : '';
        const styShort = cat.style ? cat.style.split(' – ')[0] : '';

        const pillBtn = document.createElement('button');
        pillBtn.type = 'button';
        pillBtn.className = 'sywat-btn-pill';
        pillBtn.innerHTML = '⚡ Load: <strong>' + styShort + '</strong> (' + divShort + ' ' + genShort + ')';
        pillBtn.onclick = function() {
            loadTfaaCategory(prefix, cat.division, cat.gender, cat.style);
        };
        buttonContainer.appendChild(pillBtn);
    });

    if (tfaaMatches.length > 1) {
        const alertBox = document.createElement('div');
        alertBox.className = 'sywat-multi-style-alert';
        
        const alertTitle = document.createElement('div');
        alertTitle.className = 'sywat-multi-style-title';
        alertTitle.innerHTML = '⚠️ <strong>Archer has multiple TFAA styles on file. Click a style to load:</strong>';
        
        alertBox.appendChild(alertTitle);
        alertBox.appendChild(buttonContainer);
        pillsEl.appendChild(alertBox);
    }

    let officialName = tfaaMatches[0].full_name || tfaaMatches[0].name || '';
    let cleanTyped = typedName.trim().toLowerCase().replace(/\s+/g, ' ');
    let cleanOfficial = officialName.trim().toLowerCase().replace(/\s+/g, ' ');

    let badgeText = '';
    if (isExactMatch) {
        badgeEl.className = 'sywat-badge-verified';
        badgeText = '🟢 TFAA Validated: ' + knownStyles.join(', ');
    } else {
        badgeEl.className = 'sywat-badge-known';
        badgeText = '⚠️ TFAA Style Mismatch (TFAA Lists: ' + knownStyles.join(', ') + ')';
    }

    if (cleanTyped !== cleanOfficial && officialName !== '') {
        badgeText += '<br><span class="sywat-badge-subtext">↳ Official TFAA Name: <strong>' + officialName + '</strong></span>';
        badgeText += '<button type="button" class="sywat-btn-use-official" onclick="updateNameToOfficial(\'' + prefix + '\', \'' + officialName.replace(/'/g, "\\'") + '\')">✏️ Use Official Name</button>';
    }

    badgeEl.innerHTML = badgeText;
}

function autoFillOnNameChange(prefix) {
    const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
    if (!nameInput) return;

    const typedName = nameInput.value;
    const tfaaMatches = findTfaaMatch(typedName);

    if (tfaaMatches && tfaaMatches.length > 0) {
        const targetMatch = tfaaMatches[0];
        const divEl   = document.getElementById(prefix + '_division');
        const genEl   = document.getElementById(prefix + '_gender');
        const styleEl = document.getElementById(prefix + '_style');

        if (divEl && targetMatch.division) matchDeskTfaaSelect(targetMatch.division, divEl);
        if (genEl && targetMatch.gender) matchDeskTfaaSelect(targetMatch.gender, genEl);
        if (styleEl && targetMatch.style) matchDeskTfaaSelect(styleEl, styleEl);

        handleStyleRestrictions(prefix);
    } else if (prefix === 'walkin') {
        const shopMatch = findShopHistoryMatch(typedName);
        if (shopMatch) {
            const divEl   = document.getElementById(prefix + '_division');
            const genEl   = document.getElementById(prefix + '_gender');
            const styleEl = document.getElementById(prefix + '_style');
            const payEl   = document.getElementById(prefix + '_pay_option');

            if (divEl && shopMatch.division) matchDeskTfaaSelect(shopMatch.division, divEl);
            if (genEl && shopMatch.gender) matchDeskTfaaSelect(shopMatch.gender, genEl);
            if (styleEl && shopMatch.style) matchDeskTfaaSelect(styleEl, styleEl);
            if (payEl && shopMatch.paid) payEl.value = shopMatch.paid;

            handleStyleRestrictions(prefix);
        } else {
            const divEl   = document.getElementById('walkin_division');
            const genEl   = document.getElementById('walkin_gender');
            const styleEl = document.getElementById('walkin_style');
            if (divEl) divEl.value = '';
            if (genEl) genEl.value = '';
            if (styleEl) styleEl.value = '';
            handleStyleRestrictions('walkin');
        }
    }

    evaluateTfaaStatus(prefix);
}

function closeWalkinModal() {
    const modal = document.getElementById('walkinModal');
    if (modal) modal.classList.remove('active');

    const nameInput = document.getElementById('walkin_name_input');
    if (nameInput) nameInput.value = '';

    const divEl = document.getElementById('walkin_division');
    if (divEl) divEl.value = '';
    const genEl = document.getElementById('walkin_gender');
    if (genEl) genEl.value = '';
    const styleEl = document.getElementById('walkin_style');
    if (styleEl) styleEl.value = '';

    const payEl = document.getElementById('walkin_pay_option');
    if (payEl) payEl.value = 'Individual';

    const famSel = document.getElementById('walkin_family_group_id');
    if (famSel) famSel.value = '';

    toggleFamilyBox('walkin');
    handleStyleRestrictions('walkin');
    evaluateTfaaStatus('walkin');
    closeAllDeskTfaaAutocompletes();
}

function openWalkinModal() {
    closeWalkinModal();
    const modal = document.getElementById('walkinModal');
    if (modal) modal.classList.add('active');

    const lineSel = document.getElementById('walkin_line_index');
    if (lineSel) {
        updateWalkinFamilyOptions(lineSel.value);
    }

    const walkinNameInput = document.getElementById('walkin_name_input');
    if (walkinNameInput) walkinNameInput.focus();
}

function toggleFamilyBox(type) {
    const payOpt = document.getElementById(type + '_pay_option');
    const box    = document.getElementById(type + '_family_box');
    if (payOpt && box) {
        if (payOpt.value === 'Family') {
            box.classList.remove('sywat-hidden');
        } else {
            box.classList.add('sywat-hidden');
        }
    }
}

function continueFamilyLoop(familyId, lineIdx) {
    openWalkinModal();
    
    const lineSelect = document.getElementById('walkin_line_index');
    if (lineSelect && lineIdx !== undefined) {
        lineSelect.value = lineIdx;
        updateWalkinFamilyOptions(lineIdx);
    }

    const payOptSelect = document.getElementById('walkin_pay_option');
    if (payOptSelect) {
        payOptSelect.value = 'Family';
        toggleFamilyBox('walkin');
    }

    const familySelect = document.getElementById('walkin_family_group_id');
    if (familySelect) {
        familySelect.value = familyId;
    }
}

function handleStyleRestrictions(type) {
    const divisionEl = document.getElementById(type + '_division');
    const styleEl    = document.getElementById(type + '_style');
    if (!divisionEl || !styleEl) return;

    const currentDiv = divisionEl.value;

    if (!currentDiv || currentDiv === 'No Show') {
        for (let i = 0; i < styleEl.options.length; i++) styleEl.options[i].disabled = false;
        return;
    }

    for (let i = 0; i < styleEl.options.length; i++) {
        const opt = styleEl.options[i];
        const val = opt.value;
        let isDisabled = false;

        if (val.indexOf('Genesis') !== -1 && currentDiv !== 'Cub (8 - 11)') isDisabled = true;

        const isFSPro = val.indexOf('FS Pro') !== -1;
        const isFSL   = val.indexOf('FSL –') !== -1;
        const isTRAD  = val.indexOf('TRAD') !== -1 || val.toLowerCase().indexOf('traditional') !== -1;

        if (isFSPro || isFSL || isTRAD) {
            if (!['Adult (18 - 49)', 'Senior (50 - 59)', 'Silver Senior (60 - 69)', 'Master Senior (70+)'].includes(currentDiv)) isDisabled = true;
        }

        const isLB = val.indexOf('LB') !== -1 || val.toLowerCase().indexOf('longbow') !== -1;
        if (isLB && currentDiv !== 'Adult (18 - 49)') isDisabled = true;

        opt.disabled = isDisabled;
        if (isDisabled && styleEl.value === val) styleEl.value = 'FS – Freestyle';
    }
}

/**
 * Initializes Check-In Desk event listeners and pre-fills active forms.
 * Safe for footer enqueuing regardless of document.readyState.
 */
function initSywatCheckin() {
    const walkinInput = document.getElementById('walkin_name_input');
    if (walkinInput) {
        walkinInput.addEventListener('input', function() {
            autoFillOnNameChange('walkin');
        });
    }

    const checkinArcherNameEl = document.getElementById('checkin_archer_name');
    if (checkinArcherNameEl) {
        checkinArcherNameEl.addEventListener('input', function() {
            autoFillOnNameChange('checkin');
        });
        autoFillOnNameChange('checkin');
        toggleFamilyBox('checkin');
    }

    const searchInput = document.getElementById('sywat_search_input');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('.sywat-archer-row');
            
            rows.forEach(row => {
                const name = row.getAttribute('data-name');
                if (name && name.includes(filter)) {
                    row.classList.remove('sywat-hidden');
                } else {
                    row.classList.add('sywat-hidden');
                }
            });

            const hiddenSearches = document.querySelectorAll('.sywat-hidden-search');
            hiddenSearches.forEach(hidden => {
                hidden.value = this.value;
            });
        });
        
        searchInput.dispatchEvent(new Event('input'));
    }

    if (document.getElementById('walkin_division')) handleStyleRestrictions('walkin');
    if (document.getElementById('checkin_division')) handleStyleRestrictions('checkin');
    if (document.getElementById('edit_division')) handleStyleRestrictions('edit');
}

// Execution Guard: Run immediately if DOM is ready, otherwise attach to DOMContentLoaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSywatCheckin);
} else {
    initSywatCheckin();
}

document.addEventListener('input', function(e) {
    if (e.target && (e.target.id === 'walkin_name_input' || e.target.id === 'checkin_archer_name' || e.target.id === 'edit_archer_name' || e.target.name === 'edit_name' || e.target.name === 'walkin_name' || e.target.name === 'checkin_name')) {
        handleDeskTfaaAutocomplete(e.target);
    }
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.sywat-desk-tfaa-dropdown')) {
        closeAllDeskTfaaAutocompletes();
    }
});
