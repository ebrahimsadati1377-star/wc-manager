<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/ProductWorkflowBootstrap.php';
Auth::requireLogin();
try{$d=json_decode((string)file_get_contents('php://input'),true);if(!is_array($d))throw new RuntimeException('داده ورودی معتبر نیست.');
 $j=(new ProductWorkflowService())->create($d);jsonResponse(['success'=>true,'job'=>$j]);
}catch(Throwable $e){jsonResponse(['success'=>false,'message'=>$e->getMessage()],422);}
