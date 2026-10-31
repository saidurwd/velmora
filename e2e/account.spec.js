import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function noViolations(page, label) {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
    expect(summary, `${label}\n${summary.join('\n')}`).toEqual([]);
}

async function signIn(page) {
    await page.goto('/en/login');
    await page.getByLabel('Email or mobile number').fill('demo.customer@example.com');
    await page.getByLabel('Password').fill('Demo-Pass-1234!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/en\/account/);
}

test('a signed-in customer orders, finds the order in My orders, and cancels it (axe clean in both languages)', async ({ page }) => {
    await signIn(page);

    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.getByRole('link', { name: 'Checkout' }).click();
    await page.waitForFunction(() => document.querySelector('[x-data]')?._x_dataStack !== undefined);
    await page.getByLabel(/Recipient|Full name/).first().fill('Rahim Uddin');
    await page.getByLabel(/Mobile/).first().fill('01712345678');
    await page.locator('#division_id').selectOption({ label: 'Dhaka' });
    await page.locator('#district_id').selectOption({ label: 'Dhaka' });
    await page.locator('#upazila_id').selectOption({ label: 'Savar' });
    await page.locator('#f-address_line').fill('House 5, Road 2');
    await page.getByRole('button', { name: 'Continue to review' }).click();
    await page.getByRole('button', { name: /Place order/ }).click();

    await expect(page.getByRole('heading', { level: 1, name: /Thank you/ })).toBeVisible();
    await noViolations(page, 'confirmation of a signed-in customer');
    await page.getByRole('link', { name: 'See this order in My orders' }).click();

    await expect(page.getByRole('heading', { level: 1, name: /VM-\d{4}-\d{6}/ })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cancel order' })).toBeVisible();
    await noViolations(page, 'my order page');
    const orderUrl = page.url();

    await page.goto('/en/account/orders');
    await expect(page.getByRole('heading', { level: 1, name: 'My orders' })).toBeVisible();
    await noViolations(page, 'my orders list');

    await page.goto('/bn/account/orders');
    await expect(page.getByRole('heading', { level: 1, name: 'আমার অর্ডার' })).toBeVisible();
    await noViolations(page, 'bn my orders list');
    await page.goto(orderUrl.replace('/en/', '/bn/'));
    await expect(page.getByRole('button', { name: 'অর্ডার বাতিল করুন' })).toBeVisible();
    await noViolations(page, 'bn my order page');

    await page.goto(orderUrl);
    await page.getByLabel(/Why are you cancelling/).fill('Ordered by mistake');
    await page.getByRole('button', { name: 'Cancel order' }).click();
    await expect(page.getByText('Your order was cancelled.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cancel order' })).toHaveCount(0);
    await noViolations(page, 'cancelled order page');
});

test('a guest makes an account from the confirmation page and the page passes axe', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.getByRole('link', { name: 'Checkout' }).click();
    await page.waitForFunction(() => document.querySelector('[x-data]')?._x_dataStack !== undefined);
    await page.getByLabel(/Recipient|Full name/).first().fill('Karim Ali');
    await page.getByLabel(/Mobile/).first().fill('01812345678');
    await page.locator('#division_id').selectOption({ label: 'Dhaka' });
    await page.locator('#district_id').selectOption({ label: 'Dhaka' });
    await page.locator('#upazila_id').selectOption({ label: 'Savar' });
    await page.locator('#f-address_line').fill('House 9, Road 4');
    await page.getByLabel(/Email/).fill(`karim${Date.now()}@example.com`);
    await page.getByRole('button', { name: 'Continue to review' }).click();
    await page.getByRole('button', { name: /Place order/ }).click();

    await expect(page.getByRole('heading', { level: 2, name: 'Keep track of this order' })).toBeVisible();
    await page.getByLabel('Password', { exact: true }).fill('Sup3r-Secret-Passw0rd!');
    await page.getByLabel('Repeat password').fill('Sup3r-Secret-Passw0rd!');
    await page.getByRole('button', { name: 'Create account' }).click();

    await expect(page.getByText(/we have sent you an email to finish setting up your account/)).toBeVisible();
    await noViolations(page, 'confirmation after starting an account');
});
