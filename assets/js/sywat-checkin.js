/**
 * SYWAT Archery - Check-In Desk Client Engine
 * Path: assets/js/sywat-checkin.js
 * Version: 6.5.6
 */

function escapeHtmlDesk(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function cleanDeskName(str) {
    if (!str) return '';
    return String(str).replace(/[\s\u00a0]+/g, ' ').trim().toLowerCase();
}

function updateWalkinFamilyOptions(lineIdx) {
    const famSelect = document.getElementById('walkin_family_group_id');
    if (!famSelect) return;

    const currentVal = famSelect.value;
    famSelect.innerHTML = '<option value="">[ Auto-Create Group for Primary Contact ]</option>';

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

/**
 * Universal TFAA Match Engine querying both window.tfaaData object cache and desk array caches.
 */
function getAllTfaaMatches(typedName) {
    if (!typedName) return [];
    const clean = cleanDeskName(typedName);
    if (!clean || clean.length < 2) return [];

    let rawMatches = [];

    // 1. Primary Check: Master TFAA database cache (window.tfaaData)
    if (window.tfaaData) {
        if (window.tfaaData.exact && window.tfaaData.exact[clean]) {
            const entry = window.tfaaData.exact[clean];
            const arr = Array.isArray(entry) ? entry : [entry];
            rawMatches = rawMatches.concat(arr);
        }

        if (window.tfaaData.fl) {
            const parts = clean.split(' ');
            if (parts.length > 1) {
                const flKey = parts[0] + '___' + parts[parts.length - 1];
                if (window.tfaaData.fl[flKey]) {
                    const entry = window.tfaaData.fl[flKey];
                    const arr = Array.isArray(entry) ? entry : [entry];
                    rawMatches = rawMatches.concat(arr);
                }
            }
        }

        if (window.tfaaData.exact) {
            const keys = Object.keys(window.tfaaData.exact);
            for (let i = 0; i < keys.length; i++) {
                const kClean = cleanDeskName(keys[i]);
                if (kClean.includes(clean) || clean.includes(kClean)) {
                    const entry = window.tfaaData.exact[keys[i]];
                    const arr = Array.isArray(entry) ? entry : [entry];
                    rawMatches = rawMatches.concat(arr);
                }
            }
        }
    }

    // 2. Secondary Check: Desk array caches (window.sywatDeskTfaaShooters / sywatTfaaShooters)
    let shooterArray = [];
    if (Array.isArray(window.sywatDeskTfaaShooters)) {
        shooterArray = shooterArray.concat(window.sywatDeskTfaaShooters);
    }
    if (typeof sywatTfaaShooters !== 'undefined' && Array.isArray(sywatTfaaShooters)) {
        shooterArray = shooterArray.concat(sywatTfaaShooters);
    }
    if (window.sywatDeskData && Array.isArray(window.sywatDeskData.tfaaShooters)) {
        shooterArray = shooterArray.concat(window.sywatDeskData.tfaaShooters);
    }

    if (shooterArray.length > 0) {
        const arrMatches = shooterArray.filter(s => {
            const sName = cleanDeskName(s.name || s.full_name || '');
            return sName && (sName.includes(clean) || clean.includes(sName));
        });
        rawMatches = rawMatches.concat(arrMatches);
    }

    // Deduplicate matches
    const seen = new Set();
    const uniqueMatches = [];

    for (let m of rawMatches) {
        if (!m) continue;
        const nameStr = (m.full_name || m.name || '').trim();
        if (!nameStr) continue;
        const divStr  = (m.division || '').trim();
        const styStr  = (m.style || '').trim();
        const genStr  = (m.gender || '').trim();
        
        const dedupKey = (cleanDeskName(nameStr) + '___' + divStr + '___' + styStr + '___' + genStr).toLowerCase();
        if (!seen.has(dedupKey)) {
            seen.add(dedupKey);
            uniqueMatches.push({
                name: nameStr,
                full_name: nameStr,
                division: m.division || '',
                gender: m.gender || '',
                style: m.style || '',
                phone: m.phone || m.phone_number || ''
            });
        }
    }

    // Sort: Exact name matches first, then FS Pro prioritization
    uniqueMatches.sort((a, b) => {
        const aName = cleanDeskName(a.name);
        const bName = cleanDeskName(b.name);
        const aExact = (aName === clean) ? 1 : 0;
        const bExact = (bName === clean) ? 1 : 0;
        if (aExact !== bExact) return bExact - aExact;

        const aPro = (a.style && a.style.indexOf('FS Pro') !== -1) ? 1 : 0;
        const bPro = (b.style && b.style.indexOf('FS Pro') !== -1) ? 1 : 0;
        return bPro - aPro;
    });

    return uniqueMatches;
}

function findTfaaMatch(typedName) {
    const matches = getAllTfaaMatches(typedName);
    return matches.length > 0 ? matches : null;
}

function findShopHistoryMatch(typedName) {
    if (!typedName || !window.sywatHistory || !window.sywatHistory.exact) return null;
    const clean = cleanDeskName(typedName);
    if (!clean) return null;

    if (window.sywatHistory.exact[clean]) return window.sywatHistory.exact[clean];

    const parts = clean.split(' ');
    if (parts.length > 1) {
        const flKey = parts[0] + '___' + parts[parts.length - 1];
        if (window.sywatHistory.fl && window.sywatHistory.fl[flKey]) return window.sywatHistory.fl[flKey];
    }
    return null;
}

/**
 * Smart option matcher translating TFAA style codes, full titles, and gender letters to standard select values.
 */
function matchDeskTfaaSelect(value, selectEl) {
    if (!value || !selectEl) return;
    const clean = value.trim().toLowerCase();
    if (!clean) return;

    const styleMap = {
        'fs': 'fs – freestyle',
        'freestyle': 'fs – freestyle',
        'fs pro': 'fs pro – freestyle pro',
        'freestyle pro': 'fs pro – freestyle pro',
        'fsl': 'fsl – freestyle limited',
        'freestyle limited': 'fsl – freestyle limited',
        'fsl-r': 'fsl-r – freestyle limited recurve',
        'fslr': 'fsl-r – freestyle limited recurve',
        'olympic recurve': 'fsl-r – freestyle limited recurve',
        'bhfs': 'bhfs – bowhunter freestyle',
        'bowhunter freestyle': 'bhfs – bowhunter freestyle',
        'mbh': 'mbh – modern bowhunter',
        'modern bowhunter': 'mbh – modern bowhunter',
        'bbc': 'bbc – barebow compound',
        'barebow compound': 'bbc – barebow compound',
        'bbr': 'bbr – barebow recurve',
        'barebow recurve': 'bbr – barebow recurve',
        'trad': 'trad – traditional',
        'traditional': 'trad – traditional',
        'lb': 'lb – longbow',
        'longbow': 'lb – longbow',
        'genesis': 'genesis'
    };

    const cleanBase = clean.split(' –')[0].trim();
    let mappedTarget = styleMap[clean] || styleMap[cleanBase] || '';

    // 1. Direct or mapped target matching
    for (let opt of selectEl.options) {
        if (!opt.value) continue;
        const optLower = opt.value.trim().toLowerCase();

        if (optLower === clean || (mappedTarget && optLower.startsWith(mappedTarget.split(' –')[0]))) {
            selectEl.value = opt.value;
            return;
        }
    }

    // 2. Gender resolution ("M" / "Male", "F" / "Female")
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

    // 3. Fallback prefix/substring match
    for (let opt of selectEl.options) {
        if (!opt.value) continue;
        const optLower = opt.value.trim().toLowerCase();

        const cleanSanitized = cleanBase.replace(/[^a-z0-9]/gi, '');
        const optSanitized   = optLower.split(' –')[0].replace(/[^a-z0-9]/gi, '');

        if (cleanSanitized && optSanitized && cleanSanitized === optSanitized) {
            selectEl.value = opt.value;
            return;
        }

        if (optLower.includes(clean) || (cleanSanitized.length >= 2 && optLower.includes(cleanSanitized))) {
            selectEl.value = opt.value;
            return;
        }
    }
}

function handleDeskTfaaAutocomplete(input) {
    const query = cleanDeskName(input.value);
    closeAllDeskTfaaAutocompletes();

    if (query.length < 2) return;

    const matches = getAllTfaaMatches(query).slice(0, 8);
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
            const isPrimaryContact = (input.id === 'walkin_group_name' || input.name === 'walkin_group_name');
            const prevVal = input.getAttribute('data-prev-val') || input.value;

            input.value = m.name;
            input.setAttribute('data-prev-val', m.name);
            closeAllDeskTfaaAutocompletes();

            const form = input.closest('form');
            let prefix = 'walkin';
            if (input.id === 'checkin_archer_name' || input.name === 'checkin_name') {
                prefix = 'checkin';
            } else if (input.id === 'edit_archer_name' || input.name === 'edit_name') {
                prefix = 'edit';
            }

            if (isPrimaryContact) {
                const phoneEl      = form ? form.querySelector('#walkin_group_phone, input[name="walkin_group_phone"]') : null;
                const archerNameEl = form ? form.querySelector('#walkin_name_input, input[name="walkin_name"]') : null;

                if (phoneEl && (m.phone || m.phone_number)) {
                    phoneEl.value = m.phone || m.phone_number;
                } else if (phoneEl) {
                    const shopMatch = findShopHistoryMatch(m.name);
                    if (shopMatch && shopMatch.phone) {
                        phoneEl.value = shopMatch.phone;
                    }
                }

                if (archerNameEl && (!archerNameEl.value || archerNameEl.value === prevVal || archerNameEl.value === input.value)) {
                    archerNameEl.value = m.name;
                }

                const divEl   = form ? form.querySelector('select[name="walkin_division"]') : null;
                const genEl   = form ? form.querySelector('select[name="walkin_gender"]') : null;
                const styleEl = form ? form.querySelector('select[name="walkin_style"]') : null;

                if (divEl && m.division) matchDeskTfaaSelect(m.division, divEl);
                if (genEl && m.gender) matchDeskTfaaSelect(m.gender, genEl);
                if (styleEl && m.style) matchDeskTfaaSelect(styleEl, styleEl);

                autoFillOnNameChange('walkin');
            } else {
                if (form) {
                    const divEl   = form.querySelector('select[name="walkin_division"], select[name="checkin_division"], select[name="edit_division"]');
                    const genEl   = form.querySelector('select[name="walkin_gender"], select[name="checkin_gender"], select[name="edit_gender"]');
                    const styleEl = form.querySelector('select[name="walkin_style"], select[name="checkin_style"], select[name="edit_style"]');

                    if (divEl && m.division) matchDeskTfaaSelect(m.division, divEl);
                    if (genEl && m.gender) matchDeskTfaaSelect(m.gender, genEl);
                    if (styleEl && m.style) matchDeskTfaaSelect(styleEl, styleEl);
                }

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

function loadTfaaCategory(prefix, divVal, genVal, styleVal, officialName) {
    const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
    if (nameInput && officialName) {
        nameInput.value = officialName;
    }

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

    const typedName   = nameInput.value.trim();
    const cleanTyped  = cleanDeskName(typedName);
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

    let isCategoryMatch      = false;
    let knownStyles          = [];
    const hasMultipleMatches = tfaaMatches.length > 1;

    let buttonContainer = document.createElement('div');
    buttonContainer.className = 'sywat-pill-box';

    tfaaMatches.forEach(cat => {
        const catName = cat.full_name || cat.name || '';
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
            if (!hasMultipleMatches || cleanDeskName(catName) === cleanTyped) {
                isCategoryMatch = true;
            }
        }

        const divShort = cat.division ? cat.division.split(' ')[0] : '';
        const genShort = cat.gender ? cat.gender.charAt(0) : '';
        const styShort = cat.style ? cat.style.split(' – ')[0] : '';

        const pillBtn = document.createElement('button');
        pillBtn.type = 'button';
        pillBtn.className = 'sywat-btn-pill';

        let pillLabel = '';
        if (hasMultipleMatches && catName) {
            pillLabel = '⚡ Load: <strong>' + escapeHtmlDesk(catName) + '</strong> (' + styShort + ' ' + divShort + ' ' + genShort + ')';
        } else {
            pillLabel = '⚡ Load: <strong>' + styShort + '</strong> (' + divShort + ' ' + genShort + ')';
        }
        pillBtn.innerHTML = pillLabel;

        pillBtn.onclick = function() {
            loadTfaaCategory(prefix, cat.division, cat.gender, cat.style, catName);
        };
        buttonContainer.appendChild(pillBtn);
    });

    if (hasMultipleMatches) {
        const alertBox = document.createElement('div');
        alertBox.className = 'sywat-multi-style-alert';
        
        const alertTitle = document.createElement('div');
        alertTitle.className = 'sywat-multi-style-title';
        alertTitle.innerHTML = '⚠️ <strong>Multiple TFAA member records found. Click a member to load:</strong>';
        
        alertBox.appendChild(alertTitle);
        alertBox.appendChild(buttonContainer);
        pillsEl.appendChild(alertBox);
    }

    let exactNameMatchRecord = tfaaMatches.find(m => cleanDeskName(m.full_name || m.name) === cleanTyped);
    let officialName         = exactNameMatchRecord ? (exactNameMatchRecord.full_name || exactNameMatchRecord.name) : (tfaaMatches[0].full_name || tfaaMatches[0].name || '');
    let cleanOfficial        = cleanDeskName(officialName);

    let isNameMatch = (cleanTyped === cleanOfficial);

    let badgeText = '';
    if (isCategoryMatch && isNameMatch) {
        badgeEl.className = 'sywat-badge-verified';
        badgeText = '🟢 TFAA Validated: ' + knownStyles.join(', ');
    } else if (isCategoryMatch && !isNameMatch) {
        badgeEl.className = 'sywat-badge-known';
        badgeText = '⚠️ TFAA Name Mismatch (TFAA Lists: ' + knownStyles.join(', ') + ')';
    } else {
        badgeEl.className = 'sywat-badge-known';
        badgeText = '⚠️ TFAA Style Mismatch (TFAA Lists: ' + knownStyles.join(', ') + ')';
    }

    if (!isNameMatch && officialName !== '') {
        badgeText += '<br><span class="sywat-badge-subtext">↳ Official TFAA Name: <strong>' + escapeHtmlDesk(officialName) + '</strong></span>';
        badgeText += '<button type="button" class="sywat-btn-use-official" onclick="updateNameToOfficial(\'' + prefix + '\', \'' + officialName.replace(/'/g, "\\'") + '\')">✏️ Use Official Name</button>';
    }

    badgeEl.innerHTML = badgeText;
}

function autoFillOnNameChange(prefix) {
    const nameInput = (prefix === 'walkin') ? document.getElementById('walkin_name_input') : document.getElementById('checkin_archer_name');
    if (!nameInput) return;

    const typedName = nameInput.value.trim();
    if (!typedName) {
        if (prefix === 'walkin') {
            const divEl   = document.getElementById('walkin_division');
            const genEl   = document.getElementById('walkin_gender');
            const styleEl = document.getElementById('walkin_style');
            if (divEl) divEl.value = '';
            if (genEl) genEl.value = '';
            if (styleEl) styleEl.value = '';
            handleStyleRestrictions('walkin');
        }
        evaluateTfaaStatus(prefix);
        return;
    }

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

            if (divEl && shopMatch.division) matchDeskTfaaSelect(shopMatch.division, divEl);
            if (genEl && shopMatch.gender) matchDeskTfaaSelect(shopMatch.gender, genEl);
            if (styleEl && shopMatch.style) matchDeskTfaaSelect(styleEl, styleEl);

            handleStyleRestrictions(prefix);
        }
    }

    evaluateTfaaStatus(prefix);
}

function closeWalkinModal() {
    const modal = document.getElementById('walkinModal');
    if (modal) modal.classList.remove('active');

    const contactInput = document.getElementById('walkin_group_name');
    if (contactInput) {
        contactInput.value = '';
        contactInput.setAttribute('data-prev-val', '');
    }

    const phoneInput = document.getElementById('walkin_group_phone');
    if (phoneInput) phoneInput.value = '';

    const nameInput = document.getElementById('walkin_name_input');
    if (nameInput) nameInput.value = '';

    const divEl = document.getElementById('walkin_division');
    if (divEl) divEl.value = '';
    const genEl = document.getElementById('walkin_gender');
    if (genEl) genEl.value = '';
    const styleEl = document.getElementById('walkin_style');
    if (styleEl) styleEl.value = '';

    const jsonInput = document.querySelector('input[name="walkin_session_archers_json"]');
    if (jsonInput) jsonInput.value = '';

    const famSel = document.getElementById('walkin_family_group_id');
    if (famSel) famSel.value = '';

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

    const contactInput = document.getElementById('walkin_group_name');
    if (contactInput) {
        contactInput.setAttribute('data-prev-val', contactInput.value || '');
    }

    const walkinNameInput = document.getElementById('walkin_name_input');
    if (walkinNameInput) walkinNameInput.focus();
}

function toggleFamilyBox(type) {
    const box = document.getElementById(type + '_family_box');
    if (box) {
        box.classList.remove('sywat-hidden');
    }
}

function continueFamilyLoop(familyId, lineIdx) {
    openWalkinModal();
    
    const lineSelect = document.getElementById('walkin_line_index');
    if (lineSelect && lineIdx !== undefined) {
        lineSelect.value = lineIdx;
        updateWalkinFamilyOptions(lineIdx);
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

function handleWalkinGroupInput(input) {
    if (!input) return;
    const archerNameInput = document.getElementById('walkin_name_input');
    const prevVal = input.getAttribute('data-prev-val') || '';
    
    if (archerNameInput) {
        if (!archerNameInput.value || archerNameInput.value === prevVal) {
            archerNameInput.value = input.value;
            autoFillOnNameChange('walkin');
        }
    }
    input.setAttribute('data-prev-val', input.value);
}

window.handleWalkinGroupInput = handleWalkinGroupInput;

/**
 * Initializes Check-In Desk event listeners and pre-fills active forms.
 */
function initSywatCheckin() {
    const walkinGroupName = document.getElementById('walkin_group_name');
    if (walkinGroupName) {
        walkinGroupName.setAttribute('data-prev-val', walkinGroupName.value || '');
        walkinGroupName.addEventListener('input', function() {
            handleWalkinGroupInput(this);
        });
    }

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

// Execution Guard
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSywatCheckin);
} else {
    initSywatCheckin();
}

document.addEventListener('input', function(e) {
    if (e.target && (e.target.id === 'walkin_name_input' || e.target.id === 'checkin_archer_name' || e.target.id === 'edit_archer_name' || e.target.name === 'edit_name' || e.target.name === 'walkin_name' || e.target.name === 'checkin_name' || e.target.id === 'walkin_group_name' || e.target.name === 'walkin_group_name')) {
        handleDeskTfaaAutocomplete(e.target);
    }
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.sywat-desk-tfaa-dropdown')) {
        closeAllDeskTfaaAutocompletes();
    }
});
