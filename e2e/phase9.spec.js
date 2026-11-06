import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';

// Phase 9 pages: message preferences, privacy tools, wishlist, reviews, returns, support, the help centre and contact page, and the order page with its new buttons.
// Each is checked by axe at phone width in English and Bangla; the forms are used with the keyboard and without JavaScript. Data: e2e/seed-phase9.php.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ids = () => JSON.parse(readFileSync('storage/app/e2e-phase9.json', 'utf8'));

async function noViolations(page, label) {
    // the consent banner appears a moment after load, fixed to the bottom, and would sit on top of links the scan measures: answer it first
    await page.getByRole('button', { name: 'No thanks' }).click({ timeout: 2000 }).catch(() => {});
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

/** Opens the page in both languages; the page must have a level-1 heading and no axe violations in each. */
async function bothLanguages(page, path, label) {
    for (const locale of ['en', 'bn']) {
        await page.goto(`/${locale}${path}`);
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        await expect(page.locator('h1').first(), `${label} (${locale}) has a heading`).toBeVisible();
        await noViolations(page, `${label} (${locale})`);
    }
}

test.describe('signed in', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page);
    });

    test('messages, privacy, wishlist, my reviews, my returns and my tickets are axe clean in both languages', async ({ page }) => {
        await bothLanguages(page, '/account/preferences', 'messages & preferences');
        await bothLanguages(page, '/account/privacy', 'privacy');
        await bothLanguages(page, '/account/wishlist', 'wishlist');
        await bothLanguages(page, '/account/reviews', 'my reviews');
        await bothLanguages(page, '/account/support', 'my tickets');
        await bothLanguages(page, '/account/support/new', 'new ticket');
    });

    test('the order page offers buy again and the review of a line not yet reviewed, and the review form is axe clean', async ({ page }) => {
        const { order, openVariant, ticket } = ids();
        await bothLanguages(page, `/account/orders/${order}`, 'order page');

        await page.goto(`/en/account/orders/${order}`);
        await expect(page.getByRole('button', { name: /Buy again/ })).toBeVisible();
        await expect(page.getByRole('link', { name: /Send items back/ })).toBeVisible();

        await bothLanguages(page, `/account/orders/${order}/reviews/${openVariant}`, 'review form');
        await bothLanguages(page, `/account/support/${ticket}`, 'a ticket the shop answered');
        await page.goto(`/en/account/support/${ticket}`);
        await expect(page.getByText('You can open the receipt from the order page')).toBeVisible();
    });

    test('a customer sends items back from the order page and follows the request', async ({ page }) => {
        const { order } = ids();
        await page.goto(`/en/account/orders/${order}`);
        await page.getByRole('link', { name: /Send items back/ }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Send items back' })).toBeVisible();
        await noViolations(page, 'return form (English)');

        await page.getByLabel('Demo item 1').fill('1');
        await page.getByLabel('Why are you sending it back?').selectOption('changed_mind');
        await page.getByRole('button', { name: 'Send my request' }).click();
        await expect(page.getByText('We have your request.')).toBeVisible();

        await bothLanguages(page, '/account/returns', 'my returns');
        await page.goto('/en/account/returns');
        await expect(page.getByText('Waiting for us').first()).toBeVisible();
    });

    test('the message preferences are saved (quiet hours too)', async ({ page }) => {
        await page.goto('/en/account/preferences');
        await page.getByLabel('Send me offers and news by text message').check();
        await page.getByLabel('Quiet from').fill('21:00');
        await page.getByLabel('Quiet until').fill('06:30');
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Your preferences are saved.')).toBeVisible();
        await expect(page.getByLabel('Send me offers and news by text message')).toBeChecked();
        await expect(page.getByLabel('Quiet from')).toHaveValue('21:00');
        await noViolations(page, 'preferences after saving');
    });

    test('a data copy can be asked for, after the customer confirms it is them', async ({ page }) => {
        await page.goto('/en/account/privacy');
        await page.getByRole('button', { name: 'Ask for a copy' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Confirm it is you' })).toBeVisible();   // privacy tools need a fresh confirmation
        await noViolations(page, 'confirming it is the customer');
        await page.getByLabel('Password').fill('Demo-Pass-1234!');
        await page.getByRole('button', { name: 'Confirm' }).click();

        await page.goto('/en/account/privacy');
        await page.getByRole('button', { name: 'Ask for a copy' }).click();
        await expect(page.getByText(/preparing your copy|Ready/).first()).toBeVisible();
        await noViolations(page, 'privacy after asking for a copy');
    });

    test('a ticket is written and sent with the keyboard alone', async ({ page }) => {
        await page.goto('/en/account/support/new');
        await page.getByLabel('What is it about?').focus();
        await page.keyboard.type('Keyboard question about delivery');
        await page.getByLabel('Your message').focus();
        await page.keyboard.type('I wrote this without using the mouse, please tell me when my parcel arrives.');
        await page.getByRole('button', { name: 'Send', exact: true }).focus();
        await page.keyboard.press('Enter');
        await expect(page.getByText('Keyboard question about delivery').first()).toBeVisible();
        await noViolations(page, 'a new ticket');
    });
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('a signed-in customer still sends a ticket and saves preferences with plain forms', async ({ page }) => {
        await signIn(page);
        await page.goto('/en/account/support/new');
        await page.getByLabel('What is it about?').fill('Plain form question');
        await page.getByLabel('Your message').fill('This form was sent with scripts switched off.');
        await page.getByRole('button', { name: 'Send', exact: true }).click();
        await expect(page.getByText('Plain form question').first()).toBeVisible();

        await page.goto('/en/account/preferences');
        await page.getByLabel('Send me offers and news by email').check();
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Your preferences are saved.')).toBeVisible();
    });
});

test('the help centre and the contact page are axe clean for everyone, in both languages', async ({ page }) => {
    await bothLanguages(page, '/help', 'help centre');
    await bothLanguages(page, '/contact', 'contact');
    await page.goto('/en/help');
    await page.getByLabel('Search the help centre').fill('delivery');
    await page.locator('#main').getByRole('button', { name: 'Search' }).click();
    await expect(page).toHaveURL(/q=delivery/);
    await noViolations(page, 'help centre search');
});

test('every signed-in page sends a visitor to sign in', async ({ page }) => {
    for (const path of ['/account/preferences', '/account/privacy', '/account/wishlist', '/account/reviews', '/account/returns', '/account/support', '/account/support/new']) {
        await page.goto(`/en${path}`);
        await expect(page, path).toHaveURL(/\/en\/login/);
    }
});
