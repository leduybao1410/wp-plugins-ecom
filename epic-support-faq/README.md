# EPIC Support FAQ

Administrator-managed FAQ records for the EPIC website and the support chat
assistant. WordPress is the system of record; the website keeps its shipped
locale dictionaries only as an offline fallback.

## Runtime contract

- **`epic-admin/v1/faqs*`** — session-gated CRUD used by the operations
  dashboard (`ops.epicroastery.coffee` and `/ops`). Every route delegates to
  `Epic_Admin_Dashboard::require_admin_session()` and therefore requires an
  unrevoked administrator session plus `manage_options`.
- **`epic-support/v1/faq-context?lang=`** — server-to-server bundle read for the
  website. Gated by the shared `X-Epic-Secret` already used by
  `epic-support-chat`, supports `ETag`/`If-None-Match`, and returns
  `Cache-Control: private, max-age=60`.

## Data model

- `{prefix}epic_support_faqs` — one row per FAQ: group, position, status
  (`draft|published`), `site_visible`, `bot_visible`, revision, audit fields.
- `{prefix}epic_support_faq_translations` — one row per locale
  (`vi,en,ru,hi,zh,ko,ja`).
- `{prefix}epic_support_faq_bundles` — the compiled per-locale payload, its
  content-hash version (also the ETag), byte size and truncation flag.

## Compile-on-save

Every mutation that changes published content (create/update when published,
publish, unpublish, delete, reorder, import) recompiles **all seven locale
bundles**. The bundle is capped by
`epic_support_faq_context_max_bytes` (default 20,000) and
`epic_support_faq_context_max_items` (default 60); overflow drops the lowest
priority items and sets `truncated` so the dashboard can warn. A compile
failure keeps the previous bundle.

Publishing requires **all seven translations**. Drafts may be saved with a
partial set; a published FAQ cannot lose a translation until it is unpublished
again, so every per-locale bundle stays complete.

## Seed import

`data/seed-faqs.json` is generated from the website's locale dictionaries by
`Product/website/scripts/export-faq-seed.mjs`. Activation imports it when the
table is empty; the dashboard can also run the import explicitly (idempotent on
`group` + item index via `seed_key`). Only records complete in all seven
locales are auto-published; the rest stay drafts. The `contact` group currently
ships in Vietnamese and English only, so it stays a draft and the website keeps
its static fallback for that page until the missing translations are added.

## Website switch

`Product/website` reads the bundle when `SUPPORT_FAQ_SOURCE=wordpress`, and by default in production once the WordPress credentials exist; `SUPPORT_FAQ_SOURCE=static` forces the shipped dictionaries.
Any fetch failure falls back to the shipped locale dictionaries, so a
WordPress outage never blanks a page or the chat. Pages use a 300 s ISR
revalidation; the chat route revalidates on the first message of a session,
orders FAQs by relevance and caps the request payload at ~9,000 bytes so the
model prompt stays under its 20,000-byte guard.

## Verification

- `admin-dashboard/tests/support-faq-plugin.test.mjs` parses the PHP files and
  checks the schema, publish gate, route/file boundaries, secret gate and seed
  shape.
- `admin-dashboard/tests/faq-workflows.test.mjs` covers the dashboard draft,
  completeness and bundle-summary helpers.
- `Product/website/src/lib/__tests__/support-faq-context.test.ts` covers bundle
  validation, per-group fallback, `site`/`bot` projection and chat ordering.
- Production rollout still needs: activate the plugin, import the seed,
  translate the contact group, set `SUPPORT_FAQ_SOURCE=wordpress`, and smoke
  test one edited FAQ through both the page section and the chat widget.
