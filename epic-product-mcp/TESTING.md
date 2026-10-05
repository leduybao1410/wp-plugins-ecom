# EPIC Product MCP staging checklist

Run this against staging with a backup and dedicated test products. Do not run destructive cases against production as a substitute for staging.

## Authentication and permissions

- Connect with a WordPress Application Password and confirm ability discovery over `/wp-json/mcp/mcp-adapter-default-server`.
- Confirm an unauthenticated request is rejected.
- Confirm a user without `edit_products` cannot search, preview, or apply.
- Confirm an editor can change a product they may edit, but cannot edit another user's product without the corresponding object capability.
- Confirm the permanent variation delete is denied without the variation's delete capability.

## Product read and update

- Search by exact slug and read a product. Verify the returned record contains the complete WooCommerce v3 fields, seven locale keys, and a revision token.
- Preview and apply changes to price, regular/sale price, stock quantity/status, weight/dimensions, SKU, images, attributes, categories/tags, linked products, downloads, and arbitrary non-reserved `meta_data`. Confirm WooCommerce accepts or rejects each field through its controller.
- Confirm `_epic_product_copy_*` keys are rejected inside `meta_data` and are writable only through `translations`.
- For each of `name`, `notes`, `excerpt`, and `description`, omit one locale and confirm preview rejects the edit; provide all seven locale values and confirm the diff lists every changed field.
- Confirm non-text changes preserve existing copy metadata.
- Create a product without `status` and verify it is draft.
- Preview a published slug change and verify the warning appears.
- Preview product Trash and verify the product is in WordPress Trash afterward.

## Variations

- List and read full variations with parent product translations and per-variation revisions.
- Create and edit a variation with price, stock, SKU, image, attributes, and metadata fields.
- Preview routine variation removal and verify apply sets its status to draft.
- Preview a separate `permanent_delete_variation` operation and confirm only `permanently-delete-variation` can apply it; verify the variation is gone afterward.

## Preview security and concurrency

- Alter one character in a preview token and confirm rejection.
- Use a preview token as a different WordPress user and confirm rejection.
- Wait longer than ten minutes and confirm the token is expired.
- Apply a token once, then replay it and confirm rejection.
- Preview an edit, change the same product from another user/session, then apply the old token and confirm a revision conflict with no overwrite.
- Read the same product repeatedly when WooCommerce returns different randomized `related_ids`; confirm its revision token stays stable. Confirm edits to saved WooCommerce fields or translation metadata still change the revision and reject a stale preview.
- Confirm audit logs include actor ID, product ID, operation, and field names, and contain no product values or credentials.

## Copy import and storefront

- Run `website/scripts/i18n/import-product-copy.mjs` without `--apply`; confirm all source slugs are matched or explicitly reviewed as unmatched.
- Import on staging with `--apply`; verify every source value field-by-field and verify pre-existing WordPress values remain unchanged.
- Deploy the storefront branch and check Sweet Love Blend and Cozy Blend in `vi`, `en`, `ru`, `hi`, `zh`, `ko`, and `ja`: product grid, quick view, product page, FAQ/structured data, related coffee, cart, checkout, and public coffee/search responses.
- Make representative price and stock changes, then verify catalog display and checkout recomputation.
- Only after the comparison passes, remove migrated product overrides from source locale files and rebuild generated dictionaries with `node scripts/i18n/build.mjs`.
