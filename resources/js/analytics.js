// Privacy-respecting analytics (first-party only). Nothing is sent until the visitor accepts; "Do Not Track" and
// "Global Privacy Control" count as a refusal and the banner is not even shown. No third-party scripts, no identifiers:
// each event is just a name, the language, the page address (no query string) and, for products, the product id.
const COOKIE = 'velmora_analytics';

export function consent() {
    if (navigator.globalPrivacyControl === true || navigator.doNotTrack === '1') return 'denied';
    const m = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=(granted|denied)'));
    return m ? m[1] : null;
}

export function setConsent(value) {
    document.cookie = `${COOKIE}=${value}; Max-Age=31536000; Path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
}

export function track(name, extra = {}) {
    if (consent() !== 'granted') return;
    const lang = document.documentElement.lang || 'en';
    const payload = JSON.stringify({ name, path: location.pathname, ...extra });
    const url = `/${lang}/analytics/events`;
    try {
        // keepalive lets the request finish even if the visitor leaves the page straight away
        fetch(url, { method: 'POST', body: payload, headers: { 'Content-Type': 'application/json' }, keepalive: true, credentials: 'same-origin' }).catch(() => {});
    } catch (e) {
        /* analytics must never break the page */
    }
}

/** Sends the events this page declares in <meta name="velmora:…"> tags. */
export function trackPage() {
    track('page_view');
    const item = document.querySelector('meta[name="velmora:item"]')?.content;
    if (item) track('view_item', { product: item });
    const results = document.querySelector('meta[name="velmora:search"]')?.content;
    if (results !== undefined && results !== null && results !== '') track('search', { results: Number(results) });
}

export function consentBanner() {
    return {
        open: false,
        init() {
            this.open = consent() === null;
            if (consent() === 'granted') trackPage();
        },
        accept() {
            setConsent('granted');
            this.open = false;
            trackPage();
        },
        decline() {
            setConsent('denied');
            this.open = false;
        },
        reopen() {
            this.open = true;
            this.$nextTick(() => this.$refs.banner?.focus());
        },
    };
}
