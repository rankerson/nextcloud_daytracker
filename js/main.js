(function () {
    'use strict';

    const requiredIds = ['dt-app', 'dt-admin-open', 'dt-view-day', 'dt-view-week', 'dt-date-prev', 'dt-date', 'dt-date-next', 'dt-today', 'dt-state', 'dt-category-list', 'dt-week-view', 'dt-admin-modal', 'dt-admin-overlay', 'dt-admin-panel', 'dt-admin-close', 'dt-admin-content', 'dt-admin-add-category', 'dt-csv-export', 'dt-admin-save', 'dt-admin-cancel'];
    const elements = {};
    let catalog = [];
    let selectedByCategory = {};
    let selectedDate = '';
    let viewMode = 'day';
    let weekData = {};
    let draftCounter = 1;

    const byId = id => document.getElementById(id);
    const apiUrl = path => window.OC && typeof OC.generateUrl === 'function' ? OC.generateUrl('/apps/daytracker' + path) : '/index.php/apps/daytracker' + path;
    const requestToken = () => window.OC && typeof OC.requestToken === 'string' ? OC.requestToken : '';

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

    function setState(text, type = '') {
        elements.state.textContent = text;
        elements.state.className = 'dt-state' + (type ? ' dt-state-' + type : '');
    }

    function clearNode(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function createElement(tag, className = '', text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    }

    async function getJson(path) {
        const response = await fetch(apiUrl(path), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('GET ' + path + ' failed with HTTP ' + response.status);
        return response.json();
    }

    async function postJson(path, payload) {
        const response = await fetch(apiUrl(path), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: requestToken() },
            body: JSON.stringify(payload)
        });
        if (!response.ok) {
            let message = 'POST ' + path + ' failed with HTTP ' + response.status;
            try {
                const data = await response.json();
                if (data.error) message += ': ' + data.error;
            } catch (error) {
                message += ': response is not JSON';
            }
            throw new Error(message);
        }
        return response.json();
    }

    function exposeDebug() {
        window.daytrackerDebug = {
            get catalog() { return catalog; },
            get selectedByCategory() { return selectedByCategory; },
            get currentDate() { return selectedDate; },
            get weekData() { return weekData; }
        };
    }

    async function loadCatalog() {
        const data = await getJson('/api/catalog');
        catalog = Array.isArray(data.categories) ? data.categories : [];
    }

    async function loadDay(date) {
        const data = await getJson('/api/day/' + encodeURIComponent(date));
        const map = {};
        (data.entries || []).forEach(entry => {
            map[String(entry.category_id)] = String(entry.option_id);
        });
        return map;
    }

    function optionButton(category, option, selectedId, date) {
        const button = createElement('button', 'dt-option-button' + (selectedId === String(option.id) ? ' dt-option-active' : ''), option.label);
        button.type = 'button';
        button.dataset.categoryId = String(category.id);
        button.dataset.optionId = String(option.id);
        button.dataset.date = date;
        button.setAttribute('aria-pressed', selectedId === String(option.id) ? 'true' : 'false');
        button.addEventListener('click', () => saveSelection(category.id, option.id, date));
        return button;
    }

    function renderDay() {
        clearNode(elements.categoryList);
        catalog.forEach(category => {
            const selectedId = selectedByCategory[String(category.id)] || '';
            const selected = (category.options || []).find(option => String(option.id) === selectedId);
            const card = createElement('article', 'dt-category-card');
            const header = createElement('div', 'dt-category-card-header');
            header.append(createElement('h2', 'dt-category-card-title', category.name), createElement('span', 'dt-category-status' + (selected ? ' dt-category-status-set' : ''), selected ? 'gesetzt' : 'offen'));
            const options = createElement('div', 'dt-options');
            (category.options || []).forEach(option => options.appendChild(optionButton(category, option, selectedId, selectedDate)));
            card.append(header, options);
            elements.categoryList.appendChild(card);
        });
        if (!catalog.length) elements.categoryList.appendChild(createElement('div', 'dt-empty-state', 'Keine Kategorien vorhanden.'));
    }

    async function renderWeek() {
        try {
            setState('Lade Woche ...', 'saving');
            const start = mondayOf(selectedDate);
            const dates = Array.from({ length: 7 }, (_, index) => shiftDate(start, index));
            weekData = {};
            await Promise.all(dates.map(async date => { weekData[date] = await loadDay(date); }));
            clearNode(elements.weekView);
            const grid = createElement('div', 'dt-week-grid');
            grid.appendChild(createElement('div', 'dt-week-head', 'Kategorie'));
            dates.forEach(date => {
                const parts = date.split('-').map(Number);
                const label = new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' }).format(new Date(parts[0], parts[1] - 1, parts[2]));
                grid.appendChild(createElement('div', 'dt-week-head', label));
            });
            catalog.forEach(category => {
                grid.appendChild(createElement('div', 'dt-week-category', category.name));
                dates.forEach(date => {
                    const cell = createElement('div', 'dt-week-cell');
                    const options = createElement('div', 'dt-options');
                    const selectedId = weekData[date][String(category.id)] || '';
                    (category.options || []).forEach(option => options.appendChild(optionButton(category, option, selectedId, date)));
                    cell.appendChild(options);
                    grid.appendChild(cell);
                });
            });
            elements.weekView.appendChild(grid);
            exposeDebug();
            setState('Bereit');
        } catch (error) {
            console.error('Wochenansicht konnte nicht geladen werden.', error);
            setState('Fehler beim Laden', 'error');
        }
    }

    async function saveSelection(categoryId, optionId, date) {
        try {
            setState('Speichere ...', 'saving');
            const response = await postJson('/api/day/' + encodeURIComponent(date), { category_id: Number(categoryId), option_id: Number(optionId) });
            if (viewMode === 'day') {
                selectedByCategory[String(categoryId)] = String(response.entry.option_id);
                renderDay();
            } else {
                weekData[date][String(categoryId)] = String(response.entry.option_id);
                await renderWeek();
            }
            exposeDebug();
            setState('Gespeichert', 'saved');
        } catch (error) {
            console.error('Daytracker konnte nicht speichern.', error);
            setState('Fehler beim Speichern', 'error');
        }
    }

    async function reloadDay() {
        try {
            setState('Lade ...', 'saving');
            selectedByCategory = await loadDay(selectedDate);
            renderDay();
            exposeDebug();
            setState('Bereit');
        } catch (error) {
            console.error('Tag konnte nicht geladen werden.', error);
            setState('Fehler beim Laden', 'error');
        }
    }

    function setView(mode) {
        viewMode = mode;
        elements.categoryList.hidden = mode !== 'day';
        elements.weekView.hidden = mode !== 'week';
        elements.viewDay.className = 'dt-button ' + (mode === 'day' ? 'dt-button-primary' : 'dt-button-secondary');
        elements.viewWeek.className = 'dt-button ' + (mode === 'week' ? 'dt-button-primary' : 'dt-button-secondary');
        elements.viewDay.setAttribute('aria-pressed', mode === 'day' ? 'true' : 'false');
        elements.viewWeek.setAttribute('aria-pressed', mode === 'week' ? 'true' : 'false');
        if (mode === 'day') reloadDay(); else renderWeek();
    }

    async function changeDate(date) {
        if (!date) return;
        selectedDate = date;
        elements.date.value = date;
        if (viewMode === 'day') await reloadDay(); else await renderWeek();
    }

    function moveArrayItem(array, index, delta) {
        const target = index + delta;
        if (target < 0 || target >= array.length) return;
        const item = array[index];
        array.splice(index, 1);
        array.splice(target, 0, item);
    }

    function adminButton(text, title, handler) {
        const button = createElement('button', 'dt-button dt-button-small', text);
        button.type = 'button';
        button.title = title;
        button.addEventListener('click', handler);
        return button;
    }

    function renderAdmin() {
        clearNode(elements.adminContent);
        catalog.forEach((category, categoryIndex) => {
            const box = createElement('section', 'dt-admin-category');
            box.dataset.categoryId = category.id == null ? '' : String(category.id);

            const heading = createElement('div', 'dt-admin-heading');
            heading.appendChild(createElement('strong', '', category.id == null ? 'Neue Kategorie' : 'Kategorie-ID ' + category.id));
            const categoryControls = createElement('div', 'dt-admin-controls');
            categoryControls.append(
                adminButton('↑', 'Kategorie nach oben', () => { moveArrayItem(catalog, categoryIndex, -1); renderAdmin(); }),
                adminButton('↓', 'Kategorie nach unten', () => { moveArrayItem(catalog, categoryIndex, 1); renderAdmin(); })
            );
            heading.appendChild(categoryControls);

            const row = createElement('div', 'dt-admin-row');
            const nameGroup = createElement('div');
            const nameId = 'dt-admin-name-' + categoryIndex;
            const nameLabel = createElement('label', '', 'Kategoriename');
            nameLabel.htmlFor = nameId;
            const nameInput = createElement('input', 'dt-admin-category-name');
            nameInput.id = nameId;
            nameInput.name = nameId;
            nameInput.value = category.name || '';
            nameInput.addEventListener('input', () => { category.name = nameInput.value; });
            nameGroup.append(nameLabel, nameInput);

            const limitGroup = createElement('div');
            const limitId = 'dt-admin-limit-' + categoryIndex;
            const limitLabel = createElement('label', '', 'Dashboard-Anzahl');
            limitLabel.htmlFor = limitId;
            const limitInput = createElement('input', 'dt-admin-limit');
            limitInput.id = limitId;
            limitInput.name = limitId;
            limitInput.type = 'number';
            limitInput.min = '0';
            limitInput.max = '50';
            limitInput.value = String(category.dashboard_limit === undefined ? 2 : category.dashboard_limit);
            limitInput.addEventListener('input', () => { category.dashboard_limit = Number(limitInput.value || 0); });
            limitGroup.append(limitLabel, limitInput);
            row.append(nameGroup, limitGroup);

            const optionHeading = createElement('div', 'dt-admin-options-heading');
            optionHeading.appendChild(createElement('strong', '', 'Optionen'));
            optionHeading.appendChild(adminButton('+ Option', 'Neue Option hinzufügen', () => {
                category.options = Array.isArray(category.options) ? category.options : [];
                category.options.push({ id: null, label: 'Neue Option', sort_order: category.options.length + 1, draftId: draftCounter++ });
                renderAdmin();
            }));

            const optionList = createElement('div', 'dt-admin-option-list');
            (category.options || []).forEach((option, optionIndex) => {
                const optionRow = createElement('div', 'dt-admin-option-row');
                optionRow.dataset.optionId = option.id == null ? '' : String(option.id);
                const badge = createElement('span', 'dt-id-badge', option.id == null ? 'neu' : 'ID ' + option.id);
                const inputId = 'dt-admin-option-' + categoryIndex + '-' + optionIndex;
                const label = createElement('label', 'dt-visually-hidden', 'Option ' + (optionIndex + 1));
                label.htmlFor = inputId;
                const input = createElement('input', 'dt-admin-option-input');
                input.id = inputId;
                input.name = inputId;
                input.value = option.label || '';
                input.addEventListener('input', () => { option.label = input.value; });
                const controls = createElement('div', 'dt-admin-controls');
                controls.append(
                    adminButton('↑', 'Option nach oben', () => { moveArrayItem(category.options, optionIndex, -1); renderAdmin(); }),
                    adminButton('↓', 'Option nach unten', () => { moveArrayItem(category.options, optionIndex, 1); renderAdmin(); }),
                    adminButton('×', 'Option entfernen', () => { category.options.splice(optionIndex, 1); renderAdmin(); })
                );
                optionRow.append(badge, label, input, controls);
                optionList.appendChild(optionRow);
            });

            box.append(heading, row, optionHeading, optionList);
            elements.adminContent.appendChild(box);
        });
    }

    function catalogPayload() {
        return catalog.map(category => ({
            id: category.id == null ? null : Number(category.id),
            name: String(category.name || '').trim(),
            dashboard_limit: Number(category.dashboard_limit === undefined ? 2 : category.dashboard_limit),
            options: (category.options || []).map(option => ({
                id: option.id == null ? null : Number(option.id),
                label: String(option.label || '').trim()
            })).filter(option => option.label !== '')
        })).filter(category => category.name !== '');
    }

    async function saveAdmin() {
        try {
            setState('Speichere Administration ...', 'saving');
            await postJson('/api/catalog', { categories: catalogPayload() });
            await loadCatalog();
            selectedByCategory = await loadDay(selectedDate);
            renderDay();
            renderAdmin();
            elements.adminModal.hidden = true;
            exposeDebug();
            setState('Administration gespeichert', 'saved');
        } catch (error) {
            console.error('Administration konnte nicht gespeichert werden.', error);
            setState('Fehler beim Speichern der Administration', 'error');
        }
    }

    function bindEvents() {
        elements.adminOpen.addEventListener('click', () => { renderAdmin(); elements.adminModal.hidden = false; });
        [elements.adminClose, elements.adminCancel, elements.adminOverlay].forEach(element => element.addEventListener('click', () => { elements.adminModal.hidden = true; }));
        elements.adminAdd.addEventListener('click', () => {
            catalog.push({ id: null, name: 'Neue Kategorie', dashboard_limit: 2, options: [], draftId: draftCounter++ });
            renderAdmin();
        });
        elements.adminSave.addEventListener('click', saveAdmin);
        elements.prev.addEventListener('click', () => changeDate(shiftDate(selectedDate, viewMode === 'week' ? -7 : -1)));
        elements.next.addEventListener('click', () => changeDate(shiftDate(selectedDate, viewMode === 'week' ? 7 : 1)));
        elements.today.addEventListener('click', () => changeDate(dateString()));
        elements.date.addEventListener('change', () => changeDate(elements.date.value));
        elements.viewDay.addEventListener('click', () => setView('day'));
        elements.viewWeek.addEventListener('click', () => setView('week'));
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && !elements.adminModal.hidden) elements.adminModal.hidden = true; });
    }

    async function init() {
        const missing = requiredIds.filter(id => !byId(id));
        if (missing.length) {
            console.error('Daytracker: Fehlende Elemente', missing);
            return;
        }
        Object.assign(elements, {
            app: byId('dt-app'), adminOpen: byId('dt-admin-open'), viewDay: byId('dt-view-day'), viewWeek: byId('dt-view-week'),
            prev: byId('dt-date-prev'), date: byId('dt-date'), next: byId('dt-date-next'), today: byId('dt-today'), state: byId('dt-state'),
            categoryList: byId('dt-category-list'), weekView: byId('dt-week-view'), adminModal: byId('dt-admin-modal'), adminOverlay: byId('dt-admin-overlay'),
            adminPanel: byId('dt-admin-panel'), adminClose: byId('dt-admin-close'), adminContent: byId('dt-admin-content'), adminAdd: byId('dt-admin-add-category'),
            csv: byId('dt-csv-export'), adminSave: byId('dt-admin-save'), adminCancel: byId('dt-admin-cancel')
        });
        selectedDate = dateString();
        elements.date.value = selectedDate;
        elements.csv.href = apiUrl('/export.csv');
        bindEvents();
        exposeDebug();
        try {
            await loadCatalog();
            await reloadDay();
            renderAdmin();
        } catch (error) {
            console.error('Daytracker konnte nicht initialisiert werden.', error);
            setState('Fehler beim Laden', 'error');
        }
    }

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
}());
