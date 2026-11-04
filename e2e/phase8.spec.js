import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';

// Phase 8 pages: digital downloads and licence keys (signed in, and a guest), refund and cancellation words, choosing a time, the cart line for a booking, "My bookings"
// (signed in, and a guest), each checked by axe at phone width in English, and in Bangla where the page can be reached in Bangla. Data: e2e/seed-phase8.php.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const read = (name) => readFileSync(`storage/app/${name}`, 'utf8').trim();

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

/** The newest code the log gateway "sent" (the e2e server uses the log SMS driver). */
function lastSmsCode() {
    const lines = readFileSync('storage/logs/laravel.log', 'utf8').split('\n').filter((l) => l.includes('[sms:log]'));
    const match = (lines.at(-1) ?? '').match(/\b(\d{6})\b/);
    expect(match, 'a code in the log').not.toBeNull();
    return match[1];
}

test('My downloads lists the files and keys, and the keys page is axe clean (English and Bangla)', async ({ page }) => {
    await signIn(page);
    await page.goto('/en/account/library');
    await expect(page.getByRole('heading', { level: 1, name: 'My downloads' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'E2E Cooking E-book' })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Download/ }).first()).toBeVisible();
    await expect(page.getByText('1 of 1 licence keys are ready.')).toBeVisible();
    await noViolations(page, 'my downloads (English)');

    await page.getByRole('button', { name: /Show my keys/ }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Your licence keys' })).toBeVisible();
    await expect(page.getByText('E2E-AAAA-1111')).toBeVisible();
    await noViolations(page, 'licence keys (English)');

    await page.goto('/bn/account/library');
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    await expect(page.getByRole('heading', { level: 1, name: 'আমার ডাউনলোড' })).toBeVisible();
    await noViolations(page, 'my downloads (Bangla)');
});

