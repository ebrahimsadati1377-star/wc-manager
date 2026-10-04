<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/ProductWorkflowBootstrap.php';
Auth::requireLogin();$svc=new ProductWorkflowService();
try{$d=json_decode((string)file_get_contents('php://input'),true);$id=(int)($d['job_id']??0);if($id<1)throw new RuntimeException('شناسه Workflow معتبر نیست.');
 $a=$svc->analyze($id);jsonResponse(['success'=>true,'analysis'=>$a,'attributes'=>ProductAiSeoService::buildAttributes($a),'status'=>$svc->summary($id)]);
}catch(Throwable $e){if(!empty($id))$svc->markFailed($id,$e);jsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
