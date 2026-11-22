<?php
// Same-origin, anonymous exhibition presence. PHP 7.2+; no database needed.
header('Content-Type: application/json; charset=utf-8');
$origin=isset($_SERVER['HTTP_ORIGIN'])?$_SERVER['HTTP_ORIGIN']:'';
$allowed=array_filter(array_map('trim',explode(',',getenv('ALLOWED_ORIGINS')?:'')));
if($origin!==''&&!in_array($origin,$allowed,true)){http_response_code(403);echo json_encode(array('ok'=>false,'error'=>'origin_not_allowed'));exit;}
if($origin!==''){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 600');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function reply($code, $data) { http_response_code($code); echo json_encode($data); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET') reply(200, array('ok'=>true,'php'=>true,'writable'=>is_writable(__DIR__),'message'=>'Exhibition presence server is available.'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405,array('ok'=>false));
if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH']>2048) reply(413,array('ok'=>false));
$raw=file_get_contents('php://input',false,null,0,2049);
if(strlen($raw)>2048)reply(413,array('ok'=>false));
$input=json_decode($raw,true);
if(!is_array($input)||!isset($input['token'])||!preg_match('/^[a-f0-9]{32}$/D',$input['token'])) reply(400,array('ok'=>false));
$key=hash('sha256',$input['token']); $now=microtime(true); $leaving=!empty($input['leave']);
if(!$leaving){
  if(!isset($input['name'])||!is_string($input['name'])||!preg_match('/^[A-Za-z][A-Za-z0-9]{2,24}$/D',$input['name']))reply(400,array('ok'=>false));
  foreach(array('x','y','z','yaw') as $field){if(!isset($input[$field])||!is_numeric($input[$field])||!is_finite((float)$input[$field]))reply(400,array('ok'=>false));}
  if($input['x'] < -32 || $input['x'] > 12 || $input['z'] < -10 || $input['z'] > 12 || $input['y'] < -1 || $input['y'] > 10)reply(400,array('ok'=>false));
}
$prefix="<?php exit; ?>\n";
$lock=fopen(__DIR__.'/.room-lock.php','c+');
if(!$lock||!flock($lock,LOCK_EX))reply(503,array('ok'=>false,'error'=>'storage_not_writable'));
// Protect runtime storage even if directly requested over HTTP.
if(fstat($lock)['size']===0){fwrite($lock,$prefix);fflush($lock);}
$file=__DIR__.'/.room-state.php';$state=array();
if(is_file($file)){$stored=file_get_contents($file);$decoded=json_decode(substr($stored,strlen($prefix)),true);if(is_array($decoded))$state=$decoded;}
foreach($state as $id=>$peer){if($now-$peer['seen']>15)unset($state[$id]);}
if($leaving)unset($state[$key]);
else{
  if(!isset($state[$key])&&count($state)>=50){flock($lock,LOCK_UN);fclose($lock);reply(503,array('ok'=>false,'error'=>'room_full'));}
  $state[$key]=array('id'=>substr($key,0,20),'name'=>$input['name'],'x'=>(float)$input['x'],'y'=>(float)$input['y'],'z'=>(float)$input['z'],'yaw'=>fmod((float)$input['yaw'],6.283185307),'moving'=>!empty($input['moving']),'jump'=>isset($input['jump'])?max(0,min(1000000000,(int)$input['jump'])):0,'jumpBase'=>isset($input['jumpBase'])?max(0,min(3,(float)$input['jumpBase'])):0,'seen'=>$now);
}
$written=file_put_contents($file,$prefix.json_encode($state));
flock($lock,LOCK_UN);fclose($lock);
if($written===false)reply(503,array('ok'=>false,'error'=>'storage_write_failed'));
$peers=array();foreach($state as $id=>$peer){if($id===$key)continue;unset($peer['seen']);$peers[]=$peer;}
reply(200,array('ok'=>true,'peers'=>$peers));
