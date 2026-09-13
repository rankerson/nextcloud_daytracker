(() => {
    'use strict';

    const REQUIRED_IDS = [
        'dt-app', 'dt-view-day', 'dt-view-week', 'dt-admin-open',
        'dt-date-prev', 'dt-date', 'dt-date-next', 'dt-today',
        'dt-timeslice', 'dt-state', 'dt-category-list', 'dt-day-view',
        'dt-week-view', 'dt-admin-modal', 'dt-admin-overlay',
        'dt-admin-panel', 'dt-admin-close', 'dt-admin-content',
        'dt-admin-add-timeslice', 'dt-admin-add-category',
        'dt-admin-timeslices', 'dt-admin-categories', 'dt-csv-export',
        'dt-admin-save', 'dt-admin-cancel'
    ];

    const STATUS = {
        ready: 'Bereit', loading: 'Lade ...', saving: 'Speichere ...',
        saved: 'Gespeichert', adminSaved: 'Administration gespeichert',
        loadError: 'Fehler beim Laden', saveError: 'Fehler beim Speichern',
        adminSaveError: 'Fehler beim Speichern der Administration'
    };

    let catalog = { categories: [], timeslices: [] };
    let timeslices = [];
    let selectedDate = localDateString(new Date());
    let selectedTimesliceId = null;
    let entries = [];
    let entriesBySliceAndCategory = new Map();
    let currentView = 'day';
    let adminDraft = null;
    let temporarySequence = 0;
    let dom = {};

    document.addEventListener('DOMContentLoaded', initialize);

    async function initialize() {
        const missing = REQUIRED_IDS.filter((id) => document.getElementById(id) === null);
        if (missing.length > 0) {
            console.error('Daytracker wurde kontrolliert abgebrochen. Fehlende Pflicht-IDs:', missing);
            return;
        }
        dom = Object.fromEntries(REQUIRED_IDS.map((id) => [id, document.getElementById(id)]));
        dom['dt-date'].value = selectedDate;
        dom['dt-csv-export'].href = generateUrl('/apps/daytracker/export.csv');
        bindStaticEvents();
        exposeDebugData();
        await reloadAll();
    }

    function bindStaticEvents() {
        dom['dt-view-day'].addEventListener('click', () => setView('day'));
        dom['dt-view-week'].addEventListener('click', () => setView('week'));
        dom['dt-date-prev'].addEventListener('click', () => changePeriod(-1));
        dom['dt-date-next'].addEventListener('click', () => changePeriod(1));
        dom['dt-today'].addEventListener('click', () => setSelectedDate(localDateString(new Date())));
        dom['dt-date'].addEventListener('change', () => {
            if (isDateString(dom['dt-date'].value)) setSelectedDate(dom['dt-date'].value);
        });
        dom['dt-timeslice'].addEventListener('change', () => {
            selectedTimesliceId = toPositiveInt(dom['dt-timeslice'].value);
            renderCurrentView();
        });
        dom['dt-admin-open'].addEventListener('click', openAdmin);
        dom['dt-admin-close'].addEventListener('click', closeAdmin);
        dom['dt-admin-cancel'].addEventListener('click', closeAdmin);
        dom['dt-admin-overlay'].addEventListener('click', closeAdmin);
        dom['dt-admin-add-timeslice'].addEventListener('click', addTimeslice);
        dom['dt-admin-add-category'].addEventListener('click', addCategory);
        dom['dt-admin-save'].addEventListener('click', saveAdministration);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !dom['dt-admin-modal'].hidden) closeAdmin();
        });
    }

    async function reloadAll() {
        setStatus(STATUS.loading);
        try {
            await loadCatalog();
            await loadEntriesForCurrentView();
            renderCurrentView();
            setStatus(STATUS.ready);
        } catch (error) {
            console.error('Daytracker-Ladefehler:', error);
            setStatus(STATUS.loadError, 'error');
        }
    }

    async function loadCatalog() {
        const response = await fetchJson(generateUrl('/apps/daytracker/api/catalog'));
        catalog = {
            categories: Array.isArray(response.categories) ? response.categories : [],
            timeslices: Array.isArray(response.timeslices) ? response.timeslices : []
        };
        timeslices = catalog.timeslices;
        if (!timeslices.some((item) => Number(item.id) === selectedTimesliceId)) {
            selectedTimesliceId = timeslices.length > 0 ? Number(timeslices[0].id) : null;
        }
        renderTimesliceSelect();
    }

    async function loadEntriesForCurrentView() {
        const dates = currentView === 'week' ? weekDates(selectedDate) : [selectedDate];
        const results = await Promise.all(dates.map((date) => fetchJson(generateUrl(`/apps/daytracker/api/day/${date}`))));
        entries = results.flatMap((result) => (result.entries || []).map((entry) => ({ ...entry, entry_date: result.date })));
        rebuildEntryIndex();
    }

    function rebuildEntryIndex() {
        entriesBySliceAndCategory = new Map();
        for (const entry of entries) {
            entriesBySliceAndCategory.set(entryKey(entry.entry_date, entry.timeslice_id, entry.category_id), entry);
        }
    }

    function renderTimesliceSelect() {
        dom['dt-timeslice'].replaceChildren(...timeslices.map((timeslice) => {
            const option = document.createElement('option');
            option.value = String(timeslice.id);
            option.textContent = timeslice.name;
            option.selected = Number(timeslice.id) === selectedTimesliceId;
            return option;
        }));
        dom['dt-timeslice'].disabled = timeslices.length === 0;
    }

    async function setView(view) {
        if (view !== 'day' && view !== 'week') return;
        currentView = view;
        dom['dt-view-day'].classList.toggle('dt-active', view === 'day');
        dom['dt-view-week'].classList.toggle('dt-active', view === 'week');
        dom['dt-view-day'].setAttribute('aria-pressed', String(view === 'day'));
        dom['dt-view-week'].setAttribute('aria-pressed', String(view === 'week'));
        applyViewVisibility();
        await reloadEntriesAndRender();
    }

    async function changePeriod(direction) {
        const date = parseLocalDate(selectedDate);
        date.setDate(date.getDate() + direction * (currentView === 'week' ? 7 : 1));
        await setSelectedDate(localDateString(date));
    }

    async function setSelectedDate(date) {
        selectedDate = date;
        dom['dt-date'].value = date;
        await reloadEntriesAndRender();
    }

    async function reloadEntriesAndRender() {
        setStatus(STATUS.loading);
        try {
            await loadEntriesForCurrentView();
            renderCurrentView();
            setStatus(STATUS.ready);
        } catch (error) {
            console.error('Daytracker-Ladefehler:', error);
            setStatus(STATUS.loadError, 'error');
        }
    }

    function renderCurrentView() {
        applyViewVisibility();
        if (currentView === 'week') renderWeekView();
        else renderDayView();
    }

    function applyViewVisibility() {
        const showDay = currentView === 'day';
        const dayView = dom['dt-day-view'];
        const weekView = dom['dt-week-view'];

        dayView.hidden = !showDay;
        weekView.hidden = showDay;
        dayView.style.display = showDay ? '' : 'none';
        weekView.style.display = showDay ? 'none' : '';
        dayView.setAttribute('aria-hidden', String(!showDay));
        weekView.setAttribute('aria-hidden', String(showDay));
    }

    function renderDayView() {
        const fragment = document.createDocumentFragment();
        for (const category of catalog.categories) {
            const entry = getEntry(selectedDate, selectedTimesliceId, category.id);
            fragment.append(createCategoryCard(category, entry));
        }
        dom['dt-category-list'].replaceChildren(fragment);
    }

    function createCategoryCard(category, entry) {
        const card = element('article', 'dt-category-card');
        card.id = `dt-category-${category.id}`;
        const header = element('header', 'dt-category-header');
        const title = element('h2', 'dt-category-title', category.name);
        const status = element('span', `dt-category-status${isSet(entry) ? ' dt-set' : ''}`, isSet(entry) ? 'gesetzt' : 'offen');
        header.append(title, status);
        card.append(header);

        if (category.input_mode === 'options' || category.input_mode === 'both') {
            const optionList = element('div', 'dt-option-list');
            for (const option of category.options || []) {
                const button = element('button', 'button dt-option-button', option.label);
                button.type = 'button';
                button.id = `dt-option-${category.id}-${option.id}`;
                const active = Number(entry?.option_id) === Number(option.id);
                button.setAttribute('aria-pressed', String(active));
                button.addEventListener('click', () => saveCategoryValue(category, option.id, currentText(category.id)));
                optionList.append(button);
            }
            card.append(optionList);
        }
        if (category.input_mode === 'text' || category.input_mode === 'both') {
            const control = element('div', 'dt-text-control');
            const label = element('label', 'dt-visually-hidden', `Freitext für ${category.name}`);
            label.htmlFor = `dt-text-${category.id}`;
            const textarea = element('textarea');
            textarea.id = `dt-text-${category.id}`;
            textarea.name = `text-${category.id}`;
            textarea.value = entry?.text_value || '';
            const button = element('button', 'button dt-text-save', 'Speichern');
            button.type = 'button';
            button.id = `dt-text-save-${category.id}`;
            button.addEventListener('click', () => saveCategoryValue(category, entry?.option_id ?? null, textarea.value));
            control.append(label, textarea, button);
            card.append(control);
        }
        return card;
    }

    function renderWeekView() {
        const dates = weekDates(selectedDate);
        const table = element('table', 'dt-week-table');
        const thead = document.createElement('thead');
        const headingRow = document.createElement('tr');
        headingRow.append(element('th', '', 'Kategorie'));
        for (const date of dates) {
            const heading = document.createElement('th');
            heading.scope = 'col';
            heading.textContent = formatWeekHeading(date);
            headingRow.append(heading);
        }
        thead.append(headingRow);
        const tbody = document.createElement('tbody');
        for (const category of catalog.categories) {
            const row = document.createElement('tr');
            const categoryHeader = element('th', '', category.name);
            categoryHeader.scope = 'row';
            row.append(categoryHeader);
            for (const date of dates) row.append(createWeekCell(category, date));
            tbody.append(row);
        }
        table.append(thead, tbody);
        dom['dt-week-view'].replaceChildren(table);
    }

    function createWeekCell(category, date) {
        const entry = getEntry(date, selectedTimesliceId, category.id);
        const cell = element('td', 'dt-week-cell');
        cell.id = `dt-week-cell-${date}-${selectedTimesliceId}-${category.id}`;
        cell.append(element('div', `dt-week-status${isSet(entry) ? ' dt-set' : ''}`, isSet(entry) ? 'gesetzt' : 'offen'));
        if (category.input_mode === 'options' || category.input_mode === 'both') {
            const list = element('div', 'dt-week-option-list');
            for (const option of category.options || []) {
                const button = element('button', 'button dt-week-option', option.label);
                button.type = 'button';
                button.id = `dt-week-option-${date}-${selectedTimesliceId}-${category.id}-${option.id}`;
                button.setAttribute('aria-pressed', String(Number(entry?.option_id) === Number(option.id)));
                button.addEventListener('click', () => saveWeekValue(category, date, option.id, weekTextId(date, category.id)));
                list.append(button);
            }
            cell.append(list);
        }
        if (category.input_mode === 'text' || category.input_mode === 'both') {
            const label = element('label', 'dt-visually-hidden', `Freitext ${category.name} ${date}`);
            label.htmlFor = weekTextId(date, category.id);
            const textarea = element('textarea', 'dt-week-text');
            textarea.id = weekTextId(date, category.id);
            textarea.name = `week-text-${date}-${category.id}`;
            textarea.value = entry?.text_value || '';
            const save = element('button', 'button dt-week-text-save', 'Speichern');
            save.type = 'button';
            save.id = `dt-week-text-save-${date}-${selectedTimesliceId}-${category.id}`;
            save.addEventListener('click', () => saveCategoryValue(category, entry?.option_id ?? null, textarea.value, date));
            cell.append(label, textarea, save);
        }
        return cell;
    }

    async function saveWeekValue(category, date, optionId, textId) {
        const textarea = document.getElementById(textId);
        await saveCategoryValue(category, optionId, textarea ? textarea.value : '', date);
    }

    async function saveCategoryValue(category, optionId, textValue, date = selectedDate) {
        setStatus(STATUS.saving);
        try {
            const result = await postJson(generateUrl(`/apps/daytracker/api/day/${date}`), {
                category_id: Number(category.id), timeslice_id: Number(selectedTimesliceId),
                option_id: optionId === null ? null : Number(optionId), text_value: textValue || ''
            });
            const saved = { ...result.entry, entry_date: date };
            entriesBySliceAndCategory.set(entryKey(date, selectedTimesliceId, category.id), saved);
            entries = Array.from(entriesBySliceAndCategory.values());
            renderCurrentView();
            setStatus(STATUS.saved, 'success');
        } catch (error) {
            console.error('Daytracker-Speicherfehler:', error);
            setStatus(STATUS.saveError, 'error');
        }
    }

    function openAdmin() {
        adminDraft = structuredClone(catalog);
        renderAdmin();
        dom['dt-admin-modal'].hidden = false;
        document.body.classList.add('dt-modal-open');
        dom['dt-admin-close'].focus();
    }

    function closeAdmin() {
        dom['dt-admin-modal'].hidden = true;
        document.body.classList.remove('dt-modal-open');
        adminDraft = null;
        dom['dt-admin-open'].focus();
    }

    function renderAdmin(preserveScroll = false) {
        if (!adminDraft) return;
        const oldScroll = preserveScroll ? dom['dt-admin-content'].scrollTop : 0;
        dom['dt-admin-timeslices'].replaceChildren(...adminDraft.timeslices.map((item, index) => createAdminTimeslice(item, index)));
        dom['dt-admin-categories'].replaceChildren(...adminDraft.categories.map((item, index) => createAdminCategory(item, index)));
        if (preserveScroll) {
            requestAnimationFrame(() => {
                const maximum = Math.max(0, dom['dt-admin-content'].scrollHeight - dom['dt-admin-content'].clientHeight);
                dom['dt-admin-content'].scrollTop = Math.min(oldScroll, maximum);
            });
        }
    }

    function createAdminTimeslice(item, index) {
        const key = clientKey(item, 'ts');
        const row = element('div', 'dt-admin-timeslice-row');
        row.id = `dt-admin-timeslice-${key}`;
        row.append(readonlyField(`dt-admin-timeslice-id-${key}`, 'Technische ID', item.id ?? 'neu'));
        row.append(inputField(`dt-admin-timeslice-name-${key}`, `timeslice-name-${key}`, 'Name', item.name, (value) => { item.name = value; }));
        row.append(actionButtons([
            ['↑', 'Zeitscheibe nach oben', () => move(adminDraft.timeslices, index, -1)],
            ['↓', 'Zeitscheibe nach unten', () => move(adminDraft.timeslices, index, 1)],
            ['Löschen', 'Zeitscheibe löschen', () => removeTimeslice(index)]
        ]));
        return row;
    }

    function createAdminCategory(category, index) {
        const key = clientKey(category, 'cat');
        const wrapper = element('section', 'dt-admin-category');
        wrapper.id = `dt-admin-category-${key}`;
        const grid = element('div', 'dt-admin-category-grid');
        grid.append(readonlyField(`dt-admin-category-id-${key}`, 'Technische ID', category.id ?? 'neu'));
        grid.append(inputField(`dt-admin-category-name-${key}`, `category-name-${key}`, 'Name', category.name, (value) => { category.name = value; }));
        grid.append(selectField(`dt-admin-category-mode-${key}`, `category-mode-${key}`, 'Eingabemodus', category.input_mode, [
            ['options', 'Auswahl'], ['text', 'Freitext'], ['both', 'Auswahl + Freitext']
        ], (value) => { category.input_mode = value; }));
        grid.append(numberField(`dt-admin-category-dashboard-limit-${key}`, `category-limit-${key}`, 'Dashboard-Anzahl', category.dashboard_limit, (value) => { category.dashboard_limit = Math.max(0, value); }));
        grid.append(checkboxField(
            `dt-admin-category-dashboard-enabled-${key}`,
            `category-dashboard-enabled-${key}`,
            'Im Dashboard anzeigen',
            category.dashboard_enabled !== false,
            (checked) => { category.dashboard_enabled = checked; }
        ));
        grid.append(actionButtons([
            ['↑', 'Kategorie nach oben', () => move(adminDraft.categories, index, -1)],
            ['↓', 'Kategorie nach unten', () => move(adminDraft.categories, index, 1)],
            ['Löschen', 'Kategorie löschen', () => { adminDraft.categories.splice(index, 1); renderAdmin(true); }]
        ]));
        wrapper.append(grid);
        const options = element('div', 'dt-admin-options');
        for (const [optionIndex, option] of (category.options || []).entries()) {
            options.append(createAdminOption(category, option, optionIndex));
        }
        const add = element('button', 'button', 'Option hinzufügen');
        add.type = 'button';
        add.addEventListener('click', () => {
            category.options.push({ id: null, label: '', sort_order: category.options.length + 1, _clientKey: nextKey('opt') });
            renderAdmin(true);
        });
        wrapper.append(options, add);
        return wrapper;
    }

    function createAdminOption(category, option, index) {
        const categoryKey = clientKey(category, 'cat');
        const key = clientKey(option, 'opt');
        const row = element('div', 'dt-admin-option-row');
        row.id = `dt-admin-option-${categoryKey}-${key}`;
        row.append(readonlyField(`dt-admin-option-id-${categoryKey}-${key}`, 'Technische ID', option.id ?? 'neu'));
        row.append(inputField(`dt-admin-option-label-${categoryKey}-${key}`, `option-label-${categoryKey}-${key}`, 'Label', option.label, (value) => { option.label = value; }));
        row.append(actionButtons([
            ['↑', 'Option nach oben', () => move(category.options, index, -1)],
            ['↓', 'Option nach unten', () => move(category.options, index, 1)],
            ['Löschen', 'Option löschen', () => { category.options.splice(index, 1); renderAdmin(true); }]
        ]));
        return row;
    }

    function addTimeslice() {
        adminDraft.timeslices.push({ id: null, name: '', sort_order: adminDraft.timeslices.length + 1, _clientKey: nextKey('ts') });
        renderAdmin(true);
    }

    function addCategory() {
        adminDraft.categories.push({ id: null, name: '', input_mode: 'options', dashboard_limit: 4, dashboard_enabled: true, options: [], sort_order: adminDraft.categories.length + 1, _clientKey: nextKey('cat') });
        renderAdmin(true);
    }

    function removeTimeslice(index) {
        if (adminDraft.timeslices.length <= 1) {
            setStatus('Mindestens eine Zeitscheibe muss erhalten bleiben.', 'error');
            return;
        }
        adminDraft.timeslices.splice(index, 1);
        renderAdmin(true);
    }

    function move(list, index, direction) {
        const target = index + direction;
        if (target < 0 || target >= list.length) return;
        [list[index], list[target]] = [list[target], list[index]];
        renderAdmin(true);
    }

    async function saveAdministration() {
        if (!adminDraft) return;
        captureAdminInputs();
        setStatus(STATUS.saving);
        dom['dt-admin-save'].disabled = true;
        try {
            const payload = {
                timeslices: adminDraft.timeslices.map((item) => ({ id: item.id ?? null, name: item.name.trim() })),
                categories: adminDraft.categories.map((category) => ({
                    id: category.id ?? null, name: category.name.trim(), input_mode: category.input_mode,
                    dashboard_limit: Number(category.dashboard_limit),
                    dashboard_enabled: category.dashboard_enabled !== false,
                    options: (category.options || []).map((option) => ({ id: option.id ?? null, label: option.label.trim() }))
                }))
            };
            validateAdminPayload(payload);
            const result = await postJson(generateUrl('/apps/daytracker/api/catalog'), payload);
            catalog = result.catalog;
            timeslices = catalog.timeslices;
            if (!timeslices.some((item) => Number(item.id) === selectedTimesliceId)) {
                selectedTimesliceId = timeslices.length ? Number(timeslices[0].id) : null;
            }
            renderTimesliceSelect();
            closeAdmin();
            await loadEntriesForCurrentView();
            renderCurrentView();
            setStatus(STATUS.adminSaved, 'success');
        } catch (error) {
            console.error('Daytracker-Administrationsfehler:', error);
            setStatus(STATUS.adminSaveError, 'error');
        } finally {
            dom['dt-admin-save'].disabled = false;
        }
    }

    function captureAdminInputs() {
        for (const timeslice of adminDraft.timeslices) {
            const key = clientKey(timeslice, 'ts');
            timeslice.name = document.getElementById(`dt-admin-timeslice-name-${key}`)?.value ?? timeslice.name;
        }
        for (const category of adminDraft.categories) {
            const key = clientKey(category, 'cat');
            category.name = document.getElementById(`dt-admin-category-name-${key}`)?.value ?? category.name;
            category.input_mode = document.getElementById(`dt-admin-category-mode-${key}`)?.value ?? category.input_mode;
            category.dashboard_limit = Number(document.getElementById(`dt-admin-category-dashboard-limit-${key}`)?.value ?? category.dashboard_limit);
            category.dashboard_enabled = document.getElementById(`dt-admin-category-dashboard-enabled-${key}`)?.checked ?? (category.dashboard_enabled !== false);
            for (const option of category.options || []) {
                const optionKey = clientKey(option, 'opt');
                option.label = document.getElementById(`dt-admin-option-label-${key}-${optionKey}`)?.value ?? option.label;
            }
        }
    }

    function validateAdminPayload(payload) {
        if (payload.timeslices.length === 0) throw new Error('Mindestens eine Zeitscheibe muss erhalten bleiben.');
        if (payload.timeslices.some((item) => !item.name)) throw new Error('Zeitscheibennamen dürfen nicht leer sein.');
        for (const category of payload.categories) {
            if (!category.name) throw new Error('Kategorienamen dürfen nicht leer sein.');
            if (!Number.isInteger(category.dashboard_limit) || category.dashboard_limit < 0) throw new Error('Dashboard-Anzahl ist ungültig.');
            if (category.options.some((option) => !option.label)) throw new Error('Optionsbezeichnungen dürfen nicht leer sein.');
        }
    }

    function readonlyField(id, labelText, value) {
        const field = element('div', 'dt-field');
        const label = element('label', '', labelText); label.htmlFor = id;
        const input = document.createElement('input'); input.id = id; input.name = id; input.value = String(value); input.readOnly = true; input.className = 'dt-technical-id';
        field.append(label, input); return field;
    }

    function inputField(id, name, labelText, value, onInput) {
        const field = element('div', 'dt-field');
        const label = element('label', '', labelText); label.htmlFor = id;
        const input = document.createElement('input'); input.id = id; input.name = name; input.type = 'text'; input.value = value || '';
        input.addEventListener('input', () => onInput(input.value)); field.append(label, input); return field;
    }

    function numberField(id, name, labelText, value, onInput) {
        const field = element('div', 'dt-field');
        const label = element('label', '', labelText); label.htmlFor = id;
        const input = document.createElement('input'); input.id = id; input.name = name; input.type = 'number'; input.min = '0'; input.step = '1'; input.value = String(value ?? 0);
        input.addEventListener('input', () => onInput(Number(input.value))); field.append(label, input); return field;
    }

    function checkboxField(id, name, labelText, checked, onChange) {
        const field = element('div', 'dt-field dt-checkbox-field');
        const input = document.createElement('input');
        input.id = id;
        input.name = name;
        input.type = 'checkbox';
        input.checked = Boolean(checked);
        const label = element('label', '', labelText);
        label.htmlFor = id;
        input.addEventListener('change', () => onChange(input.checked));
        field.append(input, label);
        return field;
    }

    function selectField(id, name, labelText, value, choices, onChange) {
        const field = element('div', 'dt-field');
        const label = element('label', '', labelText); label.htmlFor = id;
        const select = document.createElement('select'); select.id = id; select.name = name;
        for (const [choiceValue, choiceLabel] of choices) {
            const option = document.createElement('option'); option.value = choiceValue; option.textContent = choiceLabel; option.selected = choiceValue === value; select.append(option);
        }
        select.addEventListener('change', () => onChange(select.value)); field.append(label, select); return field;
    }

    function actionButtons(definitions) {
        const group = element('div', 'dt-admin-row-actions');
        for (const [text, label, handler] of definitions) {
            const button = element('button', 'button', text); button.type = 'button'; button.setAttribute('aria-label', label); button.title = label; button.addEventListener('click', handler); group.append(button);
        }
        return group;
    }

    async function fetchJson(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        return parseResponse(response);
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: OC.requestToken },
            body: JSON.stringify(payload)
        });
        return parseResponse(response);
    }

    async function parseResponse(response) {
        let payload;
        try { payload = await response.json(); } catch (error) { throw new Error(`Ungültige Serverantwort (${response.status}).`); }
        if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`);
        return payload;
    }

    function exposeDebugData() {
        window.daytrackerDebug = {
            get catalog() { return catalog; }, get timeslices() { return timeslices; },
            get selectedDate() { return selectedDate; }, get selectedTimesliceId() { return selectedTimesliceId; },
            get entries() { return entries; }
        };
    }

    function setStatus(message, kind = '') {
        dom['dt-state'].textContent = message;
        dom['dt-state'].classList.toggle('dt-error', kind === 'error');
        dom['dt-state'].classList.toggle('dt-success', kind === 'success');
    }
    function getEntry(date, timesliceId, categoryId) { return entriesBySliceAndCategory.get(entryKey(date, timesliceId, categoryId)); }
    function entryKey(date, timesliceId, categoryId) { return `${date}|${Number(timesliceId)}|${Number(categoryId)}`; }
    function isSet(entry) { return Boolean(entry && (entry.option_id !== null || String(entry.text_value || '').trim() !== '')); }
    function currentText(categoryId) { return document.getElementById(`dt-text-${categoryId}`)?.value || ''; }
    function weekTextId(date, categoryId) { return `dt-week-text-${date}-${selectedTimesliceId}-${categoryId}`; }
    function generateUrl(path) { return window.OC?.generateUrl ? OC.generateUrl(path) : `/index.php${path}`; }
    function element(tag, className = '', text = '') { const node = document.createElement(tag); if (className) node.className = className; if (text !== '') node.textContent = text; return node; }
    function clientKey(item, prefix) { if (item.id !== null && item.id !== undefined) return String(item.id); if (!item._clientKey) item._clientKey = nextKey(prefix); return item._clientKey; }
    function nextKey(prefix) { temporarySequence += 1; return `new-${prefix}-${temporarySequence}`; }
    function toPositiveInt(value) { const parsed = Number(value); return Number.isInteger(parsed) && parsed > 0 ? parsed : null; }
    function isDateString(value) { const date = parseLocalDate(value); return !Number.isNaN(date.getTime()) && localDateString(date) === value; }
    function parseLocalDate(value) { const [year, month, day] = value.split('-').map(Number); return new Date(year, month - 1, day); }
    function localDateString(date) { const year = date.getFullYear(); const month = String(date.getMonth() + 1).padStart(2, '0'); const day = String(date.getDate()).padStart(2, '0'); return `${year}-${month}-${day}`; }
    function weekDates(dateString) { const date = parseLocalDate(dateString); const weekday = date.getDay() || 7; date.setDate(date.getDate() - weekday + 1); return Array.from({ length: 7 }, (_, index) => { const day = new Date(date); day.setDate(date.getDate() + index); return localDateString(day); }); }
    function formatWeekHeading(dateString) { return new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' }).format(parseLocalDate(dateString)); }
})();
