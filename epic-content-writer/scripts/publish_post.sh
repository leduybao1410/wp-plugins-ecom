#!/usr/bin/env bash
# Publish a VI + EN post pair to WordPress (admin.epicroastery.coffee or
# whatever WC_URL points to) as Drafts by default.
#
# Requires these environment variables to already be set:
#   WC_URL          e.g. https://admin.epicroastery.coffee
#   WP_USER         WordPress username
#   WP_APP_PASSWORD WordPress Application Password (with spaces, quote it)
#                   — OR, if none is configured, WP_PASSWORD (the account
#                   login password, authenticated via the JWT endpoint).
#                   WordPress rejects a login password over HTTP Basic, so
#                   JWT is the only way to use one non-interactively.
#
# Usage:
#   publish_post.sh \
#     --category-id 85 \
#     --slug rang-theo-cong-thuc-rieng \
#     --title-vi "Rang theo công thức riêng là gì?" \
#     --content-file-vi body_vi.html \
#     --excerpt-vi "Mô tả meta ~150-160 ký tự..." \
#     --title-en "What is a private roast profile?" \
#     --content-file-en body_en.html \
#     --excerpt-en "Meta description ~150-160 chars..." \
#     --tags "rang xay,b2b,ca phe" \
#     --image-id 202 \
#     --status draft
#
# --image-id sets the post's featured_media (id from references/images.md's
# per-pillar photo pool). It does NOT insert the <img> tag into the body —
# that must already be in --content-file-vi/en, written by the article
# author, since only they know the right alt text and placement.
#
# All HTTP calls use curl (not Python's requests/urllib — those get
# rejected by this host's WAF from a cloud sandbox). This script is safe
# to run from either the user's own machine or a cloud/scheduled session.

set -euo pipefail

STATUS="draft"
TAGS=""
IMAGE_ID=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --category-id) CATEGORY_ID="$2"; shift 2 ;;
    --slug) SLUG="$2"; shift 2 ;;
    --title-vi) TITLE_VI="$2"; shift 2 ;;
    --content-file-vi) CONTENT_FILE_VI="$2"; shift 2 ;;
    --excerpt-vi) EXCERPT_VI="$2"; shift 2 ;;
    --title-en) TITLE_EN="$2"; shift 2 ;;
    --content-file-en) CONTENT_FILE_EN="$2"; shift 2 ;;
    --excerpt-en) EXCERPT_EN="$2"; shift 2 ;;
    --tags) TAGS="$2"; shift 2 ;;
    --status) STATUS="$2"; shift 2 ;;
    --image-id) IMAGE_ID="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
done

: "${WC_URL:?Set WC_URL}"
: "${WP_USER:?Set WP_USER}"
: "${CATEGORY_ID:?Set --category-id}"
: "${SLUG:?Set --slug}"
: "${TITLE_VI:?Set --title-vi}"
: "${CONTENT_FILE_VI:?Set --content-file-vi}"
: "${TITLE_EN:?Set --title-en}"
: "${CONTENT_FILE_EN:?Set --content-file-en}"

# Auth: prefer an Application Password over HTTP Basic (WP_APP_PASSWORD); fall
# back to the account login password via JWT (WP_PASSWORD) when no app password
# is configured. WordPress only accepts Application Passwords over Basic Auth,
# so a plain login password MUST go through the JWT endpoint.
if [[ -z "${WP_APP_PASSWORD:-}" && -z "${WP_PASSWORD:-}" ]]; then
  echo "Set either WP_APP_PASSWORD (Basic Auth) or WP_PASSWORD (JWT)." >&2
  exit 1
fi

# This WP install sometimes leaks PHP warnings (display_errors on) as raw
# HTML *before* the actual JSON body on an otherwise-successful REST
# response. Strip anything before the first '{' or '[' so json.load()
# still works instead of choking on the warning text.
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

# This host occasionally drops the TLS connection outright (curl exit 35,
# or just an empty body) — transient, not a real failure. Retry a few
# times with a short pause before giving up, so one blip doesn't sink an
# unattended daily run.
curl_retry() {
  local attempt out
  for attempt in 1 2 3 4; do
    if out=$(curl -s "$@"); then
      if [[ -n "$out" ]]; then
        printf '%s' "$out"
        return 0
      fi
    fi
    sleep 3
  done
  echo "curl_retry: giving up after 4 attempts for: $*" >&2
  return 1
}

