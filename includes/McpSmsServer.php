<?php

require_once __DIR__ . '/ProductImageGenerator.php';

/** SMS + product-image extensions for WC Manager MCP. */
class WcManagerSmsMcpServer extends WcManagerMcpServer
{
    private IPPanelClient $sms;
    private ProductImageGenerator $productImages;

    public function __construct(?IPPanelClient $sms = null, ?ProductImageGenerator $productImages = null)
    {
        parent::__construct();
        $this->sms = $sms ?? new IPPanelClient();
        $this->productImages = $productImages ?? new ProductImageGenerator();
    }

    public function tools(): array
    {
        $tools = parent::tools();
        $tools[] = $this->extensionTool('get_sms_status','Check BAJI SMS status','Checks whether the server-side IPPanel connection is configured. Never returns the API key.',['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],true,false,true);
        $tools[] = $this->extensionTool('send_sms','Send an SMS with BAJI IPPanel','Sends one explicitly provided SMS to one Iranian mobile number through the configured BAJI IPPanel account.',['type'=>'object','required'=>['recipient','message'],'properties'=>['recipient'=>['type'=>'string','minLength'=>10,'maxLength'=>16,'description'=>'Iranian mobile number, e.g. 09xxxxxxxxx or +989xxxxxxxxx.'],'message'=>['type'=>'string','minLength'=>1,'maxLength'=>1000]],'additionalProperties'=>false],false,false,false);
        $tools[] = $this->extensionTool(
            'generate_product_images',
            'Generate independent BAJI product images',
            'Generates count independent product-image jobs. For count=7 it executes exactly seven separate image-generation requests; every request produces one photo, one frame, one pose and one independent file. Collages, grids, multi-panel and multi-view outputs are forbidden. Optionally uploads every generated file separately to WordPress Media.',
            [
                'type'=>'object',
                'required'=>['product_name'],
                'properties'=>[
                    'product_name'=>['type'=>'string'],
                    'product_reference_image'=>['type'=>'string','description'=>'HTTPS URL for the garment reference image.'],
                    'product_reference_file'=>['type'=>'object','description'=>'Garment reference image attached in ChatGPT; secure download metadata is injected automatically.'],
                    'product_reference_openaiFileIdRefs'=>['type'=>'array','items'=>['type'=>'object']],
                    'face_reference_image'=>['type'=>'string','description'=>'HTTPS URL for the face reference image.'],
                    'face_reference_file'=>['type'=>'object','description'=>'Face reference image attached in ChatGPT; secure download metadata is injected automatically.'],
                    'face_reference_openaiFileIdRefs'=>['type'=>'array','items'=>['type'=>'object']],
                    'count'=>['type'=>'integer'],
                    'aspect_ratio'=>['type'=>'string','enum'=>['9:16','16:9','1:1']],
                    'instructions'=>['type'=>'string'],
                    'wordpress_upload'=>['type'=>'boolean'],
                ],
                'additionalProperties'=>false,
            ],
            false,false,false
        );
        $tools[] = $this->extensionTool(
            'create_product_with_ai_images',
            'Create WooCommerce product with 1-10 AI images',
            'Creates 1-10 independent product photos from a ChatGPT-attached reference, uploads each file separately to WordPress, uses the first as featured image and the rest as gallery images, then creates the WooCommerce product.',
            [
                'type'=>'object','required'=>['product_name','price','product_reference_file'],
                'properties'=>[
                    'product_name'=>['type'=>'string','minLength'=>1], 'price'=>['type'=>'string','description'=>'WooCommerce regular price as digits, e.g. 678900'],
                    'product_reference_file'=>['type'=>'object','description'=>'Reference product image attached in ChatGPT; download metadata is injected automatically.'],
                    'face_reference_file'=>['type'=>'object','description'=>'Optional separate face reference. If omitted, product reference is reused.'],
                    'count'=>['type'=>'integer','minimum'=>1,'maximum'=>10,'default'=>7], 'aspect_ratio'=>['type'=>'string','enum'=>['9:16','16:9','1:1'],'default'=>'9:16'],
                    'instructions'=>['type'=>'string'], 'status'=>['type'=>'string','enum'=>['draft','pending','publish'],'default'=>'publish'],
                    'description'=>['type'=>'string'], 'short_description'=>['type'=>'string'], 'sku'=>['type'=>'string'],
                    'manage_stock'=>['type'=>'boolean'], 'stock_quantity'=>['type'=>'integer','minimum'=>0]
                ], 'additionalProperties'=>false
            ], false,false,false
        );
        return $tools;
    }

    public function callTool(string $name, array $arguments): array
    {
        if ($name === 'get_sms_status') return $this->extensionResult($this->sms->status());
        if ($name === 'send_sms') {
            try {
                $recipient=trim((string)($arguments['recipient']??'')); $message=trim((string)($arguments['message']??''));
                if ($recipient===''||$message==='') return $this->extensionError('recipient and message are required.');
                return $this->extensionResult($this->sms->send($recipient,$message));
            } catch (Throwable $e) { return $this->extensionError($e->getMessage()); }
        }
        if ($name === 'create_product_with_ai_images') {
            try { return $this->extensionResult($this->productImages->createProductWithImages($arguments)); }
            catch (Throwable $e) { return $this->extensionError($e->getMessage()); }
        }
        if ($name === 'generate_product_images') {
            try { return $this->extensionResult($this->productImages->generate($arguments)); }
            catch (Throwable $e) { return $this->extensionError($e->getMessage()); }
        }
        return parent::callTool($name,$arguments);
    }

    private function extensionTool(string $name,string $title,string $description,array $inputSchema,bool $readOnly,bool $destructive,bool $idempotent): array
    {
        $fileParams=[];
        $properties = $inputSchema['properties'] ?? [];
        if ($properties instanceof stdClass) $properties = (array)$properties;
        if (!is_array($properties)) $properties = [];
        foreach (['product_reference_file','face_reference_file'] as $fileParam) if (isset($properties[$fileParam])) $fileParams[]=$fileParam;
        return ['name'=>$name,'description'=>$description,'inputSchema'=>$inputSchema,'_meta'=>$fileParams ? ['openai/fileParams'=>$fileParams] : (object)[],'annotations'=>['title'=>$title,'readOnlyHint'=>$readOnly,'destructiveHint'=>$destructive,'idempotentHint'=>$idempotent,'openWorldHint'=>true]];
    }

    private function extensionResult(array $payload): array
    {
        return ['content'=>[['type'=>'text','text'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],'structuredContent'=>$payload,'isError'=>false];
    }

    private function extensionError(string $message): array
    {
        return ['content'=>[['type'=>'text','text'=>$message]],'structuredContent'=>['error'=>$message],'isError'=>true];
    }
}
