<?php
class ProductWorkflowRepository {
    private PDO $db;
    public function __construct(?PDO $db=null){$this->db=$db??Database::get();}

    public function get(int $id): array {
        $s=$this->db->prepare('SELECT * FROM ai_product_jobs WHERE id=?');
        $s->execute([$id]); $r=$s->fetch();
        if(!$r) throw new RuntimeException('Workflow پیدا نشد.');
        foreach(['manual_input_json','analysis_json','seo_json','qc_json','publish_result_json','validation_json','provider_snapshot_json'] as $k){
            $r[$k]=$this->decode($r[$k]??null);
        }
        return $r;
    }

    public function create(array $d): array {
        $s=$this->db->prepare('INSERT INTO ai_product_jobs
        (product_id,workflow_status,product_name,category_id,raw_product_image_url,face_reference_url,provider,fallback_provider,regular_price,sale_price,stock_quantity,manual_input_json,progress_percent,current_step,validation_json,provider_snapshot_json,started_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        $s->execute([
            $d['product_id']??null,'draft_input',$d['product_name']??'',$d['category_id']??null,
            $d['raw_product_image_url']??null,$d['face_reference_url']??null,$d['provider']??'arena',$d['fallback_provider']??null,
            $d['regular_price']??null,$d['sale_price']??null,$d['stock_quantity']??null,$this->encode($d['manual_input']??[]),
            5,'input',$this->encode($d['validation']??[]),$this->encode($d['provider_snapshot']??[])
        ]);
        $id=(int)$this->db->lastInsertId();
        $this->event($id,'info','job_created','Workflow created');
        return $this->get($id);
    }
    public function update(int $id,array $fields): void {
        $allowed=['product_id','workflow_status','product_name','category_id','raw_product_image_url','face_reference_url','provider','fallback_provider','regular_price','sale_price','stock_quantity','manual_input_json','analysis_json','seo_json','qc_json','publish_result_json','error_message','retry_count','progress_percent','current_step','validation_json','provider_snapshot_json','visual_approved_at','completed_at','lock_token','lock_expires_at','revision'];
        $set=[];$params=[];
        foreach($fields as $k=>$v){
            if(!in_array($k,$allowed,true)) continue;
            if(str_ends_with($k,'_json')&&is_array($v)) $v=$this->encode($v);
            $set[]=$k.'=?'; $params[]=$v;
        }
        if(!$set) return;
        $set[]='revision=revision+1'; $params[]=$id;
        $s=$this->db->prepare('UPDATE ai_product_jobs SET '.implode(',',$set).' WHERE id=?');
        $s->execute($params);
    }

    public function images(int $jobId): array {
        $s=$this->db->prepare('SELECT * FROM ai_product_job_images WHERE job_id=? ORDER BY image_index');
        $s->execute([$jobId]); $rows=$s->fetchAll();
        foreach($rows as &$r){
            foreach(['qc_json','technical_qc_json','visual_qc_json'] as $k){$r[$k]=$this->decode($r[$k]??null);}
        }
        return $rows;
    }
    public function upsertImage(int $jobId,int $index,array $d): void {
        $s=$this->db->prepare('INSERT INTO ai_product_job_images
        (job_id,image_index,provider,generation_status,local_path,public_url,wordpress_media_id,qc_status,qc_score,qc_json,retry_count,prompt_text,technical_qc_json,visual_qc_json,checksum_sha256,generation_ms,approved_at,error_message)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE provider=VALUES(provider),generation_status=VALUES(generation_status),local_path=VALUES(local_path),public_url=VALUES(public_url),wordpress_media_id=VALUES(wordpress_media_id),qc_status=VALUES(qc_status),qc_score=VALUES(qc_score),qc_json=VALUES(qc_json),retry_count=VALUES(retry_count),prompt_text=VALUES(prompt_text),technical_qc_json=VALUES(technical_qc_json),visual_qc_json=VALUES(visual_qc_json),checksum_sha256=VALUES(checksum_sha256),generation_ms=VALUES(generation_ms),approved_at=VALUES(approved_at),error_message=VALUES(error_message)');
        $s->execute([$jobId,$index,$d['provider']??'',$d['generation_status']??'pending',$d['local_path']??null,$d['public_url']??null,$d['wordpress_media_id']??null,$d['qc_status']??'pending',$d['qc_score']??null,$this->encode($d['qc_json']??[]),(int)($d['retry_count']??0),$d['prompt_text']??null,$this->encode($d['technical_qc_json']??[]),$this->encode($d['visual_qc_json']??[]),$d['checksum_sha256']??null,$d['generation_ms']??null,$d['approved_at']??null,$d['error_message']??null]);
    }

    public function event(int $jobId,string $level,string $type,string $message='',array $context=[]): void {
        $s=$this->db->prepare('INSERT INTO ai_product_job_events(job_id,level,event_type,message,context_json) VALUES (?,?,?,?,?)');
        $s->execute([$jobId,$level,$type,$message,$this->encode($context)]);
    }
    public function events(int $jobId,int $limit=40): array {
        $limit=max(1,min(100,$limit));
        $s=$this->db->prepare('SELECT * FROM ai_product_job_events WHERE job_id=? ORDER BY id DESC LIMIT '.$limit);
        $s->execute([$jobId]); $rows=array_reverse($s->fetchAll());
        foreach($rows as &$r){$r['context_json']=$this->decode($r['context_json']??null);}
        return $rows;
    }

    public function acquireLock(int $jobId,string $token,int $seconds=330): bool {
        $seconds=max(30,min(900,$seconds));
        $s=$this->db->prepare('UPDATE ai_product_jobs SET lock_token=?,lock_expires_at=DATE_ADD(NOW(),INTERVAL '.$seconds.' SECOND) WHERE id=? AND (lock_token IS NULL OR lock_expires_at IS NULL OR lock_expires_at<NOW())');
        $s->execute([$token,$jobId]); return $s->rowCount()===1;
    }
    public function releaseLock(int $jobId,string $token): void {
        $s=$this->db->prepare('UPDATE ai_product_jobs SET lock_token=NULL,lock_expires_at=NULL WHERE id=? AND lock_token=?');
        $s->execute([$jobId,$token]);
    }

    private function encode($v): string {$j=json_encode(is_array($v)?$v:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($j)?$j:'{}';}
    private function decode($v): array {if(!is_string($v)||trim($v)==='')return[];$j=json_decode($v,true);return is_array($j)?$j:[];}
}
