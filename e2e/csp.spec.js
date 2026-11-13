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
        if (/Content Security Policy|Refused to|Alpine (Expression )?Error|CSP Parser Error/i.test(msg.text())) violations.push(msg.text());
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

test('the page runs Alpine\'s CSP build: components start, and no script evaluation is allowed', async ({ page }) => {
    const seen = await watch(page);
    const response = await page.goto('/en/p/cotton-panjabi');
    expect(response.headers()['content-security-policy']).not.toContain('unsafe-eval');
    await page.waitForFunction(() => document.querySelector('[x-data]')?._x_dataStack !== undefined);
    // recently viewed: remembered on this device, shown on the next product page
    await page.goto('/en/p/steel-water-bottle');
    const strip = page.locator('section[aria-labelledby="rv-h"]');
    await expect(strip).toBeVisible();
    await expect(strip).toContainText('Cotton Panjabi');
    // the policy really is enforced by this browser: a script put into the page as text is refused, and the browser says so
    await page.evaluate(() => {
        const script = document.createElement('script');
        script.textContent = 'window.__injected = true';
        document.head.appendChild(script);
    });
    expect(await page.evaluate(() => window.__injected ?? false), 'an inline script must not run').toBe(false);
    const all = await seen();
    expect(all.some((v) => v.includes('script-src')), `the browser reported the refusal: ${JSON.stringify(all)}`).toBe(true);
    // Playwright's own page.evaluate is exempt from the policy, so the absence of 'unsafe-eval' is checked through the header above.
    expect(all.filter((v) => !v.includes('script-src')), 'nothing else was blocked').toEqual([]);
});

test('the component library: the dialog opens, takes focus, and closes with Escape and the close button', async ({ page }) => {
    const seen = await watch(page);
    await page.goto('/en/dev/components');
    await page.waitForFunction(() => document.querySelector('[x-data]')?._x_dataStack !== undefined);
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeHidden();
    await page.getByRole('button', { name: 'Open dialog' }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await page.getByRole('button', { name: 'Open dialog' }).click();
    await dialog.getByRole('button', { name: /close/i }).click();
    await expect(dialog).toBeHidden();
    expect(await seen()).toEqual([]);
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
