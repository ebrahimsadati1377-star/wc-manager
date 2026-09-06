<?php

class McpToolException extends RuntimeException
{
    public array $details;

    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }
}

class WcManagerMcpServer
{
    public const LATEST_PROTOCOL = '2026-07-28';
    public const LEGACY_PROTOCOL = '2025-11-25';

    private WooCommerceClient $wc;
    private BasalamClient $basalam;
    private ChatImageService $images;

    public function __construct(
        ?WooCommerceClient $wc = null,
        ?BasalamClient $basalam = null,
        ?ChatImageService $images = null
    ) {
        $this->wc = $wc ?? new WooCommerceClient();
        $this->basalam = $basalam ?? new BasalamClient();
        $this->images = $images ?? new ChatImageService();
    }

    public function dispatch(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $method = trim((string)($request['method'] ?? ''));
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        if (($request['jsonrpc'] ?? '') !== '2.0' || $method === '') {
            return $this->rpcError($id, -32600, 'Invalid Request');
        }

        if (!array_key_exists('id', $request)) {
            if ($method === 'notifications/initialized' || str_starts_with($method, 'notifications/')) {
                return null;
            }
        }

        try {
            switch ($method) {
                case 'server/discover':
                    return $this->rpcResult($id, [
                        'protocolVersion' => self::LATEST_PROTOCOL,
                        'serverInfo' => $this->serverInfo(),
                        'capabilities' => ['tools' => (object)[]],
                    ]);

                case 'initialize':
                    $requested = trim((string)($params['protocolVersion'] ?? self::LEGACY_PROTOCOL));
                    $protocol = in_array($requested, [self::LATEST_PROTOCOL, self::LEGACY_PROTOCOL], true)
                        ? $requested
                        : self::LEGACY_PROTOCOL;
                    return $this->rpcResult($id, [
                        'protocolVersion' => $protocol,
                        'capabilities' => ['tools' => ['listChanged' => false]],
                        'serverInfo' => $this->serverInfo(),
                        'instructions' => 'WC Manager MCP exposes controlled WooCommerce, WordPress media/article, and Basalam product operations. Prefer upload_and_attach_product_image for conversation-image workflows.',
                    ]);

                case 'ping':
                    return $this->rpcResult($id, (object)[]);

                case 'tools/list':
                    return $this->rpcResult($id, [
                        'tools' => $this->tools(),
                        'ttlMs' => 300000,
                        'cacheScope' => 'private',
                    ]);

                case 'tools/call':
                    $name = trim((string)($params['name'] ?? ''));
                    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                    if ($name === '') {
                        return $this->rpcError($id, -32602, 'Tool name is required.');
                    }
                    return $this->rpcResult($id, $this->callTool($name, $arguments));

                default:
                    return $this->rpcError($id, -32601, 'Method not found');
            }
        } catch (Throwable $e) {
            return $this->rpcError($id, -32603, 'Internal error', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function tools(): array
    {
        return [
            $this->tool(
                'check_connection',
                'Check WC Manager connections',
                'Checks WooCommerce and Basalam connectivity/configuration without modifying data.',
                ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                true,
                false,
                true
            ),
            $this->tool(
                'search_products',
                'Search WooCommerce products',
                'Search and filter WooCommerce products. Returns IDs, names and full WooCommerce product payloads.',
                [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string'],
                        'sku' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10],
                    ],
                    'additionalProperties' => false,
                ],
                true,
                false,
                true
            ),
            $this->tool(
                'get_product',
                'Get a WooCommerce product',
                'Fetch one WooCommerce product by numeric ID.',
                $this->idSchema('product_id'),
                true,
                false,
                true
            ),
            $this->tool(
                'create_product',
                'Create a WooCommerce product',
                'Creates a WooCommerce product. The product object accepts standard WooCommerce product fields supported by WC Manager.',
                [
                    'type' => 'object',
                    'required' => ['product'],
                    'properties' => [
                        'product' => ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => true],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                false,
                false
            ),
            $this->tool(
                'update_product',
                'Update a WooCommerce product',
                'Updates selected fields on an existing WooCommerce product.',
                [
                    'type' => 'object',
                    'required' => ['product_id', 'changes'],
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'minimum' => 1],
                        'changes' => ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => true],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                false,
                false
            ),
            $this->tool(
                'list_categories',
                'List WooCommerce product categories',
                'Lists or searches WooCommerce product categories.',
                [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string'],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                    ],
                    'additionalProperties' => false,
                ],
                true,
                false,
                true
            ),
            $this->tool(
                'upload_image',
                'Upload/import an image',
                'Imports one image into WC Manager from a ChatGPT conversation file reference, public URL, or Base64. Optionally copies it into the WordPress media library.',
                $this->imageInputSchema(true),
                false,
                false,
                false
            ),
            $this->tool(
                'attach_product_image',
                'Attach an image to a product',
                'Attaches an existing WordPress media ID or public image URL to a WooCommerce product as featured image or gallery image while preserving current images.',
                [
                    'type' => 'object',
                    'required' => ['product_id'],
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'minimum' => 1],
                        'media_id' => ['type' => 'integer', 'minimum' => 1],
                        'image_url' => ['type' => 'string', 'format' => 'uri'],
                        'position' => ['type' => 'string', 'enum' => ['featured', 'append'], 'default' => 'append'],
                        'name' => ['type' => 'string'],
                        'alt' => ['type' => 'string'],
                    ],
                    'anyOf' => [
                        ['required' => ['media_id']],
                        ['required' => ['image_url']],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                false,
                false
            ),
            $this->tool(
                'upload_and_attach_product_image',
                'Upload and attach a product image',
                'End-to-end image workflow: imports an image into WC Manager, copies it to WordPress Media, and attaches it to a WooCommerce product. Use this for images attached or generated in ChatGPT.',
                array_merge($this->imageInputSchema(false), [
                    'required' => ['product_id'],
                    'properties' => array_merge(
                        $this->imageInputSchema(false)['properties'],
                        [
                            'product_id' => ['type' => 'integer', 'minimum' => 1],
                            'position' => ['type' => 'string', 'enum' => ['featured', 'append'], 'default' => 'append'],
                            'alt' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                        ]
                    ),
                    'additionalProperties' => false,
                ]),
                false,
                false,
                false
            ),
            $this->tool(
                'search_articles',
                'Search WordPress articles',
                'Search WordPress posts/articles using the connected WordPress application-password credentials.',
                [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10],
                    ],
                    'additionalProperties' => false,
                ],
                true,
                false,
                true
            ),
            $this->tool(
                'get_article',
                'Get a WordPress article',
                'Fetch a WordPress post/article by ID.',
                $this->idSchema('post_id'),
                true,
                false,
                true
            ),
            $this->tool(
                'create_article',
                'Create a WordPress article',
                'Creates a WordPress post/article. Draft is the default status.',
                [
                    'type' => 'object',
                    'required' => ['title', 'content'],
                    'properties' => [
                        'title' => ['type' => 'string', 'minLength' => 1],
                        'content' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['draft', 'pending', 'private', 'publish'], 'default' => 'draft'],
                        'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                false,
                false
            ),
            $this->tool(
                'update_article',
                'Update a WordPress article',
                'Updates title/content/status/categories of an existing WordPress post.',
                [
                    'type' => 'object',
                    'required' => ['post_id', 'title', 'content', 'status'],
                    'properties' => [
                        'post_id' => ['type' => 'integer', 'minimum' => 1],
                        'title' => ['type' => 'string', 'minLength' => 1],
                        'content' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['draft', 'pending', 'private', 'publish']],
                        'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                false,
                false
            ),
            $this->tool(
                'list_basalam_products',
                'List Basalam vendor products',
                'Lists products from the configured Basalam vendor account.',
                [
                    'type' => 'object',
                    'properties' => [
                        'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10],
                    ],
                    'additionalProperties' => true,
                ],
                true,
                false,
                true
            ),
            $this->tool(
                'get_basalam_product',
                'Get a Basalam product',
                'Fetches a Basalam product by Basalam product ID.',
                $this->idSchema('basalam_product_id'),
                true,
                false,
                true
            ),
            $this->tool(
                'update_basalam_product',
                'Update a Basalam product',
                'Updates selected fields on an existing Basalam product through the configured vendor API.',
                [
                    'type' => 'object',
                    'required' => ['basalam_product_id', 'changes'],
                    'properties' => [
                        'basalam_product_id' => ['type' => 'integer', 'minimum' => 1],
                        'changes' => ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => true],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                false,
                false
            ),
            $this->tool(
                'sync_basalam_product',
                'Sync WooCommerce product to Basalam',
                'Runs the existing WC Manager WooCommerce-to-Basalam sync workflow for one WooCommerce product.',
                [
                    'type' => 'object',
                    'required' => ['product_id'],
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'minimum' => 1],
                        'force' => ['type' => 'boolean', 'default' => false],
                    ],
                    'additionalProperties' => false,
                ],
                false,
                false,
                false
            ),
        ];
    }

