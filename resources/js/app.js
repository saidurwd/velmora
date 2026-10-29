import Alpine from 'alpinejs';
import { consentBanner } from './analytics';

// Alpine is progressive enhancement only: browse, search, filter and checkout must work without JS.
window.Alpine = Alpine;

Alpine.data('consentBanner', consentBanner);

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
    close() {
        this.open = false;
        this.active = -1;
    },
}));

// Recently viewed products: remembered on this device only (localStorage), shown on product pages. No server round trip.
Alpine.data('recentlyViewed', (current) => ({
    items: [],
    init() {
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
