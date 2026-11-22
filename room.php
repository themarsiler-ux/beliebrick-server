<?php
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$origin=$_SERVER['HTTP_ORIGIN']??'';
$origins=array_filter(array_map('trim',explode(',',getenv('ALLOWED_ORIGINS')?:'https://beliebrick.com,https://www.beliebrick.com,https://m.beliebrick.com')));
function reply($code,$data){http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;}
if($origin!==''&&!in_array($origin,$origins,true))reply(403,['ok'=>false,'error'=>'origin_not_allowed']);
if($origin!==''){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');}header('Access-Control-Allow-Methods: GET, POST, OPTIONS');header('Access-Control-Allow-Headers: Content-Type');header('Access-Control-Max-Age: 600');
if($_SERVER['REQUEST_METHOD']==='OPTIONS')reply(204,[]);
if($_SERVER['REQUEST_METHOD']==='GET')reply(200,['ok'=>true,'protocol'=>2,'php'=>true,'writable'=>is_writable(__DIR__)]);
if($_SERVER['REQUEST_METHOD']!=='POST')reply(405,['ok'=>false]);
$raw=file_get_contents('php://input',false,null,0,32769);if(strlen($raw)>32768)reply(413,['ok'=>false]);$in=json_decode($raw,true);
if(!is_array($in)||!isset($in['token'])||!is_string($in['token'])||!preg_match('/^[a-f0-9]{32}$/D',$in['token']))reply(400,['ok'=>false]);
$key=hash('sha256',$in['token']);$now=microtime(true);$leave=!empty($in['leave']);$room=$in['room']??'expo';
if(!in_array($room,['expo','A','B','C','D','E','F','G','H'],true))reply(400,['ok'=>false,'error'=>'invalid_room']);
if(!$leave){
 if(!isset($in['name'])||!is_string($in['name'])||!preg_match('/^[A-Za-z][A-Za-z0-9]{2,24}$/D',$in['name']))reply(400,['ok'=>false]);
 foreach(['x','y','z','yaw'] as $f)if(!isset($in[$f])||!is_numeric($in[$f])||!is_finite((float)$in[$f]))reply(400,['ok'=>false]);
 if($in['x']< -32||$in['x']>33||$in['z']< -10||$in['z']>14||$in['y']< -1||$in['y']>10)reply(400,['ok'=>false]);
}
$prefix="<?php exit; ?>\n";$lock=fopen(__DIR__.'/.shared-lock.php','c+');if(!$lock||!flock($lock,LOCK_EX))reply(503,['ok'=>false,'error'=>'storage_not_writable']);if(fstat($lock)['size']===0){fwrite($lock,$prefix);fflush($lock);}
$file=__DIR__.'/.shared-room.php';$state=['peers'=>[],'bots'=>[],'leader'=>'','lease'=>0,'revision'=>0,'botSeen'=>0];if(is_file($file)){$decoded=json_decode(substr(file_get_contents($file),strlen($prefix)),true);if(is_array($decoded)&&isset($decoded['peers']))$state=$decoded;}
foreach($state['peers'] as $id=>$p)if($now-$p['seen']>30)unset($state['peers'][$id]);
if($leave){if(isset($state['peers'][$key])&&$state['peers'][$key]['room']===$room)unset($state['peers'][$key]);}
else{if(!isset($state['peers'][$key])&&count($state['peers'])>=50){flock($lock,LOCK_UN);fclose($lock);reply(503,['ok'=>false,'error'=>'room_full']);}$state['peers'][$key]=['id'=>substr($key,0,20),'name'=>$in['name'],'x'=>(float)$in['x'],'y'=>(float)$in['y'],'z'=>(float)$in['z'],'yaw'=>fmod((float)$in['yaw'],M_PI*2),'moving'=>!empty($in['moving']),'jump'=>max(0,min(1000000000,(int)($in['jump']??0))),'jumpBase'=>max(0,min(3,(float)($in['jumpBase']??0))),'room'=>$room,'active'=>!isset($in['active'])||!!$in['active'],'seen'=>$now];}
$leader=$state['leader'];
if(!isset($state['peers'][$leader])||$state['peers'][$leader]['room']!=='expo'||empty($state['peers'][$leader]['active'])||$state['lease']<$now){$state['leader']='';if(!$leave&&$room==='expo'&&!empty($in['active'])){$state['leader']=$key;$state['lease']=$now+5;}foreach($state['peers'] as $id=>$p){if($state['leader']===''&&$p['room']==='expo'&&!empty($p['active'])){$state['leader']=$id;$state['lease']=$now+5;break;}}}
$isLeader=!$leave&&$room==='expo'&&$state['leader']===$key;
if($isLeader){$state['lease']=$now+5;if(isset($in['bots'])&&is_array($in['bots'])&&count($in['bots'])<=50){$bots=[];$names=[];foreach($in['bots'] as $b){if(!is_array($b)||!isset($b['name'])||!is_string($b['name'])||!preg_match('/^[A-Za-z][A-Za-z0-9]{2,24}$/D',$b['name'])||isset($names[$b['name']]))continue;$valid=true;foreach(['x','y','z','yaw'] as $f)if(!isset($b[$f])||!is_numeric($b[$f])||!is_finite((float)$b[$f]))$valid=false;if(!$valid||$b['x']< -32||$b['x']>33||$b['z']< -10||$b['z']>14||$b['y']< -1||$b['y']>10)continue;$names[$b['name']]=true;$bots[]=['name'=>$b['name'],'x'=>(float)$b['x'],'y'=>(float)$b['y'],'z'=>(float)$b['z'],'yaw'=>(float)$b['yaw'],'moving'=>!empty($b['moving'])];}$state['bots']=$bots;$state['revision']++;$state['botSeen']=$now;}}
if($state['leader']===''&&$now-$state['botSeen']>30)$state['bots']=[];
$ok=file_put_contents($file,$prefix.json_encode($state))!==false;$peers=[];foreach($state['peers'] as $id=>$p)if($id!==$key){unset($p['seen']);$peers[]=$p;}
$result=['ok'=>$ok,'protocol'=>2,'peers'=>$peers,'leader'=>$isLeader,'bots'=>$state['bots'],'total'=>count($state['peers'])+count($state['bots']),'online'=>count($state['peers']),'virtual'=>count($state['bots']),'revision'=>$state['revision'],'serverTime'=>$now];flock($lock,LOCK_UN);fclose($lock);reply($ok?200:503,$result);
