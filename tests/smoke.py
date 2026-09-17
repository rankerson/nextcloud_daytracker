"""Browser/API compatibility checks against a disposable Nextcloud instance."""
import csv
import io
from playwright.sync_api import sync_playwright, expect

BASE = "http://localhost:8080"
PASSWORD = "Daytracker-CI-test-only-9382!"


def login(browser, username):
    context = browser.new_context()
    page = context.new_page()
    page.goto(BASE + "/index.php/login")
    page.locator('input[name="user"]').fill(username)
    page.locator('input[name="password"]').fill(PASSWORD)
    page.locator('button[type="submit"]').click()
    page.wait_for_url("**/apps/**", timeout=60000)
    page.goto(BASE + "/index.php/apps/daytracker/")
    expect(page.locator("#dt-state")).to_have_text("Bereit", timeout=30000)
    return context, page


def api(page, path, payload=None, csrf=True):
    return page.evaluate("""async ({path, payload, csrf}) => {
        const headers = {Accept: 'application/json'};
        if (payload !== null) headers['Content-Type'] = 'application/json';
        if (csrf) headers.requesttoken = OC.requestToken;
        const response = await fetch(OC.generateUrl('/apps/daytracker' + path), {
            method: payload === null ? 'GET' : 'POST', headers,
            body: payload === null ? undefined : JSON.stringify(payload),
        });
        const text = await response.text();
        let data; try { data = JSON.parse(text); } catch { data = text; }
        return {status: response.status, data};
    }""", {"path": path, "payload": payload, "csrf": csrf})


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch()
        context, page = login(browser, "admin")
        errors = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.reload()
        expect(page.locator("#dt-state")).to_have_text("Bereit", timeout=30000)
        catalog_result = api(page, "/api/catalog")
        assert catalog_result["status"] == 200, catalog_result
        catalog = catalog_result["data"]
        category = catalog["categories"][0]
        category["input_mode"] = "both"
        category["name"] = 'Test "Kategorie"; ä'
        saved = api(page, "/api/catalog", catalog)
        assert saved["status"] == 200, saved
        category = saved["data"]["catalog"]["categories"][0]
        payload = {"category_id": category["id"], "timeslice_id": catalog["timeslices"][0]["id"],
                   "option_id": category["options"][0]["id"], "text_value": 'Zeile 1; "Zitat" \\Pfad\nZeile 2 ä'}
        date = "2026-09-17"
        saved = api(page, f"/api/day/{date}", payload)
        assert saved["status"] == 200, saved
        loaded = api(page, f"/api/day/{date}")
        assert loaded["status"] == 200, loaded
        assert loaded["data"]["entries"][0]["text_value"] == payload["text_value"], loaded
        rejected = api(page, f"/api/day/{date}", payload, csrf=False)
        assert rejected["status"] in (403, 412), rejected
        exported = api(page, "/export.csv")
        assert exported["status"] == 200, exported
        rows = list(csv.reader(io.StringIO(exported["data"].lstrip("\ufeff")), delimiter=";"))
        assert rows[1][2] == category["name"] and rows[1][4] == payload["text_value"], rows
        page.reload()
        expect(page.locator("#dt-state")).to_have_text("Bereit", timeout=30000)
        page.locator("#dt-view-week").click()
        expect(page.locator("#dt-week-view")).to_be_visible()
        page.locator("#dt-admin-open").click()
        expect(page.locator("#dt-admin-panel")).to_be_visible()
        page.locator("#dt-admin-save").click()
        expect(page.locator("#dt-state")).to_have_text("Administration gespeichert", timeout=30000)
        # Non-admin user must not read or change another user's values.
        other_context, other = login(browser, "tester")
        other_catalog = api(other, "/api/catalog")["data"]
        assert category["id"] not in [c["id"] for c in other_catalog["categories"]]
        assert api(other, f"/api/day/{date}")["data"]["entries"] == []
        assert api(other, f"/api/day/{date}", payload)["status"] == 404
        own_category = other_catalog["categories"][0]
        own_payload = {"category_id": own_category["id"], "timeslice_id": other_catalog["timeslices"][0]["id"],
                       "option_id": own_category["options"][0]["id"], "text_value": ""}
        assert api(other, f"/api/day/{date}", own_payload)["status"] == 200
        page.goto(BASE + "/index.php/apps/dashboard/")
        expect(page.locator(".dt-dashboard")).to_be_visible(timeout=30000)
        expect(page.locator(".dt-dashboard-option").first).to_be_visible(timeout=30000)
        page.locator(".dt-dashboard-option").first.click()
        expect(page.locator(".dt-dashboard-state")).to_have_text("Gespeichert", timeout=30000)
        assert not errors, errors
        other_context.close()
        context.close()
        browser.close()
        print("PASS: activation, catalog CRUD, day persistence, CSV, CSRF, user isolation, day/week/admin UI and dashboard")


if __name__ == "__main__":
    main()
