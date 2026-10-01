# EPIC brand facts (source of truth)

Use this file for stable, approved facts about EPIC. For current offerings, product data, stock, pack sizes, prices, operating hours and wholesale terms, query the integrated `epic_site` MCP when writing or updating an article; this document is not the live catalog. If a stable fact needed for an article is not documented here, do not invent it — ask the user or leave it out. These posts are published under EPIC's name. See SKILL.md's "Personalizing 'Vì sao chọn EPIC'" section for how to tailor the closing section to each article.

## Company

- **EPIC Coffee Roaster** (also "EPIC Roastery") has self-roasted coffee in Sài Gòn since **2014**, on a drum roaster, tuning the roast profile batch by batch — both for its own retail line and for outside partners across Vietnam.
- Green beans arrive by the sack, are hand-sorted before roasting, and rest after roasting before being packed to order — the point being freshness at delivery, not long warehouse time.
- EPIC's coffee has previously been exported to **Russia** and the **Netherlands**.
- Roastery address: **54/8 Ao Đôi, Bình Hưng Hòa, Hồ Chí Minh** (production site).
- Café address: **49 Ngô Thời Nhiệm, Phường Võ Thị Sáu, Quận 3, Thành phố Hồ Chí Minh** (retail/tasting location).

## Service scope (verify current availability and terms with MCP)

1. **Bán lẻ cà phê** — roasted coffee sold direct (retail bags, online storefront).
2. **Rang cà phê B2B theo yêu cầu** — contract roasting for other businesses/brands, tuned per-partner recipe.
3. **Đóng gói OEM** — private-label packaging/production for other coffee brands.
4. **Setup quán cà phê trọn gói** — turnkey café setup service.
5. **Đào tạo pha chế** — barista/brewing training.
6. **Tư vấn, bán, cho thuê máy móc quán cà phê và sửa chữa kỹ thuật** — equipment consulting, sales, rental, and technical repair/maintenance for cafés. (Confirmed by user 2026-08-27: EPIC does offer machine rental, not just sales/repair.)
7. **Cupping và gửi mẫu** — public service page offers free cupping at the roastery or coffee samples shipped to the partner. Confirm current availability with `get_service_info` before stating this as a current offer.

The live service list can be returned by `get_service_info`; it currently includes wholesale/custom roasting and blending, OEM, café setup and training, equipment supply/rental/buyback/maintenance, and cupping/sample requests. `get_store_info` currently reports a 5kg/month wholesale minimum and quote-only B2B pricing. Re-check both facts at drafting time; do not use historical prices or old sales-pitch figures.

## Product price and stock facts (never hand-maintain here)

Product names, slugs, origins, tasting notes, pack sizes, public retail prices, and stock status are volatile. Retrieve them via `search_coffee` followed by `get_coffee`. Include a retail price only for the exact pack that MCP returned as priced and in stock, label it as checked on that date, and link to the returned product URL. Wholesale prices are private/quote-only and must never be included.

## Why-choose-EPIC angles, by pillar

Pick 3-5 relevant to the pillar for each article's closing section — don't reuse an identical set every time.

- **Retail**: roasts in small batches and rests beans before packing (freshness); sorts green beans by hand; export track record (Russia, Netherlands) as a quality signal; direct-from-roaster means no long warehouse chain between roast and cup.
- **B2B roasting**: 2014-founded roastery with an established drum-roasting process; already roasts to custom profiles for partners across Vietnam (not just its own retail line); can tune recipes per-partner rather than offering one fixed profile.
- **OEM**: same production line as its own retail brand, so OEM partners get the same quality control; export experience (Russia, Netherlands) shows it can meet outside buyers' standards; one roastery handles both the roast and the private-label packaging, fewer handoffs.
- **Café setup**: EPIC runs its own café (49 Ngô Thời Nhiệm, Q3) as well as the roastery, so the setup advice comes from operating both ends, not just selling equipment; can pair the setup with its own roasted coffee and equipment lines.
- **Barista training**: trains for its own café and roastery operations already, so the training is grounded in a real working bar, not classroom-only; can pair training with the coffee/equipment EPIC actually supplies, so the workflow taught matches the gear the café will run.
- **Equipment consulting/repair/rental**: EPIC sells, rents, and services the gear itself (not a pure reseller with no technical follow-up); services its own café's equipment day to day; can offer rental as a lower-upfront-cost option for new/unproven shops alongside buy or repair.

## Where to send the reader (CTA per pillar)

Site is bilingual with a `/{lang}/` prefix (`vi` or `en`). Use `localizePath`-style paths — i.e. write the link as `/{lang}/wholesale` etc.

| Pillar | Best CTA link | Why |
|---|---|---|
| Bán lẻ | `/{lang}/coffee` (retail catalog) | Direct to buy |
| Rang B2B, OEM | `/{lang}/wholesale` | Wholesale/B2B inquiry form lives here |
| Setup quán, Đào tạo, Máy móc | `/{lang}/wholesale` (inquiry) or `/{lang}/visit` (see the café/roastery in person) | No dedicated service-inquiry page yet — wholesale form or an in-person visit are the real paths that exist today |

Don't invent a phone number, email, or hotline — none is confirmed here. Route every CTA to a real page above.
