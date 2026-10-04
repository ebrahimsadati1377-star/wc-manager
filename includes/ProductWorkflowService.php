<?php
class ProductWorkflowService {
    private ProductWorkflowRepository $repo;
    private ProductImageProviderManager $providers;
    public function __construct(?ProductWorkflowRepository $repo=null,?ProductImageProviderManager $providers=null){
        $this->repo=$repo??new ProductWorkflowRepository();$this->providers=$providers??new ProductImageProviderManager();
    }

    public function preflight(array $input=[]): array {
        $wc=new WooCommerceClient();
        $face=trim((string)($input['face_reference_url']??getSetting('baji_face_reference_url','')));
        $regular=trim((string)($input['regular_price']??''));$sale=trim((string)($input['sale_price']??''));
        $checks=[
            'woocommerce'=>$wc->isConfigured(),
            'wordpress_media'=>$wc->isWpConfigured(),
            'face_reference'=>$face!=='',
            'regular_price'=>$regular!==''&&is_numeric($regular)&&(float)$regular>0,
            'sale_price'=>$sale===''||(is_numeric($sale)&&(float)$sale>=0&&(float)$sale<=(float)$regular),
        ];
        $provider=$this->providers->snapshot();$selected=(string)$provider['selected'];
        return [
            'ready_for_images'=>!empty($provider['available'][$selected])&&$checks['wordpress_media'],
            'ready_for_publish'=>$checks['woocommerce']&&$checks['wordpress_media']&&$checks['regular_price']&&$checks['sale_price'],
            'checks'=>$checks,'provider'=>$provider,'face_reference_url'=>$face
        ];
    }
    public function create(array $input): array {
        $name=trim((string)($input['product_name']??''));$raw=trim((string)($input['raw_product_image_url']??''));
        $regular=$input['regular_price']??'';$sale=$input['sale_price']??'';
        $errors=[];
        if($name==='')$errors[]='نام محصول الزامی است.';
        if(!$this->isHttpUrl($raw))$errors[]='عکس خام محصول معتبر نیست.';
        if($regular===''||!is_numeric($regular)||(float)$regular<=0)$errors[]='قیمت اصلی معتبر نیست.';
        if($sale!==''&&(!is_numeric($sale)||(float)$sale<0||(float)$sale>(float)$regular))$errors[]='قیمت تخفیف معتبر نیست.';
        if($errors)throw new RuntimeException(implode(' | ',$errors));

        $face=trim((string)($input['face_reference_url']??''));
        if($face==='')$face=trim((string)getSetting('baji_face_reference_url','')); else setSetting('baji_face_reference_url',$face);
        $cats=array_values(array_filter(array_map('intval',(array)($input['category_ids']??[]))));
        $snap=$this->providers->snapshot();
        return $this->repo->create([
            'product_id'=>!empty($input['product_id'])?(int)$input['product_id']:null,
            'product_name'=>$name,'category_id'=>$cats[0]??null,'raw_product_image_url'=>$raw,'face_reference_url'=>$face,
            'provider'=>$snap['selected'],'fallback_provider'=>$snap['fallback_provider'],
            'regular_price'=>(float)$regular,'sale_price'=>$sale===''?null:(float)$sale,
            'stock_quantity'=>($input['stock_quantity']??'')===''?null:max(0,(int)$input['stock_quantity']),
            'manual_input'=>['notes'=>trim((string)($input['notes']??'')),'category_ids'=>$cats,'short_description'=>(string)($input['short_description']??''),'description'=>(string)($input['description']??'')],
            'validation'=>['valid'=>true,'checked_at'=>date('c')],'provider_snapshot'=>$snap
        ]);
    }
    public function analyze(int $jobId): array {
        $job=$this->repo->get($jobId);$manual=(array)$job['manual_input_json'];
        try{
            $a=(new ProductAiSeoService())->analyze((string)$job['raw_product_image_url'],[
                'rough_name'=>(string)$job['product_name'],'short_description'=>(string)($manual['short_description']??''),
                'description'=>(string)($manual['description']??''),'notes'=>(string)($manual['notes']??''),
                'category_ids'=>(array)($manual['category_ids']??[])
            ]);
            $a['analysis_mode']='ai';
        }catch(Throwable $e){
            $name=ProductAiSeoService::plain((string)$job['product_name'],140)?:'محصول جدید باجی';
            $a=['name'=>$name,'short_description'=>ProductAiSeoService::cleanHtml((string)($manual['short_description']??'')),
                'description'=>ProductAiSeoService::cleanHtml((string)($manual['description']??'')),'focus_keyword'=>$name,
                'seo_title'=>ProductAiSeoService::plain('خرید '.$name.' | باجی',60),
                'meta_description'=>ProductAiSeoService::plain('خرید '.$name.' از باجی؛ مشاهده تصاویر و مشخصات واقعی محصول.',160),
                'material'=>'','color'=>'','size'=>'','uses'=>'','suitable_for'=>'','care'=>'','other_description'=>'',
                'category_ids'=>(array)($manual['category_ids']??[]),'analysis_mode'=>'safe_fallback',
                'analysis_warning'=>'تحلیل AI در دسترس نبود؛ هیچ مشخصات نامعلومی حدس زده نشد.'];
            $this->repo->event($jobId,'warning','analysis_fallback',$a['analysis_warning'],['error'=>mb_substr($e->getMessage(),0,250)]);
        }
        $a['unknown_attributes']=array_values(array_filter([
            empty($a['material'])?'جنس':null,empty($a['size'])?'سایز':null,empty($a['care'])?'مراقبت و شستشو':null
        ]));
        $cats=array_values(array_filter(array_map('intval',(array)($a['category_ids']??[]))));
        $this->repo->update($jobId,['workflow_status'=>'analyzed','current_step'=>'analysis','progress_percent'=>20,
            'product_name'=>(string)($a['name']??$job['product_name']),'category_id'=>$cats[0]??$job['category_id'],'analysis_json'=>$a,'error_message'=>null]);
        $this->repo->event($jobId,'info','analysis_ready','Product analysis completed.');
        return $a;
    }
    public function buildSeo(int $jobId): array {
        $job=$this->repo->get($jobId);$a=(array)$job['analysis_json']; if(!$a)throw new RuntimeException('ابتدا تحلیل محصول را انجام دهید.');
        $name=ProductAiSeoService::plain($a['name']??$job['product_name'],140);$focus=ProductAiSeoService::plain($a['focus_keyword']??$name,120);
        $seo=[
            'name'=>$name,'short_description'=>ProductAiSeoService::cleanHtml((string)($a['short_description']??'')),
            'description'=>ProductAiSeoService::cleanHtml((string)($a['description']??'')),'focus_keyword'=>$focus,
            'seo_title'=>ProductAiSeoService::plain($a['seo_title']??('خرید '.$name.' | باجی'),60),
            'meta_description'=>ProductAiSeoService::plain($a['meta_description']??('خرید '.$name.' از باجی؛ مشاهده تصاویر و مشخصات واقعی محصول.'),160),
            'category_ids'=>array_values(array_filter(array_map('intval',(array)($a['category_ids']??[])))),
            'attributes'=>ProductAiSeoService::buildAttributes($a)
        ];
        $this->repo->update($jobId,['workflow_status'=>'seo_ready','current_step'=>'seo','progress_percent'=>80,'seo_json'=>$seo,'error_message'=>null]);
        $this->repo->event($jobId,'info','seo_ready','SEO package prepared.');
        return $seo;
    }

    public function summary(int $jobId): array {
        return ['job'=>$this->repo->get($jobId),'images'=>$this->repo->images($jobId),'events'=>$this->repo->events($jobId,30),
            'provider'=>$this->providers->snapshot(),'required_images'=>max(1,(int)getSetting('required_product_images_count','7'))];
    }
    public function markFailed(int $jobId,Throwable $e): void {
        $m=mb_substr($e->getMessage(),0,1000);$this->repo->update($jobId,['workflow_status'=>'failed','current_step'=>'failed','error_message'=>$m]);
        $this->repo->event($jobId,'error','workflow_failed',$m);
    }
    private function isHttpUrl(string $u): bool {return (bool)filter_var($u,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($u,PHP_URL_SCHEME)),['http','https'],true);}
}
