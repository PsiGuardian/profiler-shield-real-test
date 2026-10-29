<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use RealTest\Fixture\{TestKernel,Scenarios};
use Symfony\Component\HttpFoundation\{Request,Response,StreamedResponse};

$strict=in_array('--strict',$argv,true);
$dir=sys_get_temp_dir().'/profiler-real-lab-'.bin2hex(random_bytes(8));
mkdir($dir,0700,true);
$env=[
 'GUARDIAN_STORAGE_DIR'=>$dir.'/vault',
 'GUARDIAN_ENCRYPTION_KEY'=>bin2hex(random_bytes(32)),
 'GUARDIAN_LOGIN_PATH'=>'/internal/lab-'.bin2hex(random_bytes(18)),
 'GUARDIAN_COLLECTION_BRIDGE'=>'0',
 'GUARDIAN_DEBUG_ERRORS_FOR_AUTHENTICATED'=>'0',
 'GUARDIAN_SHOW_PHP_VERSION'=>'0',
];
foreach($env as $name=>$value){$_ENV[$name]=$_SERVER[$name]=$value;putenv($name.'='.$value);}
$store=new \OmegaGuardian\ProfilerShield\Storage\FileStore($dir.'/vault');
$store->initialize();
$rows=[];$issues=[];$total=0;$passes=0;
function snapshot(TestKernel $kernel,array $s):array{
 $req=Request::create('https://localhost'.$s['path'],$s['method'],[],[],[],[
  'HTTPS'=>'on','REMOTE_ADDR'=>'203.0.113.18','HTTP_ACCEPT'=>$s['accept']
 ]);
 $res=$kernel->handle($req);
 $body=$res->getContent();
 if($res instanceof StreamedResponse){$body='[streamed: not buffered]';}
 $result=['status'=>$res->getStatusCode(),'mime'=>strtolower(trim(explode(';',(string)$res->headers->get('Content-Type',''))[0])),
  'body'=>is_string($body)?$body:'','headers'=>[]];
 foreach(['Allow','Retry-After','WWW-Authenticate','Location','X-Fixture','X-Debug-Token','Content-Type'] as $header){
  if($res->headers->has($header))$result['headers'][$header]=$res->headers->get($header);
 }
 $kernel->terminate($req,$res);
 return $result;
}
function testCase(array $s,array $clean,array $guard,string $mode):array {
 $fail=[];
 if($clean['status']!==$s['status'])$fail[]='fixture baseline status unexpectedly '.$clean['status'];
 if($guard['status']!==$s['status'])$fail[]='guarded HTTP status changed to '.$guard['status'];
 if(($s['same']??false)){
  // Normalize only diagnostic headers, never mask a difference in application semantics.
  $a=$clean;$b=$guard;
  foreach(['X-Debug-Token'] as $h){unset($a['headers'][$h],$b['headers'][$h]);}
  if($a['body']!==$b['body'])$fail[]='response contract body differs';
  if($a['mime']!==$b['mime'])$fail[]='response content type differs';
  foreach(['Allow','Retry-After','WWW-Authenticate','Location','X-Fixture'] as $h){
   if(($a['headers'][$h]??null)!==($b['headers'][$h]??null))$fail[]='required header '.$h.' changed';
  }
 }
 $sensitive=['GPS_PRIVATE_SQL_SENTINEL_2026','GPS_PRIVATE_ENV_SENTINEL_2026','GPS_PRIVATE_PATH_SENTINEL_2026','GPS_PRIVATE_TOKEN_SENTINEL_2026'];
 $public=$guard['body'].' '.json_encode($guard['headers']);
 foreach($sensitive as $token){if(str_contains($public,$token))$fail[]='private fixture sentinel disclosed';}
 if(($s['id']??'')==='SAFE-08' && isset($guard['headers']['X-Debug-Token']))$fail[]='X-Debug-Token exposed';
 if(($s['id']??'')==='HTTP-10'&&!str_contains($guard['mime'],'xml'))$fail[]='XML Accept received non-XML';
 if(($s['id']??'')==='PROF-01'||($s['id']??'')==='PROF-02'){
  if(!in_array($guard['status'],[403,404],true))$fail[]='unauthorized profiler not denied';
 }
 return array_values(array_unique($fail));
}
try{
 foreach(['native','generic','custom'] as $mode){
  $settings=new \OmegaGuardian\ProfilerShield\Security\Settings($store);
  $settings->set(['error_mode'=>$mode,'error_custom_text'=>'Public safe message']);
  foreach([false,true] as $debug){
   // Run kernels sequentially; routing relies on explicit guarded flag and separate caches.
   $clean=new TestKernel($dir,false,$debug);
   $_SERVER['REAL_TEST_GUARDED']='0';$_ENV['REAL_TEST_GUARDED']='0';$clean->boot();
   $baseline=[];
   foreach(Scenarios::all() as $scenario){if(!($scenario['guardedOnly']??false))$baseline[$scenario['id']]=snapshot($clean,$scenario);}
   $clean->shutdown();
   $_SERVER['REAL_TEST_GUARDED']='1';$_ENV['REAL_TEST_GUARDED']='1';
   $guard=new TestKernel($dir,true,$debug);$guard->boot();
   foreach(Scenarios::all() as $s){
    $c=$baseline[$s['id']]??['status'=>$s['status'],'mime'=>'','body'=>'','headers'=>[]];
    $g=snapshot($guard,$s);
    $errors=testCase($s,$c,$g,$mode);
    $id=$s['id'];
    $rows[]=['test_id'=>$id,'mode'=>$mode,'debug'=>$debug,'status'=>count($errors)?'FAIL':'PASS','assertions'=>$errors,
     'expected_status'=>$s['status'],'observed_status'=>$g['status'],'baseline_mime'=>$c['mime'],'guarded_mime'=>$g['mime']];
    $total++;
    if($errors){$issues[]=[$id,$mode,$debug,$errors];}else{$passes++;}
   }
   $guard->shutdown();
  }
 }
 $report=['suite'=>'Profiler Shield Real Test','status'=>$issues?'FAIL':'PASS','cases'=>$total,'passed'=>$passes,'failed'=>count($issues),
  'symfony_version'=>\Symfony\Component\HttpKernel\Kernel::VERSION,'php_version'=>PHP_VERSION,'tests'=>$rows];
 echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
 if($strict && $issues)exit(1);
}finally{
 if(is_dir($dir)){
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}
  @rmdir($dir);
 }
}
