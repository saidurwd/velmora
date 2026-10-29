import { test, expect } from '@playwright/test';

async function loadedFamilies(page) {
    await page.evaluate(() => document.fonts.ready);
    return page.evaluate(() => [...document.fonts].filter((f) => f.status === 'loaded').map((f) => f.family.replace(/["']/g, '')));
}

test('English pages load the Latin font and do not preload the Bangla one', async ({ page }) => {
    await page.goto('/en');
    const families = await loadedFamilies(page);
    expect(families.some((f) => f.includes('Inter'))).toBe(true);
    // (the Bangla face may still load later for the "বাংলা" language-switcher label; it is just never preloaded on English pages)
    const preloads = await page.locator('link[rel=preload][as=font]').evaluateAll((els) => els.map((e) => e.href));
    expect(preloads.some((h) => /noto-sans-bengali/.test(h))).toBe(false);
});

test('Bangla pages load and really use the Bangla font for Bangla text', async ({ page }) => {
    await page.goto('/bn/p/' + encodeURIComponent('সুতির-পাঞ্জাবি'));
    const families = await loadedFamilies(page);
    expect(families.some((f) => f.includes('Noto Sans Bengali'))).toBe(true);

    const cdp = await page.context().newCDPSession(page);
    await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
    const { root } = await cdp.send('DOM.getDocument');
    const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: root.nodeId, selector: 'h1' });
    const { fonts } = await cdp.send('CSS.getPlatformFontsForNode', { nodeId });
    expect(fonts.map((f) => f.familyName).join(' ')).toContain('Noto Sans Bengali'); // the font actually used to draw the heading
});

test('Bangla pages use looser line spacing than English (readability of Bangla script)', async ({ page }) => {
    await page.goto('/en'); const en = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).lineHeight) / parseFloat(getComputedStyle(document.documentElement).fontSize));
    await page.goto('/bn'); const bn = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).lineHeight) / parseFloat(getComputedStyle(document.documentElement).fontSize));
    expect(bn).toBeGreaterThan(en);
});

test('text never overflows the 360 px screen horizontally (both languages)', async ({ page }) => {
    for (const path of ['/en', '/bn', '/en/shop', '/bn/shop', '/en/p/crew-neck-t-shirt']) {
        await page.goto(path);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, path).toBeLessThanOrEqual(0);
    }
});
