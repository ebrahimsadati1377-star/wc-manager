<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/ProductWorkflowBootstrap.php';
Auth::requireLogin();

try {
    $d=json_decode((string)file_get_contents('php://input'),true);
    $jobId=(int)($d['job_id']??0);
    $items=(array)($d['images']??[]);
    $required=max(1,(int)getSetting('required_product_images_count','7'));
    if($jobId<1) throw new RuntimeException('شناسه Workflow معتبر نیست.');
    if(count($items)!==$required) throw new RuntimeException("گالری باید دقیقاً {$required} تصویر داشته باشد.");

    $repo=new ProductWorkflowRepository();
    $repo->get($jobId);
    $wc=new WooCommerceClient();
    $base=rtrim(uploadUrlBase(),'/').'/uploads/products/';
    $idx=1;

    foreach($items as $item){
        $mediaId=(int)($item['id']??0);
        $src=trim((string)($item['src']??''));

        if($mediaId>0){
            $res=$wc->get('wp-json/wp/v2/media/'.$mediaId);
            if(!empty($res['error'])) throw new RuntimeException('تصویر '.$idx.' در WordPress Media پیدا نشد.');
            $src=trim((string)($res['body']['source_url']??$src));
        } else {
            if($src==='' || !str_starts_with($src,$base)) {
                throw new RuntimeException('تصویر '.$idx.' باید داخل WC Manager آپلود شده باشد یا شناسه WordPress داشته باشد.');
            }
            $relative=rawurldecode(substr($src,strlen($base)));
            if($relative==='' || str_contains($relative,'..')) throw new RuntimeException('مسیر تصویر '.$idx.' معتبر نیست.');
            $local=rtrim(UPLOAD_DIR,'/\\').'/'.$relative;
            if(!is_file($local) || !is_readable($local)) throw new RuntimeException('فایل تصویر '.$idx.' روی سرور پیدا نشد.');
            $up=$wc->uploadMedia($local,basename($local));
            if(!empty($up['error']) || empty($up['data']['id'])) throw new RuntimeException('آپلود تصویر '.$idx.' در WordPress Media ناموفق بود.');
            $mediaId=(int)$up['data']['id'];
            $src=trim((string)($up['data']['source_url']??''));
        }

        if($mediaId<1 || $src==='') throw new RuntimeException('تصویر '.$idx.' کامل ثبت نشد.');
        $repo->upsertImage($jobId,$idx,[
            'provider'=>'manual','generation_status'=>'ready',
            'public_url'=>$src,'wordpress_media_id'=>$mediaId,
            'qc_status'=>'pending','retry_count'=>0
        ]);
        $idx++;
    }

    $repo->update($jobId,[
        'workflow_status'=>'images_ready',
        'current_step'=>'images',
        'progress_percent'=>55,
        'provider'=>'manual',
        'error_message'=>null
    ]);
    $repo->event($jobId,'info','gallery_adopted','Seven gallery images attached to workflow.');
    jsonResponse(['success'=>true,'status'=>(new ProductWorkflowService())->summary($jobId)]);
} catch(Throwable $e){
    jsonResponse(['success'=>false,'message'=>$e->getMessage()],422);
}
