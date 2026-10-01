#!/usr/bin/env python3
"""Publish one complete EPIC seven-language draft set after preflight checks.

Dry-run is the default. Network requests use curl via update_post_content.WpClient
because this WordPress host rejects Python HTTPS clients in some environments.
"""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlencode

from update_post_content import DEFAULT_ENV, WpClient, raw_content

LOCALES = ("vi", "en", "ru", "hi", "zh", "ko", "ja")
CATEGORIES = {84, 85, 86, 87, 88, 89}
FRONTEND = "https://www.epicroastery.coffee"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, required=True,
                        help="Tuesday report containing base_slug and seven post IDs/slugs")
    parser.add_argument("--env-file", type=Path, default=DEFAULT_ENV)
    parser.add_argument("--apply", action="store_true", help="Publish after the full preflight")
    return parser.parse_args()


def load_manifest(path: Path) -> tuple[str, list[dict]]:
    data = json.loads(path.read_text(encoding="utf-8"))
    base_slug = str(data.get("base_slug", ""))
    if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", base_slug):
        raise RuntimeError("Manifest base_slug is missing or invalid")
    created = data.get("created_at") or data.get("created_at_utc")
    if not created:
        raise RuntimeError("Manifest must include created_at")
    created_at = datetime.fromisoformat(str(created).replace("Z", "+00:00"))
    if created_at.tzinfo is None:
        raise RuntimeError("Manifest created_at must include a timezone")
    age_hours = (datetime.now(timezone.utc) - created_at.astimezone(timezone.utc)).total_seconds() / 3600
    if age_hours < -1 or age_hours > 72:
        raise RuntimeError(f"Manifest age is outside the allowed 72-hour window ({age_hours:.1f} hours)")
    posts = data.get("posts")
    if not isinstance(posts, list) or len(posts) != 7:
        raise RuntimeError("Manifest must identify exactly seven posts")
    return base_slug, posts


def record_result(path: Path, values: dict) -> None:
    data = json.loads(path.read_text(encoding="utf-8"))
    data.update(values)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix="epic-publish-report-", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(data, handle, ensure_ascii=False, indent=2)
            handle.write("\n")
        os.replace(temp_name, path)
    except Exception:
        Path(temp_name).unlink(missing_ok=True)
        raise


def get_slug(client: WpClient, slug: str) -> dict:
    query = urlencode({"slug": slug, "status": "any", "context": "edit",
                       "_fields": "id,slug,status,categories,featured_media,content"})
    code, raw = client._curl(f"{client.base}/wp-json/wp/v2/posts?{query}")
    rows = client.decode_json(code, raw)
    if not isinstance(rows, list) or len(rows) != 1:
        raise RuntimeError(f"Expected one WordPress post for slug {slug}; found {len(rows) if isinstance(rows, list) else 'invalid response'}")
    return rows[0]


def preflight(client: WpClient, base_slug: str, manifest_posts: list[dict]) -> list[dict]:
    expected = [base_slug if locale == "vi" else f"{base_slug}-{locale}" for locale in LOCALES]
    if len(manifest_posts) != 7:
        raise RuntimeError("Manifest must identify exactly seven posts")
    by_slug = {str(row.get("slug", "")): row for row in manifest_posts}
    if len(by_slug) != 7:
        raise RuntimeError("Manifest repeats a locale slug")
    if set(by_slug) != set(expected):
        raise RuntimeError("Manifest slugs do not match the required VI/EN/RU/HI/ZH/KO/JA set")
    posts = []
    ids = set()
    category_set: set[int] | None = None
    for slug in expected:
        row = by_slug[slug]
        try:
            post_id = int(row["id"])
        except (KeyError, TypeError, ValueError) as error:
            raise RuntimeError(f"Manifest has no valid post ID for {slug}") from error
        if post_id in ids:
            raise RuntimeError("Manifest repeats a WordPress post ID")
        ids.add(post_id)
        post = client.get_post(post_id)
        if post.get("slug") != slug or row.get("slug") != slug:
            raise RuntimeError(f"WordPress slug and manifest disagree for post {post_id}")
        if post.get("status") != "draft":
            raise RuntimeError(f"{slug} has status {post.get('status')!r}; all seven must still be drafts")
        categories = {int(value) for value in post.get("categories", [])}
        if len(categories) != 1 or not categories.issubset(CATEGORIES):
            raise RuntimeError(f"{slug} must belong to exactly one EPIC content category (84–89)")
        if category_set is None:
            category_set = categories
        elif categories != category_set:
            raise RuntimeError("The seven posts do not share one category")
        body = raw_content(post)
        if len(re.sub(r"<[^>]+>", " ", body).strip()) < 200:
            raise RuntimeError(f"{slug} has missing or too-short content")
        if not post.get("featured_media"):
            raise RuntimeError(f"{slug} has no featured EPIC image")
        if not re.search(r"<img\b[^>]*\bsrc\s*=\s*['\"][^'\"]+['\"]", body, re.I):
            raise RuntimeError(f"{slug} has no inline image")
        posts.append(post)
    return posts


