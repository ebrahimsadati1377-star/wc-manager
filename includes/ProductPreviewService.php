<?php
class ProductPreviewService {
    private ProductWorkflowRepository $repo;
    private ProductImageQualityService $quality;
    public function __construct(?ProductWorkflowRepository $repo=null,?ProductImageQualityService $quality=null){$this->repo=$repo??new ProductWorkflowRepository();$this->quality=$quality??new ProductImageQualityService($this->repo);}
    public function build(int $jobId): array {
        $job=$this->repo->get($jobId);$images=$this->repo->images($jobId);$required=max(1,(int)getSetting('required_product_images_count','7'));
        if(count($images)!==$required)throw new RuntimeException("پیش‌نمایش به {$required} تصویر کامل نیاز دارد.");
        foreach($images as $img){
            if(($img['generation_status']??'')!=='ready'||empty($img['wordpress_media_id']))throw new RuntimeException('همه تصاویر باید آماده و در WordPress ثبت شده باشند.');
            if(($img['qc_status']??'')!=='technical_pass')throw new RuntimeException('همه تصاویر باید QC فنی را پاس کنند.');
        }
        $seo=(array)$job['seo_json'];if(!$seo||empty($seo['seo_title']))throw new RuntimeException('SEO هنوز آماده نیست.');
        $preview=[
            'job_id'=>$jobId,'product_id'=>$job['product_id']?(int)$job['product_id']:null,'name'=>(string)($seo['name']??$job['product_name']),
            'regular_price'=>$job['regular_price'],'sale_price'=>$job['sale_price'],'stock_quantity'=>$job['stock_quantity'],
            'category_ids'=>(array)($seo['category_ids']??[]),'short_description'=>(string)($seo['short_description']??''),
            'description'=>(string)($seo['description']??''),'seo_title'=>(string)($seo['seo_title']??''),
            'meta_description'=>(string)($seo['meta_description']??''),'focus_keyword'=>(string)($seo['focus_keyword']??''),
            'attributes'=>(array)($seo['attributes']??[]),'images'=>$images,
            'manual_visual_review_required'=>true,'publish_locked'=>true
        ];
        $this->repo->update($jobId,['workflow_status'=>'preview_ready','current_step'=>'preview','progress_percent'=>90,'error_message'=>null]);
        $this->repo->event($jobId,'info','preview_ready','Final preview prepared.');
        return $preview;
    }
}
