<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/ProductWorkflowBootstrap.php';
Auth::requireLogin();
try{$d=json_decode((string)file_get_contents('php://input'),true);$id=(int)($d['job_id']??0);if($id<1)throw new RuntimeException('شناسه Workflow معتبر نیست.');
 $repo=new ProductWorkflowRepository();$job=$repo->get($id);$qc=(array)$job['qc_json'];if(empty($qc['all_technical_pass']))throw new RuntimeException('ابتدا QC تصاویر باید پاس شود.');
 $seo=(new ProductWorkflowService())->buildSeo($id);jsonResponse(['success'=>true,'seo'=>$seo,'status'=>(new ProductWorkflowService())->summary($id)]);
}catch(Throwable $e){jsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