    public function callTool(string $name, array $arguments): array
    {
        try {
            switch ($name) {
                case 'check_connection':
                    $woo = $this->wc->ping();
                    $basalam = ['configured' => $this->basalam->isConfigured()];
                    if ($basalam['configured']) {
                        $basalam['ping'] = $this->normalizeUpstream($this->basalam->ping(), false);
                    }
                    return $this->toolSuccess([
                        'woocommerce' => $this->normalizeUpstream($woo, false),
                        'wordpress_media' => ['configured' => $this->wc->isWpConfigured()],
                        'basalam' => $basalam,
                    ]);

                case 'search_products':
                    $params = [];
                    foreach (['search', 'sku', 'status', 'stock_status'] as $key) {
                        if (isset($arguments[$key]) && trim((string)$arguments[$key]) !== '') {
                            $params[$key] = trim((string)$arguments[$key]);
                        }
                    }
                    $params['page'] = $this->boundedInt($arguments['page'] ?? 1, 1, 100000, 1);
                    $params['per_page'] = $this->boundedInt($arguments['per_page'] ?? 10, 1, 20, 10);
                    return $this->toolSuccess($this->normalizeUpstream($this->wc->getProducts($params)));

                case 'get_product':
                    return $this->toolSuccess($this->normalizeUpstream(
                        $this->wc->getProduct($this->positiveInt($arguments['product_id'] ?? 0, 'product_id'))
                    ));

                case 'create_product':
                    $product = is_array($arguments['product'] ?? null) ? $arguments['product'] : [];
                    $product = apiFilterArray($product, apiProductAllowedFields());
                    if (!$product) {
                        throw new McpToolException('No supported product fields were provided.');
                    }
                    return $this->toolSuccess($this->normalizeUpstream($this->wc->createProduct($product)));

                case 'update_product':
                    $productId = $this->positiveInt($arguments['product_id'] ?? 0, 'product_id');
                    $changes = is_array($arguments['changes'] ?? null) ? $arguments['changes'] : [];
                    $changes = apiFilterArray($changes, apiProductAllowedFields());
                    if (!$changes) {
                        throw new McpToolException('No supported product fields were provided.');
                    }
                    return $this->toolSuccess($this->normalizeUpstream($this->wc->updateProduct($productId, $changes)));

                case 'list_categories':
                    $params = [
                        'page' => $this->boundedInt($arguments['page'] ?? 1, 1, 100000, 1),
                        'per_page' => $this->boundedInt($arguments['per_page'] ?? 50, 1, 100, 50),
                    ];
                    if (isset($arguments['search']) && trim((string)$arguments['search']) !== '') {
                        $params['search'] = trim((string)$arguments['search']);
                    }
                    return $this->toolSuccess($this->normalizeUpstream($this->wc->getCategories($params)));

                case 'upload_image':
                    return $this->toolSuccess($this->uploadImage($arguments));

                case 'attach_product_image':
                    return $this->toolSuccess($this->attachProductImage(
                        $this->positiveInt($arguments['product_id'] ?? 0, 'product_id'),
                        isset($arguments['media_id']) ? $this->positiveInt($arguments['media_id'], 'media_id') : null,
                        trim((string)($arguments['image_url'] ?? '')),
                        (string)($arguments['position'] ?? 'append'),
                        trim((string)($arguments['name'] ?? '')),
                        trim((string)($arguments['alt'] ?? ''))
                    ));

                case 'upload_and_attach_product_image':
                    return $this->toolSuccess($this->uploadAndAttachProductImage($arguments));

                case 'search_articles':
                    $params = [
                        'page' => $this->boundedInt($arguments['page'] ?? 1, 1, 100000, 1),
                        'per_page' => $this->boundedInt($arguments['per_page'] ?? 10, 1, 20, 10),
                        'context' => 'edit',
                    ];
                    if (isset($arguments['search']) && trim((string)$arguments['search']) !== '') {
                        $params['search'] = trim((string)$arguments['search']);
                    }
                    if (isset($arguments['status']) && trim((string)$arguments['status']) !== '') {
                        $params['status'] = trim((string)$arguments['status']);
                    }
                    return $this->toolSuccess($this->normalizeUpstream(
                        $this->wc->get('wp-json/wp/v2/posts', $params)
                    ));

                case 'get_article':
                    return $this->toolSuccess($this->normalizeUpstream(
                        $this->wc->getPost($this->positiveInt($arguments['post_id'] ?? 0, 'post_id'))
                    ));

                case 'create_article':
                    $title = trim((string)($arguments['title'] ?? ''));
                    if ($title === '') {
                        throw new McpToolException('title is required.');
                    }
                    $content = (string)($arguments['content'] ?? '');
                    $status = (string)($arguments['status'] ?? 'draft');
                    $categoryIds = $this->positiveIntList($arguments['category_ids'] ?? []);
                    return $this->toolSuccess($this->normalizeUpstream(
                        $this->wc->createPostWithCategories($title, $content, $status, $categoryIds)
                    ));

                case 'update_article':
                    $postId = $this->positiveInt($arguments['post_id'] ?? 0, 'post_id');
                    $title = trim((string)($arguments['title'] ?? ''));
                    if ($title === '') {
                        throw new McpToolException('title is required.');
                    }
                    $content = (string)($arguments['content'] ?? '');
                    $status = (string)($arguments['status'] ?? 'draft');
                    $categoryIds = $this->positiveIntList($arguments['category_ids'] ?? []);
                    return $this->toolSuccess($this->normalizeUpstream(
                        $this->wc->updatePostWithCategories($postId, $title, $content, $status, $categoryIds)
                    ));

                case 'list_basalam_products':
                    if (!$this->basalam->isConfigured()) {
                        throw new McpToolException('Basalam connection is not configured.');
                    }
                    $params = $arguments;
                    $params['page'] = $this->boundedInt($arguments['page'] ?? 1, 1, 100000, 1);
                    $params['per_page'] = $this->boundedInt($arguments['per_page'] ?? 10, 1, 20, 10);
                    return $this->toolSuccess($this->normalizeUpstream($this->basalam->getVendorProducts($params)));

                case 'get_basalam_product':
                    if (!$this->basalam->isConfigured()) {
                        throw new McpToolException('Basalam connection is not configured.');
                    }
                    return $this->toolSuccess($this->normalizeUpstream(
                        $this->basalam->getProduct(
                            $this->positiveInt($arguments['basalam_product_id'] ?? 0, 'basalam_product_id')
                        )
                    ));

                case 'update_basalam_product':
                    if (!$this->basalam->isConfigured()) {
                        throw new McpToolException('Basalam connection is not configured.');
                    }
                    $basalamId = $this->positiveInt($arguments['basalam_product_id'] ?? 0, 'basalam_product_id');
                    $changes = is_array($arguments['changes'] ?? null) ? $arguments['changes'] : [];
                    if (!$changes) {
                        throw new McpToolException('changes must contain at least one field.');
                    }
                    return $this->toolSuccess($this->normalizeUpstream(
                        $this->basalam->updateProduct($basalamId, $changes)
                    ));

                case 'sync_basalam_product':
                    $productId = $this->positiveInt($arguments['product_id'] ?? 0, 'product_id');
                    $sync = new BasalamSync($this->wc, $this->basalam);
                    if (!$sync->isConfigured()) {
                        throw new McpToolException('WooCommerce/Basalam sync is not configured.');
                    }
                    $result = $sync->syncProduct($productId, (bool)($arguments['force'] ?? false));
                    if (!empty($result['error']) || (($result['success'] ?? true) === false)) {
                        throw new McpToolException(
                            (string)($result['message'] ?? $result['error'] ?? 'Basalam sync failed.'),
                            $result
                        );
                    }
                    return $this->toolSuccess($result);

                default:
                    return $this->toolError('Unknown tool: ' . $name);
            }
        } catch (ChatImageException $e) {
            return $this->toolError($e->getMessage(), [
                'error' => $e->errorCode,
                'status' => $e->status,
            ]);
        } catch (McpToolException $e) {
            return $this->toolError($e->getMessage(), $e->details);
        } catch (Throwable $e) {
            return $this->toolError('Tool execution failed.', ['message' => $e->getMessage()]);
        }
    }

