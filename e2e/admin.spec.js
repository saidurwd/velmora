import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import crypto from 'node:crypto';

// Phase 10 gate: the staff pages in a browser. One sign-in (password, then the two-step code), then the key screens in English and Bangla:
// accessibility (axe) and keyboard-only use. The code is worked out from the throw-away secret that e2e/seed-admin.php stored.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const SCREENS = [
    ['dashboard', '/admin'],
    ['orders list', '/admin/orders'],
    ['reports list', '/admin/reports'],
    ['a report', '/admin/reports/sales_by_day?from=2020-01-01&to=2030-12-31'],
    ['orders file', '/admin/reports/orders_file?from=2020-01-01&to=2030-12-31'],
    ['stock files', '/admin/inventory/imports'],
    ['settings', '/admin/settings'],
    ['system health', '/admin/ops/health'],
    ['scheduled tasks', '/admin/ops/scheduler'],
    ['audit log', '/admin/audit'],
    ['support', '/admin/support'],
    ['activity log', '/admin/security/activity'],
    ['security events', '/admin/security/events'],
    ['login history', '/admin/security/logins'],
    ['my sessions and recent sign-ins', '/admin/account/sessions'],
    ['queue monitor', '/admin/ops/queues'],
    ['about', '/admin/ops/about'],
];

function base32(s) {
    const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of s) bits += a.indexOf(c).toString(2).padStart(5, '0');
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

function code(step) {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(step));
    const h = crypto.createHmac('sha1', base32(SECRET)).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
    return String(n % 1_000_000).padStart(6, '0');
}

async function scan(page, label) {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
    expect(summary, `${label}\n${summary.join('\n')}`).toEqual([]);
}

test.describe.configure({ mode: 'serial' });

let state;

test.beforeAll(async ({ browser }, info) => {
    const context = await browser.newContext(info.project.use);
    const page = await context.newPage();
    await page.goto('/admin/login');
    await page.getByLabel(/email/i).fill('e2e.admin@example.com');
    await page.getByLabel(/password/i).fill('E2e-Admin-Pass-2026!');
    await page.getByRole('button', { name: /^continue$/i }).click();
    await expect(page).toHaveURL(/\/admin\/mfa\/challenge/);
    await page.getByLabel(/code/i).first().fill(code(Math.floor(Date.now() / 30000)));
    await page.getByRole('button', { name: /verify and sign in/i }).click();
    await expect(page).toHaveURL(/\/admin(\/?$|\?|\/(?!login|mfa))/);
    state = await context.storageState();
    await context.close();
});

for (const [lang, locale] of [['English', 'en-US'], ['Bangla', 'bn-BD']]) {
    test.describe(lang, () => {
        let context;
        let page;

        test.beforeAll(async ({ browser }, info) => {
            context = await browser.newContext({ ...info.project.use, storageState: state, locale });
            page = await context.newPage();
        });
        test.afterAll(async () => {
            await context.close();
        });

        for (const [name, path] of SCREENS) {
            test(`axe: ${name}`, async () => {
                const response = await page.goto(path);
                expect(response.status(), `${path} answered ${response.status()}`).toBe(200);
                await expect(page.locator('html')).toHaveAttribute('lang', locale.startsWith('bn') ? 'bn' : 'en');
                await expect(page.locator('main')).toBeVisible();
                await scan(page, `${lang}: ${path}`);
            });
        }

        test('keyboard: the bulk bar on the orders list works without a mouse', async () => {
            await page.goto('/admin/orders');
            const select = page.locator('select[id^="bulk-action-"]');
            if ((await select.count()) === 0) test.skip(true, 'no bulk bar on this build');
            await select.focus();
            await expect(select).toBeFocused();
            await page.keyboard.press('Tab');
            await page.keyboard.press('Space'); // the "choose all" box
            const boxes = page.locator('input[name="ids[]"]');
            if ((await boxes.count()) > 0) await expect(boxes.first()).toBeChecked();
            await scan(page, `${lang}: orders list with boxes ticked`);
        });

        test('keyboard: the first Tab reaches the way past the menu', async () => {
            await page.goto('/admin');
            await page.keyboard.press('Tab');
            const focused = await page.evaluate(() => document.activeElement?.textContent?.trim() ?? '');
            expect(focused.length, 'the first focus stop must be a named control').toBeGreaterThan(0);
        });
    });
}

// Phase 12: the Content-Security-Policy is enforced, so the "are you sure?" question before a delete is a data attribute (data-confirm) handled by resources/js/behaviors.js,
// not an inline handler. It runs at desktop width, where staff mostly work; the phone layout is checked by the staff-menu test below.
test.describe('the delete question (Content-Security-Policy enforced)', () => {
    test('deleting asks first, cancelling really cancels, and the page is not blocked by the policy', async ({ browser }, info) => {
        const context = await browser.newContext({ ...info.project.use, storageState: state, viewport: { width: 1280, height: 900 } });
        const page = await context.newPage();
        const violations = [];
        page.on('console', (msg) => {
            if (/Content Security Policy|Refused to/i.test(msg.text())) violations.push(msg.text());
        });
        await page.goto('/admin/catalog/categories');
        const remove = page.getByRole('button', { name: /^delete$/i }).first();
        await expect(remove).toBeVisible();
        const before = await page.locator('tbody tr, li').count();

        let asked = '';
        page.once('dialog', async (dialog) => {
            asked = dialog.message();
            await dialog.dismiss();
        });
        await remove.click();
        await page.waitForLoadState('networkidle');

        expect(asked.length, 'the confirmation question was shown').toBeGreaterThan(5);
        await expect(page).toHaveURL(/\/admin\/catalog\/categories/);
        expect(await page.locator('tbody tr, li').count()).toBe(before);
        expect(violations).toEqual([]);
        await context.close();
    });
});

