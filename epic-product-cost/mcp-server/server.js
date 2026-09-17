#!/usr/bin/env node
/**
 * EPIC Product Cost MCP server.
 *
 * Wraps the epic-product-cost WordPress plugin's REST API (namespace
 * `epic-product-cost/v1`, registered by includes/class-rest-api.php) as MCP tools, so cost data
 * can be read and edited directly from a chat instead of the wp-admin screen.
 *
 * Auth: WordPress Application Passwords (a core WP feature — Users -> Profile -> Application
 * Passwords in wp-admin). No custom secret to manage on the WordPress side.
 *
 * Required environment variables:
 *   EPIC_SITE_URL       e.g. https://admin.epicroastery.coffee (no trailing slash needed)
 *   EPIC_WP_USERNAME    the wp-admin username the application password was created under
 *   EPIC_WP_APP_PASSWORD  the application password itself (spaces are fine, WordPress ignores them)
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

const SITE_URL = process.env.EPIC_SITE_URL;
const WP_USERNAME = process.env.EPIC_WP_USERNAME;
const WP_APP_PASSWORD = process.env.EPIC_WP_APP_PASSWORD;

if (!SITE_URL || !WP_USERNAME || !WP_APP_PASSWORD) {
	console.error(
		'[epic-product-cost-mcp] Missing required environment variables. ' +
			'Set EPIC_SITE_URL, EPIC_WP_USERNAME, and EPIC_WP_APP_PASSWORD.'
	);
	process.exit(1);
}

const BASE_URL = `${SITE_URL.replace(/\/+$/, '')}/wp-json/epic-product-cost/v1`;
const AUTH_HEADER = 'Basic ' + Buffer.from(`${WP_USERNAME}:${WP_APP_PASSWORD}`).toString('base64');

/**
 * Thin fetch wrapper: adds auth, parses JSON, and turns a non-2xx response (WP_Error's REST
 * shape is `{ code, message, data: { status } }`) into a thrown Error with a useful message.
 */
async function apiFetch(path, options = {}) {
	const res = await fetch(`${BASE_URL}${path}`, {
		...options,
		headers: {
			Authorization: AUTH_HEADER,
			'Content-Type': 'application/json',
			...(options.headers || {}),
		},
	});

	const raw = await res.text();
	let body = null;
	if (raw) {
		try {
			body = JSON.parse(raw);
		} catch {
			body = raw;
		}
	}

	if (!res.ok) {
		const message = body && typeof body === 'object' && body.message ? body.message : `HTTP ${res.status}`;
		throw new Error(`EPIC Product Cost API error (${res.status}): ${message}`);
	}

	return body;
}

/**
 * Every tool below accepts either `product_id` (exact) or `product_name` (a search term run
 * through the plugin's /products/search endpoint). Resolving here — rather than pushing the
 * ambiguity onto the REST API — keeps the API itself simple and RESTful (id-addressed), while
 * letting a person just say a coffee's name in chat.
 */
async function resolveProductId({ product_id, product_name }) {
	if (product_id !== undefined && product_id !== null) {
		return product_id;
	}
	if (!product_name) {
		throw new Error('Provide either product_id or product_name.');
	}

	const matches = await apiFetch(`/products/search?q=${encodeURIComponent(product_name)}`);

	if (!matches.length) {
		throw new Error(`No product found matching "${product_name}".`);
	}
	if (matches.length > 1) {
		const list = matches.map((m) => `#${m.product_id} ${m.name}`).join('; ');
		throw new Error(
			`"${product_name}" matches more than one product: ${list}. Retry with an exact product_id.`
		);
	}
	return matches[0].product_id;
}

function textResult(value) {
	return { content: [{ type: 'text', text: JSON.stringify(value, null, 2) }] };
}

function errorResult(err) {
	return {
		content: [{ type: 'text', text: err instanceof Error ? err.message : String(err) }],
		isError: true,
	};
}

const server = new McpServer({ name: 'epic-product-cost', version: '1.0.0' });

