#!/usr/bin/env python3
"""Safely preview or update a WordPress post's body without changing metadata.

Network requests use curl because this WordPress host's WAF rejects Python
HTTPS clients in some environments. By default the script only previews a
unified diff. --apply is required to write. Backups and inventory exports live
under ~/.local/share/epic-content-writer unless --output-dir is supplied.
"""

from __future__ import annotations

import argparse
import difflib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from urllib.parse import urlencode

ROOT = Path(__file__).resolve().parents[3]
DEFAULT_ENV = ROOT / "website" / ".env"
DEFAULT_DATA = Path.home() / ".local" / "share" / "epic-content-writer"
CATEGORIES = (84, 85, 86, 87, 88, 89)
PRESERVED_FIELDS = ("slug", "status", "categories", "tags", "featured_media", "title", "excerpt")


def parse_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    try:
        lines = path.read_text(encoding="utf-8").splitlines()
    except OSError:
        return values
    for line in lines:
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def curl_config_quote(value: str) -> str:
    if "\n" in value or "\r" in value:
        raise ValueError("Credential contains a line break; refusing to create curl config")
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


class WpClient:
    def __init__(self, env_file: Path):
        env_values = parse_env_file(env_file)
        self.values = {**env_values, **os.environ}
        self.base = self.values.get("WC_URL", "").strip().rstrip("/")
        self.user = self.values.get("WP_USER", "").strip()
        self.app_password = self.values.get("WP_APP_PASSWORD", "").strip()
        self.password = self.values.get("WP_PASSWORD", "").strip()
        if not self.base:
            raise RuntimeError("WC_URL is missing from the environment and selected env file")
        if not self.user or not (self.app_password or self.password):
            raise RuntimeError("WP_USER and WP_APP_PASSWORD or WP_PASSWORD are required")
        self.auth_path: Path | None = None
        self._configure_auth()

    def _curl(self, url: str, *, method: str = "GET", fields: list[str] | None = None,
              headers: list[str] | None = None) -> tuple[int, str]:
        cmd = ["curl", "--silent", "--show-error", "--max-time", "45", "--output", "-",
               "--write-out", "\n__EPIC_HTTP__:%{http_code}"]
        if self.auth_path:
            cmd += ["--config", str(self.auth_path)]
        cmd += ["--request", method, url]
        for field in fields or []:
            cmd += ["--data-urlencode", field]
        for header in headers or []:
            cmd += ["--header", header]
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError(f"curl failed with exit code {result.returncode}: {result.stderr.strip()}")
        match = re.search(r"\n__EPIC_HTTP__:(\d{3})\s*$", result.stdout)
        if not match:
            raise RuntimeError("WordPress response did not include an HTTP status")
        return int(match.group(1)), result.stdout[:match.start()]

    def _configure_auth(self) -> None:
        if self.app_password:
            config = f"user = {curl_config_quote(self.user + ':' + self.app_password)}\n"
        else:
            token_url = f"{self.base}/wp-json/jwt-auth/v1/token"
            cmd = ["curl", "--silent", "--show-error", "--max-time", "30", "--output", "-",
                   "--write-out", "\n__EPIC_HTTP__:%{http_code}", "--config", "-",
                   "--request", "POST", token_url, "--data-urlencode", f"username={self.user}",
                   "--data-urlencode", f"password={self.password}"]
            # Keep the password out of curl's command line by supplying fields
            # through a private config on stdin rather than --data-urlencode.
            config = (f"data-urlencode = {curl_config_quote('username=' + self.user)}\n"
                      f"data-urlencode = {curl_config_quote('password=' + self.password)}\n")
            cmd = ["curl", "--silent", "--show-error", "--max-time", "30", "--output", "-",
                   "--write-out", "\n__EPIC_HTTP__:%{http_code}", "--config", "-",
                   "--request", "POST", token_url]
            result = subprocess.run(cmd, input=config, capture_output=True, text=True)
            if result.returncode:
                raise RuntimeError(f"WordPress authentication request failed: {result.stderr.strip()}")
            try:
                raw = result.stdout.split("\n__EPIC_HTTP__:", 1)[0]
                token = json.loads(raw).get("token", "")
            except (ValueError, AttributeError):
                token = ""
            if not token:
                raise RuntimeError("WordPress JWT authentication failed")
            config = f"header = {curl_config_quote('Authorization: Bearer ' + token)}\n"

        fd, name = tempfile.mkstemp(prefix="epic-wp-auth-")
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(config)
        self.auth_path = Path(name)

    def close(self) -> None:
        if self.auth_path:
            self.auth_path.unlink(missing_ok=True)
            self.auth_path = None

    @staticmethod
    def decode_json(status: int, raw: str):
        if status < 200 or status >= 300:
            raise RuntimeError(f"WordPress returned HTTP {status}: {raw[:500]}")
        starts = [pos for pos in (raw.find("{"), raw.find("[")) if pos >= 0]
        if not starts:
            raise RuntimeError(f"WordPress response is not JSON: {raw[:300]}")
        return json.loads(raw[min(starts):])

    def get_post(self, post_id: int) -> dict:
        query = urlencode({"context": "edit", "_fields": "id,slug,status,modified_gmt,categories,tags,featured_media,title,excerpt,content"})
        status, raw = self._curl(f"{self.base}/wp-json/wp/v2/posts/{post_id}?{query}")
        return self.decode_json(status, raw)

    def update_body(self, post_id: int, content_path: Path) -> dict:
        status, raw = self._curl(
            f"{self.base}/wp-json/wp/v2/posts/{post_id}?context=edit",
            method="POST",
            fields=[f"content@{content_path}"],
        )
        return self.decode_json(status, raw)

    def inventory(self, directory: Path) -> list[dict]:
        directory.mkdir(parents=True, exist_ok=True)
        posts: list[dict] = []
        seen_ids: set[int] = set()
        duplicate_ids: set[int] = set()
        for page in range(1, 1001):
            query = urlencode({
                "categories": ",".join(map(str, CATEGORIES)),
                "per_page": 100,
                "page": page,
                "orderby": "id",
                "order": "asc",
                "status": "any",
                "context": "edit",
                "_fields": "id,slug,status,modified,modified_gmt,categories,tags,featured_media,title,excerpt,content",
            })
            status, raw = self._curl(f"{self.base}/wp-json/wp/v2/posts?{query}")
            batch = self.decode_json(status, raw)
            if not isinstance(batch, list):
                raise RuntimeError("Expected a post list from WordPress inventory")
            for post in batch:
                post_id = post.get("id")
                if post_id in seen_ids:
                    duplicate_ids.add(post_id)
                    continue
                seen_ids.add(post_id)
                posts.append(post)
            if len(batch) < 100:
                break
        else:
            raise RuntimeError("Inventory exceeded the safety limit of 1000 pages")
        if duplicate_ids:
            raise RuntimeError(
                "WordPress pagination returned duplicate post IDs "
                + ", ".join(map(str, sorted(duplicate_ids)))
                + "; rerun the inventory before reviewing updates"
            )

        for post in posts:
            safe_slug = re.sub(r"[^a-zA-Z0-9._-]+", "_", post.get("slug", "post"))
            (directory / f"{post['id']}-{safe_slug}.json").write_text(
                json.dumps(post, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
            )
        manifest = {
            "created_at_utc": datetime.now(timezone.utc).isoformat(),
            "category_ids": list(CATEGORIES),
            "count": len(posts),
            "duplicate_ids": sorted(duplicate_ids),
            "posts": [{k: post.get(k) for k in ("id", "slug", "status", "modified_gmt", "categories", "featured_media")} for post in posts],
        }
        (directory / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        return posts


def raw_content(post: dict) -> str:
    content = post.get("content") or {}
    return content.get("raw") or content.get("rendered") or ""


def backup_post(post: dict, directory: Path) -> Path:
    directory.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    path = directory / f"{post['id']}-{post['slug']}-{stamp}.json"
    path.write_text(json.dumps(post, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return path


def preview(old: str, new: str, *, context: int = 3) -> str:
    return "".join(difflib.unified_diff(
        old.splitlines(keepends=True), new.splitlines(keepends=True),
        fromfile="wordpress-current", tofile="proposed-content", n=context,
    ))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--env-file", type=Path, default=DEFAULT_ENV)
    parser.add_argument("--inventory", action="store_true", help="Export all posts in EPIC categories 84–89")
    parser.add_argument("--output-dir", type=Path, help="Inventory/backup directory; defaults to local application data")
    parser.add_argument("--post-id", type=int)
    parser.add_argument("--expected-modified-gmt")
    parser.add_argument("--content-file", type=Path)
    parser.add_argument("--restore-backup", type=Path, help="Use content.raw from a prior backup JSON")
    parser.add_argument("--apply", action="store_true", help="Write the proposed content; without this flag, only preview")
    args = parser.parse_args()

    if args.inventory and any((args.post_id, args.content_file, args.restore_backup)):
        parser.error("--inventory cannot be combined with post update options")
    if not args.inventory:
        if not args.post_id:
            parser.error("--post-id is required unless --inventory is used")
        if bool(args.content_file) == bool(args.restore_backup):
            parser.error("provide exactly one of --content-file or --restore-backup")
        if not args.expected_modified_gmt:
            parser.error("--expected-modified-gmt is required for updates and restores")

    client = WpClient(args.env_file)
    try:
        if args.inventory:
            directory = args.output_dir or (DEFAULT_DATA / "inventory" / datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ"))
            posts = client.inventory(directory)
            print(json.dumps({"count": len(posts), "directory": str(directory)}, ensure_ascii=False))
            return 0

        post = client.get_post(args.post_id)
        if post.get("id") != args.post_id:
            raise RuntimeError("WordPress returned a different post ID")
        if post.get("modified_gmt") != args.expected_modified_gmt:
            raise RuntimeError(
                f"Post changed since it was reviewed (current modified_gmt={post.get('modified_gmt')}); refresh and review again"
            )
        if args.restore_backup:
            backup = json.loads(args.restore_backup.read_text(encoding="utf-8"))
            if backup.get("id") != args.post_id:
                raise RuntimeError("Backup post ID does not match --post-id")
            proposed = raw_content(backup)
        else:
            proposed = args.content_file.read_text(encoding="utf-8")
        old = raw_content(post)
        if proposed == old:
            print(json.dumps({"post_id": args.post_id, "slug": post.get("slug"), "changed": False, "message": "Content is already identical"}, ensure_ascii=False))
            return 0

        print(f"Post {post['id']} · {post['slug']} · status={post.get('status')} · modified_gmt={post.get('modified_gmt')}")
        print(f"Body length: {len(old)} → {len(proposed)} characters")
        sys.stdout.write(preview(old, proposed))
        if not args.apply:
            print("\nPreview only. Re-run with --apply to write.")
            return 0

        # Re-read immediately before mutation and refuse to overwrite newer work.
        current = client.get_post(args.post_id)
        if current.get("modified_gmt") != args.expected_modified_gmt:
            raise RuntimeError(
                f"Post changed immediately before update (current modified_gmt={current.get('modified_gmt')}); no write made"
            )
        if current.get("status") != post.get("status"):
            raise RuntimeError("Post status changed since preview; no write made")

        backup_dir = args.output_dir or (DEFAULT_DATA / "backups")
        backup_path = backup_post(current, backup_dir)
        if args.content_file:
            update_path = args.content_file
            remove_update_file = False
        else:
            handle = tempfile.NamedTemporaryFile(prefix="epic-restore-", suffix=".html", delete=False)
            update_path = Path(handle.name)
            try:
                handle.write(proposed.encode("utf-8"))
            finally:
                handle.close()
            remove_update_file = True
        try:
            updated = client.update_body(args.post_id, update_path)
        finally:
            if remove_update_file:
                update_path.unlink(missing_ok=True)
        if updated.get("id") != post["id"] or updated.get("slug") != post.get("slug"):
            raise RuntimeError(f"Unexpected WordPress update response; backup saved at {backup_path}")
        if updated.get("status") != post.get("status"):
            raise RuntimeError(f"Post status changed unexpectedly; backup saved at {backup_path}")
        verify = client.get_post(args.post_id)
        if verify.get("status") != post.get("status") or verify.get("slug") != post.get("slug"):
            raise RuntimeError(f"Post identity/status verification failed; backup saved at {backup_path}")
        changed_fields = [field for field in PRESERVED_FIELDS if verify.get(field) != post.get(field)]
        if changed_fields:
            raise RuntimeError(
                f"Post metadata changed unexpectedly in {', '.join(changed_fields)}; backup saved at {backup_path}"
            )
        if raw_content(verify) != proposed:
            raise RuntimeError(f"Saved body differs from proposal; backup saved at {backup_path}")
        print(json.dumps({
            "updated": True,
            "post_id": args.post_id,
            "slug": post.get("slug"),
            "status": verify.get("status"),
            "backup": str(backup_path),
            "modified_gmt": verify.get("modified_gmt"),
        }, ensure_ascii=False))
        return 0
    finally:
        client.close()


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
