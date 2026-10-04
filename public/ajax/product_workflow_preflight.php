<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/ProductWorkflowBootstrap.php';
Auth::requireLogin();
try{
 $data=json_decode((string)file_get_contents('php://input'),true);if(!is_array($data))$data=[];
 $svc=new ProductWorkflowService();$r=$svc->preflight($data);$face=(string)($r['face_reference_url']??'');
 $r['face_reference_quality']=$face!==''?(new ProductImageQualityService())->inspectReference($face,'face'):['reference_pass'=>false,'reason'=>'چهره مرجع BAJI ثبت نشده است.'];
 jsonResponse(['success'=>true,'preflight'=>$r]);
}catch(Throwable $e){jsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
