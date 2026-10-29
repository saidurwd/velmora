import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function noViolations(page, label) {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
    expect(summary, `${label}\n${summary.join('\n')}`).toEqual([]);
}

test('add from the product page, see it in the cart, change it, remove it', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Add to cart' }).click();

    await expect(page).toHaveURL(/\/en\/cart$/);
    await expect(page.getByText('Added to your cart.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Cotton Panjabi' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Cart, 1 item/ })).toBeVisible();

    await page.getByLabel('Quantity').fill('3');
    await page.getByRole('button', { name: 'Update' }).click();
    await expect(page.getByRole('link', { name: /Cart, 3 items/ })).toBeVisible();
    await expect(page.getByText('5,550', { exact: false }).first()).toBeVisible(); // 3 × ৳1,850

    await page.getByRole('button', { name: 'Remove' }).click();
    await expect(page.getByText('Your cart is empty')).toBeVisible();
});

test('a friendly message instead of an error when asking for more than is in stock', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.getByLabel('Quantity').fill('9999');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await expect(page).toHaveURL(/\/en\/p\//);
    await expect(page.getByRole('alert').or(page.locator('[role=status]')).filter({ hasText: /only \d+ left|at most/i })).toBeVisible();
});

test('Bangla digits work in the quantity box and the Bangla cart reads in Bangla', async ({ page }) => {
    await page.goto('/bn/p/' + encodeURIComponent('সুতির-পাঞ্জাবি'));
    await page.getByLabel('পরিমাণ').fill('২');
    await page.getByRole('button', { name: 'কার্টে যোগ করুন' }).click();
    await expect(page).toHaveURL(/\/bn\/cart$/);
    await expect(page.getByText('আপনার কার্ট')).toBeVisible();
    await expect(page.getByLabel('পরিমাণ')).toHaveValue('2');
});

test('the cart works with JavaScript off, and the cart survives a reload (cookie)', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await expect(page.getByRole('link', { name: 'Cotton Panjabi' })).toBeVisible();
    await page.goto('/en/shop');
    await expect(page.getByRole('link', { name: /Cart, 1 item/ })).toBeVisible();
    await context.close();
});

test('keyboard only: quantity field to submit', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.getByLabel('Quantity').focus();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('button', { name: 'Add to cart' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/cart$/);
});

test('the cart carries no accessibility violations: empty, with items, and in Bangla', async ({ page }) => {
    await page.goto('/en/cart');
    await noViolations(page, 'empty cart');

    await page.goto('/en/p/cotton-panjabi');
    await noViolations(page, 'product page with the add form');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await noViolations(page, 'cart with items');

    await page.locator('nav a[hreflang="bn"]').first().click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    await noViolations(page, 'cart with items (Bangla)');
});

test('the cart does not overflow a 360 px screen', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
});

test('apply a discount code in the cart, see the discount, remove it', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await expect(page).toHaveURL(/\/en\/cart$/);

    await page.getByLabel('Discount code').fill('nosuchcode');
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.getByText(/We do not recognise the code/)).toBeVisible();

    await page.getByLabel('Discount code').fill(' e2e10 ');
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.getByText('Code applied.')).toBeVisible();
    await expect(page.getByText('Items after discount')).toBeVisible();
    await expect(page.getByText('1,665', { exact: false }).first()).toBeVisible(); // ৳1,850 less 10%
    await noViolations(page, 'cart with a code');

    await page.getByRole('button', { name: 'Remove code E2E10' }).click();
    await expect(page.getByText('Code removed.')).toBeVisible();
    await expect(page.getByText('Items after discount')).toHaveCount(0);
});