    private function uploadImage(array $arguments): array
    {
        $imported = $this->images->import($arguments);
        $public = $imported;
        unset($public['local_path']);

        $result = ['wc_manager_media' => $public];
        $copyToWordPress = (bool)($arguments['copy_to_wordpress'] ?? false);
        if ($copyToWordPress) {
            $media = $this->normalizeUpstream(
                $this->wc->uploadMedia($imported['local_path'], $imported['filename'])
            );
            $result['wordpress_media'] = $media['data'];
        }

        apiLogActivity('mcp_upload_image', $imported['filename'], $imported['content_type'] . ' ' . $imported['size'] . ' bytes');
        return $result;
    }

    private function uploadAndAttachProductImage(array $arguments): array
    {
        $productId = $this->positiveInt($arguments['product_id'] ?? 0, 'product_id');
        $imported = $this->images->import($arguments);

        $wpMedia = $this->normalizeUpstream(
            $this->wc->uploadMedia($imported['local_path'], $imported['filename'])
        );
        $mediaBody = is_array($wpMedia['data'] ?? null) ? $wpMedia['data'] : [];
        $mediaId = $this->positiveInt($mediaBody['id'] ?? 0, 'wordpress_media_id');

        $name = trim((string)($arguments['name'] ?? ''));
        $alt = trim((string)($arguments['alt'] ?? ''));
        $position = (string)($arguments['position'] ?? 'append');
        $attached = $this->attachProductImage($productId, $mediaId, '', $position, $name, $alt);

        $public = $imported;
        unset($public['local_path']);

        apiLogActivity(
            'mcp_upload_attach_product_image',
            'product:' . $productId,
            'media_id=' . $mediaId . ' filename=' . $imported['filename'] . ' position=' . $position
        );

        return [
            'wc_manager_media' => $public,
            'wordpress_media' => $mediaBody,
            'product' => $attached['data'] ?? $attached,
        ];
    }

