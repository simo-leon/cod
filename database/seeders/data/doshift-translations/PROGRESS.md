# Doslift translation — progress

Source: `~/workspace/doshift-products/batches/bulk-*.json` (3,509 products, FR-only).
Skill rules: `~/workspace/custom-skills/jemla_product_rewrite/SKILL.md`.
No invented specs/numbers/claims; price, categories, images untouched; products stay inactive.

## Done (2026-11-26)

| Chunk | Source batches | Products | File |
|---|---|---|---|
| chunk-001 | bulk-001 → bulk-005 | 50 | `chunk-001.json` |

- Validation: 50 unique SKUs, order matches source, every product has
  ar/en/fr × 6 fields (name, short_description, description, meta_title,
  meta_description, meta_keywords), no squidlux / Fou Prix / sales / rating /
  medical / free-return claims. Verified programmatically.
- Command draft: `ApplyTranslationsCommand.php` — `jemla:apply-translations`
  (ready to install in `app/Console/Commands/`; NOT installed — repo is
  diverged from origin/main and no push/repo change was authorised).
  Pint PASS, php -l PASS.
- Local test: 10/10 (first 10 of chunk-001, `--limit=10`).
  DB verification 180/180 field checks (10 products × 3 locales × 6 fields)
  in `product_flat`; status stayed 0, price and url_key untouched.
  Re-run 10/10 (idempotent); bogus SKU → "not found" + failures file;
  `--dry-run` works. Test products deleted afterwards: DB back to 50
  products, 0 doshift, 0 orphan flat/attribute/image/inventory rows.
  Repo `git status` unchanged by this work.

## Remaining

- 3,509 − 50 = **3,459 products** in bulk-006 → bulk-351 (346 batch files).
- At 50/chunk: **70 more chunks** (chunk-002 → chunk-071; last one 9 products).
- Time estimate: chunk-001 took ~3 min wall clock with 5 translators in
  parallel. Same pace in waves of 5 chunks: ~14 waves ≈ **45–60 min** of
  translation work, plus assembly/verification. Apply runtime measured
  ~0.2 s/product → full apply ≈ **12 min** (excluding live mapping work).

## Quality flags (from translators — check before activation)

- part-002: doshift-125750 short description was truncated in source
  ("avec cet .") — fixed without inventing detail. doshift-126365 height
  range is very wide (12.5–28 cm by model) — verify against the real
  product. doshift-125467 XXL mouse pad has no size in source — kept
  generic. doshift-124784 source contradicts itself on Real Madrid
  licensing — no "officially licensed" claim made. doshift-126298 air
  fryer has no wattage in source — none added.
- part-003: doshift-123941 / 123924 / 123917 are identical except colour
  (pink / purple / beige) — names disambiguated by colour. doshift-124187
  colour is "white and black (two versions)" — kept as-is.
- part-004: doshift-123847 source claims "Jeu de la décennie à Cannes" —
  NOT repeated, kept generic ("primé"). doshift-122782 source description
  is cut mid-sentence — completed from the spec table only. doshift-123909
  vs 123701 share the same source name — disambiguated by colour.
- part-005: "Colisage grossiste B2B : 12 pièces / carton" removed from the
  two shower shelves (wholesaler info, not for retail customers).
  doshift-121836 charger worded as MagSafe 1-compatible, NOT as an Apple
  original.
- part-001: doshift-126717 (transparent tank) source itself is vague on
  compatibility — kept generic + "check dimensions/thread before buying"
  note from the source. doshift-127845 brand "Atmosphera" appears only in
  the box contents — brand NOT claimed in the translation.

## LIVE blocker — do not apply live by SKU

- Live SKUs were randomised by fix commands (e.g. product 444 is now
  `ZlEwW6c6Oq`). A plain SKU lookup finds NOTHING live.
- The command supports `--map=<mapping.json>` (`{"doshift-<id>": <jemla_id>}`)
  to find products by Jemla id. Mapping files are on the HOST under
  `storage/logs/jemla-*-sku-mapping-*.json` — they must be collected there
  before any live apply. Documented in the command header; NOT solved here.
- Only ~2,642 of the 3,509 bundled products were created live; ~867
  failed (mostly undiagnosed). The apply run will report those as
  "not found" — they need the failure diagnosis first.
- The 54 products with no mappable category were never imported and are
  not in the bulk files — no translation needed for them unless they are
  categorised and imported later.

## Local-vs-live note (found during testing)

- Local default locale is `ar`, live it is `fr`. `createBulkItem` requires
  the default-locale name, so FR-only batches FAIL locally unless an `ar`
  placeholder name is added (test used ar name = fr name; the translation
  apply overwrote it). Live the import worked, so this is a local-only
  quirk. `updateProduct` (the apply path) has no such requirement.

## Layout

- `chunk-001.json` … assembled chunks (50 products each).
- `parts/part-001.json` … per-batch translator output (10 products each).
- `ApplyTranslationsCommand.php` — command draft, install to
  `app/Console/Commands/` when the repo divergence is resolved; bundle
  chunks under `database/seeders/data/doshift-translations/`.
