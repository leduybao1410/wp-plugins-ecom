=== EPIC Image Optimize ===
Contributors: epicroastery
Tags: woocommerce, media, images, performance
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

== Description ==

Caps every future product photo upload at 1600px / quality 78, and adds
Media → Optimize Product Images so already-uploaded product photos can be
shrunk in place — same filename, same URL, nothing else on the site needs
to change.

Why: product photos were landing at ~1500x1500px / ~370KB each, straight
from wp-admin uploads with no resize or compression. The headless Next.js
storefront's image optimizer has to download and transform that full-size
original on every first request for a given display size — measured at
3-5 seconds per image on a cold cache, which is what was showing up as a
volatile mobile PageSpeed score.

== Installation ==

1. Upload and activate like any other plugin.
2. Future uploads are capped automatically — no setup needed.
3. Go to Media → Optimize Product Images, review the current sizes, and
   click "Optimize now" to shrink the photos already in use. Originals are
   backed up to wp-content/uploads/epic-image-optimize-backup/ the first
   time each file is touched.

== Changelog ==

= 1.0.0 =
* Initial release.