    private function attachProductImage(
        int $productId,
        ?int $mediaId,
        string $imageUrl,
        string $position,
        string $name,
        string $alt
    ): array {
        if ($mediaId === null && $imageUrl === '') {
            throw new McpToolException('Provide media_id or image_url.');
        }
        if (!in_array($position, ['featured', 'append'], true)) {
            throw new McpToolException('position must be featured or append.');
        }

        $product = $this->normalizeUpstream($this->wc->getProduct($productId));
        $body = is_array($product['data'] ?? null) ? $product['data'] : [];
        $existing = is_array($body['images'] ?? null) ? $body['images'] : [];

        $newImage = $mediaId !== null ? ['id' => $mediaId] : ['src' => $imageUrl];
        if ($name !== '') {
            $newImage['name'] = $name;
        }
        if ($alt !== '') {
            $newImage['alt'] = $alt;
        }

        $payload = [];
        foreach ($existing as $image) {
            if (!is_array($image)) {
                continue;
            }
            $id = (int)($image['id'] ?? 0);
            $src = trim((string)($image['src'] ?? ''));

            if ($mediaId !== null && $id === $mediaId) {
                continue;
            }
            if ($mediaId === null && $imageUrl !== '' && $src === $imageUrl) {
                continue;
            }

            if ($id > 0) {
                $payload[] = ['id' => $id];
            } elseif ($src !== '') {
                $payload[] = ['src' => $src];
            }
        }

        if ($position === 'featured') {
            array_unshift($payload, $newImage);
        } else {
            $payload[] = $newImage;
        }

        $updated = $this->normalizeUpstream($this->wc->updateProduct($productId, ['images' => $payload]));
        apiLogActivity(
            'mcp_attach_product_image',
            'product:' . $productId,
            ($mediaId !== null ? 'media_id=' . $mediaId : 'url=' . $imageUrl) . ' position=' . $position
        );

        return $updated;
    }