server.registerTool(
	'list_products',
	{
		title: 'List product costs',
		description:
			'List every tracked coffee product with its current cost (per 250g), the date that cost became ' +
			'effective, and the live 250g retail price for reference. Use this to see the whole catalog at a ' +
			'glance before editing a specific product.',
		inputSchema: {},
	},
	async () => {
		try {
			const products = await apiFetch('/products');
			return textResult(products);
		} catch (err) {
			return errorResult(err);
		}
	}
);

server.registerTool(
	'search_products',
	{
		title: 'Search products by name',
		description:
			'Search the catalog by product name (partial match, e.g. "peru" or "gesha"). Use this to find a ' +
			"product's exact product_id before calling get_product_cost / set_product_cost if a name is ambiguous.",
		inputSchema: {
			query: z.string().describe('Name or partial name to search for.'),
		},
	},
	async ({ query }) => {
		try {
			const matches = await apiFetch(`/products/search?q=${encodeURIComponent(query)}`);
			return textResult(matches);
		} catch (err) {
			return errorResult(err);
		}
	}
);

server.registerTool(
	'get_product_cost',
	{
		title: 'Get a product cost + history',
		description:
			'Get the current cost (per 250g, plus the calculated 500g/1kg cost) for one product, and its full ' +
			'cost-change history. Identify the product by product_id or by product_name (a unique match). Pass ' +
			'`date` (YYYY-MM-DD) to see what the cost was as of a past date instead of today.',
		inputSchema: {
			product_id: z.number().int().optional().describe('WooCommerce product ID.'),
			product_name: z.string().optional().describe('Product name (used if product_id is omitted).'),
			date: z.string().optional().describe('YYYY-MM-DD. Defaults to today.'),
		},
	},
	async ({ product_id, product_name, date }) => {
		try {
			const id = await resolveProductId({ product_id, product_name });
			const qs = date ? `?date=${encodeURIComponent(date)}` : '';
			const result = await apiFetch(`/costs/${id}${qs}`);
			return textResult(result);
		} catch (err) {
			return errorResult(err);
		}
	}
);

server.registerTool(
	'set_product_cost',
	{
		title: 'Set a product cost',
		description:
			'Record a cost for one product, effective from a given date (defaults to today). This never ' +
			'overwrites history — it adds a new dated entry (or corrects the entry for that exact date if one ' +
			'already exists), so past orders keep using whatever cost was true at the time. Cost is per 250g; ' +
			'500g/1kg cost is calculated automatically as 2x/4x. Identify the product by product_id or ' +
			'product_name (a unique match).',
		inputSchema: {
			product_id: z.number().int().optional().describe('WooCommerce product ID.'),
			product_name: z.string().optional().describe('Product name (used if product_id is omitted).'),
			cost_250g: z.number().nonnegative().describe('Cost per 250g bag, in VND.'),
			effective_date: z.string().optional().describe('YYYY-MM-DD. Defaults to today.'),
			note: z.string().optional().describe('Optional note, e.g. the source of this cost figure.'),
		},
	},
	async ({ product_id, product_name, cost_250g, effective_date, note }) => {
		try {
			const id = await resolveProductId({ product_id, product_name });
			const result = await apiFetch(`/costs/${id}`, {
				method: 'POST',
				body: JSON.stringify({ cost_250g, effective_date, note }),
			});
			return textResult(result);
		} catch (err) {
			return errorResult(err);
		}
	}
);

server.registerTool(
	'delete_cost_history_entry',
	{
		title: 'Delete a cost history entry',
		description:
			'Permanently delete one cost-history entry (e.g. to fix a mistaken value). Requires both the ' +
			'product_id and the specific entry_id (from get_product_cost\'s history list) — this cannot be undone.',
		inputSchema: {
			product_id: z.number().int().describe('WooCommerce product ID.'),
			entry_id: z.number().int().describe('The history row id to delete, from get_product_cost.'),
		},
	},
	async ({ product_id, entry_id }) => {
		try {
			const result = await apiFetch(`/costs/${product_id}/history/${entry_id}`, { method: 'DELETE' });
			return textResult(result);
		} catch (err) {
			return errorResult(err);
		}
	}
);

const transport = new StdioServerTransport();
await server.connect(transport);
