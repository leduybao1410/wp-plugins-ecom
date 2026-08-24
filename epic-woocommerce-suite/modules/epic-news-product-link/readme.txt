=== EPIC News ↔ Product Link ===
Contributors: epicroastery
Tags: woocommerce, posts, products, related content
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

== Description ==

Adds one meta box — "Linked Coffee (EPIC)" — to the sidebar of the post
editor (Posts, i.e. the journal/news content that powers the Next.js
website's /news section). It lets an editor explicitly connect a post to
WooCommerce product data, instead of the website having to guess a
relationship from the post's text:

* **Product** — the exact coffee this post is about (e.g. an origin
  spotlight article written about one specific bean).
* **Product category** — a WooCommerce product category this post relates
  to more broadly (e.g. "Cà Phê Blend").
* **Product tags** — one or more WooCommerce product tags this post relates
  to (flavor notes, or a tier tag like "signature"/"decaf"). Ctrl/Cmd-click
  to select more than one.

All three fields are optional and independent — set any combination, or
none at all.

= Where this shows up =

This plugin only stores the link; it doesn't render anything on the
storefront itself. The three fields are exposed as `meta` on the
WordPress REST API's post objects (`epic_linked_product_id`,
`epic_linked_product_category`, `epic_linked_product_tags`), which the
Next.js website reads to build the "related coffee" column on each news
article page. See `getRelatedProductsForPost()` in the website's
`src/lib/products.ts`.

Matching priority on the website side: the specific linked product first
(if set), padded out with other coffees of the same type; otherwise
coffees matching the linked category and/or tags; otherwise the site's
curated featured picks, so the section is never empty.

= Setup =

1. Install and activate (requires WooCommerce active — the meta box reads
   WooCommerce's own product/category/tag data).
2. Edit any post. In the right-hand sidebar, find "Linked Coffee (EPIC)".
3. Pick a product, a category, and/or one or more tags as appropriate for
   that post, then update/publish.

= Notes =

* Only applies to the `post` post type (the journal), not WooCommerce
  products or pages.
* The three fields are read-only over the REST API — they can only be set
  from the post editor's meta box, not written via the API.
* Nothing is required — a post with none of the three fields set simply
  falls back to the website's featured-picks default.

== Changelog ==

= 1.0.0 =
* Initial release: Linked Coffee meta box (product / category / tags),
  exposed as REST-readable post meta.
