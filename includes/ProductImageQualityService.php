<?php
class ProductImageQualityService {
    private ProductWorkflowRepository $repo;
    public function __construct(?ProductWorkflowRepository $repo=null){$this->repo=$repo??new ProductWorkflowRepository();}

    public function run(int $jobId): array {
        $required=max(1,(int)getSetting('required_product_images_count','7'));$images=$this->repo->images($jobId);
        if(count($images)!==$required)throw new RuntimeException("برای QC دقیقاً {$required} تصویر لازم است.");
        $checks=[];$hashes=[];$all=true;
        foreach($images as $img){
            try{$r=$this->inspect((string)$img['public_url']);}catch(Throwable $e){$r=['technical_pass'=>false,'score'=>0,'reason'=>$e->getMessage(),'checksum_sha256'=>''];}
            $hash=(string)($r['checksum_sha256']??'');
            if($hash!==''&&isset($hashes[$hash])){$r['technical_pass']=false;$r['score']=min(40,(int)$r['score']);$r['reason']='این تصویر با تصویر '.$hashes[$hash].' یکسان است.';}
            elseif($hash!=='')$hashes[$hash]=(int)$img['image_index'];
            $all=$all&&!empty($r['technical_pass']);
            $this->repo->upsertImage($jobId,(int)$img['image_index'],array_merge($img,['qc_status'=>$r['technical_pass']?'technical_pass':'failed','qc_score'=>$r['score'],'technical_qc_json'=>$r,'checksum_sha256'=>$hash?:null]));
            $checks[]=['image_index'=>(int)$img['image_index']]+$r;
        }
        $summary=['all_technical_pass'=>$all,'manual_visual_review_required'=>true,'checks'=>$checks,'checked_at'=>date('c')];
        $this->repo->update($jobId,['workflow_status'=>$all?'qc_passed':'needs_review','current_step'=>$all?'quality_control':'needs_review','progress_percent'=>$all?68:58,'qc_json'=>$summary,'error_message'=>$all?null:'حداقل یک تصویر QC فنی را پاس نکرد.']);
        $this->repo->event($jobId,$all?'info':'warning','technical_qc',$all?'All images passed technical QC.':'One or more images failed technical QC.');
        return $summary;
    }

    public function inspectReference(string $url,string $kind): array {
        try{$d=$this->download($url);$i=@getimagesizefromstring($d);if(!$i)throw new RuntimeException('تصویر مرجع معتبر نیست.');
            $mw=$kind==='face'?600:700;$mh=$kind==='face'?800:900;
            return ['reference_pass'=>(int)$i[0]>=$mw&&(int)$i[1]>=$mh,'width'=>(int)$i[0],'height'=>(int)$i[1],'file_size'=>strlen($d),'recommended_min'=>$mw.'x'.$mh];
        }catch(Throwable $e){return ['reference_pass'=>false,'width'=>0,'height'=>0,'reason'=>$e->getMessage()];}
    }

    public function inspect(string $url): array {
        $raw=$this->download($url);$i=@getimagesizefromstring($raw);if(!$i)throw new RuntimeException('فایل تصویر معتبر نیست.');
        $w=(int)$i[0];$h=(int)$i[1];$ratio=$h?($w/$h):0;$ratioOk=abs($ratio-(9/16))<=0.012;$portrait=$h>$w;$res=$w>=900&&$h>=1600;$bytes=strlen($raw)>=50000;
        $score=($ratioOk?30:0)+($portrait?15:0)+($res?35:0)+($bytes?20:0);$pass=$ratioOk&&$portrait&&$res&&$bytes;
        return ['technical_pass'=>$pass,'score'=>$score,'width'=>$w,'height'=>$h,'file_size'=>strlen($raw),'ratio_9_16_ok'=>$ratioOk,'portrait_ok'=>$portrait,'resolution_ok'=>$res,'bytes_ok'=>$bytes,'checksum_sha256'=>hash('sha256',$raw),'reason'=>$pass?'':'نسبت، رزولوشن یا حجم تصویر استاندارد نیست.'];
    }

    private function download(string $url): string {
        if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')throw new RuntimeException('آدرس HTTPS تصویر معتبر نیست.');
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));$allowed=array_filter([strtolower((string)parse_url((string)getSetting('store_url',''),PHP_URL_HOST)),'manage.bajistyle.ir']);
        if($host===''||!in_array($host,$allowed,true))throw new RuntimeException('دامنه تصویر برای QC مجاز نیست.');
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>35,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'BAJI-WC-Manager/ProductQC']);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
        if(!is_string($raw)||$status<200||$status>=300)throw new RuntimeException('دریافت تصویر ناموفق بود: '.($err?:'HTTP '.$status));
        if(strlen($raw)>12582912)throw new RuntimeException('فایل تصویر بیش از حد بزرگ است.');
        return $raw;
    }
}
