<?php
class ProductPublishService {
    private ProductWorkflowRepository $repo;
    private WooCommerceClient $wc;
    public function __construct(?ProductWorkflowRepository $repo=null,?WooCommerceClient $wc=null){$this->repo=$repo??new ProductWorkflowRepository();$this->wc=$wc??new WooCommerceClient();}

    public function publish(int $jobId,bool $approved): array {
        if(!$approved)throw new RuntimeException('انتشار نهایی فقط بعد از تأیید بصری ممکن است.');
        $job=$this->repo->get($jobId);if(($job['workflow_status']??'')!=='preview_ready')throw new RuntimeException('Workflow هنوز به مرحله پیش‌نمایش نهایی نرسیده است.');
        $images=$this->repo->images($jobId);$required=max(1,(int)getSetting('required_product_images_count','7'));
        if(count($images)!==$required)throw new RuntimeException("تعداد تصاویر نهایی باید دقیقاً {$required} باشد.");
        $imagePayload=[];
        foreach($images as $img){
            if(($img['qc_status']??'')!=='technical_pass'||empty($img['wordpress_media_id']))throw new RuntimeException('همه تصاویر باید QC فنی را پاس کرده باشند.');
            $imagePayload[]=['id'=>(int)$img['wordpress_media_id']];
        }
        $seo=(array)$job['seo_json'];if(!$seo)throw new RuntimeException('بسته SEO آماده نیست.');
        $regular=$this->price($job['regular_price']);if($regular==='')throw new RuntimeException('قیمت اصلی ثبت نشده است.');
        $cats=array_values(array_filter(array_map('intval',(array)($seo['category_ids']??[]))));if(!$cats&&!empty($job['category_id']))$cats=[(int)$job['category_id']];
        $payload=[
            'name'=>(string)($seo['name']??$job['product_name']),'type'=>'simple','status'=>'publish','regular_price'=>$regular,
            'description'=>(string)($seo['description']??''),'short_description'=>(string)($seo['short_description']??''),
            'categories'=>array_map(static fn($id)=>['id'=>$id],$cats),'images'=>$imagePayload,'attributes'=>(array)($seo['attributes']??[]),
            'meta_data'=>$this->seoMeta($seo)
        ];
        $sale=$this->price($job['sale_price']);if($sale!==''){if((float)$sale>(float)$regular)throw new RuntimeException('قیمت تخفیف نمی‌تواند بیشتر از قیمت اصلی باشد.');$payload['sale_price']=$sale;}
        if($job['stock_quantity']!==null&&$job['stock_quantity']!==''){$q=max(0,(int)$job['stock_quantity']);$payload['manage_stock']=true;$payload['stock_quantity']=$q;$payload['stock_status']=$q>0?'instock':'outofstock';}
        $id=(int)($job['product_id']??0);$res=$id>0?$this->wc->updateProduct($id,$payload):$this->wc->createProduct($payload);
        if(!empty($res['error']))throw new RuntimeException('انتشار WooCommerce ناموفق بود: '.$res['error']);
        $body=is_array($res['body']??null)?$res['body']:[];$productId=(int)($body['id']??0);if($productId<1)throw new RuntimeException('WooCommerce شناسه محصول را برنگرداند.');
        $focus=(string)($seo['focus_keyword']??$payload['name']);
        foreach($images as $img){$this->wc->updateMedia((int)$img['wordpress_media_id'],['alt_text'=>$focus.' - تصویر '.(int)$img['image_index'],'title'=>$payload['name'].' - تصویر '.(int)$img['image_index']]);}
        $result=['product_id'=>$productId,'permalink'=>(string)($body['permalink']??''),'status'=>(string)($body['status']??''),'published_at'=>date('c')];
        $this->repo->update($jobId,['product_id'=>$productId,'workflow_status'=>'published','current_step'=>'published','progress_percent'=>96,'visual_approved_at'=>date('Y-m-d H:i:s'),'completed_at'=>date('Y-m-d H:i:s'),'publish_result_json'=>$result,'error_message'=>null]);
        $this->repo->event($jobId,'info','published','Product published to WooCommerce.',['product_id'=>$productId]);
        logActivity('product_workflow_published',(string)$productId,$payload['name']);

        try {
            $verification=(new ProductPublishVerificationService($this->repo,$this->wc))->verify($jobId,$productId);
        } catch (Throwable $verificationError) {
            $verification=[
                'verified'=>false,
                'verification_status'=>'verification_error',
                'error'=>mb_substr($verificationError->getMessage(),0,500),
                'product_id'=>$productId,
                'verified_at'=>date('c'),
            ];
            $this->repo->update($jobId,[
                'workflow_status'=>'published_with_issues',
                'current_step'=>'published_with_issues',
                'progress_percent'=>100,
                'publish_verification_json'=>$verification,
                'verification_status'=>'verification_error',
                'error_message'=>'محصول منتشر شد اما Verification کامل نشد.'
            ]);
            $this->repo->event($jobId,'warning','publish_verification_error',$verification['error']);
        }

        $result['verification']=$verification;
        return $result;
    }

    private function seoMeta(array $s): array {
        return [
            ['key'=>'_yoast_wpseo_title','value'=>(string)($s['seo_title']??'')],
            ['key'=>'_yoast_wpseo_metadesc','value'=>(string)($s['meta_description']??'')],
            ['key'=>'_yoast_wpseo_focuskw','value'=>(string)($s['focus_keyword']??'')],
            ['key'=>'rank_math_title','value'=>(string)($s['seo_title']??'')],
            ['key'=>'rank_math_description','value'=>(string)($s['meta_description']??'')],
            ['key'=>'rank_math_focus_keyword','value'=>(string)($s['focus_keyword']??'')]
        ];
    }
    private function price($v): string {if($v===null||$v===''||!is_numeric($v))return'';return rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.');}
}
