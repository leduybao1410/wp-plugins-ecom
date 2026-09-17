# Image library — one real EPIC photo per article, no stock/AI images

Every article must include at least one real photo of EPIC's own roastery, café, product, or equipment — never a generic stock photo or an AI-generated image (this is a real business; readers should see the real thing). All images below are already uploaded to the WordPress Media Library from EPIC's own asset folders (the same photos already used on the live site or in EPIC's Shopee listings), so they're guaranteed on-brand and real.

## How to pick the image for a given post

Each pillar has its own small pool of images (below). Given the `subtopic_index` (0-9) computed in SKILL.md's rotation logic, pick `image_index = subtopic_index % pool_size` from that pillar's pool — this cycles through the pool so the same 10 posts for a pillar don't all reuse the exact same photo, without requiring a separate state file.

| Pillar | Category | Image pool (WP media id → filename → suggested alt text) |
|---|---|---|
| 0 — Bán Lẻ Cà Phê | 84 | 216 product-arabica-lamdong.jpg "Cà phê Arabica Lâm Đồng rang xay của EPIC" · 217 product-brazil-guima-natural.jpg "Cà phê Brazil Guima sơ chế Natural của EPIC" · 218 product-cozy-blend.jpg "Cà phê blend Cozy của EPIC" · 219 product-daily-muse-blend.jpg "Cà phê blend Daily Muse của EPIC" · 220 product-ethiopia-yirgacheffe.jpg "Cà phê Ethiopia Yirgacheffe của EPIC" · 221 product-gentle-brew-blend4.jpg "Cà phê blend Gentle Brew của EPIC" · 222 product-peru-cajamarca.jpg "Cà phê Peru Cajamarca của EPIC" · 223 product-robusta-baoloc.jpg "Cà phê Robusta Bảo Lộc của EPIC" · 224 product-sweetlove-blend.jpg "Cà phê blend Sweet Love của EPIC" |
| 1 — Rang B2B Theo Yêu Cầu | 85 | 200 roaster-epic.jpg "Máy rang cà phê dạng trống tại xưởng rang EPIC" · 201 roaster-finest.jpg "Xưởng rang cà phê EPIC tại Sài Gòn" · 202 roaster-passion.jpg "Quy trình rang cà phê thủ công tại EPIC" · 203 roaster-perfection.jpg "Hạt cà phê sau khi rang tại xưởng EPIC" |
| 2 — Đóng Gói OEM | 86 | same 9-image product pool as pillar 0 (216-224) — these ARE EPIC's real finished packaging, relevant as a packaging-quality reference for OEM articles |
| 3 — Setup Quán Cà Phê Trọn Gói | 87 | 204 interior-overhead.jpg "Không gian quán cà phê nhìn từ trên cao" · 205 interior-tables.jpg "Khu vực bàn ghế trong quán cà phê" · 206 patio.jpg "Không gian sân vườn của quán cà phê EPIC" · 207 lounge.jpg "Khu vực lounge của quán cà phê" · 208 bar-counter.jpg "Quầy pha chế tại quán cà phê" · 209 wall-sign.jpg "Bảng hiệu trang trí trong quán cà phê" |
| 4 — Đào Tạo Pha Chế | 88 | 210 pourover.jpg "Pha cà phê bằng phương pháp pour-over" · 211 drink.jpg "Ly đồ uống cà phê thành phẩm" · 212 hero-latte.jpg "Latte art tại quán cà phê" · 208 bar-counter.jpg "Thực hành pha chế tại quầy bar" |
| 5 — Tư Vấn & Sửa Chữa Máy Móc | 89 | 213 espresso-machine.jpg "Máy pha espresso tại quán cà phê" · 214 banner1-essential-setup.jpg "Thiết bị pha chế cần thiết cho quán cà phê" · 215 banner3-precision-care.jpg "Bảo trì máy pha cà phê chính xác" |

All images live at `https://admin.epicroastery.coffee/wp-content/uploads/2026/08/<filename>` (media id resolves to that URL too via `GET /wp-json/wp/v2/media/<id>`, use that if the upload path ever changes).

## How to embed it

Two things, both required:

1. **Inline in the article body** — place one `<img>` near the top of the post, right after the opening hook and before (or as part of) the first H2, so the post has visual interest immediately rather than a wall of text:
   ```html
   <img src="https://admin.epicroastery.coffee/wp-content/uploads/2026/08/<filename>" alt="<contextual alt text>" style="width:100%;height:auto;border-radius:8px;margin:1em 0;" />
   ```
   Write the `alt` text to fit *this specific article*, not just the generic suggestion in the table above — e.g. for a post about choosing a home grinder, an espresso-machine photo's alt could read "Máy pha espresso — một trong những thiết bị nên đầu tư song song với máy xay tại nhà" rather than the bare generic caption.
2. **Set it as the featured image too** — pass `featured_media=<id>` when creating the post (both VI and EN can share the same `featured_media`, or each can use a different photo from the same pool if you want visual variety between the two language versions). `scripts/publish_post.sh` accepts `--image-id <id>` for this.

## If a pillar ever needs new/more images

Upload via `curl -u "$WP_USER:$WP_APP_PASSWORD" -X POST "$WC_URL/wp-json/wp/v2/media" -H "Content-Disposition: attachment; filename=<name>.jpg" -H "Content-Type: image/jpeg" --data-binary "@<local path>"` — response includes `id` and `source_url`. Only use real EPIC photos (ask the user for more if this pool runs thin) — never substitute a generic stock/AI image just to fill the requirement.
