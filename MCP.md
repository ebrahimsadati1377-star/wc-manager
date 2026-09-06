# WC Manager MCP

WC Manager now exposes a remote, authenticated Model Context Protocol endpoint for WooCommerce, WordPress media/articles, and Basalam operations.

## Endpoint

```text
https://manage.bajistyle.ir/mcp.php
```

The endpoint is stateless and supports:

- MCP `2026-07-28` request/response flow (`server/discover`, `tools/list`, `tools/call`)
- legacy `2025-11-25` initialization for compatible clients
- JSON responses over HTTP POST

## Authentication

The MCP endpoint reuses WC Manager's existing ChatGPT API bearer tokens.

```http
Authorization: Bearer wcm_...
```

No WooCommerce, WordPress, or Basalam secret is exposed to the MCP client. Those credentials remain stored in WC Manager.

## Tool catalog

### Connectivity

- `check_connection`

### WooCommerce products

- `search_products`
- `get_product`
- `create_product`
- `update_product`
- `list_categories`

### Images / media

- `upload_image`
- `attach_product_image`
- `upload_and_attach_product_image`

`upload_and_attach_product_image` is the preferred end-to-end path. It:

1. accepts `openaiFileIdRefs`, a public URL, or Base64;
2. permanently stores the image under WC Manager's `/uploads/chatgpt/` path;
3. copies the file into the WordPress Media Library;
4. receives the WordPress media ID;
5. attaches that media item to the requested WooCommerce product while preserving existing images.

Supported image types: JPEG, PNG, WebP. Maximum size: 10 MiB.

### WordPress articles

- `search_articles`
- `get_article`
- `create_article`
- `update_article`

### Basalam

- `list_basalam_products`
- `get_basalam_product`
- `update_basalam_product`
- `sync_basalam_product`

No product/media delete tools are exposed in the first MCP release.

## MCP smoke tests

Replace `$TOKEN` with an active WC Manager ChatGPT API token.

### Discover server

```bash
curl -sS https://manage.bajistyle.ir/mcp.php \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: server/discover' \
  --data '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{}}'
```

### List tools

```bash
curl -sS https://manage.bajistyle.ir/mcp.php \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/list' \
  --data '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

### Search products

```bash
curl -sS https://manage.bajistyle.ir/mcp.php \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/call' \
  -H 'Mcp-Name: search_products' \
  --data '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search_products","arguments":{"search":"رکابی","per_page":5}}}'
```

## Image workflow example

A client can call `upload_and_attach_product_image` with one of the accepted image-source forms.

Example with a public URL:

```json
{
  "jsonrpc": "2.0",
  "id": 4,
  "method": "tools/call",
  "params": {
    "name": "upload_and_attach_product_image",
    "arguments": {
      "product_id": 123,
      "image_url": "https://example.com/product.jpg",
      "filename": "product.jpg",
      "position": "featured",
      "alt": "تصویر محصول"
    }
  }
}
```

A successful result contains three checkpoints:

- `wc_manager_media`: permanent WC Manager URL and image metadata
- `wordpress_media`: WordPress media object including `id` and `source_url`
- `product`: updated WooCommerce product object with the image attached

## Deployment

Production deploys automatically from `main` through the existing GitHub Actions deployment workflow. The `public/uploads/` directory remains server-owned and is excluded from rsync replacement, so imported media is preserved across deployments.
