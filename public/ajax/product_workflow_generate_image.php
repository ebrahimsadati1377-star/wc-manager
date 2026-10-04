<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/ProductWorkflowBootstrap.php';
Auth::requireLogin(); set_time_limit(330);
$repo=new ProductWorkflowRepository();$providers=new ProductImageProviderManager();$token=bin2hex(random_bytes(16));
try{
 $d=json_decode((string)file_get_contents('php://input'),true);$jobId=(int)($d['job_id']??0);$index=(int)($d['index']??0);$force=!empty($d['force']);
 $required=max(1,(int)getSetting('required_product_images_count','7'));if($jobId<1||$index<1||$index>$required)throw new RuntimeException('شماره Workflow یا تصویر معتبر نیست.');
 if(!$repo->acquireLock($jobId,$token,330))throw new RuntimeException('یک پردازش دیگر برای این Workflow در حال اجراست.');
 $job=$repo->get($jobId);if(empty($job['analysis_json']))throw new RuntimeException('ابتدا تحلیل محصول را انجام دهید.');
 $existing=array_values(array_filter($repo->images($jobId),static fn($r)=>(int)$r['image_index']===$index&&($r['generation_status']??'')==='ready'&&!empty($r['wordpress_media_id'])&&!empty($r['public_url'])));
 if($existing&&!$force){$r=$existing[0];$repo->releaseLock($jobId,$token);jsonResponse(['success'=>true,'reused'=>true,'image'=>['id'=>(int)$r['wordpress_media_id'],'src'=>(string)$r['public_url'],'index'=>$index]]);}
 if($force&&$existing){
   $retryLimit=max(0,(int)getSetting('product_qc_retry_limit','2'));
   if((int)$existing[0]['retry_count'] >= $retryLimit) throw new RuntimeException('حداکثر تعداد بازسازی این تصویر مصرف شده است.');
 }
 $provider=$providers->assertReady((string)($d['provider']??$job['provider']));if($provider==='manual')throw new RuntimeException('حالت دستی را از گالری محصول استفاده کنید.');
 $a=(array)$job['analysis_json'];$face=trim((string)$job['face_reference_url']);if($face==='')$face=trim((string)getSetting('baji_face_reference_url',''));if($face==='')throw new RuntimeException('چهره مرجع BAJI ثبت نشده است.');
 $name=(string)($a['name']??$job['product_name']);$backgrounds=['clean premium studio','soft daylight interior','minimal fashion boutique','neutral architectural background','bright editorial studio','clean urban fashion setting','warm minimal studio'];$bg=$backgrounds[($index-1)%count($backgrounds)];
 $style='Keep the garment fully visible and unobstructed.';if(str_contains($name,'شومیز')||str_contains($name,'تیشرت')||str_contains($name,'پیراهن'))$style.=' Pair with a tasteful different pant or skirt.';elseif(str_contains($name,'شلوار'))$style.=' Pair with a simple different top.';
 $instructions='BAJI professional ecommerce catalog photography. Preserve the exact garment from the product reference. Do not change color, pattern, fabric appearance, seams, pockets, buttons, zipper, collar, hood, length, proportions or silhouette. Keep the BAJI face identity from the face reference. One model only, one frame only, no collage, no grid, no text. Photorealistic anatomy and fabric. '.$style.' Background: '.$bg.'.';
 $args=['product_name'=>$name,'product_reference_image'=>(string)$job['raw_product_image_url'],'face_reference_image'=>$face,'aspect_ratio'=>'9:16','wordpress_upload'=>true,'provider'=>$provider,'instructions'=>$instructions];
 $started=microtime(true);$gen=new ProductImageGenerator();
 try{$out=$gen->generateSingle($args,$index,$required);}catch(Throwable $e){$fallback=$providers->fallbackAfterFailure($provider);if($fallback===null)throw $e;$repo->event($jobId,'warning','provider_fallback','Primary provider failed; fallback used.',['from'=>$provider,'to'=>$fallback,'image'=>$index]);$args['provider']=$fallback;$out=$gen->generateSingle($args,$index,$required);$provider=$fallback;}
 $ms=(int)round((microtime(true)-$started)*1000);$media=(array)($out['job']['wordpress_media']??[]);$mediaId=(int)($media['id']??0);$src=trim((string)($media['source_url']??''));if($mediaId<1||$src==='')throw new RuntimeException('تصویر تولید شد اما در WordPress Media ثبت نشد.');
 $old=array_values(array_filter($repo->images($jobId),static fn($r)=>(int)$r['image_index']===$index));$retry=$old?(int)$old[0]['retry_count']+($force?1:0):0;
 $repo->upsertImage($jobId,$index,['provider'=>$provider,'generation_status'=>'ready','public_url'=>$src,'wordpress_media_id'=>$mediaId,'qc_status'=>'pending','retry_count'=>$retry,'prompt_text'=>$instructions,'generation_ms'=>$ms,'error_message'=>null]);
 $rows=$repo->images($jobId);$ready=count(array_filter($rows,static fn($r)=>($r['generation_status']??'')==='ready'));$progress=20+(int)floor(min($required,$ready)/$required*35);
 $repo->update($jobId,['workflow_status'=>$ready===$required?'images_ready':'images_generating','current_step'=>'images','progress_percent'=>$progress,'provider'=>$provider,'error_message'=>null]);
 $repo->event($jobId,'info',$force?'image_regenerated':'image_generated','Image ready.',['index'=>$index,'provider'=>$provider,'media_id'=>$mediaId,'generation_ms'=>$ms]);
 $repo->releaseLock($jobId,$token);
 jsonResponse(['success'=>true,'image'=>['id'=>$mediaId,'src'=>$src,'index'=>$index],'provider'=>$provider,'ready_count'=>$ready,'required_count'=>$required,'generation_ms'=>$ms]);
}catch(Throwable $e){if(!empty($jobId))$repo->event($jobId,'error','image_generation_failed',$e->getMessage(),['index'=>$index??0]);if(!empty($jobId)&&isset($token))$repo->releaseLock($jobId,$token);jsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
