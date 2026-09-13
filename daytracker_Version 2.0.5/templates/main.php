<?php

declare(strict_types=1);

style('daytracker', 'ui-runtime-fix');
script('daytracker', 'ui-runtime-fix');
?>
<div id="dt-app" class="dt-app">
    <header class="dt-header">
        <div class="dt-header-title">
            <h1>Daytracker</h1>
            <p>Werte je Tag, Zeitscheibe und Kategorie erfassen.</p>
        </div>
        <div class="dt-header-actions">
            <button id="dt-view-day" type="button" class="dt-button dt-button-primary">Tag</button>
            <button id="dt-view-week" type="button" class="dt-button">Woche</button>
            <button id="dt-admin-open" type="button" class="dt-button">Administration</button>
        </div>
    </header>

    <section class="dt-toolbar" aria-label="Ansichtsauswahl">
        <button id="dt-date-prev" type="button" class="dt-button dt-button-icon" aria-label="Zurück">‹</button>
        <input id="dt-date" type="date" name="daytracker-date" class="dt-date-input" aria-label="Datum">
        <button id="dt-date-next" type="button" class="dt-button dt-button-icon" aria-label="Vor">›</button>
        <button id="dt-today" type="button" class="dt-button">Heute</button>
        <label for="dt-timeslice">Zeitscheibe</label>
        <select id="dt-timeslice" name="daytracker-timeslice" class="dt-select"></select>
    </section>

    <div id="dt-state" class="dt-state" role="status" aria-live="polite">Bereit</div>

    <main id="dt-view-scroll" class="dt-view-scroll">
        <section id="dt-category-list" class="dt-category-list" aria-label="Tagesansicht"></section>
        <section id="dt-week-view" class="dt-week-view" aria-label="Wochenansicht" hidden></section>
    </main>

    <div id="dt-admin-modal" class="dt-modal" hidden>
        <div id="dt-admin-overlay" class="dt-modal-overlay" aria-hidden="true"></div>
        <section id="dt-admin-panel" class="dt-modal-panel" role="dialog" aria-modal="true" aria-labelledby="dt-admin-title">
            <header class="dt-modal-header">
                <div>
                    <h2 id="dt-admin-title">Administration</h2>
                    <p>Kategorien, Optionen und Zeitscheiben ID-basiert verwalten.</p>
                </div>
                <button id="dt-admin-close" type="button" class="dt-button dt-button-plain" aria-label="Administration schließen">×</button>
            </header>
            <div id="dt-admin-content" class="dt-admin-content"></div>
            <footer class="dt-modal-actions">
                <button id="dt-admin-add-timeslice" type="button" class="dt-button">Zeitscheibe hinzufügen</button>
                <button id="dt-admin-add-category" type="button" class="dt-button">Kategorie hinzufügen</button>
                <a id="dt-csv-export" class="dt-button" href="#" target="_blank" rel="noopener noreferrer">CSV exportieren</a>
                <button id="dt-admin-save" type="button" class="dt-button dt-button-primary">Administration speichern</button>
                <button id="dt-admin-cancel" type="button" class="dt-button">Schließen</button>
            </footer>
        </section>
    </div>
</div>
