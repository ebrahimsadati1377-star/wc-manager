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
                'required'=>['product_name','product_reference_image','face_reference_image'],
                'properties'=>[
                    'product_name'=>['type'=>'string'],
                    'product_reference_image'=>['type'=>'string','description'=>'HTTPS URL for the garment reference image.'],
                    'face_reference_image'=>['type'=>'string','description'=>'HTTPS URL for the model face reference image.'],
                    'count'=>['type'=>'integer'],
                    'aspect_ratio'=>['type'=>'string','enum'=>['9:16','16:9','1:1']],
                    'instructions'=>['type'=>'string'],
                    'wordpress_upload'=>['type'=>'boolean'],
                ],
                'additionalProperties'=>false,
            ],
            false,false,false
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
        if ($name === 'generate_product_images') {
            try { return $this->extensionResult($this->productImages->generate($arguments)); }
            catch (Throwable $e) { return $this->extensionError($e->getMessage()); }
        }
        return parent::callTool($name,$arguments);
    }

    private function extensionTool(string $name,string $title,string $description,array $inputSchema,bool $readOnly,bool $destructive,bool $idempotent): array
    {
        return ['name'=>$name,'description'=>$description,'inputSchema'=>$inputSchema,'_meta'=>(object)[],'annotations'=>['title'=>$title,'readOnlyHint'=>$readOnly,'destructiveHint'=>$destructive,'idempotentHint'=>$idempotent,'openWorldHint'=>true]];
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
