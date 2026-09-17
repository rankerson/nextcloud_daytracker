WITH category AS (
  INSERT INTO oc_daytracker_categories (user_id, name, sort_order, input_mode, dashboard_limit, dashboard_enabled)
  VALUES ('admin', 'Updater-Test', 0, 'text', 0, true) RETURNING id
), timeslice AS (
  INSERT INTO oc_daytracker_timeslices (user_id, name, sort_order)
  VALUES ('admin', 'Updater-Zeitscheibe', 0) RETURNING id
)
INSERT INTO oc_daytracker_entries (user_id, entry_date, category_id, timeslice_id, text_value, updated_at)
SELECT 'admin', '2026-09-17', category.id, timeslice.id, 'Daten bleiben erhalten', '2026-09-17 10:00:00'
FROM category, timeslice;

INSERT INTO oc_preferences (userid, appid, configkey, configvalue)
VALUES ('admin', 'daytracker', 'rollback_test', 'O''Brien: Einstellungen bleiben erhalten');
