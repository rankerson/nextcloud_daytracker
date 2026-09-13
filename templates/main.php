<?php declare(strict_types=1); ?>
<div id="dt-app" class="dt-app">
    <header class="dt-header">
        <div class="dt-header-title"><h1>Daytracker</h1><p>Erfasse pro Tag und Kategorie genau einen Wert.</p></div>
        <div class="dt-header-actions">
            <button id="dt-view-day" type="button" class="dt-button dt-button-primary" aria-pressed="true">Tag</button>
            <button id="dt-view-week" type="button" class="dt-button dt-button-secondary" aria-pressed="false">Woche</button>
            <button id="dt-admin-open" type="button" class="dt-button dt-button-secondary">Administration</button>
        </div>
    </header>
    <section class="dt-datebar" aria-label="Datumsauswahl">
        <button id="dt-date-prev" type="button" class="dt-button dt-button-icon" aria-label="Vorheriger Zeitraum">‹</button>
        <input id="dt-date" class="dt-date-input" type="date" name="daytracker-date" aria-label="Datum">
        <button id="dt-date-next" type="button" class="dt-button dt-button-icon" aria-label="Nächster Zeitraum">›</button>
        <button id="dt-today" type="button" class="dt-button dt-button-secondary">Heute</button>
    </section>
    <div id="dt-state" class="dt-state" role="status" aria-live="polite">Bereit</div>
    <section id="dt-category-list" class="dt-category-list" aria-label="Daytracker Kategorien"></section>
    <section id="dt-week-view" class="dt-week-view" aria-label="Wochenansicht" hidden></section>
    <div id="dt-admin-modal" class="dt-modal" hidden>
        <div id="dt-admin-overlay" class="dt-modal-overlay" aria-hidden="true"></div>
        <section id="dt-admin-panel" class="dt-modal-panel" role="dialog" aria-modal="true" aria-labelledby="dt-admin-title">
            <header class="dt-modal-header"><div><h2 id="dt-admin-title">Administration</h2><p>Namen und Reihenfolge werden über unveränderliche IDs verwaltet.</p></div><button id="dt-admin-close" type="button" class="dt-button dt-button-plain" aria-label="Administration schließen">×</button></header>
            <div id="dt-admin-content" class="dt-admin-content"></div>
            <footer class="dt-modal-actions">
                <button id="dt-admin-add-category" type="button" class="dt-button dt-button-secondary">Kategorie hinzufügen</button>
                <a id="dt-csv-export" class="dt-button dt-button-secondary" href="#" target="_blank" rel="noopener noreferrer">CSV exportieren</a>
                <button id="dt-admin-save" type="button" class="dt-button dt-button-primary">Administration speichern</button>
                <button id="dt-admin-cancel" type="button" class="dt-button dt-button-secondary">Schließen</button>
            </footer>
        </section>
    </div>
</div>
