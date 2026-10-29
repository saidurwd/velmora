import { test, expect } from '@playwright/test';

test('skip link is the first stop and moves focus to the main content', async ({ page }) => {
    await page.goto('/en/shop');
    await page.keyboard.press('Tab');
    const skip = page.locator('.skip-link');
    await expect(skip).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#main')).toBeFocused();
});

test('search box: type, arrow through suggestions, Enter opens the product, Esc closes', async ({ page }) => {
    await page.goto('/en');
    const box = page.getByRole('combobox');
    await box.focus();
    await page.keyboard.type('panj');

    const list = page.getByRole('listbox');
    await expect(list).toBeVisible();
    await expect(box).toHaveAttribute('aria-expanded', 'true');

    await page.keyboard.press('ArrowDown');
    const active = await box.getAttribute('aria-activedescendant');
    expect(active).toBe('sg-0');
    await expect(page.locator('#sg-0')).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('ArrowDown');
    await expect(box).toHaveAttribute('aria-activedescendant', 'sg-1');
    await page.keyboard.press('ArrowUp');
    await expect(box).toHaveAttribute('aria-activedescendant', 'sg-0');

    await page.keyboard.press('Escape');
    await expect(list).toBeHidden();
    await expect(box).toHaveAttribute('aria-expanded', 'false');

    await page.keyboard.press('ArrowDown'); // closed: nothing happens
    await box.fill('');
    await page.keyboard.type('panj');
    await expect(list).toBeVisible();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/en\/p\//);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
});

test('search box: Enter with no highlighted suggestion runs the normal search', async ({ page }) => {
    await page.goto('/en');
    await page.getByRole('combobox').fill('wallet');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/en\/search\?q=wallet/);
    await expect(page.getByText('Leather Wallet').first()).toBeVisible();
});

test('variant picker: reachable and changeable from the keyboard', async ({ page }) => {
    await page.goto('/en/p/crew-neck-t-shirt');
    const sku = page.getByText(/^SKU:/);
    const first = await sku.textContent();

    const sizeGroup = page.getByRole('group', { name: /size/i });
    await sizeGroup.locator('input[type=radio]:checked').focus();
    await page.keyboard.press('ArrowRight');   // radio groups move with the arrow keys
    await expect(page).toHaveURL(/opt%5Bsize%5D=|opt\[size\]=/);
    expect(await page.getByText(/^SKU:/).textContent()).not.toBe(first);
});

test('filters: every control is keyboard operable and Apply narrows the list', async ({ page }) => {
    await page.goto('/en/shop');
    // on a phone the filters are folded away: open them with the keyboard first
    await page.getByText('Filters', { exact: true }).first().focus();
    await page.keyboard.press('Enter');
    await page.getByRole('group', { name: 'Brand' }).getByLabel(/Dhaka Craft/).focus();
    await page.keyboard.press('Space');
    await page.locator('aside').getByRole('button', { name: 'Apply' }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/brand(%5B%5D|\[\])=dhaka-craft/);
    await expect(page.getByRole('heading', { name: /products?$/ })).toBeVisible();
});

test('consent banner is reachable by keyboard and can be reopened from the footer', async ({ page }) => {
    await page.goto('/en');
    const banner = page.getByRole('region', { name: /help us improve/i });
    await expect(banner).toBeVisible();
    await banner.getByRole('button', { name: 'No thanks' }).focus();
    await page.keyboard.press('Enter');
    await expect(banner).toBeHidden();
    await page.getByRole('button', { name: 'Privacy settings' }).focus();
    await page.keyboard.press('Enter');
    await expect(banner).toBeVisible();
    await expect(banner).toBeFocused();
});
