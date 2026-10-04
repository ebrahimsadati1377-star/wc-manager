<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/ProductWorkflowBootstrap.php';
Auth::requireLogin();
try{
 $data=json_decode((string)file_get_contents('php://input'),true);if(!is_array($data))$data=[];
 $svc=new ProductWorkflowService();$r=$svc->preflight($data);
 jsonResponse(['success'=>true,'preflight'=>$r]);
}catch(Throwable $e){jsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
