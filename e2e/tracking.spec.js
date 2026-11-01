import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
// the link a customer would be sent (a signed, expiring link made by e2e/seed-shipment.php)
const link = () => readFileSync('storage/app/e2e-tracking-url.txt', 'utf8').trim();

async function noViolations(page, label) {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
    expect(summary, `${label}\n${summary.join('\n')}`).toEqual([]);
}

test('the tracking page shows the parcel in plain words and is axe clean in both languages', async ({ page }) => {
    await page.goto(link());
    await expect(page.getByRole('heading', { name: 'Track your order' })).toBeVisible();
    await expect(page.getByText('Parcel 1')).toBeVisible();
    await expect(page.getByText('On the way').first()).toBeVisible();
    await expect(page.getByText('Not sent yet')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('House 5'); // the address is never shown
    await expect(page.locator('body')).not.toContainText('01898765432');
    await noViolations(page, 'tracking (English)');

    const bn = page.locator('nav a[hreflang="bn"]').first();
    await expect(bn).toBeVisible();
    await bn.click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    await expect(page.getByText('পার্সেল')).toBeVisible();
    await noViolations(page, 'tracking (Bangla)');
});

test('a link whose signature has been changed shows nothing', async ({ page }) => {
    const response = await page.goto(link().replace(/signature=[0-9a-f]+/, 'signature=0000'));
    expect(response.status()).toBe(403);
    await expect(page.locator('body')).not.toContainText('Parcel 1');
});

test('a tracking page works without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto(link());
    await expect(page.getByText('Parcel 1')).toBeVisible();
    await context.close();
});
