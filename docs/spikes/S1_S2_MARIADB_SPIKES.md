# Spikes S1 (booking overlap) and S2 (Bangla search) — MariaDB 12.1.2

Run 2026-10-28 on the local MariaDB 12.1.2 server in a throwaway schema (`velmora_spike`, dropped afterwards). `velmora` was not touched. Results are **[VERIFIED] by experiment on this version only**; not tested on MySQL or other MariaDB versions.

## S1 — Double-booking guarantee
Tested `PERIOD FOR slot(starts_at, ends_at)` + `UNIQUE (resource_id, slot WITHOUT OVERLAPS)` on InnoDB.
| Test | Result |
|---|---|
| Adjacent slot (10–11, 11–12) | accepted |
| Overlapping slot (10:30–11:30) | rejected, error 1062 |
| Same time, other resource | accepted |
| Status handling: virtual-persistent column `active_resource_id = IF(status IN ('held','confirmed'), resource_id, NULL)` used as the first key part | cancelled rows may duplicate/overlap; active ones may not |
| Cancelled booking then new overlapping hold | accepted (slot reopens) |
| 20 parallel inserts for the same slot/resource | **1 succeeded, 19 rejected** |
**Decision (ADR-019):** use this. Consequence: **MariaDB-only** (MySQL 8 has no equivalent) — another reason to fix MariaDB for production. Fallback if ever migrating: discrete slot grid with `UNIQUE(resource_id, slot_start)`.
Still to do in Phase 8: capacity>1 group sessions (not covered by this constraint), DST-free timezone tests, behaviour of `INSERT … ON DUPLICATE`.

## S2 — Bangla search with built-in FULLTEXT
Tiny fixture (7 products, Bangla + English). InnoDB FULLTEXT, default parser; `innodb_ft_min_token_size=3`, stopwords on.
| Query | Result |
|---|---|
| Whole token "মোবাইল" | found both products |
| Prefix "মোবা*" boolean | found both |
| Infix "ফোন" inside compound "হেডফোন" | **FULLTEXT missed it**; `LIKE '%ফোন%'` found it but cannot use an index |
| Bangla precomposed vs decomposed (ড় / ড+়) equality under the collation | treated equal |
| Case-insensitive English `LIKE` | works |
**Findings:** FULLTEXT handles whole-word and prefix Bangla search; it has no typo tolerance, no compound/infix matching, and relevance control is limited. Sample is tiny; real relevance quality is **not** proven.
**Decision (ADR-020), revising my earlier suggestion:** MVP uses MariaDB FULLTEXT + normalisation (NFC, digits, ZWJ/ZWNJ) + synonyms table + `LIKE` prefix on SKU/name, behind the `CatalogSearch` contract. No external engine in MVP. **Trigger to adopt Meilisearch/Typesense:** relevance fixtures fail targets or zero-result rate > agreed threshold on real data (Phase 4 / 14).
Also still to verify: `innodb_ft_min_token_size` effect on 1–2 letter Bangla words, tokenisation of Bangla combining marks on large data.
