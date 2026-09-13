(function () {
    'use strict';

    const requiredIds = ['dt-app', 'dt-view-day', 'dt-view-week', 'dt-admin-open', 'dt-date-prev', 'dt-date', 'dt-date-next', 'dt-today', 'dt-timeslice', 'dt-state', 'dt-category-list', 'dt-week-view', 'dt-admin-modal', 'dt-admin-overlay', 'dt-admin-panel', 'dt-admin-close', 'dt-admin-content', 'dt-admin-add-timeslice', 'dt-admin-add-category', 'dt-csv-export', 'dt-admin-save', 'dt-admin-cancel'];
    const elements = {};
    let catalog = [];
    let timeslices = [];
    let selectedDate = '';
    let selectedTimesliceId = '';
    let viewMode = 'day';
    let dayEntries = {};
    let weekEntries = {};

    const byId = id => document.getElementById(id);
    const apiUrl = path => window.OC && typeof OC.generateUrl === 'function' ? OC.generateUrl('/apps/daytracker' + path) : '/index.php/apps/daytracker' + path;
    const requestToken = () => window.OC && typeof OC.requestToken === 'string' ? OC.requestToken : '';
    const entryKey = (timesliceId, categoryId) => String(timesliceId) + ':' + String(categoryId);

    function dateString(date = new Date()) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    }

    function shiftDate(value, days) {
        const parts = value.split('-').map(Number);
        const date = new Date(parts[0], parts[1] - 1, parts[2]);
        date.setDate(date.getDate() + days);
        return dateString(date);
    }

    function mondayOf(value) {
        const parts = value.split('-').map(Number);
        const date = new Date(parts[0], parts[1] - 1, parts[2]);
        const day = date.getDay() || 7;
        date.setDate(date.getDate() - day + 1);
        return dateString(date);
    }

    function createElement(tagName, className = '', text) {
        const element = document.createElement(tagName);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    }

    function clearNode(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function setState(text, type = '') {
        elements.state.textContent = text;
        elements.state.className = 'dt-state' + (type ? ' dt-state-' + type : '');
    }

    async function getJson(path) {
        const response = await fetch(apiUrl(path), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('GET ' + path + ' failed with HTTP ' + response.status);
        return response.json();
    }

    async function postJson(path, payload) {
        const response = await fetch(apiUrl(path), {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: requestToken() },
            body: JSON.stringify(payload)
        });
        if (!response.ok) {
            let message = 'POST ' + path + ' failed with HTTP ' + response.status;
            try { const data = await response.json(); if (data.error) message += ': ' + data.error; } catch (error) { message += ': response is not JSON'; }
            throw new Error(message);
        }
        return response.json();
    }

    function mapEntries(entries) {
        const map = {};
        (entries || []).forEach(entry => { map[entryKey(entry.timeslice_id, entry.category_id)] = entry; });
        return map;
    }

    async function loadCatalog() {
        const data = await getJson('/api/catalog');
        catalog = Array.isArray(data.categories) ? data.categories : [];
        timeslices = Array.isArray(data.timeslices) ? data.timeslices : [];
        if (!timeslices.some(item => String(item.id) === selectedTimesliceId)) selectedTimesliceId = timeslices.length ? String(timeslices[0].id) : '';
        renderTimesliceSelect();
    }

    async function loadDay(date) {
        return mapEntries((await getJson('/api/day/' + encodeURIComponent(date))).entries);
    }

    function renderTimesliceSelect() {
        clearNode(elements.timeslice);
        timeslices.forEach(timeslice => {
            const option = createElement('option', '', timeslice.name);
            option.value = String(timeslice.id);
            option.selected = option.value === selectedTimesliceId;
            elements.timeslice.appendChild(option);
        });
    }

    function statusBadge(entry) {
        return createElement('span', 'dt-status' + (entry ? ' dt-status-set' : ''), entry ? 'gesetzt' : 'offen');
    }

    function renderOptionButtons(category, entry, date, container) {
        (category.options || []).forEach(option => {
            const active = entry && String(entry.option_id) === String(option.id);
            const button = createElement('button', 'dt-option-button' + (active ? ' dt-option-active' : ''), option.label);
            button.type = 'button';
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.addEventListener('click', () => saveEntry(category, date, option.id, entry ? entry.text_value : ''));
            container.appendChild(button);
        });
    }

    function renderTextEditor(category, entry, date) {
        const row = createElement('div', 'dt-text-row');
        const textarea = createElement('textarea', 'dt-text-input');
        textarea.value = entry ? entry.text_value || '' : '';
        textarea.placeholder = 'Freitext eingeben';
        textarea.rows = 3;
        textarea.name = 'dt-text-' + date + '-' + selectedTimesliceId + '-' + category.id;
        const button = createElement('button', 'dt-button dt-text-save', 'Speichern');
        button.type = 'button';
        button.addEventListener('click', () => saveEntry(category, date, entry ? entry.option_id : null, textarea.value));
        row.append(textarea, button);
        return row;
    }

    function renderCategoryCard(category, entry, date) {
        const card = createElement('article', 'dt-category-card');
        const header = createElement('div', 'dt-card-head');
        header.append(createElement('h2', '', category.name), statusBadge(entry));
        card.appendChild(header);
        if (category.input_mode !== 'text') {
            const options = createElement('div', 'dt-options');
            renderOptionButtons(category, entry, date, options);
            card.appendChild(options);
        }
        if (category.input_mode !== 'options') card.appendChild(renderTextEditor(category, entry, date));
        return card;
    }

    function renderDay() {
        clearNode(elements.categoryList);
        catalog.forEach(category => elements.categoryList.appendChild(renderCategoryCard(category, dayEntries[entryKey(selectedTimesliceId, category.id)], selectedDate)));
        if (!catalog.length) elements.categoryList.appendChild(createElement('div', 'dt-empty-state', 'Keine Kategorien vorhanden.'));
    }

    async function renderWeek() {
        setState('Lade Woche ...', 'saving');
        const start = mondayOf(selectedDate);
        const dates = Array.from({ length: 7 }, (_, index) => shiftDate(start, index));
        weekEntries = {};
        await Promise.all(dates.map(async date => { weekEntries[date] = await loadDay(date); }));
        clearNode(elements.weekView);
        const grid = createElement('div', 'dt-week-grid');
        grid.appendChild(createElement('div', 'dt-week-head', 'Kategorie'));
        dates.forEach(date => grid.appendChild(createElement('div', 'dt-week-head', new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' }).format(new Date(date + 'T12:00:00')))));
        catalog.forEach(category => {
            grid.appendChild(createElement('div', 'dt-week-category', category.name));
            dates.forEach(date => {
                const cell = createElement('div', 'dt-week-cell');
                const entry = weekEntries[date][entryKey(selectedTimesliceId, category.id)];
                cell.appendChild(statusBadge(entry));
                if (category.input_mode !== 'text') {
                    const options = createElement('div', 'dt-options');
                    renderOptionButtons(category, entry, date, options);
                    cell.appendChild(options);
                }
                if (category.input_mode !== 'options') cell.appendChild(renderTextEditor(category, entry, date));
                grid.appendChild(cell);
            });
        });
        elements.weekView.appendChild(grid);
        setState('Bereit');
    }

    async function saveEntry(category, date, optionId, textValue) {
        try {
            setState('Speichere ...', 'saving');
            const response = await postJson('/api/day/' + encodeURIComponent(date), {
                timeslice_id: Number(selectedTimesliceId), category_id: Number(category.id),
                option_id: optionId === null ? null : Number(optionId), text_value: textValue || ''
            });
            const target = viewMode === 'day' ? dayEntries : weekEntries[date];
            target[entryKey(selectedTimesliceId, category.id)] = response.entry;
            if (viewMode === 'day') renderDay(); else await renderWeek();
            setState('Gespeichert', 'saved');
        } catch (error) {
            console.error('Daytracker konnte nicht speichern.', error);
            setState('Fehler beim Speichern', 'error');
        }
    }

    async function reloadView() {
        try {
            if (viewMode === 'day') { dayEntries = await loadDay(selectedDate); renderDay(); setState('Bereit'); }
            else await renderWeek();
        } catch (error) { console.error('Daytracker konnte nicht laden.', error); setState('Fehler beim Laden', 'error'); }
    }

    function moveItem(array, index, delta) {
        const target = index + delta;
        if (target < 0 || target >= array.length) return;
        array.splice(target, 0, array.splice(index, 1)[0]);
    }

    function adminButton(text, title, handler, extraClass = '') {
        const button = createElement('button', 'dt-button dt-admin-action ' + extraClass, text);
        button.type = 'button'; button.title = title; button.addEventListener('click', handler); return button;
    }

    function adminInput(value, name) {
        const input = createElement('input', 'dt-admin-input'); input.value = value || ''; input.name = name; return input;
    }

    function renderAdmin() {
        clearNode(elements.adminContent);
        const timesliceSection = createElement('section', 'dt-admin-section');
        timesliceSection.appendChild(createElement('h3', '', 'Zeitscheiben'));
        timeslices.forEach((timeslice, index) => {
            const row = createElement('div', 'dt-admin-row');
            const input = adminInput(timeslice.name, 'timeslice-' + index);
            input.addEventListener('input', () => { timeslice.name = input.value; });
            const controls = createElement('div', 'dt-admin-controls');
            controls.append(adminButton('↑', 'Nach oben', () => { moveItem(timeslices, index, -1); renderAdmin(); }), adminButton('↓', 'Nach unten', () => { moveItem(timeslices, index, 1); renderAdmin(); }), adminButton('×', 'Zeitscheibe löschen', () => { if (timeslices.length > 1) { timeslices.splice(index, 1); renderAdmin(); } }, 'dt-danger'));
            row.append(createElement('span', 'dt-id', timeslice.id ? 'ID ' + timeslice.id : 'neu'), input, createElement('span', '', String(index + 1)), controls);
            timesliceSection.appendChild(row);
        });
        elements.adminContent.appendChild(timesliceSection);

        catalog.forEach((category, categoryIndex) => {
            const section = createElement('section', 'dt-admin-section');
            const heading = createElement('div', 'dt-admin-section-head');
            heading.appendChild(createElement('h3', '', category.name || 'Neue Kategorie'));
            heading.appendChild(adminButton('Kategorie löschen', 'Kategorie einschließlich ihrer Tageswerte löschen', () => { catalog.splice(categoryIndex, 1); renderAdmin(); }, 'dt-danger'));
            section.appendChild(heading);
            const header = createElement('div', 'dt-admin-controls');
            const name = adminInput(category.name, 'category-' + categoryIndex);
            name.addEventListener('input', () => { category.name = name.value; });
            const mode = createElement('select', 'dt-admin-input');
            [['options', 'Auswahl'], ['text', 'Freitext'], ['both', 'Auswahl + Freitext']].forEach(definition => { const option = createElement('option', '', definition[1]); option.value = definition[0]; option.selected = category.input_mode === definition[0]; mode.appendChild(option); });
            mode.addEventListener('change', () => { category.input_mode = mode.value; });
            const limit = adminInput(String(category.dashboard_limit ?? 2), 'limit-' + categoryIndex); limit.type = 'number'; limit.min = '0'; limit.max = '50'; limit.addEventListener('input', () => { category.dashboard_limit = Number(limit.value || 0); });
            header.append(createElement('span', 'dt-id', category.id ? 'ID ' + category.id : 'neu'), name, mode, limit, adminButton('↑', 'Kategorie nach oben', () => { moveItem(catalog, categoryIndex, -1); renderAdmin(); }), adminButton('↓', 'Kategorie nach unten', () => { moveItem(catalog, categoryIndex, 1); renderAdmin(); }));
            section.appendChild(header);
            section.appendChild(createElement('h4', '', 'Optionen'));
            (category.options || []).forEach((option, optionIndex) => {
                const row = createElement('div', 'dt-admin-row');
                const input = adminInput(option.label, 'option-' + categoryIndex + '-' + optionIndex);
                input.addEventListener('input', () => { option.label = input.value; });
                const controls = createElement('div', 'dt-admin-controls');
                controls.append(adminButton('↑', 'Option nach oben', () => { moveItem(category.options, optionIndex, -1); renderAdmin(); }), adminButton('↓', 'Option nach unten', () => { moveItem(category.options, optionIndex, 1); renderAdmin(); }), adminButton('×', 'Option und zugehörige Auswahlwerte löschen', () => { category.options.splice(optionIndex, 1); renderAdmin(); }, 'dt-danger'));
                row.append(createElement('span', 'dt-id', option.id ? 'ID ' + option.id : 'neu'), input, createElement('span', '', String(optionIndex + 1)), controls);
                section.appendChild(row);
            });
            section.appendChild(adminButton('+ Option', 'Option hinzufügen', () => { category.options.push({ id: null, label: '🙂' }); renderAdmin(); }));
            elements.adminContent.appendChild(section);
        });
    }

    async function saveAdmin() {
        try {
            setState('Speichere Administration ...', 'saving');
            await postJson('/api/catalog', {
                timeslices: timeslices.map(item => ({ id: item.id ?? null, name: item.name })),
                categories: catalog.map(category => ({
                    id: category.id ?? null, name: category.name, input_mode: category.input_mode,
                    dashboard_limit: category.dashboard_limit,
                    options: (category.options || []).map(option => ({ id: option.id ?? null, label: option.label }))
                }))
            });
            await loadCatalog(); await reloadView(); renderAdmin(); elements.adminModal.hidden = true;
            setState('Administration gespeichert', 'saved');
        } catch (error) { console.error('Administration konnte nicht gespeichert werden.', error); setState('Fehler beim Speichern der Administration', 'error'); }
    }

    function bindEvents() {
        elements.prev.addEventListener('click', () => { selectedDate = shiftDate(selectedDate, viewMode === 'day' ? -1 : -7); elements.date.value = selectedDate; reloadView(); });
        elements.next.addEventListener('click', () => { selectedDate = shiftDate(selectedDate, viewMode === 'day' ? 1 : 7); elements.date.value = selectedDate; reloadView(); });
        elements.today.addEventListener('click', () => { selectedDate = dateString(); elements.date.value = selectedDate; reloadView(); });
        elements.date.addEventListener('change', () => { selectedDate = elements.date.value; reloadView(); });
        elements.timeslice.addEventListener('change', () => { selectedTimesliceId = elements.timeslice.value; reloadView(); });
        elements.viewDay.addEventListener('click', () => { viewMode = 'day'; elements.categoryList.hidden = false; elements.weekView.hidden = true; reloadView(); });
        elements.viewWeek.addEventListener('click', () => { viewMode = 'week'; elements.categoryList.hidden = true; elements.weekView.hidden = false; reloadView(); });
        elements.adminOpen.addEventListener('click', () => { renderAdmin(); elements.adminModal.hidden = false; });
        [elements.adminOverlay, elements.adminClose, elements.adminCancel].forEach(element => element.addEventListener('click', () => { elements.adminModal.hidden = true; }));
        elements.addTimeslice.addEventListener('click', () => { timeslices.push({ id: null, name: 'Neue Zeitscheibe' }); renderAdmin(); });
        elements.addCategory.addEventListener('click', () => { catalog.push({ id: null, name: 'Neue Kategorie', input_mode: 'options', dashboard_limit: 2, options: [] }); renderAdmin(); });
        elements.adminSave.addEventListener('click', saveAdmin);
        document.addEventListener('keydown', event => { if (event.key === 'Escape') elements.adminModal.hidden = true; });
    }

    async function init() {
        const missing = requiredIds.filter(id => !byId(id));
        if (missing.length) { console.error('Daytracker: Fehlende IDs', missing); return; }
        Object.assign(elements, {
            viewDay: byId('dt-view-day'), viewWeek: byId('dt-view-week'), adminOpen: byId('dt-admin-open'), prev: byId('dt-date-prev'), date: byId('dt-date'), next: byId('dt-date-next'), today: byId('dt-today'), timeslice: byId('dt-timeslice'), state: byId('dt-state'), categoryList: byId('dt-category-list'), weekView: byId('dt-week-view'), adminModal: byId('dt-admin-modal'), adminOverlay: byId('dt-admin-overlay'), adminPanel: byId('dt-admin-panel'), adminClose: byId('dt-admin-close'), adminContent: byId('dt-admin-content'), addTimeslice: byId('dt-admin-add-timeslice'), addCategory: byId('dt-admin-add-category'), csvExport: byId('dt-csv-export'), adminSave: byId('dt-admin-save'), adminCancel: byId('dt-admin-cancel')
        });
        selectedDate = dateString(); elements.date.value = selectedDate; elements.csvExport.href = apiUrl('/export.csv'); bindEvents();
        try { await loadCatalog(); await reloadView(); renderAdmin(); } catch (error) { console.error('Daytracker konnte nicht initialisiert werden.', error); setState('Fehler beim Laden', 'error'); }
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
}());
