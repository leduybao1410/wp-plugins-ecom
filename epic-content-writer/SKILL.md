---
name: epic-content-writer
description: 'Write, revise and publish multilingual EPIC Roastery website articles in Vietnamese, English, Russian, Hindi, Simplified Chinese, Korean, and Japanese across six coffee and café service pillars. Use for EPIC blog/content requests, WordPress drafts or updates, and the content calendar. Choose new topics from Search Console and cap them at two topic sets per rolling seven days.'
---

# EPIC Content Writer

Writes and (optionally) publishes blog content for EPIC Roastery — a coffee roaster in Sài Gòn that sells coffee directly and provides B2B services. Read `references/brand-facts.md` before writing. It contains stable, approved brand facts; use the integrated `epic_site` MCP for current product, price, stock and service details. Never invent facts about EPIC (certifications, client counts, years of experience beyond what's documented, etc.).

## Reader value and EPIC relevance (required)

Before drafting, state in the working notes (not in the public article): the target reader, the decision or problem they have, and the practical answer the post will give. Build the article around useful steps, selection criteria, a comparison, a worked example or a checklist that directly answers that need. Remove generic filler and unsupported advice. Search the journal with MCP `search_news` before drafting; use `get_news` on close matches to avoid repeating an existing article and to link to a genuinely related next step.

Integrate EPIC only where it helps the reader act:

- For retail topics, include one or two relevant coffees from the live catalog when a specific item genuinely illustrates the advice. State product, pack size, public price, fit for the reader's taste or brew method, and link to its product page.
- For B2B topics, explain the relevant service and what information the reader should prepare to get a useful quote. Wholesale/B2B prices are quote-only; never infer or publish a per-kg quote.
- A product or service mention is optional when it would not help answer the topic. Every topic must still finish with a relevant, working EPIC path (product catalog, wholesale inquiry, or visit page).
- Keep the educational answer primary. Mention a product or service after explaining how the reader can choose; do not use it as a substitute for the answer.

## Live facts through the integrated MCP

Use the project `epic_site` MCP tools; do not copy prices from old drafts, local catalog snapshots, or sales documents.

1. Use `search_site` for broad questions about coffees, services, FAQs or store facts. Use `fetch_content` with the returned stable ID when you need the full source. Use `search_news`/`get_news` to inspect related existing articles.
2. For a possible product, use `search_coffee` to find candidates, then `get_coffee` with the chosen product slug. Confirm exact public product URL, pack size, VND price and stock status from the detail result.
3. Use `get_service_info` for current service scope and `get_store_info` for current locations, hours, wholesale minimums and policies. Wholesale pricing is quote-only.
4. Record the MCP source ID or product slug, source URL, exact pack/price or service fact, and retrieval date in the task's editorial evidence log. Put a short price-check date beside each snapshot price in the article, and link the price to the live product page so readers can verify availability.
5. Re-fetch each product immediately before publishing/updating the article. If MCP is unavailable, returns an error, or the item is out of stock/unpriced, omit its price and mark the post as awaiting verification; do not reuse an old value. Service details may use `brand-facts.md` only for stable approved facts and must still be checked against MCP before stating current availability or terms.

Prices in article HTML are snapshots from the retrieval date. They do not update dynamically after publication. Do not imply otherwise. Never expose internal/wholesale tier prices.

## The six pillars

Every post belongs to exactly one pillar. Each pillar maps to one WordPress category on `admin.epicroastery.coffee`:

| Pillar (Vietnamese) | Category slug | Category ID |
|---|---|---|
| Bán Lẻ Cà Phê | `ban-le-ca-phe` | 84 |
| Rang Cà Phê B2B Theo Yêu Cầu | `rang-ca-phe-b2b` | 85 |
| Đóng Gói OEM | `dong-goi-oem` | 86 |
| Setup Quán Cà Phê Trọn Gói | `setup-quan-ca-phe` | 87 |
| Đào Tạo Pha Chế | `dao-tao-pha-che` | 88 |
| Tư Vấn & Sửa Chữa Máy Móc | `may-moc-sua-chua` | 89 |

(These sit alongside the site's existing 5 `/news` categories — `ca-phe-vung-trong`(70) etc. — from the separate origin-spotlight automation. Don't touch those; this skill only ever posts into 84–89.)

`references/topics.md` is an idea bank of ~10 subtopic angles per pillar (60 total), not a daily publishing calendar. When asked to choose the next topic, use **Search Console-led topic selection** below. If the user names a topic directly, work on that request without treating it as an automatically selected slot.

## Search Console-led topic selection

Choose a new topic only after reviewing Search Console performance and existing article coverage. New topics should address an unresolved search need, not fill a daily rotation.

1. Review Search Console for the recent 28-day Web search period. Capture query, landing page, device, clicks, impressions, CTR and average position for relevant opportunities. Compare with the preceding 28 days when enough data is available. Prioritize pages with impressions and a clear improvement opportunity, especially mobile CTR and important business pages.
2. Inventory existing WordPress posts in categories 84–89, across all statuses, and search for the same query intent. Verify pagination through `X-WP-TotalPages`; do not mistake the first 100 posts for the full archive.
3. Choose **update an existing article/page** when it already serves the query intent or has impressions worth building on. Choose a new article only for a distinct, uncovered search need. Use `references/topics.md` as candidate ideas, not as a rotation that must be completed.
4. Keep the three business priorities balanced: the Quận 3 café/visit experience, retail coffee, and B2B services. Pick the best-supported opportunity while avoiding consecutive concentration in one pillar where comparable opportunities exist.
5. Before starting a new topic set, count unique Vietnamese master topics in categories 84–89 created during the previous rolling seven days. Exclude locale siblings (`-en`, `-ru`, `-hi`, `-zh`, `-ko`, `-ja`). The automated weekly schedule may create at most **one** new topic set; all workflows share the overall cap of two per rolling seven days. At either cap, do not create another; recommend an update to an existing page instead. One topic set still consists of the Vietnamese master plus all six locale siblings.
6. If Search Console cannot be accessed and no recent export is supplied, do not invent query evidence or silently fall back to the old rotation. Report the missing data and either work on a user-nominated topic or recommend updating an existing page from the latest known evidence. Record the period and limitation.

For every chosen topic, keep an editorial evidence note with: target reader and question; query and Search Console period; landing page and device; intended destination URL; related existing posts and their index/performance status; EPIC evidence sources; market source; internal links; CTA; locale, image, price and stock checks. Do not put private or wholesale pricing in the note. This note lets the next 28- and 56-day review judge whether the page was crawled/indexed and which queries earned clicks.

## Article structure

Write the Vietnamese version first (it's the "master" post), then an English sibling. **Always use this exact structure** for the Vietnamese post (the English one is a natural adaptation, not a literal translation — same structure, written like an English-speaking copywriter would write it, not machine-translated):

```
# [SEO title — include the primary keyword naturally, front-loaded]

[Opening hook, 2-4 sentences — name the reader's real situation: a home
brewer, a café owner, someone about to open a shop, etc., depending on
the pillar. Get to a concrete promise of what they'll learn fast.]

[One real EPIC photo, embedded here — see "Images" below. Every post
needs at least one; never a stock or AI-generated photo.]

## [First H2 — a practical subheading tied to the day's subtopic]
[Body content. Concrete, actionable, specific to EPIC's own process where
relevant. Vietnamese business-blog register: clear, warm, not overly
salesy. Answer the reader's question before the sales moment. When a
current product price genuinely helps, state product + pack size + VND
snapshot price + check date + product link.]

## [Second H2, if useful]
[...]

## Góc nhìn thị trường
[2-4 sentences of GENERAL market/industry context — not about EPIC —
with an explicit, real, dated citation. See "Sourcing rule" below. This
is never optional and never vague ("nhiều chuyên gia cho rằng..." with no
name is not acceptable).]

## Vì sao chọn EPIC
[3-5 short bullet points, drawn ONLY from the facts in references/brand-facts.md
but written for THIS specific article, not copy-pasted from the pillar's
generic angle list — see "Personalizing Vì sao chọn EPIC" below. End with
one CTA sentence + link to the most relevant page for this pillar (see
the CTA table in brand-facts.md).]
```

Also produce:
- **Meta description** (Vietnamese, ~150–160 characters, one sentence, includes the keyword).
- **Slug**: short, kebab-case, Vietnamese without diacritics (e.g. `chon-may-pha-espresso-cho-quan-nho`).
- **Tags**: 2-4 WordPress tags — reuse existing tags where they fit (check `GET /wp-json/wp/v2/tags`) rather than always minting new ones.

For editorial review, also retain a source note for the article: target reader/problem, MCP source IDs or slugs and URLs, retrieval date, product price/pack snapshots (if used), and external market citations. Do not put private or wholesale pricing in this note. For an existing-post backfill, persist a dated JSON report under `reports/` with each post ID/slug, language, MCP source URL/date, price snapshots or an explicit pending/not-applicable status, preserved metadata/status result, and restorable backup path.

## Languages — one VI master + 6 locale siblings (required)

Every topic is published as **7 posts**: the Vietnamese master (`{slug}`) plus **6 locale siblings**, one per language the site serves:

| Locale | Slug suffix | Notes |
|---|---|---|
| English | `-en` | natural adaptation, written like an English copywriter — not a literal translation |
| Russian | `-ru` | |
| Hindi | `-hi` | |
| Chinese (Simplified) | `-zh` | |
| Korean | `-ko` | |
| Japanese | `-ja` | |

These six suffixes are exactly what `src/lib/news.ts` pairs to the base topic, and what `references/wp-publishing.md` documents. **Do not skip any locale and do not invent a different suffix scheme** — a topic missing a suffix simply won't appear in that language on the frontend.

For the 6 locale siblings:
- Same base slug + the suffix above, same category, same tags, same `featured_media` (or another photo from the same pillar pool for visual variety).
- Localize the whole body, not just the title: translate headings and prose faithfully (keep the coffee-industry/café-business register), keep proper nouns, brand names, place names and coffee varieties, and **preserve the HTML structure exactly** (h2/h3, ul/ol/li, strong/em, img, `<a>`).
- Localize internal links: rewrite `/{vi|en}/...` links to that locale's prefix (e.g. `/ja/wholesale`), while keeping `/vi/...` links in the Vietnamese master.
- Write a localized **title** and a localized **meta description** (~150–160 characters) per locale.
- The `Góc nhìn thị trường` / market section and the `Vì sao chọn EPIC` section translate too — but any quoted source name stays in its original form (e.g. `iPOS.vn`, `SCA`), and the citation year stays the same.

So a single topic set = 7 drafts/live posts: `{slug}`, `{slug}-en`, `{slug}-ru`, `{slug}-hi`, `{slug}-zh`, `{slug}-ko`, `{slug}-ja`.

## Internal linking — turn the articles into a journey (required)

Every topic is part of a topic-by-topic journey, and each post must link into it so a reader can keep exploring new terms, related topics and related ideas. Two layers, both required:

1. **In-content contextual links.** In the body prose, wrap 1–3 key terms on their first mention with a link to the related topic's post — e.g. "cupping", "sơ chế/washed/natural", "MOQ", "setup quán", "đào tạo pha chế/barista", "máy pha espresso". Don't link inside headings (keep the TOC text clean), inside an existing `<a>`, or on the post's own topic. Give the anchor `class="journey-link"` so runs stay idempotent.
2. **A closing "Continue the journey" block.** End the body with a localized headed teaser that names the next topic in the chain and links to it, e.g. for VI:
   ```html
   <h2>Đọc tiếp trong hành trình</h2>
   <p>{một câu mô tả chủ đề kế tiếp}</p>
   <p><a href="/vi/news/{next-slug}">{tiêu đề bài kế tiếp} →</a></p>
   ```

**Link format is critical:** internal article URLs are `/{locale}/news/{base-slug}` — the slug is **locale-invariant** (the dictionary/sibling logic in `src/lib/news.ts` resolves the right locale from the prefix), so an English post links to `/en/news/{base-slug}`, **never** `/en/news/{base-slug}-en`. The default chain runs retail → B2B roasting → OEM → café setup → training → equipment, then loops back to retail, but any correct related topic is fine as long as every post links onward.

**Quote style still applies:** use double-quoted attributes on every `<img>` (see `references/images.md`) — a single-quoted `src` is invisible to the frontend thumbnail.

## Sourcing rule — no fabricated statistics, ever

The "Góc nhìn thị trường" section exists to give the reader real signal, not filler. Before writing it:

1. Use web search to find one genuinely relevant, reasonably recent source for whatever claim fits the subtopic — Vietnamese industry bodies (VICOFA/Hiệp hội Cà phê – Ca cao Việt Nam, Tổng cục Thống kê/GSO), international ones (ICO – International Coffee Organization, USDA FAS coffee reports), or credible trade/market-research coverage (Euromonitor, Mordor Intelligence, Vietnam-focused F&B trade press, etc.) all work.
2. Cite it plainly in the text: name the source and year, and state the claim close to how the source frames it — e.g. "Theo báo cáo của Hiệp hội Cà phê – Ca cao Việt Nam (VICOFA), ..." — don't just say "theo một nghiên cứu" with nothing to check.
3. If you can't find a solid source for the specific number you wanted, write the paragraph around a trend you *can* source, or state it qualitatively without a number — never invent a statistic to fill the space. A true, softer claim beats a precise, fake one.
4. Keep this section about the *industry/market*, not about EPIC — EPIC's own pitch belongs only in "Vì sao chọn EPIC".

## Personalizing "Vì sao chọn EPIC"

This section must read like it was written for *this* article, not pasted in from a template. The facts available (`references/brand-facts.md`) are the same for every post in a pillar, but which of them you pick, and how you phrase them, should connect back to whatever the article actually just told the reader — pick 3-5 facts and frame each one against a specific point made earlier in the post, rather than listing the pillar's generic angle set unchanged.

For example, for an article about choosing an espresso machine by café size, don't write the generic "EPIC sells and services the gear itself" bullet verbatim — write something like "Máy được tư vấn theo đúng quy mô như trên, và nếu công suất tăng lên sau này, EPIC vẫn hỗ trợ kỹ thuật/bảo trì trực tiếp chứ không chỉ bán rồi thôi" — it references the sizing advice just given. For an OEM article about MOQ, connect the "one roastery handles both roast and packaging" fact specifically to the MOQ question just discussed, rather than a generic packaging-quality bullet. Vary which facts you lead with and how you phrase them from post to post — two posts in the same pillar should not have near-identical closing sections even though they draw from the same fact file.

## Images — every post needs at least one real photo

Read `references/images.md` for the per-pillar image pool (already uploaded to the WP Media Library from EPIC's own photos — never substitute a stock photo or an AI-generated image). Choose an image from the matching pillar pool that fits the article and has not been overused recently; embed it inline near the top of the post (right after the opening hook) with an `<img>` tag, and also pass it as `--image-id <id>` to `scripts/publish_post.sh` (or `featured_media=<id>` if posting directly) so it becomes the post's featured image too. Write the `alt` text for *this* article's context, not just the generic caption in the table.

## Publishing to WordPress

Read `references/wp-publishing.md` before the first time you publish anything — it covers authentication, the exact request shape for creating the VI master + 6 locale siblings (including `featured_media`), and an important environment note (don't use Python's `requests`/`urllib` for the HTTPS call from this cloud sandbox — it gets rejected by the host's WAF; use `curl`, or the bundled scripts below).

Two ready-made wrappers, both idempotent by slug (they recover an existing post instead of creating a duplicate):

- `scripts/publish_post.sh` — creates the VI master + `-en` sibling as a pair, taking `--image-id <id>` from the pool in `references/images.md`.
- `scripts/publish_translation.sh` — creates ONE locale sibling (`--source-slug <base> --locale <ru|hi|zh|ko|ja> --content-file <body.html> --title ... --excerpt ... --category-id ... --image-id ... --tags ...`). Run it once per locale to add the remaining 5 siblings.

Default to creating all 7 posts as **`status=draft`** unless the user has explicitly asked for auto-publish in this conversation — drafts let the user review in wp-admin before anything goes live. Do not consider a topic "done" until all 7 slugs exist (`{slug}` and `{slug}-en/-ru/-hi/-zh/-ko/-ja`).

## Codex weekly schedule (authorized automatic publication)

The user authorized this specific recurring schedule: Tuesday 09:00 Asia/Ho_Chi_Minh, select one evidence-backed topic and create all seven posts as drafts; Thursday 09:00, publish that week's complete set automatically; Thursday 09:15, verify the public pages and sitemap. This authorization applies only to the scheduled set that passes the gates below. All other article requests continue to default to drafts unless separately authorized.

Tuesday run:

1. First inspect successful scheduled manifests whose `published_at` date is 28 or 56 days old. For each due set, record Search Console crawl/index status and the queries, pages, device, clicks, impressions and CTR for its seven URLs; update that manifest with a dated review result.
2. Read Search Console for the latest complete 28-day Web period, then inventory WordPress posts in categories 84–89 across all statuses and check the rolling topic caps.
3. Prefer updating an existing page when its query intent is already covered. Otherwise choose at most one distinct, uncovered topic for the week. If Search Console, WordPress inventory, or any required source is unavailable, stop without creating drafts and report the blocker.
4. Create the VI master plus all six locale siblings as drafts using the existing publishing scripts. In unattended runs, invoke shell wrappers through `python3 scripts/with_wp_env.py -- ...` so only required WordPress credentials are loaded from `website/.env`; never print or source the full environment file. Write `reports/scheduled/YYYY-MM-DD-{base-slug}.json` with `created_at` (ISO 8601 including timezone), `base_slug`, seven `posts` entries (`id`, `slug`, `locale`, `status`), Search Console evidence and completed quality checks. Thursday must select this exact manifest and no other draft set.

Thursday run:

1. Publish only the seven draft IDs in the matching Tuesday report, no older than three days. Re-fetch live EPIC facts and product availability, and verify the complete set: exact locale slugs, nonempty localized content, approved category, real inline and featured image, working locale links, sourced market claim, and unique slugs.
2. If any editorial gate fails, do not publish any post. Run `python3 scripts/publish_topic_set.py --manifest <Tuesday-manifest>` first as a dry run, then add `--apply` only after the full editorial review passes. The publisher independently verifies the seven locale slugs, WordPress IDs, category, draft statuses, body and image presence; it rolls back a partially published set to drafts and checks public HTTP 200, self-canonical and sitemap inclusion. Never substitute another pending set.
3. On full success, verify all seven public `/{locale}/news/{base-slug}` URLs return 200 with self-canonical URLs and appear in the sitemap. Check again after the frontend's 60-second WordPress data cache can refresh. Record statuses, URLs, canonical checks, sitemap checks, and failures in the report.

If a previously active scheduler is found elsewhere, pause or align it before enabling this schedule. Never send more than one new topic set live in a rolling seven-day period.

## Revising existing posts

When asked to improve existing EPIC content, inventory posts in categories 84–89, including all available statuses, with `scripts/update_post_content.py --inventory`. Group siblings by base slug. Review the Vietnamese master first for reader usefulness, unsupported claims, outdated EPIC facts, stale product mentions/prices, working links and a relevant service/product path; then carry only the necessary changes into each existing locale sibling while preserving its structure and language quality.

Update only the `content` field, preserving slug, title, excerpt, category, tags, featured image and status unless the user separately asks to change them. Preview every edit with the updater, pass the captured `modified_gmt`, and update no more than five topics per batch. The updater saves a restorable original before applying and verifies the result. Re-fetch MCP data just before each batch. For a product that's unavailable, unpriced, or not returned because the MCP is down, omit the price and hold that topic from publication/update until it can be verified. Wholesale/service prices are never inferred. See `references/wp-publishing.md` for the updater and recovery commands.

## Content calendar

There is no day-by-day topic rotation. The overall cap is two new topic sets in any rolling seven-day period; the Codex schedule creates at most one set per week on Tuesday and publishes that exact set Thursday at 09:00 Vietnam time after the required checks. If a separate scheduled job exists, align or pause it before enabling this schedule. An invocation over the cap skips new-topic creation and suggests an evidence-backed update instead.
