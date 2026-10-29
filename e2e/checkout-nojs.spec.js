import { test, expect } from '@playwright/test';

// Checking out must work with JavaScript switched off: every district and upazila is listed under its parent, and the server checks the chain.
test.use({ javaScriptEnabled: false });

const LOCALE_UPAZILA = 'Savar';

test('a guest can check out without JavaScript', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.goto('/en/checkout');

    await page.getByLabel(/Recipient/).fill('Rahim Uddin');
    await page.getByLabel(/Mobile/).first().fill('01712345678');
    await page.locator('#division_id').selectOption({ label: 'Dhaka' });
    await page.locator('#district_id').selectOption({ label: 'Dhaka' });
    await page.locator('#upazila_id').selectOption({ label: LOCALE_UPAZILA });
    await page.locator('#f-address_line').fill('House 5, Road 2');
    await page.getByRole('button', { name: 'Continue to review' }).click();

    await expect(page.getByRole('heading', { level: 1, name: 'Review your order' })).toBeVisible();
    await page.getByRole('button', { name: /Place order/ }).click();
    await expect(page.getByRole('heading', { level: 1, name: /Thank you/ })).toBeVisible();
});
