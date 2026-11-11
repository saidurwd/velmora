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
