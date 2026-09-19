#!/usr/bin/env python3
"""Fetch one EPIC journal topic (master VN post) and print its metadata + a
structured plain-text rendering of the body, so it can be translated."""
import json, re, html, subprocess, sys, os

slug = sys.argv[1]
wc = subprocess.check_output(
    ["bash", "-lc", r"""grep -E '^WC_URL=' /Volumes/data/Work/EPIC/website/.env | cut -d= -f2- | tr -d '"'"""],
    text=True,
).strip()
url = f"{wc}/wp-json/wp/v2/posts?slug={slug}&_fields=id,slug,title,excerpt,categories,tags,featured_media,content"
raw = subprocess.check_output(["curl", "-s", "--max-time", "30", url], text=True)
i = min([p for p in (raw.find("{"), raw.find("[")) if p >= 0] or [0])
post = json.loads(raw[i:])[0]

tag_ids = ",".join(str(t) for t in post["tags"])
tag_names = ""
if tag_ids:
    traw = subprocess.check_output(["curl", "-s", "--max-time", "25", f"{wc}/wp-json/wp/v2/tags?include={tag_ids}&_fields=name"], text=True)
    ti = traw.find("[")
    if ti >= 0:
        tag_names = ",".join(t["name"] for t in json.loads(traw[ti:]))

def txt(h):
    s = re.sub(r"<[^>]+>", "", h or "")
    return re.sub(r"\s+", " ", html.unescape(s)).strip()

print(f"SLUG={post['slug']}")
print(f"ID={post['id']}")
print(f"CATEGORY={post['categories'][0] if post['categories'] else ''}")
print(f"FEATURED_MEDIA={post['featured_media']}")
print(f"TAGS={tag_names}")
print(f"TITLE={txt(post['title']['rendered'])}")
print(f"EXCERPT={txt(post['excerpt']['rendered'])}")

c = post["content"]["rendered"]
# Walk block-level tags in order, emitting simple markers.
blocks = re.findall(r"<(h1|h2|h3|h4|p|li|blockquote|img)\b[^>]*>(.*?)</\1>|<img\b[^>]*/?>", c, re.S | re.I)
if not blocks:
    blocks = re.findall(r"<(h2|p)\b[^>]*>(.*?)</\1>", c, re.S | re.I)
print("---- BODY ----")
for tag, inner in blocks:
    if not tag:
        continue
    tag = tag.lower()
    if tag == "img":
        src = re.search(r'src="([^"]+)"', inner or "")
        print(f"[IMG] {src.group(1) if src else ''}")
        continue
    t = html.unescape(re.sub(r"<[^>]+>", "", inner))
    t = re.sub(r"\s+", " ", t).strip()
    if not t:
        continue
    if tag in ("h1", "h2", "h3", "h4"):
        print(f"[{tag.upper()}] {t}")
    elif tag == "li":
        print(f"  - {t}")
    elif tag == "blockquote":
        print(f"[QUOTE] {t}")
    else:
        print(f"[P] {t}")
