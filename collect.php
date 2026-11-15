<?php
define('NEWS_LIBRARY_ONLY', true);
require __DIR__.'/news-library.php';
$relay=get_relay_feed();
if($relay && ($relay['refresh_ok']??false)){
 $data=['items'=>$relay['items'],'checked_at'=>$relay['checked_at'],'updated_at'=>$relay['checked_at'],'sources'=>$relay['sources'],'refresh_interval_seconds'=>1800,'collector_trigger'=>(getenv('GITHUB_EVENT_NAME')?:'cli'),'collector_backend'=>'sites'];
 $items=$data['items'];$success=count(NEWS_SOURCES);
}else{
$old=load_news_json(__DIR__.'/feed.json'); $items=[]; $sources=[]; $success=0;
foreach(NEWS_SOURCES as $channel=>$source){
 $html=get_public_feed($channel); $parsed=$html!==null?parse_news($html,$channel):[]; $ok=count($parsed)>0;
 if($ok){$success++;$items=array_merge($items,$parsed);}else foreach($old['items']??[] as $i)if(($i['channel']??'')===$channel&&strtotime($i['published_at']??'')>=time()-NEWS_MAX_AGE)$items[]=$i;
 $sources[]=['channel'=>$channel,'source'=>$source['name'],'status'=>$ok?'updated':'cached_or_unavailable'];
}
if(!$success){fwrite(STDERR,"Both public sources unavailable; preserved prior feed.\n");exit(1);}
usort($items,static function($a,$b){return strcmp($b['published_at'],$a['published_at']);});
$data=['items'=>$items,'checked_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'sources'=>$sources,'refresh_interval_seconds'=>1800,'collector_trigger'=>(getenv('GITHUB_EVENT_NAME')?:'cli')];

 $data['collector_backend']='github_direct';
}
file_put_contents(__DIR__.'/feed.json',json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
echo 'Updated '.count($items).' public headlines from '.$success.' sources.'.PHP_EOL;
