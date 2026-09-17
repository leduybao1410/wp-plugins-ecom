---
name: epic-content-writer
description: 'Writes bilingual (Vietnamese + English) SEO blog posts for the EPIC Roastery website (admin.epicroastery.coffee / epicroastery.coffee) across EPIC''s six service pillars — retail coffee, on-demand B2B roasting, OEM packaging, turnkey café setup, barista training, and café equipment consulting/sales/repair. Use this skill whenever the user asks to write a blog post, article, or content for the EPIC website on any of these topics, asks to "viết bài", "viết content", "viết blog" for EPIC, or asks to post/draft an article to WordPress for EPIC. Every article follows a fixed structure: cited general market context (never fabricated numbers — always a real, named, dated source) plus a closing "Vì sao chọn EPIC" section, and can be pushed straight to WordPress as a Draft via the REST API using the bundled script. Also use this skill to run or explain EPIC''s daily content rotation / posting calendar.'
---

# EPIC Content Writer

Writes and (optionally) publishes blog content for EPIC Roastery — a coffee roaster in Sài Gòn that both sells coffee directly and runs a full B2B services business (roasting, OEM, café setup, training, equipment). Read `references/brand-facts.md` before writing anything — it is the only source of truth for claims about EPIC. Never invent facts about EPIC (certifications, client counts, years of experience beyond what's documented, etc.) that aren't in that file.

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

`references/topics.md` has a bank of ~10 concrete, non-repeating subtopic angles per pillar (60 total) so daily posting doesn't run dry or repeat itself for two months. When asked to write "the next" post, or to run the daily rotation, follow **Picking the next topic** below instead of guessing.

## Picking the next topic (rotation logic)

The site round-robins pillars day by day (day 1 = pillar 1, day 2 = pillar 2, ... day 7 = pillar 1's second subtopic, etc.) rather than exhausting one pillar before moving to the next — this keeps the `/news` feed varied. To find the next slot:

1. `GET {WC_URL}/wp-json/wp/v2/posts?categories=84,85,86,87,88,89&per_page=100&_fields=id,categories,slug` (paginate if `X-WP-TotalPages` > 1) to get every post already published or drafted in these six categories.
2. Count only **master (Vietnamese) posts** — i.e. exclude any slug ending in `-en` — call this `N` (posts already produced).
3. `pillar_index = N % 6` (0-indexed against the table above, in the order listed) and `subtopic_index = (N // 6) % 10` (0-indexed into that pillar's list in `references/topics.md`).
4. That gives one unambiguous next topic. If a post with a matching slug/topic tag already exists (e.g. a manual post used up that slot), just advance to the next `N` and recompute — don't overwrite.

This mirrors the same "count what's live, pick the next slot" approach the existing bi-weekly origin-spotlight automation uses — read `references/wp-publishing.md` for why (no separate database or state file needed; WordPress itself is the source of truth for what's been posted).

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
salesy in the body — the sales moment is reserved for the closing section.]

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

For the English sibling: same slug + `-en` suffix, same category, same tags. This exactly matches the convention `references/wp-publishing.md` and the existing origin-spotlight pipeline already use, so both content streams coexist on `/news` without conflicting.

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

Read `references/images.md` for the per-pillar image pool (already uploaded to the WP Media Library from EPIC's own photos — never substitute a stock photo or an AI-generated image). Pick one using `image_index = subtopic_index % pool_size` for that pillar, embed it inline near the top of the post (right after the opening hook) with an `<img>` tag, and also pass it as `--image-id <id>` to `scripts/publish_post.sh` (or `featured_media=<id>` if posting directly) so it becomes the post's featured image too. Write the `alt` text for *this* article's context, not just the generic caption in the table.

## Publishing to WordPress

Read `references/wp-publishing.md` before the first time you publish anything — it covers authentication, the exact request shape for creating the VI+EN pair (including `featured_media`), and an important environment note (don't use Python's `requests`/`urllib` for the HTTPS call from this cloud sandbox — it gets rejected by the host's WAF; use `curl`, or the bundled `scripts/publish_post.sh`, which takes `--image-id <id>` from the pool in `references/images.md`). Default to creating posts as **`status=draft`** unless the user has explicitly asked for auto-publish in this conversation — drafts let the user review in wp-admin before anything goes live.

## Content calendar

`references/topics.md` also doubles as the day-by-day calendar — it's ordered, so "day N" is always derivable from the rotation logic above rather than a separate schedule file that can drift out of sync with what's actually on the site.
