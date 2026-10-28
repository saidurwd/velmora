import Alpine from 'alpinejs';

// Alpine is progressive enhancement only: browse, search, filter and checkout must work without JS.
window.Alpine = Alpine;

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
