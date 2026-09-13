(function () {
    'use strict';
    const apiUrl = path => window.OC && typeof OC.generateUrl === 'function' ? OC.generateUrl('/apps/daytracker' + path) : '/index.php/apps/daytracker' + path;
    const requestToken = () => window.OC && typeof OC.requestToken === 'string' ? OC.requestToken : '';
    const dateString = (date = new Date()) => date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    function shiftDate(value, days) { const parts = value.split('-').map(Number); const date = new Date(parts[0], parts[1] - 1, parts[2]); date.setDate(date.getDate() + days); return dateString(date); }
    function createElement(tag, className = '', text) { const element = document.createElement(tag); if (className) element.className = className; if (text !== undefined) element.textContent = text; return element; }
    function clearNode(node) { while (node.firstChild) node.removeChild(node.firstChild); }
    async function getJson(path) { const response = await fetch(apiUrl(path), { credentials: 'same-origin', headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error('GET ' + path + ' HTTP ' + response.status); return response.json(); }
    async function postJson(path, payload) { const response = await fetch(apiUrl(path), { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: requestToken() }, body: JSON.stringify(payload) }); if (!response.ok) throw new Error('POST ' + path + ' HTTP ' + response.status); return response.json(); }
    function init(root) {
        if (!root) { console.error('Daytracker Widget: Root-Element fehlt.'); return; }
        let catalog = [], selected = {}, currentDate = dateString(), status, input, content;
        function setStatus(text, type = '') { status.textContent = text; status.className = 'dt-state' + (type ? ' dt-state-' + type : ''); }
        async function load() { const [catalogData, dayData] = await Promise.all([getJson('/api/catalog'), getJson('/api/day/' + currentDate)]); catalog = catalogData.categories || []; selected = {}; (dayData.entries || []).forEach(entry => { selected[String(entry.category_id)] = String(entry.option_id); }); }
        function render() {
            clearNode(content);
            catalog.forEach(category => {
                const selectedId = selected[String(category.id)] || '';
                const chosen = (category.options || []).find(option => String(option.id) === selectedId);
                const box = createElement('section', 'dt-dashboard-category');
                const header = createElement('div', 'dt-dashboard-category-header');
                header.append(createElement('h3', '', category.name), createElement('span', 'dt-dashboard-status' + (chosen ? ' dt-dashboard-status-set' : ''), chosen ? 'gepflegt' : 'offen'));
                box.appendChild(header);
                if (chosen) box.appendChild(createElement('div', 'dt-dashboard-selected', 'Ausgewählt: ' + chosen.label));
                const options = createElement('div', 'dt-dashboard-options');
                const limit = Math.max(0, Number(category.dashboard_limit === undefined ? 2 : category.dashboard_limit));
                (category.options || []).slice(0, limit).forEach(option => {
                    const active = String(option.id) === selectedId;
                    const button = createElement('button', 'dt-option-button' + (active ? ' dt-option-active' : ''), option.label);
                    button.type = 'button';
                    button.setAttribute('aria-pressed', active ? 'true' : 'false');
                    button.addEventListener('click', async () => {
                        try { setStatus('Speichere ...', 'saving'); const response = await postJson('/api/day/' + currentDate, { category_id: Number(category.id), option_id: Number(option.id) }); selected[String(category.id)] = String(response.entry.option_id); render(); setStatus('Gespeichert', 'saved'); }
                        catch (error) { console.error('Daytracker Widget konnte nicht speichern.', error); setStatus('Fehler beim Speichern', 'error'); }
                    });
                    options.appendChild(button);
                });
                box.appendChild(options);
                content.appendChild(box);
            });
        }
        async function reload() { try { setStatus('Lade ...', 'saving'); await load(); render(); setStatus('Bereit'); } catch (error) { console.error('Daytracker Widget konnte nicht laden.', error); setStatus('Fehler beim Laden', 'error'); } }
        clearNode(root); root.classList.add('dt-dashboard-widget');
        const bar = createElement('div', 'dt-dashboard-datebar'), prev = createElement('button', 'dt-button dt-button-icon', '‹'), next = createElement('button', 'dt-button dt-button-icon', '›');
        input = createElement('input', 'dt-date-input'); input.type = 'date'; input.name = 'daytracker-dashboard-date'; input.value = currentDate;
        prev.type = next.type = 'button'; prev.setAttribute('aria-label', 'Vorheriger Tag'); next.setAttribute('aria-label', 'Nächster Tag');
        prev.addEventListener('click', () => { currentDate = shiftDate(currentDate, -1); input.value = currentDate; reload(); });
        next.addEventListener('click', () => { currentDate = shiftDate(currentDate, 1); input.value = currentDate; reload(); });
        input.addEventListener('change', () => { if (input.value) { currentDate = input.value; reload(); } });
        bar.append(prev, input, next); status = createElement('div', 'dt-state', 'Bereit'); content = createElement('div', 'dt-dashboard-content'); const link = createElement('a', 'dt-dashboard-link', 'Daytracker öffnen'); link.href = apiUrl('/'); root.append(bar, status, content, link); reload();
    }
    function register() { if (window.OCA && OCA.Dashboard && typeof OCA.Dashboard.register === 'function') OCA.Dashboard.register('daytracker', init); else document.querySelectorAll('[data-daytracker-dashboard], .dt-dashboard-widget').forEach(init); }
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', register) : register();
}());
