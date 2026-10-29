import { test, expect } from '@playwright/test';

// Browsing, searching, filtering and choosing a variant must work with JavaScript switched off.
test.use({ javaScriptEnabled: false });

test('home, shop, category and product pages render with content', async ({ page }) => {
    for (const path of ['/en', '/bn', '/en/shop', '/en/c/clothing', '/en/p/cotton-panjabi']) {
        const res = await page.goto(path);
        expect(res?.status(), path).toBe(200);
        await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1); // exactly one page title on every page
    }
});

test('search works through the plain form', async ({ page }) => {
    await page.goto('/en');
    await page.locator('#site-search').fill('wallet');
    await page.locator('form[role=search] button[type=submit]').click();
    await expect(page).toHaveURL(/\/en\/search\?q=wallet/);
    await expect(page.getByText('Leather Wallet').first()).toBeVisible();
});

test('filters apply through the form and results are paginated with links', async ({ page }) => {
    await page.goto('/en/shop');
    await page.getByText('Filters', { exact: true }).first().click(); // the folded filters open natively, no script needed
    await page.getByRole('group', { name: 'Brand' }).getByLabel(/Dhaka Craft/).check();
    await page.locator('aside').getByRole('button', { name: 'Apply' }).click();
    await expect(page).toHaveURL(/brand(%5B%5D|\[\])=dhaka-craft/);

    await page.goto('/en/shop');
    await page.getByRole('link', { name: /Next/ }).click();
    await expect(page).toHaveURL(/page=2/);
});

test('sorting works with the no-JS button, and a variant is chosen with the noscript button', async ({ page }) => {
    await page.goto('/en/shop');
    await page.getByLabel('Sort by').selectOption('price_asc');
    await page.getByRole('button', { name: 'Apply' }).last().click();
    await expect(page).toHaveURL(/sort=price_asc/);

    await page.goto('/en/p/crew-neck-t-shirt');
    await page.getByRole('group', { name: /size/i }).getByText('XL', { exact: true }).click();
    await page.getByRole('button', { name: 'Update choice' }).click();
    await expect(page.getByText(/^SKU: TEE-XL-/)).toBeVisible();
});

test('the language switcher still works', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.locator('nav a[hreflang="bn"]').first().click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
});

test('no consent banner is shown and nothing is tracked without JavaScript', async ({ page }) => {
    const hits = [];
    page.on('request', (r) => r.url().includes('/analytics/events') && hits.push(r.url()));
    await page.goto('/en');
    await expect(page.locator('#privacy-controls section')).toBeHidden();
    expect(hits).toEqual([]);
});