test('a guest opens their downloads only with the code sent to their phone', async ({ page }) => {
    await page.goto(read('e2e-guest-library-url.txt'));
    await expect(page.getByRole('heading', { level: 1, name: 'My downloads' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send me a code' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('E2E Cooking E-book');   // nothing before the code
    await noViolations(page, 'guest downloads: ask for a code');

    await page.getByRole('button', { name: 'Send me a code' }).click();
    await page.getByLabel(/Code from the SMS/).fill('000000');
    await page.getByRole('button', { name: /Open/ }).click();
    await expect(page.getByText('That code did not work.')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('E2E Cooking E-book');
    await noViolations(page, 'guest downloads: wrong code');

    await page.getByLabel(/Code from the SMS/).fill(lastSmsCode());
    await page.getByRole('button', { name: /Open/ }).click();
    await expect(page.getByRole('heading', { name: 'E2E Cooking E-book' })).toBeVisible();
    await noViolations(page, 'guest downloads: opened');
});

test('the product pages show the refund words and the cancellation words (English and Bangla)', async ({ page }) => {
    await page.goto('/en/p/e2e-cooking-e-book');
    await expect(page.getByRole('heading', { name: 'Refunds for digital products' })).toBeVisible();
    await noViolations(page, 'digital product page (English)');
    await page.goto('/bn/p/e2e-cooking-e-book');
    await expect(page.getByRole('heading', { name: 'ডিজিটাল পণ্যের রিফান্ড' })).toBeVisible();
    await noViolations(page, 'digital product page (Bangla)');

    await page.goto('/en/p/e2e-tailor-fitting');
    await expect(page.getByRole('link', { name: 'Choose a time' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Cancelling or moving a booking' })).toBeVisible();
    await noViolations(page, 'service product page (English)');
    await page.goto('/bn/p/e2e-tailor-fitting');
    await expect(page.getByRole('heading', { name: 'বুকিং বাতিল বা সরানো' })).toBeVisible();
    await noViolations(page, 'service product page (Bangla)');
});

test('choosing a time: the free times, then the booking in the cart (English and Bangla)', async ({ page }) => {
    await page.goto('/en/book/E2E-FITTING');
    await expect(page.getByRole('heading', { level: 1, name: 'E2E Tailor Fitting' })).toBeVisible();
    await expect(page.getByRole('button', { name: /AM|PM/ }).first()).toBeVisible();
    await noViolations(page, 'book a time (English)');
    await page.goto('/bn/book/E2E-FITTING');
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    await noViolations(page, 'book a time (Bangla)');

    await page.goto('/en/book/E2E-FITTING');
    await page.getByRole('button', { name: /AM|PM/ }).first().click();
    await expect(page).toHaveURL(/\/en\/cart$/);
    await expect(page.getByText('Your time is held. Finish checkout to keep it.')).toBeVisible();
    await expect(page.getByText('E2E Tailor Fitting').first()).toBeVisible();
    await noViolations(page, 'cart with a held booking');
});

test('My bookings: the list and one booking, with what cancelling would cost (English and Bangla)', async ({ page }) => {
    await signIn(page);
    await page.goto('/en/account/bookings');
    await expect(page.getByRole('heading', { level: 1, name: 'My bookings' })).toBeVisible();
    await expect(page.getByText('Confirmed').first()).toBeVisible();
    await noViolations(page, 'my bookings (English)');

    await page.getByRole('link', { name: /^Open/ }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'E2E Tailor Fitting' })).toBeVisible();
    await expect(page.getByText(/You can cancel for free/)).toBeVisible();
    await expect(page.getByText(/Our team approves the refund/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Show times' })).toBeVisible();
    await noViolations(page, 'one booking (English)');

    const url = page.url().replace('/en/', '/bn/');
    await page.goto(url);
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    await expect(page.getByRole('heading', { name: 'এই বুকিং বাতিল করুন' })).toBeVisible();
    await noViolations(page, 'one booking (Bangla)');
    await page.goto('/bn/account/bookings');
    await noViolations(page, 'my bookings (Bangla)');
});

test('a customer moves a booking to another time and then cancels another one', async ({ page }) => {
    await signIn(page);
    await page.goto('/en/account/bookings');
    await page.getByRole('link', { name: /^Open/ }).first().click();
    const before = await page.getByRole('heading', { level: 1 }).locator('xpath=following::p[2]').textContent();
    await page.getByRole('button', { name: /^\d{1,2}:\d{2}\s?(AM|PM)$/ }).nth(2).click();
    await expect(page.getByText('Your booking was moved.')).toBeVisible();
    await noViolations(page, 'a booking after it was moved');
    expect(await page.getByRole('heading', { level: 1 }).locator('xpath=following::p[2]').textContent()).not.toBe(before);

    await page.goto('/en/account/bookings');
    await page.getByRole('link', { name: /^Open/ }).nth(1).click();
    await page.getByLabel('Reason (optional)').fill('Plans changed');
    await page.getByRole('button', { name: 'Cancel booking' }).click();
    await expect(page.getByText('Your booking was cancelled.')).toBeVisible();
    await expect(page.getByText('This booking was cancelled')).toBeVisible();
    await expect(page.getByText(/Due back to you/)).toBeVisible();
    await noViolations(page, 'a booking after it was cancelled');
});

test('a guest opens their booking with a code, and sees only that order', async ({ page }) => {
    await page.goto(read('e2e-guest-bookings-url.txt'));
    await expect(page.getByRole('heading', { level: 1, name: 'My bookings' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send me a code' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('E2E Tailor Fitting');
    await noViolations(page, 'guest bookings: ask for a code');

    await page.getByRole('button', { name: 'Send me a code' }).click();
    await page.getByLabel('Code').fill(lastSmsCode());
    await page.getByRole('button', { name: 'Open my bookings' }).click();
    await expect(page.getByRole('heading', { level: 3, name: 'E2E Tailor Fitting' })).toBeVisible();
    await noViolations(page, 'guest bookings: the list');

    await page.getByRole('link', { name: /^Open/ }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'E2E Tailor Fitting' })).toBeVisible();
    await expect(page.getByText(/You can cancel for free/)).toBeVisible();
    await noViolations(page, 'guest bookings: one booking');
});

test('a changed guest link shows nothing', async ({ page }) => {
    const response = await page.goto(read('e2e-guest-bookings-url.txt').replace(/signature=[0-9a-f]+/, 'signature=0000'));
    expect(response.status()).toBe(403);
    const lib = await page.goto(read('e2e-guest-library-url.txt').replace(/signature=[0-9a-f]+/, 'signature=0000'));
    expect(lib.status()).toBe(403);
});
