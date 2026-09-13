(() => {
    'use strict';

    let catalog = { categories: [], timeslices: [] };
    let timeslices = [];
    let selectedDate = localDateString(new Date());
    let selectedTimesliceId = null;
    let entries = [];
    let selectedByCategory = new Map();
    let root = null;
    let state = null;
    let categoryContainer = null;
    let dashboardRegistered = false;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerDashboardWidget, { once: true });
    } else {
        registerDashboardWidget();
    }

    function registerDashboardWidget() {
        if (dashboardRegistered) return;
        if (!window.OCA || !OCA.Dashboard || typeof OCA.Dashboard.register !== 'function') {
            console.error('Daytracker-Dashboard konnte nicht registriert werden: OCA.Dashboard.register ist nicht verfügbar.');
            return;
        }

        dashboardRegistered = true;
        OCA.Dashboard.register('daytracker', (widgetElement) => {
            initializeDashboard(widgetElement).catch((error) => {
                console.error('Daytracker-Dashboard konnte nicht initialisiert werden:', error);
            });
        });
    }

    async function initializeDashboard(widgetElement) {
        if (!(widgetElement instanceof HTMLElement)) {
            console.error('Daytracker-Dashboard wurde ohne gültiges Widget-Element aufgerufen.');
            return;
        }

        root = widgetElement;
        root.id = 'dt-dashboard';
        root.classList.add('dt-dashboard');
        buildStructure();
        bindEvents();
        await loadAll();
    }

    function buildStructure() {
        root.replaceChildren();

        const toolbar = element('div', 'dt-dashboard-toolbar');
        const previous = button('dt-dashboard-date-prev', '‹', 'Vorheriger Tag');
        const dateLabel = element('label', 'dt-visually-hidden', 'Datum');
        dateLabel.id = 'dt-dashboard-date-label';
        dateLabel.htmlFor = 'dt-dashboard-date';

        const dateInput = document.createElement('input');
        dateInput.id = 'dt-dashboard-date';
        dateInput.name = 'dt-dashboard-date';
        dateInput.type = 'date';
        dateInput.value = selectedDate;

        const next = button('dt-dashboard-date-next', '›', 'Nächster Tag');
        const sliceLabel = element('label', 'dt-visually-hidden', 'Zeitscheibe');
        sliceLabel.id = 'dt-dashboard-timeslice-label';
        sliceLabel.htmlFor = 'dt-dashboard-timeslice';

        const slice = document.createElement('select');
        slice.id = 'dt-dashboard-timeslice';
        slice.name = 'dt-dashboard-timeslice';
        state = element('div', 'dt-dashboard-state', 'Lade ...');
        state.id = 'dt-dashboard-state';
        state.setAttribute('role', 'status');
        state.setAttribute('aria-live', 'polite');
        toolbar.append(previous, dateLabel, dateInput, next, sliceLabel, slice, state);

        categoryContainer = element('div', 'dt-dashboard-categories');
        categoryContainer.id = 'dt-dashboard-categories';

        const footer = element('div', 'dt-dashboard-footer');
        const link = element('a', '', 'Vollständige App öffnen');
        link.id = 'dt-dashboard-app-link';
        link.href = generateUrl('/apps/daytracker/');
        footer.append(link);
        root.append(toolbar, categoryContainer, footer);
    }

    function bindEvents() {
        document.getElementById('dt-dashboard-date-prev').addEventListener('click', () => shiftDate(-1));
        document.getElementById('dt-dashboard-date-next').addEventListener('click', () => shiftDate(1));
        document.getElementById('dt-dashboard-date').addEventListener('change', async (event) => {
            if (isDateString(event.target.value)) {
                selectedDate = event.target.value;
                await loadEntries();
                renderCategories();
            }
        });
        document.getElementById('dt-dashboard-timeslice').addEventListener('change', (event) => {
            selectedTimesliceId = Number(event.target.value);
            rebuildSelection();
            renderCategories();
        });
    }

    async function loadAll() {
        setState('Lade ...');
        try {
            const response = await fetchJson(generateUrl('/apps/daytracker/api/catalog'));
            catalog = response;
            timeslices = response.timeslices || [];
            selectedTimesliceId = timeslices.length ? Number(timeslices[0].id) : null;
            renderTimeslices();
            await loadEntries();
            renderCategories();
            setState('Bereit');
        } catch (error) {
            console.error('Daytracker-Dashboard:', error);
            setState('Fehler beim Laden', 'error');
        }
    }

    async function loadEntries() {
        const response = await fetchJson(generateUrl(`/apps/daytracker/api/day/${selectedDate}`));
        entries = response.entries || [];
        rebuildSelection();
    }

    function rebuildSelection() {
        selectedByCategory = new Map();
        for (const entry of entries) {
            if (Number(entry.timeslice_id) === selectedTimesliceId) {
                selectedByCategory.set(Number(entry.category_id), entry);
            }
        }
    }

    function renderTimeslices() {
        const select = document.getElementById('dt-dashboard-timeslice');
        select.replaceChildren(...timeslices.map((item) => {
            const option = document.createElement('option');
            option.value = String(item.id);
            option.textContent = item.name;
            option.selected = Number(item.id) === selectedTimesliceId;
            return option;
        }));
        select.disabled = timeslices.length === 0;
    }

    function renderCategories() {
        categoryContainer.replaceChildren(...(catalog.categories || []).filter((category) => category.dashboard_enabled !== false).map(createCategory));
    }

    function createCategory(category) {
        const entry = selectedByCategory.get(Number(category.id));
        const selectedOption = (category.options || []).find((option) => Number(option.id) === Number(entry?.option_id));
        const selectedParts = [];
        if (selectedOption) selectedParts.push(selectedOption.label);
        if (String(entry?.text_value || '').trim()) selectedParts.push(entry.text_value.trim());

        const statusText = isSet(entry) ? 'gepflegt' : 'offen';
        const valueAndStatus = selectedParts.length > 0
            ? `${selectedParts.join(' · ')} | ${statusText}`
            : statusText;

        const card = element('section', 'dt-dashboard-category');
        const header = element('div', 'dt-dashboard-category-header');
        header.append(
            element('h3', 'dt-dashboard-category-title', category.name),
            element('span', `dt-dashboard-indicator${isSet(entry) ? ' dt-set' : ''}`, valueAndStatus)
        );
        card.append(header);

        const visibleOptions = (category.options || []).slice(0, Math.max(0, Number(category.dashboard_limit) || 0));
        if ((category.input_mode === 'options' || category.input_mode === 'both') && visibleOptions.length) {
            const options = element('div', 'dt-dashboard-options');
            for (const option of visibleOptions) {
                const control = button('', option.label, `${category.name}: ${option.label}`);
                control.className = 'button dt-dashboard-option';
                control.setAttribute('aria-pressed', String(Number(entry?.option_id) === Number(option.id)));
                control.addEventListener('click', () => save(category, option.id, entry?.text_value || ''));
                options.append(control);
            }
            card.append(options);
        }
        return card;
    }

    async function save(category, optionId, textValue) {
        setState('Speichere ...');
        try {
            const response = await postJson(generateUrl(`/apps/daytracker/api/day/${selectedDate}`), {
                category_id: Number(category.id),
                timeslice_id: Number(selectedTimesliceId),
                option_id: Number(optionId),
                text_value: textValue
            });
            const replacement = response.entry;
            entries = entries.filter((entry) => !(
                Number(entry.category_id) === Number(category.id)
                && Number(entry.timeslice_id) === selectedTimesliceId
            ));
            entries.push(replacement);
            rebuildSelection();
            renderCategories();
            setState('Gespeichert', 'success');
        } catch (error) {
            console.error('Daytracker-Dashboard-Speicherfehler:', error);
            setState('Fehler beim Speichern', 'error');
        }
    }

    async function shiftDate(days) {
        const date = parseLocalDate(selectedDate);
        date.setDate(date.getDate() + days);
        selectedDate = localDateString(date);
        document.getElementById('dt-dashboard-date').value = selectedDate;
        setState('Lade ...');
        try {
            await loadEntries();
            renderCategories();
            setState('Bereit');
        } catch (error) {
            console.error('Daytracker-Dashboard-Ladefehler:', error);
            setState('Fehler beim Laden', 'error');
        }
    }

    async function fetchJson(url) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        });
        return parseResponse(response);
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                requesttoken: OC.requestToken
            },
            body: JSON.stringify(payload)
        });
        return parseResponse(response);
    }

    async function parseResponse(response) {
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error(`Ungültige Serverantwort (${response.status}).`);
        }
        if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`);
        return payload;
    }

    function setState(message, kind = '') {
        state.textContent = message;
        state.classList.toggle('dt-error', kind === 'error');
        state.classList.toggle('dt-success', kind === 'success');
    }

    function isSet(entry) {
        return Boolean(entry && (entry.option_id !== null || String(entry.text_value || '').trim()));
    }

    function generateUrl(path) {
        return window.OC?.generateUrl ? OC.generateUrl(path) : `/index.php${path}`;
    }

    function element(tag, className = '', text = '') {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function button(id, text, label) {
        const node = element('button', 'button dt-icon-button', text);
        node.type = 'button';
        if (id) node.id = id;
        node.setAttribute('aria-label', label);
        node.title = label;
        return node;
    }

    function parseLocalDate(value) {
        const [year, month, day] = value.split('-').map(Number);
        return new Date(year, month - 1, day);
    }

    function localDateString(date) {
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    function isDateString(value) {
        const date = parseLocalDate(value);
        return !Number.isNaN(date.getTime()) && localDateString(date) === value;
    }
})();
