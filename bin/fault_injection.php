<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use RealTest\Matrix\Context;
use OmegaGuardian\ProfilerShield\Storage\FileStore;
use OmegaGuardian\ProfilerShield\Security\{PublicErrorRenderer,Audit};
use Symfony\Component\HttpFoundation\Request;

ob_start();
$root=sys_get_temp_dir().'/guardian-fault-injection-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
$rows=[];
function faultCase(string $id,callable $fn):void {
 global $root,$rows;
 $sub=$root.'/'.$id;mkdir($sub,0700,true);$ctx=null;$issues=[];$assertions=0;
 $check=static function(bool $ok,string $message)use(&$issues,&$assertions):void{$assertions++;if(!$ok)$issues[]=$message;};
 try{$ctx=new Context($sub,'native',true);$fn($ctx,$check);}
 catch(\Throwable $error){$issues[]='Harness or application exception: '.get_class($error).': '.substr($error->getMessage(),0,100);}
 finally{if($ctx!==null)$ctx->cleanup();}
 $rows[]=['id'=>$id,'status'=>$issues?'FAIL':'PASS','assertions'=>$assertions,'reasons'=>$issues];
}
function noLeak(array $r,callable $check):void{
 $data=$r['body'].' '.json_encode($r['headers']);
 foreach(['GPS_PRIVATE_SQL_SENTINEL_2026','GPS_PRIVATE_ENV_SENTINEL_2026','GPS_PRIVATE_PATH_SENTINEL_2026','GPS_PRIVATE_TOKEN_SENTINEL_2026'] as $marker)
  $check(!str_contains($data,$marker),'private marker emitted to visitor');
}
try{
 faultCase('FAULT4-01-corrupt-active-session',static function(Context $x,callable $check){
  $response=new \Symfony\Component\HttpFoundation\Response();
  $x->sessions->begin('labadmin',$response);
  $cookies=[];foreach($response->headers->getCookies() as $cookie)$cookies[$cookie->getName()]=$cookie->getValue();
  $path=$x->store->root().'/sessions.json';$backup=file_get_contents($path);
  try{
   file_put_contents($path,'not authenticated AES-GCM data');
   $portal=$x->http(true,'GET',$x->portal,'text/html',[],$cookies);
   $check($portal['status']===503,'corrupt session did not close protected portal');
   noLeak($portal,$check);
   $ordinary=$x->http(true,'GET','/api/health');
   $check($ordinary['status']===200,'business API stopped during session fault');
   $headers=$x->http(true,'GET','/api/debug-header');
   $check(!isset($headers['headers']['x-debug-token']),'debug header exposed during session fault');
   $profiler=$x->http(true,'GET','/_profiler/','text/html',[],$cookies);
   $check(in_array($profiler['status'],[403,404],true),'profiler opened under corrupt storage');
  }finally{file_put_contents($path,$backup);}
 });
 faultCase('FAULT4-02-wrong-key-after-authenticated-session',static function(Context $x,callable $check){
  $cookies=$x->session();$key=$_SERVER['GUARDIAN_ENCRYPTION_KEY'];Context::env('GUARDIAN_ENCRYPTION_KEY',bin2hex(random_bytes(32)));
  try{
   $failed=false;try{$x->store->read('accounts');}catch(\Throwable){$failed=true;}
   $check($failed,'wrong authenticated AES key silently accepted');
   $check($x->http(true,'GET','/api/health')['status']===200,'business API dependent on Guardian key');
   $response=$x->http(true,'GET','/api/debug-header');
   $check(!isset($response['headers']['x-debug-token']),'debug token exposed with wrong storage key');
   $private=$x->http(true,'GET','/_profiler/','text/html',[],$cookies);
   $check(in_array($private['status'],[403,404],true),'profiler open after wrong key');
   noLeak($private,$check);
  }finally{Context::env('GUARDIAN_ENCRYPTION_KEY',$key);}
 });
 faultCase('FAULT4-03-malformed-collection-name-and-tag',static function(Context $x,callable $check){
  foreach(['../accounts','accounts/../../outside','',str_repeat('x',500)] as $name){
   $rejected=false;try{$x->store->read($name);}catch(\InvalidArgumentException){$rejected=true;}
   $check($rejected,'bad collection name accepted');
  }
  $file=$x->store->root().'/accounts.json';$backup=file_get_contents($file);
  try{
   $envelope=json_decode($backup,true);$tag=base64_decode($envelope['tag']);$tag[0]=chr(ord($tag[0])^0x10);
   $envelope['tag']=base64_encode($tag);file_put_contents($file,json_encode($envelope));
   $failed=false;try{$x->store->read('accounts');}catch(\Throwable){$failed=true;}
   $check($failed,'tampered authentication tag accepted');
  }finally{file_put_contents($file,$backup);}
 });
 faultCase('FAULT4-04-symlink-storage-detection',static function(Context $x,callable $check){
  $outside=$x->root.'/outside-secret';file_put_contents($outside,'never-follow');
  $link=$x->store->root().'/ghost.json';symlink($outside,$link);
  $refused=false;try{$x->store->read('ghost');}catch(\Throwable){$refused=true;}
  $check($refused,'symlink collection was followed');
  $check(file_get_contents($outside)==='never-follow','symlink target modified');
  $symlinkRoot=$x->root.'/vault-link';symlink($x->store->root(),$symlinkRoot);
  $other=new FileStore($symlinkRoot);
  // Environment variable overrides are cleared only for the isolated root-init check.
  $previous=$_SERVER['GUARDIAN_STORAGE_DIR'];Context::env('GUARDIAN_STORAGE_DIR','');
  try{$other=new FileStore($symlinkRoot);$failed=false;try{$other->initialize();}catch(\Throwable){$failed=true;}
   $check($failed,'symlink vault root accepted');
  }finally{Context::env('GUARDIAN_STORAGE_DIR',$previous);}
 });
 faultCase('FAULT4-05-twig-renderer-exception-neutralization',static function(Context $x,callable $check){
  $folder=$x->root.'/templates/bundles/TwigBundle/Exception';mkdir($folder,0700,true);
  file_put_contents($folder.'/error404.html.twig','simulated public template');
  $twig=new class {public function render(string $template,array $variables=[]):string{
   throw new \RuntimeException('GPS_PRIVATE_ENV_SENTINEL_2026');
  }};
  $renderer=new PublicErrorRenderer($x->settings,$x->root,$twig);
  $response=$renderer->exceptionPage(404,['error_mode'=>'native']);
  $check($response->getStatusCode()===404,'Twig exception changed HTTP status');
  $check(!str_contains($response->getContent(),'GPS_PRIVATE_ENV_SENTINEL_2026'),'Twig exception published internal message');
  $check(str_contains((string)$response->headers->get('Content-Type'),'text/html'),'fallback format wrong');
 });
 faultCase('FAULT4-06-audit-storage-write-denied',static function(Context $x,callable $check){
  $lock=$x->store->root().'/audit.json.lock';
  if(file_exists($lock))@unlink($lock);
  mkdir($lock,0700);
  try{
   $audit=new Audit($x->store);
   $blocked=false;try{$audit->record('synthetic_event','fixture');}catch(\Throwable){$blocked=true;}
   $check($blocked,'audit lock failure was silently accepted');
   $check($x->http(true,'GET','/api/health')['status']===200,'audit write failure broke business request');
   $err=$x->http(true,'GET','/api/throw/fatal');
   $check($err['status']===500,'audit failure changed exception status');
   noLeak($err,$check);
  }finally{rmdir($lock);}
 });
 faultCase('FAULT4-07-corrupt-account-during-public-exception',static function(Context $x,callable $check){
  $file=$x->store->root().'/accounts.json';$original=file_get_contents($file);
  try{
   file_put_contents($file,'{"schema":0}');
   $error=$x->http(true,'GET','/api/throw/fatal','application/json');
   $check($error['status']===500,'failed storage altered HTTP exception status');
   noLeak($error,$check);
   $check($x->http(true,'GET','/api/health')['status']===200,'public normal API failed with corrupt account store');
   $profiler=$x->http(true,'GET','/_profiler/','text/html');
   $check(in_array($profiler['status'],[403,404],true),'anonymous profiler exposed during account failure');
  }finally{file_put_contents($file,$original);}
 });
}finally{
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as $file){if($file->isLink())@unlink($file->getPathname());elseif($file->isDir())@rmdir($file->getPathname());else @unlink($file->getPathname());}
 @rmdir($root);
}
$discard=ob_get_clean();
if($discard!=='')fwrite(STDERR,'Suppressed fixture output bytes: '.strlen($discard).PHP_EOL);
$passed=count(array_filter($rows,fn($r)=>$r['status']==='PASS'));
$report=['suite'=>'fault-injection of Guardian storage and error rendering','release'=>'v1.0.0',
 'php'=>PHP_VERSION,'symfony'=>\Symfony\Component\HttpKernel\Kernel::VERSION,
 'scenarios'=>count($rows),'passed'=>$passed,'failed'=>count($rows)-$passed,'tests'=>$rows];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
if(count($rows)!==7||$passed!==7)exit(1);
