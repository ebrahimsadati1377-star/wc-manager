<?php
class ProductImageProviderManager {
    public function selected(): string {
        $p=trim((string)getSetting('product_image_provider','arena'));
        return in_array($p,['arena','openai','manual'],true)?$p:'arena';
    }
    public function availability(): array {
        $arena=trim((string)getenv('ARENA_API_KEY')); if($arena==='')$arena=trim((string)getSetting('arena_api_key',''));
        $openai=trim((string)getenv('OPENAI_API_KEY')); if($openai==='')$openai=trim((string)getSetting('openai_api_key',''));
        if($openai===''&&function_exists('wcAgentOpenAiKeyFromSession'))$openai=trim((string)wcAgentOpenAiKeyFromSession());
        return ['arena'=>$arena!=='','openai'=>$openai!=='','manual'=>true];
    }
    public function snapshot(): array {
        return ['selected'=>$this->selected(),'available'=>$this->availability(),'fallback_enabled'=>getSetting('product_image_fallback_enabled','0')==='1','fallback_provider'=>(string)getSetting('product_image_fallback_provider','openai'),'required_count'=>max(1,(int)getSetting('required_product_images_count','7'))];
    }
    public function assertReady(?string $requested=null): string {
        $p=$requested?:$this->selected(); if($p==='manual')return $p;
        $a=$this->availability(); if(!empty($a[$p]))return $p;
        if(getSetting('product_image_fallback_enabled','0')==='1'){
            $f=(string)getSetting('product_image_fallback_provider','openai');
            if($f!==$p&&!empty($a[$f]))return $f;
        }
        throw new RuntimeException($p==='arena'?'Arena انتخاب شده اما API Key آن هنوز آماده نیست.':'موتور تصویر آماده نیست: '.$p);
    }
    public function fallbackAfterFailure(string $failed): ?string {
        if(getSetting('product_image_fallback_enabled','0')!=='1')return null;
        $f=(string)getSetting('product_image_fallback_provider','openai');$a=$this->availability();
        return $f!==$failed&&$f!=='manual'&&!empty($a[$f])?$f:null;
    }
}
