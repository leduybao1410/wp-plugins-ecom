#!/usr/bin/env bash
# Publish ONE translated sibling post ({slug}-{locale}) to WordPress as a draft.
#
# Sibling posts are how this WP install models translations (no WPML/Polylang):
# src/lib/news.ts pairs `{slug}-{locale}` with the base topic, so a translated
# post must use exactly that suffix. This script is the single-post counterpart
# to publish_post.sh (which only creates the VI + EN pair).
#
# Requires:
#   WC_URL          e.g. https://admin.epicroastery.coffee
#   WP_USER         WordPress username
#   WP_APP_PASSWORD Application Password (Basic Auth) — OR WP_PASSWORD (the
#                   account login password, authenticated via JWT, since
#                   WordPress rejects a login password over Basic Auth).
#
# Usage:
#   publish_translation.sh \
#     --source-slug chon-may-xay-ca-phe-cho-quan-moi-mo \
#     --locale ja \
#     --title "新しいカフェのためのコーヒーミルの選び方" \
#     --content-file body_ja.html \
#     --excerpt "メタ説明…" \
#     --category-id 89 \
#     --tags "may xay,thiet bi" \
#     --image-id 202 \
#     --status draft
#
# Idempotent: if `{source-slug}-{locale}` already exists it is left untouched
# (prints the existing id) rather than creating a duplicate.

set -euo pipefail

STATUS="draft"
TAGS=""
IMAGE_ID=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --source-slug) SOURCE_SLUG="$2"; shift 2 ;;
    --locale) LOCALE="$2"; shift 2 ;;
    --title) TITLE="$2"; shift 2 ;;
    --content-file) CONTENT_FILE="$2"; shift 2 ;;
    --excerpt) EXCERPT="$2"; shift 2 ;;
    --category-id) CATEGORY_ID="$2"; shift 2 ;;
    --tags) TAGS="$2"; shift 2 ;;
    --status) STATUS="$2"; shift 2 ;;
    --image-id) IMAGE_ID="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
done

: "${WC_URL:?Set WC_URL}"
: "${WP_USER:?Set WP_USER}"
: "${SOURCE_SLUG:?Set --source-slug}"
: "${LOCALE:?Set --locale}"
: "${TITLE:?Set --title}"
: "${CONTENT_FILE:?Set --content-file}"
: "${CATEGORY_ID:?Set --category-id}"

if [[ -z "${WP_APP_PASSWORD:-}" && -z "${WP_PASSWORD:-}" ]]; then
  echo "Set either WP_APP_PASSWORD (Basic Auth) or WP_PASSWORD (JWT)." >&2
  exit 1
fi

SLUG="${SOURCE_SLUG}-${LOCALE}"

json_clean() {
  python3 -c "
import sys, re
raw = sys.stdin.read()
m = re.search(r'[\{\[]', raw)
sys.stdout.write(raw[m.start():] if m else raw)
"
}

if [[ -n "${WP_APP_PASSWORD:-}" ]]; then
  AUTH=(-u "${WP_USER}:${WP_APP_PASSWORD}")
else
  TOKEN=$(curl -s -X POST "$WC_URL/wp-json/jwt-auth/v1/token" \
    --data-urlencode "username=${WP_USER}" \
    --data-urlencode "password=${WP_PASSWORD}" | json_clean \
    | python3 -c "import json,sys;print(json.load(sys.stdin).get('token',''))")
  if [[ -z "$TOKEN" ]]; then
    echo "JWT auth failed for user ${WP_USER}." >&2
    exit 1
  fi
  AUTH=(-H "Authorization: Bearer ${TOKEN}")
fi

# Already translated? Recover it instead of duplicating.
EXISTING=$(curl -s "${AUTH[@]}" "$WC_URL/wp-json/wp/v2/posts?slug=${SLUG}&status=any&context=edit&_fields=id,slug,status" | json_clean)
if echo "$EXISTING" | python3 -c "import json,sys;d=json.load(sys.stdin);sys.exit(0 if isinstance(d,list) and d else 1)" 2>/dev/null; then
  echo "$EXISTING" | python3 -c "import json,sys;d=json.load(sys.stdin)[0];print('EXISTS -> id=%s status=%s slug=%s' % (d['id'], d.get('status'), d['slug']))"
  exit 0
fi

TAG_ID_ARGS=()
if [[ -n "$TAGS" ]]; then
  IFS=',' read -ra TAG_NAMES <<< "$TAGS"
  for t in "${TAG_NAMES[@]}"; do
    name=$(echo "$t" | sed 's/^ *//;s/ *$//')
    [[ -z "$name" ]] && continue
    q=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$name")
    id=$(curl -s "${AUTH[@]}" "$WC_URL/wp-json/wp/v2/tags?search=$q" | json_clean | python3 -c "
import json,sys
d=json.load(sys.stdin)
n=sys.argv[1].strip().lower()
for t in d:
    if t['name'].strip().lower()==n:
        print(t['id']); break
" "$name")
    if [[ -z "$id" ]]; then
      id=$(curl -s "${AUTH[@]}" -X POST "$WC_URL/wp-json/wp/v2/tags" --data-urlencode "name=$name" | json_clean | python3 -c "
import json,sys
d=json.load(sys.stdin)
print(d.get('id') or (d.get('data',{}) or {}).get('term_id',''))
")
    fi
    [[ -n "$id" ]] && TAG_ID_ARGS+=(--data-urlencode "tags[]=$id")
  done
fi

IMAGE_ID_ARGS=()
[[ -n "$IMAGE_ID" ]] && IMAGE_ID_ARGS=(--data-urlencode "featured_media=$IMAGE_ID")

RESP=$(curl -s "${AUTH[@]}" -X POST "$WC_URL/wp-json/wp/v2/posts" \
  --data-urlencode "title=$TITLE" \
  --data-urlencode "slug=$SLUG" \
  --data-urlencode "content@${CONTENT_FILE}" \
  --data-urlencode "excerpt=${EXCERPT:-}" \
  --data-urlencode "status=$STATUS" \
  --data-urlencode "categories[]=$CATEGORY_ID" \
  "${TAG_ID_ARGS[@]}" "${IMAGE_ID_ARGS[@]}" | json_clean)

echo "$RESP" | python3 -c "
import json,sys
d=json.load(sys.stdin)
if 'id' in d:
    print('CREATED -> id=%s status=%s slug=%s edit=%s/wp-admin/post.php?post=%s&action=edit' % (d['id'], d.get('status'), d.get('slug'), '$WC_URL', d['id']))
else:
    print('FAILED:', d); sys.exit(1)
"
