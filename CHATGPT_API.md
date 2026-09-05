# ChatGPT API for WC Manager

This API lets ChatGPT manage WooCommerce products through WC Manager without exposing the WooCommerce Consumer Key/Secret.

## Security model

- External callers authenticate with `Authorization: Bearer <token>`.
- Tokens generated in the admin UI are shown once and only their SHA-256 hash is stored in the `settings` table.
- Alternatively set `WC_MANAGER_API_TOKEN` in the server environment. When present, the environment token takes precedence.
- WooCommerce credentials remain inside WC Manager.
- Destructive product deletion is intentionally not exposed in the OpenAPI schema.
- API mutations are written to the existing Activity Log with `chatgpt_*` actions.

## Enable

1. Deploy the `public/api/` endpoints, `includes/ChatGPTApi.php`, `public/chatgpt.php`, and `public/openapi-chatgpt.yaml`.
2. Sign in to WC Manager as admin.
3. Open `https://manage.bajistyle.ir/chatgpt.php`.
4. Generate a token and copy it immediately.
5. Test:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://manage.bajistyle.ir/api/health.php
```

Expected response:

```json
{"success":true,"service":"wc-manager-chatgpt-api","woocommerce":"connected"}
```

## OpenAPI

Schema URL:

`https://manage.bajistyle.ir/openapi-chatgpt.yaml`

Server base URL in the schema:

`https://manage.bajistyle.ir/api`

## Supported actions

- Check connection
- Upload an image directly from the current ChatGPT conversation
- Upload/import an image from a public URL or Base64
- List/search products
- Get a product
- Create a product
- Update a product
- List/create/update variations
- List/create/update product categories
- List global attributes and their terms

## ChatGPT conversation image upload

GPT Actions can include conversation files in POST requests through the special `openaiFileIdRefs` field. This also covers images generated inside ChatGPT when the generated image is available as a conversation file.

`POST /api/media.php` accepts exactly one file reference and permanently copies the image into WC Manager before the short-lived ChatGPT download URL expires. The endpoint also keeps the existing `url`, `image_url`, and `base64` fallbacks for compatibility.

Example payload shape received from ChatGPT:

```json
{
  "openaiFileIdRefs": [
    {
      "name": "generated-image.png",
      "id": "file_...",
      "mime_type": "image/png",
      "download_link": "https://..."
    }
  ]
}
```

Successful response:

```json
{
  "success": true,
  "url": "https://manage.bajistyle.ir/uploads/chatgpt/generated-image-....png",
  "filename": "generated-image-....png",
  "content_type": "image/png",
  "size": 123456,
  "width": 1024,
  "height": 1024
}
```

The returned `url` can then be passed directly in WooCommerce's standard product `images` payload.

## Example product creation

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  https://manage.bajistyle.ir/api/products.php \
  -d '{
    "name":"شلوار سنبادی",
    "type":"simple",
    "status":"draft",
    "regular_price":"2950000",
    "sale_price":"1899000",
    "manage_stock":true,
    "stock_quantity":1
  }'
```

## Notes

Product image URLs can be supplied through WooCommerce's standard `images` payload. The URL must be publicly reachable by the WordPress/WooCommerce server.
