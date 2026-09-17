# EPIC Product Cost — MCP server

Lets a chat (Claude Desktop, or any other MCP client) read and edit product cost data directly,
by calling the `epic-product-cost/v1` REST API that the WordPress plugin registers
(`includes/class-rest-api.php`, one level up). No wp-admin clicking required for routine cost
updates — though the wp-admin screen (WooCommerce → Product Cost) still works exactly as before
and shows the same data.

## 1. Create a WordPress Application Password

This uses WordPress's own **Application Passwords** feature (built into core since 5.6) — no
custom secret to create or store on the WordPress side.

1. Log into `admin.epicroastery.coffee/wp-admin` as the account you want this tool to act as
   (needs the `manage_woocommerce` capability — an Administrator or Shop Manager).
2. Go to **Users → Profile** (or **Users → All Users → your user → Edit**).
3. Scroll to **Application Passwords**. Enter a name like `EPIC Product Cost MCP` and click
   **Add New Application Password**.
4. WordPress shows the generated password **once** — copy it immediately (it looks like
   `xxxx xxxx xxxx xxxx xxxx xxxx`, spaces included; keep the spaces, or remove them — either
   works, WordPress ignores spaces in the password on the server side).

Requires the site to be served over HTTPS — WordPress refuses Application Password
authentication over plain HTTP. `admin.epicroastery.coffee` already is.

To revoke access later, delete the Application Password from that same screen at any time.

## 2. Install dependencies

```
cd mcp-server
npm install
```

## 3. Register the server with your MCP client

For **Claude Desktop**, edit its config file:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

Add an entry under `mcpServers` (create that key if it isn't there yet):

```json
{
  "mcpServers": {
    "epic-product-cost": {
      "command": "node",
      "args": ["/absolute/path/to/EPIC/wordpress-plugins/epic-product-cost/mcp-server/server.js"],
      "env": {
        "EPIC_SITE_URL": "https://admin.epicroastery.coffee",
        "EPIC_WP_USERNAME": "your-wp-username",
        "EPIC_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Use the **absolute** path to `server.js` on this machine (not a relative one). Then fully quit and
reopen Claude Desktop — the tools appear afterward as `list_products`, `search_products`,
`get_product_cost`, `set_product_cost`, and `delete_cost_history_entry` (in a session linked to
this computer via the desktop bridge, they show up prefixed, e.g.
`mcp__remote-devices__epic-product-cost__set_product_cost`).

Any other MCP-compatible client works the same way: run `node server.js` with those three
environment variables set.

## What the tools do

- **list_products** — every tracked product: current cost (per 250g), when that cost became
  effective, and the live 250g retail price for reference.
- **search_products** — find a product's exact `product_id` by (partial) name.
- **get_product_cost** — current cost + calculated 500g/1kg cost + full history for one product.
  Identify it by `product_id` or `product_name`; pass `date` (YYYY-MM-DD) to see what the cost was
  as of a past date.
- **set_product_cost** — record a cost effective from a date (defaults to today). This never
  overwrites history — it adds a new dated row, or corrects the row for that exact date if one
  already exists for it. Cost is per 250g; 500g/1kg cost is calculated automatically (2x/4x).
- **delete_cost_history_entry** — permanently remove one history row (`product_id` + `entry_id`
  from `get_product_cost`'s history list). Cannot be undone.

`product_name` matching must resolve to exactly one product — if it matches more than one, the
tool lists the matches so you can retry with an exact `product_id`.

## Notes

- This folder is intentionally excluded from the WordPress plugin's `.zip` (WordPress doesn't need
  it — only the REST API classes one level up do). It's a separate local tool.
- The MCP server only talks to your own WordPress site over the API; it has no other access.
- Every write from here (`set_product_cost`, `delete_cost_history_entry`) is attributed in
  WordPress to whichever user's Application Password made the request, same as any other
  authenticated REST call.
