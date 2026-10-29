# Bangladesh administrative geography — source record

| | |
|---|---|
| Files | `divisions.csv` (8), `districts.csv` (64), `upazilas.csv` (494) — no header row |
| Source | https://github.com/nuhil/bangladesh-geocode (`divisions/divisions.csv`, `districts/districts.csv`, `upazilas/upazilas.csv`) |
| Licence | MIT, © 2014 Nuhil Mehdy (repository `LICENSE`) |
| Upstream last updated | 2023-03-17 (GitHub `pushed_at`) |
| Downloaded | 2026-10-29 |
| Columns | divisions: `id,name_en,name_bn,website` · districts: `id,division_id,name_en,name_bn,latitude,longitude,website` · upazilas: `id,district_id,name_en,name_bn,website` |
| Row ids | the dataset's own sequential ids, kept as primary keys so re-seeding is an idempotent upsert |

**Not an official BBS file.** The upstream compiles the official Local Government Division / BBS lists. Official counts today are 8 divisions, 64 districts and 495 upazilas/thanas; this file has 494 upazilas, so at least one entry is missing or merged. City-corporation thanas inside Dhaka are not modelled (the business decision in `BANGLADESH_LOCALIZATION_AND_COMMERCE.md` is to type the Dhaka area freely). Before launch, have the owner or an operations lead check the list against the couriers' coverage lists and replace this dataset with an official file if one is preferred.
