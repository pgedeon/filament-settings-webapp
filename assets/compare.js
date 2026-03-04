/**
 * Filament Settings Web App - Printer Comparison Tool (Rewritten)
 * Features: search, filters, checkbox list, selection tray, sticky headers, grouped specs, diff highlighting
 */

(function() {
    'use strict';

    // Constants
    const API_BASE = window.fswCompareData?.ajaxUrl || '/wp-json/fsw/v1';
    const MAX_SELECTION = 4;
    const SEARCH_DEBOUNCE_MS = 200;

    // State
    let allPrinters = [];          // All available printers (for search)
    let filteredPrinters = [];     // Currently visible printers in list
    let selectedPrinters = [];     // Currently selected printer objects
    let showOnlyDifferences = false;
    let sectionsExpanded = {};     // Track which sections are expanded
    let errorTimeout = null;
    let lastRetryAction = null;

    // DOM Elements
    const app = document.getElementById('fsw-compare-app');
    const searchInput = document.getElementById('fsw-search-input');
    const printerList = document.getElementById('fsw-printer-list');
    const listCount = document.getElementById('fsw-list-count');
    const selectAllCheckbox = document.getElementById('fsw-select-all');
    const clearSelectionBtn = document.getElementById('fsw-clear-selection');
    const searchStatus = document.getElementById('fsw-search-status');
    const selectedList = document.getElementById('fsw-selected-list');
    const selectedCount = document.getElementById('fsw-selected-count');
    const compareButton = document.getElementById('fsw-compare-button');
    const compareHint = document.getElementById('fsw-compare-hint');
    const resultsSection = document.querySelector('.fsw-compare-results');
    const stickyHeader = document.getElementById('fsw-sticky-header');
    const headerCardsContainer = document.getElementById('fsw-header-cards');
    const sectionsContainer = document.getElementById('fsw-sections');
    const actionsSection = document.getElementById('fsw-results-actions');
    const loadingEl = document.getElementById('fsw-loading');
    const errorEl = document.getElementById('fsw-error');
    const shareLinkBtn = document.getElementById('fsw-share-link');
    const diffToggleBtn = document.getElementById('fsw-diff-toggle');

    // Spec display configuration
    const DISPLAY_MAPS = {
        extruder_type: {
            bowden: 'Bowden',
            direct: 'Direct',
            '': '—'
        },
        hotend_type: {
            bowden: 'Standard',
            direct: 'Direct',
            'all-metal': 'All-Metal',
            volcano: 'Volcano/High-Flow',
            other: 'Other',
            '': '—'
        },
        autolevel_type: {
            none: 'None',
            blob: 'BLTouch/3D Touch',
            mesh: 'Mesh',
            bed_visualizer: 'Bed Visualizer',
            capacitive: 'Capacitive',
            inductive: 'Inductive',
            other: 'Other',
            '': '—'
        },
        build_surface_type: {
            glass: 'Glass',
            pei: 'PEI',
            peek: 'PEEK',
            g10: 'G10/Fiberboard',
            buildtak: 'BuildTak',
            pcb: 'PCB Heatbed',
            other: 'Other',
            '': '—'
        },
        frame_type: {
            open: 'Open Frame',
            enclosed: 'Enclosed',
            cubic: 'Cubic/Box',
            delta: 'Delta',
            corexy: 'CoreXY',
            coredxy: 'CoreXY (Dual Stepper)',
            hbot: 'H-Bot',
            other: 'Other',
            '': '—'
        },
        display_type: {
            lcd_12864: '128x64 LCD',
            lcd_320240: '320x240 LCD',
            touchscreen: 'Touchscreen',
            none: 'None',
            smartphone: 'Smartphone Only',
            '': '—'
        },
        boolean: {
            1: 'Yes',
            0: 'No',
            true: 'Yes',
            false: 'No',
            '': '—'
        }
    };

    // Spec sections configuration
    const SPEC_SECTIONS = [
        {
            id: 'build-heat',
            title: 'Build & Heat',
            fields: [
                { key: 'build_volume', label: 'Build Volume', format: formatBuildVolume },
                { key: 'max_hotend_temp_c', label: 'Max Hotend Temp', suffix: '°C' },
                { key: 'max_bed_temp_c', label: 'Max Bed Temp', suffix: '°C' },
                { key: 'nozzle_count', label: 'Nozzle Count' },
                { key: 'extruder_type', label: 'Extruder Type', format: formatEnum.bind(null, DISPLAY_MAPS.extruder_type) },
                { key: 'hotend_type', label: 'Hotend Type', format: formatEnum.bind(null, DISPLAY_MAPS.hotend_type) }
            ]
        },
        {
            id: 'enclosure',
            title: 'Enclosure & Environment',
            fields: [
                { key: 'enclosure', label: 'Enclosure', format: formatBool },
                { key: 'heated_enclosure', label: 'Heated Enclosure', format: formatBool },
                { key: 'enclosure_temp_max_c', label: 'Enclosure Max Temp', suffix: '°C' },
                { key: 'chamber_heated', label: 'Heated Chamber', format: formatBool }
            ]
        },
        {
            id: 'motion',
            title: 'Motion & Frame',
            fields: [
                { key: 'frame_type', label: 'Frame Type', format: formatEnum.bind(null, DISPLAY_MAPS.frame_type) },
                { key: 'travel_speed_mm_s', label: 'Travel Speed', suffix: ' mm/s' },
                { key: 'linear_rail_xyz', label: 'Linear Rails (XYZ)' },
                { key: 'belt_drive', label: 'Belt Drive', format: formatBool },
                { key: 'pressure_advance', label: 'Pressure Advance', format: formatBool },
                { key: 'input_shaping', label: 'Input Shaping', format: formatBool }
            ]
        },
        {
            id: 'connectivity',
            title: 'Connectivity & Features',
            fields: [
                { key: 'display_type', label: 'Display Type', format: formatEnum.bind(null, DISPLAY_MAPS.display_type) },
                { key: 'tft_display', label: 'TFT Display', format: formatBool },
                { key: 'wifi_enabled', label: 'WiFi', format: formatBool },
                { key: 'ethernet_enabled', label: 'Ethernet', format: formatBool },
                { key: 'usb_media', label: 'USB Media', format: formatBool },
                { key: 'multi_material', label: 'Multi-Material', format: formatBool },
                { key: 'spool_sensors', label: 'Spool Sensors', format: formatBool },
                { key: 'power_loss_recovery', label: 'Power Loss Recovery', format: formatBool },
                { key: 'filament_sensor', label: 'Filament Sensor', format: formatBool },
                { key: 'autolevel_type', label: 'Auto Leveling', format: formatEnum.bind(null, DISPLAY_MAPS.autolevel_type) },
                { key: 'autolevel_points', label: 'Auto Level Points' },
                { key: 'build_surface_type', label: 'Build Surface', format: formatEnum.bind(null, DISPLAY_MAPS.build_surface_type) },
                { key: 'build_surface_removable', label: 'Removable Build Plate', format: formatBool }
            ]
        }
    ];

    // Utility functions
    function formatBool(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        const map = DISPLAY_MAPS.boolean;
        if (map[value] !== undefined) return map[value];
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        if (value == 1 || value == '1') return 'Yes';
        if (value == 0 || value == '0') return 'No';
        return '—';
    }

    function formatValue(value, suffix = '') {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        return String(value) + suffix;
    }

    function formatBuildVolume(printer) {
        const x = printer.build_volume_x_mm;
        const y = printer.build_volume_y_mm;
        const z = printer.build_volume_z_mm;
        if (!x || !y || !z) {
            return '—';
        }
        return `${x} × ${y} × ${z} mm`;
    }

    function formatEnum(map, value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        // Handle object values (e.g., {label: 'Bowden', value: 'bowden'})
        if (typeof value === 'object' && value !== null) {
            if (value.label !== undefined) return value.label;
            if (value.value !== undefined) return value.value;
            if (value.text !== undefined) return value.text;
            if (value.name !== undefined) return value.name;
            // Fallback to avoid [object Object]
            return JSON.stringify(value);
        }
        // Primitive: look up in map, fallback to string coercion
        return map[value] || String(value);
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, '&amp;')
                   .replace(/</g, '&lt;')
                   .replace(/>/g, '&gt;')
                   .replace(/"/g, '&quot;')
                   .replace(/'/g, '&#039;');
    }

    function debounce(fn, delay) {
        let timer;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    // Load printers via API
    async function loadAllPrinters() {
        setLoading(true);
        try {
            const response = await fetch(`${API_BASE}/printers/search?limit=200`);
            if (!response.ok) throw new Error('Failed to load printer list');
            const data = await response.json();
            allPrinters = data.printers || [];
        } catch (err) {
            showError('Failed to load printer data: ' + err.message);
            setRetryAction(loadAllPrinters);
        } finally {
            setLoading(false);
        }
    }

    // Search printers with filters
    async function searchPrinters(query, filters = {}) {
        let url = `${API_BASE}/printers/search?limit=50`;
        if (query) {
            url += `&q=${encodeURIComponent(query)}`;
        }
        if (filters.enclosure !== undefined) {
            url += `&enclosure=${filters.enclosure}`;
        }
        if (filters.frame_type) {
            url += `&frame_type=${encodeURIComponent(filters.frame_type)}`;
        }

        try {
            const response = await fetch(url);
            if (!response.ok) throw new Error('Search failed');
            return await response.json();
        } catch (err) {
            showError('Search failed: ' + err.message);
            setRetryAction(() => debouncedSearch());
            return { printers: [] };
        }
    }

    // Load full specs for selected printers
    async function loadCompareData(printerIds) {
        const url = `${API_BASE}/printers/compare?ids=${printerIds.join(',')}`;
        try {
            const response = await fetch(url);
            if (!response.ok) throw new Error('Failed to load comparison data');
            return await response.json();
        } catch (err) {
            showError('Failed to load printer details: ' + err.message);
            setRetryAction(() => performComparison());
            return { printers: [] };
        }
    }

    // UI Rendering
    function setLoading(isLoading) {
        if (loadingEl) loadingEl.style.display = isLoading ? 'block' : 'none';
        if (app) {
            if (isLoading) {
                app.setAttribute('aria-busy', 'true');
            } else {
                app.removeAttribute('aria-busy');
            }
        }
    }

    function hideError() {
        if (errorEl) {
            errorEl.style.display = 'none';
            const messageEl = errorEl.querySelector('.fsw-error-message');
            if (messageEl) messageEl.textContent = '';
        }
        clearRetryAction();
    }

    function showError(msg) {
        if (!errorEl) return;
        const messageEl = errorEl.querySelector('.fsw-error-message');
        if (messageEl) {
            messageEl.textContent = msg;
        } else {
            errorEl.textContent = msg;
        }
        errorEl.style.display = 'block';
    }

    function scheduleErrorDismiss() {
        if (!errorEl || errorEl.style.display === 'none') return;
        clearTimeout(errorTimeout);
        errorTimeout = setTimeout(() => {
            hideError();
        }, 5000);
    }

    function setRetryAction(action) {
        lastRetryAction = typeof action === 'function' ? action : null;
        const retryBtn = errorEl?.querySelector('.fsw-retry-button');
        if (retryBtn) {
            retryBtn.disabled = !lastRetryAction;
        }
    }

    function clearRetryAction() {
        lastRetryAction = null;
        const retryBtn = errorEl?.querySelector('.fsw-retry-button');
        if (retryBtn) {
            retryBtn.disabled = true;
        }
    }

    function setSearchStatus(message) {
        if (searchStatus) {
            searchStatus.textContent = message || '';
        }
    }

    function updateSelectedCount() {
        if (selectedCount) {
            selectedCount.textContent = selectedPrinters.length;
        }
    }

    function updateCompareButton() {
        if (compareButton) {
            const enabled = selectedPrinters.length >= 2;
            compareButton.disabled = !enabled;
            compareButton.setAttribute('aria-disabled', (!enabled).toString());
            compareButton.setAttribute(
                'aria-label',
                enabled
                    ? 'Compare selected printers'
                    : `Compare selected printers (disabled, ${selectedPrinters.length} of ${MAX_SELECTION} selected)`
            );
            compareHint.textContent = enabled
                ? 'Click to compare selected printers'
                : `Select at least 2 printers to compare (${selectedPrinters.length}/${MAX_SELECTION} selected)`;

            if ((window.fswCompareData?.debug || window.location.hostname === 'localhost') && enabled && compareButton.disabled) {
                console.warn('[FSW Compare] Compare button appears disabled despite 2+ selections.');
            }
        }
    }

    function renderSelectedTray() {
        selectedList.innerHTML = '';
        selectedList.style.display = selectedPrinters.length ? 'flex' : 'none';
        selectedPrinters.forEach((printer, index) => {
            const card = document.createElement('div');
            card.className = 'fsw-selected-card';
            card.setAttribute('data-id', printer.id);
            card.setAttribute('tabindex', '0');
            card.setAttribute('role', 'listitem');
            card.setAttribute('aria-label', `${printer.maker} ${printer.model} selected, press Enter to remove`);
            card.innerHTML = `
                <span class="fsw-card-label">${escapeHtml(printer.maker)} ${escapeHtml(printer.model)}</span>
                <button 
                    type="button" 
                    class="fsw-remove-btn" 
                    aria-label="Remove ${escapeHtml(printer.maker)} ${escapeHtml(printer.model)}"
                    data-index="${index}"
                >
                    ×
                </button>
            `;
            selectedList.appendChild(card);
            requestAnimationFrame(() => {
                card.classList.add('fsw-selected-card-enter');
            });
        });

        // Attach remove event listeners
        selectedList.querySelectorAll('.fsw-remove-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const idx = parseInt(e.target.dataset.index, 10);
                removePrinterAt(idx);
            });
        });

        selectedList.querySelectorAll('.fsw-selected-card').forEach(card => {
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    const cardId = card.getAttribute('data-id');
                    const idx = selectedPrinters.findIndex(p => String(p.id) === String(cardId));
                    if (idx !== -1) {
                        removePrinterAt(idx);
                    }
                }
            });
        });

        updateSelectedCount();
        updateCompareButton();
        updateUrl();
        updateListSelectionStates();
    }

    function updateListCount() {
        if (!listCount) return;
        const total = allPrinters.length;
        const showing = filteredPrinters.length;
        listCount.textContent = `Showing ${showing} of ${total} printers`;
        setSearchStatus(`Showing ${showing} of ${total} printers`);
    }

    function updateSelectAllState() {
        if (!selectAllCheckbox) return;
        if (!filteredPrinters.length) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.disabled = true;
            return;
        }

        selectAllCheckbox.disabled = false;
        const visibleIds = new Set(filteredPrinters.map(printer => String(printer.id)));
        const selectedVisible = selectedPrinters.filter(printer => visibleIds.has(String(printer.id)));

        if (selectedVisible.length === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (selectedVisible.length === filteredPrinters.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }

    function updateClearSelectionState() {
        if (!clearSelectionBtn) return;
        clearSelectionBtn.disabled = selectedPrinters.length === 0;
    }

    function updateListSelectionStates() {
        if (!printerList) return;
        const selectedIds = new Set(selectedPrinters.map(printer => String(printer.id)));
        const atLimit = !canSelectMore();
        printerList.querySelectorAll('.fsw-printer-checkbox').forEach(checkbox => {
            const checkboxId = String(checkbox.dataset.id || '');
            const isSelected = selectedIds.has(checkboxId);
            checkbox.checked = isSelected;
            checkbox.disabled = !isSelected && atLimit;
            const item = checkbox.closest('.fsw-printer-item');
            if (item) {
                item.classList.toggle('is-selected', isSelected);
                item.classList.toggle('is-disabled', !isSelected && atLimit);
                item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            }
        });
        updateSelectAllState();
        updateClearSelectionState();
    }

    function renderPrinterList(printers) {
        if (!printerList) return;
        filteredPrinters = printers;
        printerList.innerHTML = '';

        if (!printers.length) {
            const empty = document.createElement('div');
            empty.className = 'fsw-no-results';
            empty.textContent = 'No printers match your search.';
            printerList.appendChild(empty);
            updateListCount();
            updateSelectAllState();
            updateClearSelectionState();
            return;
        }

        const selectedIds = new Set(selectedPrinters.map(printer => String(printer.id)));
        const atLimit = !canSelectMore();

        printers.forEach(printer => {
            const item = document.createElement('div');
            item.className = 'fsw-printer-item';
            item.setAttribute('data-id', printer.id);
            item.setAttribute('role', 'listitem');

            const checkboxId = `fsw-printer-${printer.id}`;
            const label = document.createElement('label');
            label.className = 'fsw-printer-label';
            label.setAttribute('for', checkboxId);

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.id = checkboxId;
            checkbox.className = 'fsw-printer-checkbox';
            checkbox.dataset.id = printer.id;
            checkbox.setAttribute('aria-label', `${printer.maker} ${printer.model}`);

            const isSelected = selectedIds.has(String(printer.id));
            checkbox.checked = isSelected;
            checkbox.disabled = !isSelected && atLimit;
            item.classList.toggle('is-selected', isSelected);
            item.classList.toggle('is-disabled', !isSelected && atLimit);
            item.setAttribute('aria-selected', isSelected ? 'true' : 'false');

            checkbox.addEventListener('change', () => {
                if (checkbox.checked) {
                    const alreadySelected = selectedPrinters.some(p => String(p.id) === String(printer.id));
                    if (!alreadySelected && !canSelectMore()) {
                        checkbox.checked = false;
                        alert(`Maximum ${MAX_SELECTION} printers can be selected. Please remove one first.`);
                        return;
                    }
                    selectPrinter(printer);
                } else {
                    const idx = selectedPrinters.findIndex(p => String(p.id) === String(printer.id));
                    if (idx !== -1) {
                        removePrinterAt(idx);
                    }
                }
            });

            const name = document.createElement('span');
            name.className = 'fsw-printer-name';

            const maker = document.createElement('span');
            maker.className = 'fsw-maker';
            maker.textContent = printer.maker || '';

            const model = document.createElement('span');
            model.className = 'fsw-model';
            model.textContent = printer.model || '';

            name.appendChild(maker);
            name.appendChild(document.createTextNode(' '));
            name.appendChild(model);

            label.appendChild(checkbox);
            label.appendChild(name);
            item.appendChild(label);
            printerList.appendChild(item);
        });

        updateListCount();
        updateSelectAllState();
        updateClearSelectionState();
    }

    function renderStickyHeader(printers) {
        if (!stickyHeader) return;
        headerCardsContainer.innerHTML = '';
        printers.forEach(printer => {
            const card = document.createElement('div');
            card.className = 'fsw-header-card';
            card.innerHTML = `
                <div class="fsw-card-title">${escapeHtml(printer.maker)} ${escapeHtml(printer.model)}</div>
                <div class="fsw-card-stats">
                    <span>${formatBuildVolume(printer)}</span>
                    <span>${printer.enclosure ? 'Enclosed' : 'Open'}</span>
                    <span>${printer.max_hotend_temp_c}°C</span>
                </div>
                <button type="button" class="fsw-remove-btn-small" aria-label="Remove ${escapeHtml(printer.maker)} ${escapeHtml(printer.model)}">×</button>
            `;
            card.querySelector('.fsw-remove-btn-small').addEventListener('click', () => {
                const idx = selectedPrinters.findIndex(p => p.id === printer.id);
                if (idx !== -1) removePrinterAt(idx);
            });
            headerCardsContainer.appendChild(card);
        });
        stickyHeader.style.display = 'flex';
    }

    function renderGroupedSpecs(printers) {
        sectionsContainer.innerHTML = '';

        SPEC_SECTIONS.forEach(section => {
            // Determine if any fields in this section have differences
            let hasDifferences = false;
            if (showOnlyDifferences) {
                const firstValues = Object.values(printers[0]);
                for (let i = 1; i < printers.length; i++) {
                    for (const field of section.fields) {
                        const v1 = getFieldValue(printers[0], field);
                        const v2 = getFieldValue(printers[i], field);
                        if (!valuesEqual(v1, v2)) {
                            hasDifferences = true;
                            break;
                        }
                    }
                    if (hasDifferences) break;
                }
                if (!hasDifferences && printers.length > 1) return; // Hide section
            }

            // Create section
            const sectionEl = document.createElement('section');
            sectionEl.className = 'fsw-spec-section';
            sectionEl.id = `fsw-section-${section.id}`;

            // Section header with toggle
            const header = document.createElement('h3');
            header.className = 'fsw-section-header';
            header.innerHTML = `
                <button type="button" class="fsw-section-toggle" aria-expanded="${sectionsExpanded[section.id] !== false}" aria-controls="fsw-section-content-${section.id}">
                    <span class="fsw-toggle-icon">${sectionsExpanded[section.id] === false ? '▶' : '▼'}</span>
                    ${escapeHtml(section.title)}
                </button>
            `;
            header.querySelector('.fsw-section-toggle').addEventListener('click', () => {
                sectionsExpanded[section.id] = sectionsExpanded[section.id] === false;
                renderGroupedSpecs(selectedPrinters); // Re-render to update state
            });
            sectionEl.appendChild(header);

            // Section content (table)
            const content = document.createElement('div');
            content.id = `fsw-section-content-${section.id}`;
            content.style.display = sectionsExpanded[section.id] === false ? 'none' : 'block';

            const tableWrapper = document.createElement('div');
            tableWrapper.className = 'fsw-table-wrapper';

            const table = document.createElement('table');
            table.className = 'fsw-spec-table';

            // Header row
            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            headerRow.appendChild(createCell('th', 'Specification'));
            printers.forEach(printer => {
                headerRow.appendChild(createCell('th', `${printer.maker} ${printer.model}`));
            });
            thead.appendChild(headerRow);
            table.appendChild(thead);

            // Body rows
            const tbody = document.createElement('tbody');
            section.fields.forEach(field => {
                const row = document.createElement('tr');
                row.appendChild(createCell('td', field.label));

                const firstValue = getFieldValue(printers[0], field);
                let rowHasDiff = false;

                printers.forEach((printer, idx) => {
                    const value = getFieldValue(printer, field);
                    const cell = createCell('td', value);
                    if (idx > 0 && !valuesEqual(firstValue, value)) {
                        cell.classList.add('fsw-different');
                        rowHasDiff = true;
                    }
                    if (rowHasDiff && showOnlyDifferences) {
                        row.classList.add('fsw-diff-row');
                    }
                    row.appendChild(cell);
                });

                tbody.appendChild(row);
            });
            table.appendChild(tbody);
            tableWrapper.appendChild(table);
            content.appendChild(tableWrapper);
            sectionEl.appendChild(content);
            sectionsContainer.appendChild(sectionEl);
        });
    }

    function getFieldValue(printer, field) {
        let rawValue;
        // Special case: build_volume needs the whole printer to compute from x/y/z
        if (field.key === 'build_volume') {
            rawValue = printer;
        } else {
            rawValue = printer[field.key];
        }
        
        let value;
        if (field.format) {
            value = field.format(rawValue);
        } else {
            value = rawValue;
        }
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        return value + (field.suffix || '');
    }

    function valuesEqual(v1, v2) {
        return (v1 === v2) || (String(v1) === String(v2));
    }

    function createCell(tag, text) {
        const cell = document.createElement(tag);
        cell.textContent = text;
        return cell;
    }

    // Selection management
    function canSelectMore() {
        return selectedPrinters.length < MAX_SELECTION;
    }

    function selectPrinter(printer) {
        if (!canSelectMore()) {
            alert(`Maximum ${MAX_SELECTION} printers can be selected. Please remove one first.`);
            return;
        }

        // Avoid duplicates
        if (selectedPrinters.some(p => p.id === printer.id)) {
            return;
        }

        selectedPrinters.push(printer);
        renderSelectedTray();
    }

    function removePrinterAt(index) {
        const printer = selectedPrinters[index];
        const card = printer ? selectedList.querySelector(`.fsw-selected-card[data-id="${printer.id}"]`) : null;
        if (card) {
            card.classList.add('fsw-selected-card-exit');
            setTimeout(() => {
                selectedPrinters.splice(index, 1);
                renderSelectedTray();
                if (selectedPrinters.length < 2) {
                    hideResults();
                }
            }, 180);
        } else {
            selectedPrinters.splice(index, 1);
            renderSelectedTray();
            if (selectedPrinters.length < 2) {
                hideResults();
            }
        }
    }

    function updateUrl() {
        if (selectedPrinters.length >= 2) {
            const ids = selectedPrinters.map(p => p.id).join(',');
            const newUrl = `${window.location.pathname}?compare=${ids}`;
            window.history.replaceState({}, '', newUrl);
        } else {
            // Clear querystring if less than 2 selected
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, '', cleanUrl);
        }
    }

    function loadFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const compareParam = params.get('compare');
        if (!compareParam) return;

        const ids = compareParam.split(',').map(s => parseInt(s, 10)).filter(id => id > 0);
        if (ids.length < 2) return;

        // Load all printers if not already loaded, then find matches
        if (allPrinters.length === 0) {
            loadAllPrinters().then(() => {
                ids.forEach(id => {
                    const printer = allPrinters.find(p => p.id === id);
                    if (printer && !selectedPrinters.some(p => p.id === id)) {
                        selectedPrinters.push(printer);
                    }
                });
                renderSelectedTray();
                if (selectedPrinters.length >= 2) {
                    performComparison();
                }
            });
        } else {
            ids.forEach(id => {
                const printer = allPrinters.find(p => p.id === id);
                if (printer && !selectedPrinters.some(p => p.id === id)) {
                    selectedPrinters.push(printer);
                }
            });
            renderSelectedTray();
            if (selectedPrinters.length >= 2) {
                performComparison();
            }
        }
    }

    function showResults() {
        resultsSection.style.display = 'block';
        if (actionsSection) actionsSection.style.display = 'flex';
    }

    function hideResults() {
        resultsSection.style.display = 'none';
        if (actionsSection) actionsSection.style.display = 'none';
    }

    async function performComparison() {
        if (selectedPrinters.length < 2) return;

        setLoading(true);
        scheduleErrorDismiss();

        try {
            const data = await loadCompareData(selectedPrinters.map(p => p.id));
            if (data.printers && data.printers.length === selectedPrinters.length) {
                // Override selected printers with full data
                selectedPrinters = data.printers;
                renderComparison();
                hideError();
            } else {
                showError('Incomplete printer data received');
            }
        } finally {
            setLoading(false);
        }
    }

    function renderComparison() {
        renderStickyHeader(selectedPrinters);
        renderGroupedSpecs(selectedPrinters);
        showResults();
    }

    function copyShareLink() {
        const ids = selectedPrinters.map(p => p.id).join(',');
        const shareUrl = `${window.location.origin}${window.location.pathname}?compare=${ids}`;
        navigator.clipboard.writeText(shareUrl).then(() => {
            const originalText = shareLinkBtn.innerHTML;
            shareLinkBtn.innerHTML = '<span class="fsw-icon">✓</span> Copied!';
            setTimeout(() => {
                shareLinkBtn.innerHTML = originalText;
            }, 2000);
        }).catch(err => {
            alert('Failed to copy link: ' + err.message);
        });
    }

    // Event handlers
    const debouncedSearch = debounce(async function() {
        await refreshPrinterList();
    }, SEARCH_DEBOUNCE_MS);

    function getActiveFilters() {
        const filters = {};
        document.querySelectorAll('.fsw-filter-chip.active, .fsw-chip.active').forEach(chip => {
            const filter = chip.dataset.filter;
            const value = chip.dataset.value;
            if (filter === 'enclosure') {
                filters.enclosure = value === '1' ? 1 : 0;
            } else if (filter === 'build_volume') {
                filters.build_volume = value;
            } else {
                filters[filter] = value;
            }
        });
        return filters;
    }

    function isLargeBuild(printer) {
        const x = Number(printer.build_volume_x_mm) || 0;
        const y = Number(printer.build_volume_y_mm) || 0;
        const z = Number(printer.build_volume_z_mm) || 0;
        return Math.max(x, y, z) >= 300;
    }

    function applyClientFilters(printers, filters) {
        let results = printers;
        if (filters.build_volume) {
            results = results.filter(isLargeBuild);
        }
        return results;
    }

    function setListLoading(isLoading) {
        if (!printerList) return;
        if (isLoading) {
            printerList.innerHTML = '<div class="fsw-search-loading">Loading printers...</div>';
        }
    }

    async function refreshPrinterList() {
        const query = searchInput.value.trim();
        const activeFilterChips = getActiveFilters();
        scheduleErrorDismiss();
        setRetryAction(() => debouncedSearch());
        setListLoading(true);

        let printers = [];
        const serverFilters = Object.keys(activeFilterChips).filter(key => key !== 'build_volume');
        if (!query && serverFilters.length === 0) {
            printers = allPrinters;
        } else {
            const results = await searchPrinters(query, activeFilterChips);
            printers = results.printers || [];
        }

        printers = applyClientFilters(printers, activeFilterChips);
        renderPrinterList(printers);
    }

    function handleFilterClick(e) {
        if (e.target.classList.contains('fsw-filter-chip') || e.target.classList.contains('fsw-chip')) {
            e.target.classList.toggle('active');
            e.target.setAttribute('aria-pressed', e.target.classList.contains('active') ? 'true' : 'false');
            // Trigger search with current query
            debouncedSearch();
        }
    }

    function handleSearchInput(e) {
        if (e.target === searchInput) {
            const query = searchInput.value.trim();
            if (!query && Object.keys(getActiveFilters()).length === 0) {
                renderPrinterList(allPrinters);
            } else {
                debouncedSearch();
            }
        }
    }

    function handleSelectAllChange() {
        if (!filteredPrinters.length) return;
        const visibleIds = new Set(filteredPrinters.map(printer => String(printer.id)));

        if (selectAllCheckbox.checked) {
            const toAdd = [];
            filteredPrinters.forEach(printer => {
                if (toAdd.length + selectedPrinters.length >= MAX_SELECTION) {
                    return;
                }
                if (!selectedPrinters.some(p => String(p.id) === String(printer.id))) {
                    toAdd.push(printer);
                }
            });
            if (!toAdd.length && !canSelectMore()) {
                alert(`Maximum ${MAX_SELECTION} printers can be selected. Please remove one first.`);
                updateSelectAllState();
                return;
            }
            if (toAdd.length) {
                selectedPrinters = selectedPrinters.concat(toAdd);
                renderSelectedTray();
            }
        } else {
            selectedPrinters = selectedPrinters.filter(printer => !visibleIds.has(String(printer.id)));
            renderSelectedTray();
            if (selectedPrinters.length < 2) {
                hideResults();
            }
        }
    }

    function handleClearSelection() {
        if (!selectedPrinters.length) return;
        selectedPrinters = [];
        renderSelectedTray();
        hideResults();
    }

    // Initialize
    function init() {
        // Event listeners
        searchInput.addEventListener('input', handleSearchInput);
        document.querySelector('.fsw-filters')?.addEventListener('click', handleFilterClick);
        selectAllCheckbox?.addEventListener('change', handleSelectAllChange);
        clearSelectionBtn?.addEventListener('click', handleClearSelection);
        compareButton?.addEventListener('click', () => {
            if (selectedPrinters.length >= 2) {
                performComparison();
            }
        });
        diffToggleBtn?.addEventListener('click', () => {
            showOnlyDifferences = !showOnlyDifferences;
            diffToggleBtn.classList.toggle('active', showOnlyDifferences);
            diffToggleBtn.setAttribute('aria-pressed', showOnlyDifferences);
            renderGroupedSpecs(selectedPrinters);
        });
        shareLinkBtn?.addEventListener('click', copyShareLink);
        errorEl?.querySelector('.fsw-retry-button')?.addEventListener('click', () => {
            if (lastRetryAction) {
                hideError();
                lastRetryAction();
            }
        });
        clearRetryAction();

        // Load data and check URL
        loadAllPrinters().then(() => {
            renderPrinterList(allPrinters);
            loadFromUrl();
        });
    }

    // Start
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
