// k6 load scenario (Phase 12): a mix of storefront pages, search suggestions, catalog API reads and guest cart + checkout quote. Run against a seeded environment:
//   BASE=https://staging.example VUS=20 DURATION=2m k6 run tests/performance/storefront-api.k6.js
// Each virtual user presents a different address in X-Forwarded-For so the per-address limits are not what is measured: ONLY do this against an environment that trusts its proxy
// (TRUSTED_PROXIES), never against production. The thresholds are the targets in docs/architecture/PERFORMANCE_AND_SCALABILITY.md. Setup fails loudly (no silent pass) if the product is missing.
import http from 'k6/http';
import { check, group } from 'k6';
import { Trend } from 'k6/metrics';

const BASE = __ENV.BASE || 'http://127.0.0.1:8011';
const T = { page: new Trend('t_page', true), pdp: new Trend('t_pdp', true), suggest: new Trend('t_suggest', true), apiList: new Trend('t_api_list', true), apiProduct: new Trend('t_api_product', true), quote: new Trend('t_quote', true), cartAdd: new Trend('t_cart_add', true) };

export const options = {
    scenarios: { mixed: { executor: 'constant-vus', vus: Number(__ENV.VUS || 10), duration: __ENV.DURATION || '40s' } },
    thresholds: {
        t_pdp: ['p(95)<600'], t_page: ['p(95)<600'], t_suggest: ['p(95)<300'], t_api_list: ['p(95)<600'], t_api_product: ['p(95)<600'], t_quote: ['p(95)<800'], t_cart_add: ['p(95)<800'],
        http_req_failed: ['rate<0.01'],
    },
};

export function setup() {
    const first = http.get(`${BASE}/api/v1/products/cotton-panjabi`);
    if (first.status !== 200) throw new Error(`setup failed: product answered ${first.status}`);   // never a silent pass
    const detail = first.json('data');
    const div = http.get(`${BASE}/api/v1/geo/divisions`).json('data.0.id');
    const dist = http.get(`${BASE}/api/v1/geo/districts?division=${div}`).json('data.0.id');
    const upa = http.get(`${BASE}/api/v1/geo/upazilas?district=${dist}`).json('data.0.id');
    return { variant: detail.variant.id, div, dist, upa };
}

http.setResponseCallback(http.expectedStatuses({ min: 200, max: 399 }));
let shown = 0;
function note(r, what) { if (r.status >= 400 && shown < 12) { shown++; console.error(`BAD ${r.status} ${what} ${String(r.body).slice(0, 120).replace(/\s+/g, ' ')}`); } }

export default function (d) {
    const ip = `10.${(__VU >> 8) & 255}.${__VU & 255}.${(__ITER % 250) + 1}`;   // each user looks like a different visitor (the per-address limits are not what is measured)
    const h = { headers: { 'X-Forwarded-For': ip, 'Accept': 'application/json' } };
    const pick = __ITER % 10;

    if (pick < 4) {
        const urls = ['/en', '/en/shop', '/bn/shop', '/en/c/clothing'];
        const r = http.get(`${BASE}${urls[__ITER % urls.length]}`, { headers: { 'X-Forwarded-For': ip } });
        T.page.add(r.timings.duration); note(r, 'page'); check(r, { 'page 200': (x) => x.status === 200 });
    } else if (pick < 6) {
        const r = http.get(`${BASE}/en/p/cotton-panjabi`, { headers: { 'X-Forwarded-For': ip } });
        T.pdp.add(r.timings.duration); note(r, 'pdp'); check(r, { 'pdp 200': (x) => x.status === 200 });
    } else if (pick < 7) {
        const r = http.get(`${BASE}/en/search/suggest?q=cot`, { headers: { 'X-Forwarded-For': ip, Accept: 'application/json' } });
        T.suggest.add(r.timings.duration); note(r, 'suggest'); check(r, { 'suggest 200': (x) => x.status === 200 });
    } else if (pick < 8) {
        const a = http.get(`${BASE}/api/v1/products?per_page=24`, h);
        T.apiList.add(a.timings.duration); note(a, 'api list'); check(a, { 'api list 200': (x) => x.status === 200 });
        const b = http.get(`${BASE}/api/v1/products/cotton-panjabi`, h);
        T.apiProduct.add(b.timings.duration); note(b, 'api product'); check(b, { 'api product 200': (x) => x.status === 200 });
    } else {
        group('guest cart and quote', () => {
            const add = http.post(`${BASE}/api/v1/cart/items`, JSON.stringify({ variant: d.variant, quantity: 1 }), { headers: { ...h.headers, 'Content-Type': 'application/json' } });
            T.cartAdd.add(add.timings.duration); note(add, 'cart add'); check(add, { 'cart add ok': (x) => x.status === 201 || x.status === 200 });
            const token = add.json('data.token');
            const q = http.post(`${BASE}/api/v1/checkout/quote`, JSON.stringify({ address: { recipient: 'Load Test', phone: '01712345678', division_id: d.div, district_id: d.dist, upazila_id: d.upa, address_line: 'House 1' }, shipping_method: 'standard', payment_method: 'cod' }), { headers: { ...h.headers, 'Content-Type': 'application/json', 'X-Cart-Token': token } });
            T.quote.add(q.timings.duration); note(q, 'quote'); check(q, { 'quote 200': (x) => x.status === 200 });
        });
    }
}
