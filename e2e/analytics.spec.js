import { test, expect } from '@playwright/test';

function watch(page) {
    const events = [];
    const foreign = [];
    const origin = new URL(page.context()._options?.baseURL || 'http://127.0.0.1:8010').host;
    page.on('request', (r) => {
        const u = new URL(r.url());
        if (u.pathname.endsWith('/analytics/events')) events.push(JSON.parse(r.postData() || '{}'));
        if (!['127.0.0.1', 'localhost'].includes(u.hostname) && u.protocol.startsWith('http') && u.host !== origin) foreign.push(r.url());
    });

    return { events, foreign };
}

test('nothing is sent before the visitor chooses, and nothing goes to other sites', async ({ page }) => {
    const { events, foreign } = watch(page);
    await page.goto('/en/p/cotton-panjabi');
    await expect(page.getByRole('region', { name: /help us improve/i })).toBeVisible();
    await page.waitForTimeout(500);
    expect(events).toEqual([]);
    expect(foreign).toEqual([]);
});

test('allowing sends page_view and view_item (first-party), and the choice is remembered', async ({ page }) => {
    const { events } = watch(page);
    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Allow' }).click();
    await expect.poll(() => events.length).toBeGreaterThanOrEqual(2);

    expect(events.map((e) => e.name).sort()).toEqual(['page_view', 'view_item']);
    expect(events.every((e) => e.path === '/en/p/cotton-panjabi')).toBe(true);
    expect(events.find((e) => e.name === 'view_item').product).toMatch(/^[0-9a-z]{26}$/);
    for (const e of events) expect(Object.keys(e).every((k) => ['name', 'path', 'product', 'results'].includes(k))).toBe(true); // nothing else is ever sent

    await page.goto('/en/shop');                     // remembered: no banner, events keep flowing
    await expect(page.getByRole('region', { name: /help us improve/i })).toBeHidden();
    await expect.poll(() => events.filter((e) => e.path === '/en/shop').length).toBe(1);
});

test('a search sends the result count only, never the words', async ({ page }) => {
    const { events } = watch(page);
    await page.goto('/en');
    await page.getByRole('button', { name: 'Allow' }).click();
    await page.goto('/en/search?q=panjabi');
    await expect.poll(() => events.some((e) => e.name === 'search')).toBe(true);

    const search = events.find((e) => e.name === 'search');
    expect(search.results).toBeGreaterThan(0);
    expect(JSON.stringify(events)).not.toContain('panjabi?'); // the path carries no query string
    expect(JSON.stringify(search)).not.toContain('q=');
});

test('declining sends nothing, then or later', async ({ page }) => {
    const { events } = watch(page);
    await page.goto('/en/shop');
    await page.getByRole('button', { name: 'No thanks' }).click();
    await page.goto('/en/p/cotton-panjabi');
    await page.waitForTimeout(500);
    expect(events).toEqual([]);
});

test('Global Privacy Control counts as a refusal: no banner, no events', async ({ page }) => {
    await page.addInitScript(() => Object.defineProperty(navigator, 'globalPrivacyControl', { value: true }));
    const { events } = watch(page);
    await page.goto('/en/p/cotton-panjabi');
    await expect(page.getByRole('region', { name: /help us improve/i })).toBeHidden();
    await page.waitForTimeout(500);
    expect(events).toEqual([]);
});

test('the Bangla banner and the privacy settings link', async ({ page }) => {
    await page.goto('/bn');
    await expect(page.getByRole('button', { name: 'অনুমতি দিন' })).toBeVisible();
    await page.getByRole('button', { name: 'না, ধন্যবাদ' }).click();
    await expect(page.getByRole('button', { name: 'গোপনীয়তা সেটিংস' })).toBeVisible();
});