def update_status(client: WpClient, post_id: int, status: str) -> dict:
    code, raw = client._curl(
        f"{client.base}/wp-json/wp/v2/posts/{post_id}?context=edit",
        method="POST", fields=[f"status={status}"],
    )
    return client.decode_json(code, raw)


def fetch_public(url: str) -> tuple[int, str]:
    result = subprocess.run(
        ["curl", "--silent", "--show-error", "--location", "--max-time", "30",
         "--write-out", "\n__EPIC_HTTP__:%{http_code}", url],
        capture_output=True, text=True,
    )
    if result.returncode:
        raise RuntimeError(f"Public page check failed for {url}: {result.stderr.strip()}")
    match = re.search(r"\n__EPIC_HTTP__:(\d{3})\s*$", result.stdout)
    if not match:
        raise RuntimeError(f"Public page check returned no HTTP status for {url}")
    return int(match.group(1)), result.stdout[:match.start()]


def verify_public(base_slug: str) -> list[str]:
    paths = [f"/{locale}/news/{base_slug}" for locale in LOCALES]
    errors = []
    for path in paths:
        url = FRONTEND + path
        code, body = fetch_public(url)
        if code != 200:
            errors.append(f"{url}: HTTP {code}")
            continue
        canonicals = re.findall(r"<link\b[^>]*\brel=['\"]canonical['\"][^>]*\bhref=['\"]([^'\"]+)", body, re.I)
        if not canonicals:
            canonicals = re.findall(r"<link\b[^>]*\bhref=['\"]([^'\"]+)['\"][^>]*\brel=['\"]canonical['\"]", body, re.I)
        if not any(value.rstrip("/").endswith(path) for value in canonicals):
            errors.append(f"{url}: missing self-canonical")
    code, sitemap = fetch_public(FRONTEND + "/sitemap.xml")
    if code != 200:
        errors.append(f"sitemap.xml: HTTP {code}")
    else:
        missing = [path for path in paths if path not in sitemap]
        if missing:
            errors.append("sitemap missing: " + ", ".join(missing))
    return errors


def rollback(client: WpClient, published: list[int]) -> list[str]:
    errors = []
    for post_id in reversed(published):
        try:
            update_status(client, post_id, "draft")
            if client.get_post(post_id).get("status") != "draft":
                errors.append(f"post {post_id} did not return to draft")
        except Exception as error:  # preserve the original failure and report rollback separately
            errors.append(f"post {post_id}: {error}")
    return errors


def main() -> int:
    args = parse_args()
    client = WpClient(args.env_file)
    touched: list[int] = []
    try:
        base_slug, manifest_posts = load_manifest(args.manifest)
        posts = preflight(client, base_slug, manifest_posts)
        print(f"Preflight passed: {base_slug} and all seven locale drafts are ready.")
        if not args.apply:
            print("Dry run only. Re-run with --apply to publish and verify the set.")
            return 0

        try:
            for post in posts:
                post_id = int(post["id"])
                # Add before the request: a lost HTTP response can still mean
                # WordPress applied the status change, so rollback must cover it.
                touched.append(post_id)
                updated = update_status(client, post_id, "publish")
                if updated.get("status") != "publish":
                    raise RuntimeError(f"WordPress did not publish {post['slug']}")
            for post in posts:
                if client.get_post(int(post["id"])).get("status") != "publish":
                    raise RuntimeError(f"WordPress status check failed for {post['slug']}")

            # The storefront caches WordPress news data for 60 seconds.
            time.sleep(65)
            errors = verify_public(base_slug)
            if errors:
                raise RuntimeError("Public verification failed: " + "; ".join(errors))
            public_urls = [f"{FRONTEND}/{locale}/news/{base_slug}" for locale in LOCALES]
            record_result(args.manifest, {
                "publish_attempted_at": datetime.now(timezone.utc).isoformat(),
                "published_at": datetime.now(timezone.utc).isoformat(),
                "publish_status": "success",
                "rollback_status": "not_needed",
                "public_verification": "passed",
                "public_urls": public_urls,
            })
        except Exception as error:
            rollback_errors = rollback(client, touched)
            try:
                record_result(args.manifest, {
                    "publish_attempted_at": datetime.now(timezone.utc).isoformat(),
                    "publish_status": "failed",
                    "publish_error": str(error),
                    "rollback_status": "needs_attention" if rollback_errors else "verified_draft",
                    "rollback_errors": rollback_errors,
                    "public_urls": [f"{FRONTEND}/{locale}/news/{base_slug}" for locale in LOCALES],
                })
            except Exception as report_error:
                rollback_errors.append(f"could not update report: {report_error}")
            print(f"ERROR: {error}", file=sys.stderr)
            if rollback_errors:
                print("ROLLBACK NEEDS ATTENTION: " + "; ".join(rollback_errors), file=sys.stderr)
            else:
                print("Published members were returned to draft and verified.", file=sys.stderr)
            return 1

        print("SUCCESS: all seven posts are published, self-canonical, HTTP 200, and listed in the sitemap.")
        for locale in LOCALES:
            print(f"{locale}: {FRONTEND}/{locale}/news/{base_slug}")
        return 0
    finally:
        client.close()


if __name__ == "__main__":
    raise SystemExit(main())