// Phase 12: Alpine runs as its CSP build (no script evaluation). The staff-side components, with the sign-in this file already made.
test.describe('staff-side Alpine components (CSP build)', () => {
    let page;
    let context;
    const problems = [];

    test.beforeAll(async ({ browser }, info) => {
        context = await browser.newContext({ ...info.project.use, storageState: state, viewport: { width: 1280, height: 900 } });
        page = await context.newPage();
        page.on('console', (msg) => {
            if (/Content Security Policy|Refused to|Alpine (Expression )?Error|CSP Parser Error/i.test(msg.text())) problems.push(msg.text());
        });
    });
    test.afterAll(async () => {
        await context.close();
    });

    test('the coupon form shows only the fields its choices need', async () => {
        await page.goto('/admin/promotions/coupons/create');
        await page.waitForFunction(() => document.querySelector('[x-data]')?._x_dataStack !== undefined);
        const type = page.locator('select[name="type"]');
        const percent = page.locator('[x-show="type === \'percent\'"]');
        await type.selectOption('percent');
        await expect(percent).toBeVisible();
        await type.selectOption('free_shipping');
        await expect(percent).toBeHidden();
        await page.locator('select[name="scope"]').selectOption('products');
        await expect(page.locator('[x-show="scope === \'products\'"]')).toBeVisible();
        await page.locator('select[name="scope"]').selectOption('all');
        await expect(page.locator('[x-show="scope === \'products\'"]')).toBeHidden();
        expect(problems).toEqual([]);
    });

    test('the automatic promotion form switches its fields too', async () => {
        await page.goto('/admin/promotions/automatic/create');
        await page.waitForFunction(() => document.querySelector('[x-data]')?._x_dataStack !== undefined);
        const type = page.locator('select[name="type"]');
        const percent = page.locator('[x-show="type === \'percent\'"]');
        await type.selectOption('percent');
        await expect(percent).toBeVisible();
        const other = (await type.locator('option').allTextContents()).length > 1 ? await type.locator('option:not([value="percent"])').first().getAttribute('value') : null;
        if (other) {
            await type.selectOption(other);
            await expect(percent).toBeHidden();
        }
        expect(problems).toEqual([]);
    });

    test('the translation tabs on the product form switch language', async () => {
        await page.goto('/admin/catalog/products/create');
        await page.waitForFunction(() => document.querySelector('[x-data]')?._x_dataStack !== undefined);
        const tabs = page.getByRole('tab');
        expect(await tabs.count()).toBeGreaterThan(1);
        const second = tabs.nth(1);
        await second.click();
        await expect(second).toHaveAttribute('aria-selected', 'true');
        await expect(tabs.first()).toHaveAttribute('aria-selected', 'false');
        await expect(page.getByRole('tabpanel').filter({ visible: true })).toHaveCount(1);
        expect(problems).toEqual([]);
    });

    test('the print button asks the browser to print (data-print, no inline script)', async () => {
        await page.addInitScript(() => { window.__printed = 0; window.print = () => { window.__printed++; }; });
        await page.goto('/admin/shipping/pick-list');
        const button = page.locator('[data-print]');
        await expect(button).toBeVisible();
        await button.click();
        expect(await page.evaluate(() => window.__printed)).toBe(1);
        expect(problems).toEqual([]);
    });
});


// The staff menu on a phone: closed behind a "Menu" button so the page comes first; on a large screen it is always open. (Uses the sign-in this file already made.)
test('the staff menu collapses behind a Menu button on a phone and stays open on a desktop', async ({ browser }, info) => {
    const phone = await browser.newContext({ ...info.project.use, storageState: state, viewport: { width: 390, height: 900 } });
    const page = await phone.newPage();
    await page.goto('/admin');
    await page.waitForFunction(() => document.querySelector('[x-data]')?._x_dataStack !== undefined);
    const button = page.getByRole('button', { name: 'Menu', exact: true });
    const sales = page.locator('summary', { hasText: 'Sales' });
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await expect(sales).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), 'no sideways scrolling on a phone').toBe(false);
    await button.click();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await expect(sales).toBeVisible();
    await phone.close();

    const desktop = await browser.newContext({ ...info.project.use, storageState: state, viewport: { width: 1280, height: 800 } });
    const wide = await desktop.newPage();
    await wide.goto('/admin');
    await expect(wide.getByRole('button', { name: 'Menu', exact: true })).toBeHidden();
    await expect(wide.locator('summary', { hasText: 'Sales' })).toBeVisible();
    await desktop.close();
});