resolve_tag_id() {
  local name="$1"
  local found
  found=$(curl_retry "${AUTH[@]}" "$WC_URL/wp-json/wp/v2/tags?search=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$name")" \
    | json_clean | python3 -c "
import json,sys
d = json.load(sys.stdin)
for t in d:
    if t['name'].strip().lower() == sys.argv[1].strip().lower():
        print(t['id']); break
" "$name")
  if [[ -n "$found" ]]; then
    echo "$found"
    return
  fi
  local create_resp
  create_resp=$(curl_retry "${AUTH[@]}" -X POST "$WC_URL/wp-json/wp/v2/tags" \
    --data-urlencode "name=$name" | json_clean)
  echo "$create_resp" | python3 -c "
import json,sys
d = json.load(sys.stdin)
if 'id' in d:
    print(d['id'])
elif d.get('code') == 'term_exists':
    print(d['data']['term_id'])
else:
    sys.stderr.write('tag create failed: %s\n' % d)
    sys.exit(1)
"
}

TAG_ID_ARGS=()
if [[ -n "$TAGS" ]]; then
  IFS=',' read -ra TAG_NAMES <<< "$TAGS"
  for t in "${TAG_NAMES[@]}"; do
    t_trimmed=$(echo "$t" | sed 's/^ *//;s/ *$//')
    [[ -z "$t_trimmed" ]] && continue
    tag_id=$(resolve_tag_id "$t_trimmed")
    TAG_ID_ARGS+=(--data-urlencode "tags[]=$tag_id")
  done
fi

# Post creation is NOT safe to blindly retry on a network blip: if the
# POST actually landed server-side and only the response got lost, a
# naive retry creates a duplicate with slug "-2" (this happened once
# while building this script). So on an empty/failed attempt, check by
# slug whether it already exists before trying again — recover the
# existing post instead of creating a second one.
IMAGE_ID_ARGS=()
if [[ -n "$IMAGE_ID" ]]; then
  IMAGE_ID_ARGS=(--data-urlencode "featured_media=$IMAGE_ID")
fi

create_post() {
  local title="$1" slug="$2" content_file="$3" excerpt="$4"
  local attempt resp existing
  for attempt in 1 2 3; do
    resp=$(curl -s "${AUTH[@]}" -X POST "$WC_URL/wp-json/wp/v2/posts" \
      --data-urlencode "title=$title" \
      --data-urlencode "slug=$slug" \
      --data-urlencode "content@${content_file}" \
      --data-urlencode "excerpt=$excerpt" \
      --data-urlencode "status=$STATUS" \
      --data-urlencode "categories[]=$CATEGORY_ID" \
      "${TAG_ID_ARGS[@]}" "${IMAGE_ID_ARGS[@]}" | json_clean)
    if [[ -n "$resp" ]]; then
      printf '%s' "$resp"
      return 0
    fi
    echo "create_post: empty response for slug=$slug, checking whether it landed anyway..." >&2
    sleep 3
    existing=$(curl -s "${AUTH[@]}" "$WC_URL/wp-json/wp/v2/posts?slug=${slug}&status=any&context=edit" | json_clean)
    if [[ -n "$existing" ]] && echo "$existing" | python3 -c "
import json,sys
d = json.load(sys.stdin)
sys.exit(0 if (isinstance(d, list) and len(d) == 1) else 1)
" 2>/dev/null; then
      echo "create_post: found the post that was actually created despite the lost response." >&2
      echo "$existing" | python3 -c "import json,sys; print(json.dumps(json.load(sys.stdin)[0]))"
      return 0
    fi
  done
  echo "create_post: failed after 3 attempts for slug=$slug" >&2
  return 1
}

echo "Creating VI post..."
VI_RESP=$(create_post "$TITLE_VI" "$SLUG" "$CONTENT_FILE_VI" "${EXCERPT_VI:-}" | json_clean)
echo "$VI_RESP" | python3 -c "
import json,sys
d = json.load(sys.stdin)
if 'id' in d:
    print('VI OK -> id=%s edit=%s/wp-admin/post.php?post=%s&action=edit' % (d['id'], '$WC_URL', d['id']))
else:
    print('VI FAILED:', d); sys.exit(1)
"

echo "Creating EN post..."
EN_RESP=$(create_post "$TITLE_EN" "${SLUG}-en" "$CONTENT_FILE_EN" "${EXCERPT_EN:-}" | json_clean)
echo "$EN_RESP" | python3 -c "
import json,sys
d = json.load(sys.stdin)
if 'id' in d:
    print('EN OK -> id=%s edit=%s/wp-admin/post.php?post=%s&action=edit' % (d['id'], '$WC_URL', d['id']))
else:
    print('EN FAILED:', d); sys.exit(1)
"
