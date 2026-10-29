import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const PAGES = ['', '/shop', '/c/clothing', '/p/cotton-panjabi', '/p/crew-neck-t-shirt', '/search?q=panjabi', '/search?q=zzzzqq', '/pages/about', '/dev/components'];

async function scan(page, label) {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
    expect(summary, `${label}\n${summary.join('\n')}`).toEqual([]);
}

// Every key template, both languages. The Bangla page is reached through the language switcher, which also proves it points at the matching page.
for (const path of PAGES) {
    test(`axe: ${path || 'home'} (English and Bangla)`, async ({ page }) => {
        await page.goto(`/en${path}`);
        await expect(page.locator('main')).toBeVisible();
        await scan(page, `/en${path}`); // includes the consent banner, which is visible on a first visit

        const bn = page.locator('nav a[hreflang="bn"]').first();
        await expect(bn).toBeVisible();
        await bn.click();
        await expect(page).toHaveURL(/\/bn(\/|$|\?)/);
        await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
        await scan(page, `/bn${path}`);
    });
}

test('axe: search suggestions open', async ({ page }) => {
    await page.goto('/en');
    await page.getByRole('combobox').fill('pan');
    await expect(page.getByRole('listbox')).toBeVisible();
    await scan(page, 'suggestions open');
});

test('axe: filters and sort controls with a filter applied', async ({ page }) => {
    await page.goto('/en/shop?brand[]=dhaka-craft&sort=price_asc&in_stock=1');
    await scan(page, 'filtered shop');
});