    private function normalizeUpstream(array $response, bool $throw = true): array
    {
        $error = trim((string)($response['error'] ?? ''));
        if ($error !== '') {
            if ($throw) {
                throw new McpToolException($error, [
                    'upstream_status' => (int)($response['status'] ?? 0),
                ]);
            }
            return [
                'ok' => false,
                'error' => $error,
                'upstream_status' => (int)($response['status'] ?? 0),
            ];
        }

        return [
            'ok' => true,
            'data' => $response['body'] ?? [],
            'meta' => [
                'total' => $response['headers']['total'] ?? null,
                'total_pages' => $response['headers']['total_pages'] ?? null,
                'upstream_status' => (int)($response['status'] ?? 0),
            ],
        ];
    }

    private function tool(
        string $name,
        string $title,
        string $description,
        array $inputSchema,
        bool $readOnly,
        bool $destructive,
        bool $idempotent
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
            'annotations' => [
                'title' => $title,
                'readOnlyHint' => $readOnly,
                'destructiveHint' => $destructive,
                'idempotentHint' => $idempotent,
                'openWorldHint' => true,
            ],
        ];
    }

    private function imageInputSchema(bool $includeCopyFlag): array
    {
        $properties = [
            'filename' => ['type' => 'string'],
            'openaiFileIdRefs' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 1,
                'items' => [
                    'type' => 'object',
                    'required' => ['download_link'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'id' => ['type' => 'string'],
                        'mime_type' => ['type' => 'string'],
                        'download_link' => ['type' => 'string', 'format' => 'uri'],
                    ],
                    'additionalProperties' => true,
                ],
            ],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'image_url' => ['type' => 'string', 'format' => 'uri'],
            'base64' => ['type' => 'string'],
        ];
        if ($includeCopyFlag) {
            $properties['copy_to_wordpress'] = ['type' => 'boolean', 'default' => false];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'anyOf' => [
                ['required' => ['openaiFileIdRefs']],
                ['required' => ['url']],
                ['required' => ['image_url']],
                ['required' => ['base64']],
            ],
            'additionalProperties' => false,
        ];
    }

    private function idSchema(string $key): array
    {
        return [
            'type' => 'object',
            'required' => [$key],
            'properties' => [
                $key => ['type' => 'integer', 'minimum' => 1],
            ],
            'additionalProperties' => false,
        ];
    }

    private function positiveInt($value, string $name): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($int === false) {
            throw new McpToolException($name . ' must be a positive integer.');
        }
        return (int)$int;
    }

    private function boundedInt($value, int $min, int $max, int $default): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            return $default;
        }
        return max($min, min($max, (int)$int));
    }

    private function positiveIntList($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $value) {
            $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($int !== false) {
                $out[] = (int)$int;
            }
        }
        return array_values(array_unique($out));
    }

    private function toolSuccess(array $data): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => $this->json($data),
            ]],
            'structuredContent' => $data,
            'isError' => false,
        ];
    }

    private function toolError(string $message, array $details = []): array
    {
        $payload = ['success' => false, 'message' => $message];
        if ($details) {
            $payload['details'] = $details;
        }
        return [
            'content' => [[
                'type' => 'text',
                'text' => $this->json($payload),
            ]],
            'structuredContent' => $payload,
            'isError' => true,
        ];
    }

    private function serverInfo(): array
    {
        return [
            'name' => 'bajistyle-wc-manager',
            'title' => 'BajiStyle WC Manager',
            'version' => '1.0.0',
        ];
    }

    private function rpcResult($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function rpcError($id, int $code, string $message, array $data = []): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    private function json($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    }
}
