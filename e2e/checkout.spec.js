import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function noViolations(page, label) {
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    const summary = results.violations.map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
    expect(summary, `${label}\n${summary.join('\n')}`).toEqual([]);
}

async function fillAddress(page, { locale = 'en' } = {}) {
    const LOCALE_UPAZILA = locale === 'bn' ? 'সাভার' : 'Savar';
    // Alpine sets the selects from its own state when it starts; wait for that so it cannot undo what the test picks
    await page.waitForFunction(() => document.querySelector('[x-data]')?._x_dataStack !== undefined);
    await page.getByLabel(/Recipient|Full name|প্রাপক|নাম/).first().fill('Rahim Uddin');
    await page.getByLabel(/Mobile|মোবাইল/).first().fill('01712345678');
    await page.locator('#division_id').selectOption({ label: locale === 'bn' ? 'ঢাকা' : 'Dhaka' });
    await page.locator('#district_id').selectOption({ label: locale === 'bn' ? 'ঢাকা' : 'Dhaka' });
    await page.locator('#upazila_id').selectOption({ label: LOCALE_UPAZILA });
    await page.locator('#f-address_line').fill('House 5, Road 2');
}

test('a guest buys cash on delivery: product, cart, details, review, confirmation (and every step passes axe)', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await expect(page).toHaveURL(/\/en\/cart$/);
    await page.getByRole('link', { name: 'Checkout' }).click();

    await expect(page.getByRole('heading', { level: 1, name: 'Checkout' })).toBeVisible();
    await fillAddress(page);
    await page.getByLabel(/Email/).fill('rahim@example.com');
    await noViolations(page, 'checkout details');
    await page.getByRole('button', { name: 'Continue to review' }).click();

    await expect(page.getByRole('heading', { level: 1, name: 'Review your order' })).toBeVisible();
    await expect(page.getByText('Standard delivery')).toBeVisible();
    await expect(page.getByRole('radio', { name: /Cash on delivery/ })).toBeChecked();
    await noViolations(page, 'checkout review');
    await page.getByRole('button', { name: /Place order/ }).click();

    await expect(page.getByRole('heading', { level: 1, name: /Thank you/ })).toBeVisible();
    await expect(page.getByText(/VM-\d{4}-\d{6}/).first()).toBeVisible();
    await noViolations(page, 'order confirmation');

    // the cart is empty afterwards
    await page.goto('/en/cart');
    await expect(page.getByText('Your cart is empty')).toBeVisible();
});

test('the Bangla checkout reads in Bangla and passes axe', async ({ page }) => {
    await page.goto('/bn/p/' + encodeURIComponent('সুতির-পাঞ্জাবি'));
    await page.getByRole('button', { name: 'কার্টে যোগ করুন' }).click();
    await page.goto('/bn/checkout');

    await expect(page.getByRole('heading', { level: 1, name: 'চেকআউট' })).toBeVisible();
    await fillAddress(page, { locale: 'bn' });
    await noViolations(page, 'bn checkout details');
    await page.getByRole('button', { name: 'যাচাইয়ে যান' }).click();

    await expect(page.getByRole('heading', { level: 1, name: 'অর্ডার যাচাই করুন' })).toBeVisible();
    await expect(page.getByText('ক্যাশ অন ডেলিভারি').first()).toBeVisible();
    await noViolations(page, 'bn checkout review');
});

test('a wrong phone number is explained beside the field and nothing is lost', async ({ page }) => {
    await page.goto('/en/p/cotton-panjabi');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.goto('/en/checkout');
    await fillAddress(page);
    await page.getByLabel(/Mobile/).first().fill('12345');
    await page.getByRole('button', { name: 'Continue to review' }).click();

    await expect(page.getByText(/valid Bangladesh mobile number/).first()).toBeVisible();
    await expect(page.getByLabel(/Recipient|Full name/).first()).toHaveValue('Rahim Uddin');
    await noViolations(page, 'checkout details with an error');
});

test('a large cash order asks to confirm the phone first, and the order button waits (axe clean)', async ({ page }) => {
    await page.goto('/en/p/embroidered-panjabi');   // ৳3,200 each: four are over the ৳10,000 limit for cash orders
    await page.getByLabel('Quantity').fill('4');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await expect(page).toHaveURL(/\/en\/cart$/);
    await page.getByRole('button', { name: 'No thanks' }).click(); // the consent banner would cover the page
    await page.getByRole('link', { name: 'Checkout' }).click();
    await fillAddress(page);
    await page.getByRole('button', { name: 'Continue to review' }).click();

    await expect(page.getByRole('heading', { name: 'Confirm your phone number' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send me the code' })).toBeVisible();
    await expect(page.getByRole('button', { name: /Place order/ })).toBeDisabled();
    await noViolations(page, 'review with the phone confirmation step');

    await page.getByLabel('Code from the SMS').fill('000000');
    await page.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByText('That code did not work')).toBeVisible();
    await expect(page.getByRole('button', { name: /Place order/ })).toBeDisabled();
});
