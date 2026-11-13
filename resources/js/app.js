// The CSP build of Alpine: it reads the expressions in x-data / x-show / @click with its own safe interpreter instead of eval, so the page needs no 'unsafe-eval'.
// It refuses browser globals (window, document) inside expressions: do those things in a registered component below, or with a data attribute (behaviors.js).
import Alpine from '@alpinejs/csp';
import { consentBanner } from './analytics';
import './behaviors';

// Alpine is progressive enhancement only: browse, search, filter and checkout must work without JS.
window.Alpine = Alpine;

Alpine.data('consentBanner', consentBanner);

// Address form: show only the districts / upazilas that belong to the chosen parent. Without JavaScript every option is listed
// under its parent heading and the server checks the chain.
Alpine.data('geoSelect', (division, district) => ({
    division,
    district,
}));

// Search autocomplete (ARIA combobox). Enhancement only: the form submits normally without JavaScript.
Alpine.data('searchBox', (suggestUrl) => ({
    query: '',
    items: [],
    open: false,
    active: -1,
    announcement: '',
    controller: null,
    async fetchSuggestions() {
        const q = this.query.trim();
        if (q.length < 2) return this.close();
        this.controller?.abort();
        this.controller = new AbortController();
        try {
            const res = await fetch(`${suggestUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, signal: this.controller.signal });
            if (!res.ok) return this.close();
            this.items = (await res.json()).items ?? [];
            this.active = -1;
            this.open = this.items.length > 0;
            this.announcement = this.items.length ? `${this.items.length}` : '';
        } catch (e) {
            if (e.name !== 'AbortError') this.close();
        }
    },
    move(step) {
        if (!this.open) return;
        this.active = (this.active + step + this.items.length) % this.items.length;
    },
    choose(event) {
        if (this.open && this.active >= 0) {
            event.preventDefault();
            window.location.href = this.items[this.active].url;
        }
    },
    go(item) {
        window.location.href = item.url;
    },
    close() {
        this.open = false;
        this.active = -1;
    },
}));

// The staff menu on a phone: closed behind a "Menu" button (the page content comes first); on a large screen it is always shown (CSS). Without JavaScript it stays open.
Alpine.data('adminNav', () => ({
    open: false,
    toggle() {
        this.open = !this.open;
    },
}));

// A dialog opened with $dispatch('open-modal', 'its-id'): Esc and the backdrop close it, focus moves into it.
Alpine.data('modal', (id) => ({
    open: false,
    show(event) {
        if (event.detail !== id) return;
        this.open = true;
        this.$nextTick(() => this.$refs.panel.focus());
    },
    hide() {
        this.open = false;
    },
}));

// Recently viewed products: remembered on this device only (localStorage), shown on product pages. No server round trip.
Alpine.data('recentlyViewed', () => ({
    items: [],
    init() {
        // this product, handed over as JSON in a data attribute (the CSP build cannot read JSON.parse(...) inside an expression)
        const current = JSON.parse(this.$el.dataset.current);
        const KEY = 'velmora.recentlyViewed';
        let all = [];
        try {
            all = JSON.parse(localStorage.getItem(KEY) || '[]');
            if (!Array.isArray(all)) all = [];
        } catch (e) {
            all = [];
        }
        this.items = all.filter((i) => i && i.id !== current.id).slice(0, 4);
        try {
            localStorage.setItem(KEY, JSON.stringify([current, ...all.filter((i) => i && i.id !== current.id)].slice(0, 12)));
        } catch (e) {
            /* storage unavailable (private mode): the strip simply stays empty */
        }
    },
}));
Alpine.start();
