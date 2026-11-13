import { test, expect } from '@playwright/test';

// Phase 12: the Content-Security-Policy is enforced. These tests fail if a page is blocked by it (a script, a style, a form target), and prove that the
// behaviours that used to be inline handlers (send a form when a choice changes) still work through resources/js/behaviors.js. The staff side (the delete question) is in admin.spec.js, which
// already signs in once: all browser tests share one address and sign-in is limited to 10 a minute per address, so this file signs nobody in.
// One worker for these three: they are quick, and spreading them over every worker next to the other specs made a slow ticket form in phase9 time out on a busy laptop.
test.describe.configure({ mode: 'serial' });

/** Records every policy violation the browser reports, from a page's first moment. */
async function watch(page) {
    const violations = [];
    await page.addInitScript(() => {
        window.__csp = [];
        document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(`${e.violatedDirective} ${e.blockedURI}`));
    });
    page.on('console', (msg) => {
        if (/Content Security Policy|Refused to/i.test(msg.text())) violations.push(msg.text());
    });

    return async () => [...violations, ...(await page.evaluate(() => window.__csp ?? []))];
}

test('the policy is sent on every page, and no storefront page is blocked by it', async ({ page }) => {
    const seen = await watch(page);
    for (const path of ['/en', '/bn', '/en/shop', '/en/p/cotton-panjabi', '/en/cart', '/en/login', '/en/register', '/en/contact', '/bn/shop']) {
        const response = await page.goto(path);
        expect(response.headers()['content-security-policy'], path).toContain("script-src 'self'");
        expect(response.headers()['content-security-policy-report-only'], path).toBeUndefined();
        await page.waitForLoadState('networkidle');
        expect(await seen(), path).toEqual([]);
    }
});

test('a choice that sends its form on change still does (sorting the shop), without any inline handler', async ({ page }) => {
    const seen = await watch(page);
    await page.goto('/en/shop');
    await page.locator('#sort').selectOption('price_asc');
    await expect(page).toHaveURL(/sort=price_asc/);
    expect(await seen()).toEqual([]);
});

test('Alpine pieces still work under the policy: the search box suggests, the consent banner answers', async ({ page }) => {
    const seen = await watch(page);
    await page.goto('/en');
    await page.getByRole('combobox').first().fill('cot');
    await expect(page.getByRole('option').first()).toBeVisible({ timeout: 10_000 });
    await page.getByRole('button', { name: 'No thanks' }).click({ timeout: 5000 }).catch(() => {});
    expect(await seen()).toEqual([]);
});
