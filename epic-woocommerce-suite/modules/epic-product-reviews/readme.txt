=== EPIC Product Reviews ===
Contributors: epicroastery
Tags: woocommerce, reviews, ratings, rest-api, structured-data
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collects customer reviews on the website's product pages, moderates them in wp-admin, and feeds the approved ones into the site's Product structured data (aggregateRating + review) so Google Search Console stops flagging missing review fields.

== Description ==

Backs the review section on the website's coffee product pages. A customer writes a rating + review on the product detail page, which POSTs to the site's `/api/reviews` route (Turnstile-verified) and is forwarded into WordPress over a shared-secret-authenticated REST endpoint this plugin exposes:

* **`POST /wp-json/epic-reviews/v1/reviews`** — requires an `X-Epic-Secret` header matching the secret set under **WooCommerce → Product Reviews**. Validates the payload and stores the review with status `pending`.
* **`GET /wp-json/epic-reviews/v1/reviews?product=<id>`** — public and read-only. Returns a product's **approved** reviews plus its aggregate rating (`ratingValue` / `reviewCount`). The website renders exactly this list on the page and feeds the same numbers into its Product JSON-LD, so the structured data always reflects visible, moderated reviews (Google's requirement for review markup).
* **WooCommerce → Product Reviews** — the moderation log. Reviews arrive `pending` and never appear on the site — or in structured data — until a staff member clicks **Approve**. Unapprove pulls one back off the site; Delete removes it permanently. The screen has All / Pending / Approved filters, a **per-page selector** (10 / 25 / 50 / 100), and **checkbox + bulk actions** (Approve / Unapprove / Delete for everything selected) — plus the product, author, rating, review text, and submission date for each row.

This plugin creates no WooCommerce order, customer, or product-review comment — reviews live only in this plugin's own table. Nothing here touches the product catalog or checkout. The secret is the only setting; reading is deliberately public because it only ever exposes approved, sanitized content.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `epic-product-reviews.zip` → Install Now → Activate. (Activation creates the plugin's reviews table automatically.)
2. Go to **WooCommerce → Product Reviews** and set a long random shared secret. Copy the same value into the website's `EPIC_REVIEWS_SHARED_SECRET` environment variable (see `.env.example`) and redeploy/restart the website so it picks it up.
3. From a live product detail page, submit a test review. It should appear under **WooCommerce → Product Reviews** as "Pending". Approve it and confirm it shows up on the page and in the page's Product JSON-LD (`aggregateRating` + `review`), e.g. via the Rich Results Test at https://search.google.com/test/rich-results.
4. After approving your first review, re-run Search Console's validation for the "Product snippets" issues (Missing field "aggregateRating" / Missing field "review") — once a product has at least one approved review, those fields are present.

== Changelog ==

= 1.1.0 =
* Added a checkbox column and **bulk actions** (Approve / Unapprove / Delete) to the **WooCommerce → Product Reviews** moderation log, so staff can moderate many reviews at once.
* Added a **per-page selector** (10 / 25 / 50 / 100) to the review list, remembered per user.

= 1.0.0 =
* Initial release: `Epic_Reviews_Store` (custom reviews table), `Epic_Reviews_Rest_Api` (public GET for approved reviews + aggregate; shared-secret POST for submissions, stored pending), and the **WooCommerce → Product Reviews** moderation screen (`Epic_Reviews_List_Table` with Approve / Unapprove / Delete and All / Pending / Approved filters).
