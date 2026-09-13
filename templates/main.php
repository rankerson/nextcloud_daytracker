<?php

declare(strict_types=1);

use OCP\Util;

Util::addStyle('daytracker', 'style');
Util::addScript('daytracker', 'main');
?>
<div id="dt-app" class="dt-app">
    <header class="dt-header">
        <div class="dt-heading-row">
            <h1>Daytracker</h1>
            <button id="dt-admin-open" type="button" class="button">Administration</button>
        </div>

        <div id="dt-main-toolbar" class="dt-toolbar" aria-label="Daytracker-Steuerung">
            <div class="dt-view-switch" role="group" aria-label="Ansicht wählen">
                <button id="dt-view-day" type="button" class="button dt-active" aria-pressed="true">Tag</button>
                <button id="dt-view-week" type="button" class="button" aria-pressed="false">Woche</button>
            </div>

            <div id="dt-period-controls" class="dt-period-controls">
                <button id="dt-date-prev" type="button" class="button dt-icon-button" aria-label="Vorheriger Zeitraum" title="Vorheriger Zeitraum">‹</button>
                <label id="dt-date-label" for="dt-date" class="dt-visually-hidden">Datum</label>
                <input id="dt-date" name="dt-date" type="date">
                <button id="dt-date-next" type="button" class="button dt-icon-button" aria-label="Nächster Zeitraum" title="Nächster Zeitraum">›</button>
                <button id="dt-today" type="button" class="button">Heute</button>
            </div>

            <div class="dt-timeslice-control">
                <label id="dt-timeslice-label" for="dt-timeslice">Zeitscheibe</label>
                <select id="dt-timeslice" name="dt-timeslice"></select>
            </div>
        </div>

        <div id="dt-state" class="dt-state" role="status" aria-live="polite">Lade ...</div>
    </header>

    <main class="dt-main">
        <section id="dt-day-view" aria-label="Tagesansicht">
            <div id="dt-category-list" class="dt-category-list"></div>
        </section>

        <section id="dt-week-view" class="dt-week-view" aria-label="Wochenansicht" hidden></section>
    </main>

    <div id="dt-admin-modal" class="dt-modal" hidden>
        <div id="dt-admin-overlay" class="dt-modal-overlay" aria-hidden="true"></div>
        <section id="dt-admin-panel"
                 class="dt-modal-panel"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="dt-admin-title">
            <header class="dt-modal-header">
                <h2 id="dt-admin-title">Administration</h2>
                <button id="dt-admin-close"
                        type="button"
                        class="button dt-icon-button"
                        aria-label="Administration schließen"
                        title="Schließen">×</button>
            </header>

            <div id="dt-admin-content" class="dt-admin-content">
                <section class="dt-admin-section" aria-labelledby="dt-admin-timeslices-title">
                    <div class="dt-section-heading">
                        <h3 id="dt-admin-timeslices-title">Zeitscheiben</h3>
                        <button id="dt-admin-add-timeslice" type="button" class="button">Zeitscheibe hinzufügen</button>
                    </div>
                    <div id="dt-admin-timeslices" class="dt-admin-list"></div>
                </section>

                <section class="dt-admin-section" aria-labelledby="dt-admin-categories-title">
                    <div class="dt-section-heading">
                        <h3 id="dt-admin-categories-title">Kategorien</h3>
                        <button id="dt-admin-add-category" type="button" class="button">Kategorie hinzufügen</button>
                    </div>
                    <div id="dt-admin-categories" class="dt-admin-category-list"></div>
                </section>
            </div>

            <footer class="dt-modal-footer">
                <a id="dt-csv-export" class="button" href="#" download="daytracker-export.csv">CSV exportieren</a>
                <div class="dt-modal-actions">
                    <button id="dt-admin-cancel" type="button" class="button">Schließen</button>
                    <button id="dt-admin-save" type="button" class="button primary">Administration speichern</button>
                </div>
            </footer>
        </section>
    </div>
</div>
