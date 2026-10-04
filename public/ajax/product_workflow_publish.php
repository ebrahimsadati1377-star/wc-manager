<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/ProductWorkflowBootstrap.php';
Auth::requireLogin();
try{$d=json_decode((string)file_get_contents('php://input'),true);$id=(int)($d['job_id']??0);if($id<1)throw new RuntimeException('شناسه Workflow معتبر نیست.');
 $r=(new ProductPublishService())->publish($id,!empty($d['approved']));jsonResponse(['success'=>true,'result'=>$r,'status'=>(new ProductWorkflowService())->summary($id)]);
}catch(Throwable $e){jsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
