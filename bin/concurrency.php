<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use OmegaGuardian\ProfilerShield\Storage\FileStore;
const ROUNDS=3;
const WORKERS=8;
const INCREMENTS=40;
const THROTTLE_WORKERS=24;
function executeRace(string $mode,int $count,array $env,int $each=1):array {
 $tasks=[];
 for($i=0;$i<$count;$i++){
  $cmd=[PHP_BINARY,dirname(__DIR__).'/tests/Workers/race.php',$mode,(string)$each];
  $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),array_merge($_ENV,$env));
  if(!is_resource($proc))throw new \RuntimeException('Cannot spawn race worker '.$i);
  fclose($pipes[0]);$tasks[]=[$proc,$pipes];
 }
 $out=[];
 foreach($tasks as [$proc,$pipes]){
  $stdout=trim(stream_get_contents($pipes[1]));$stderr=trim(stream_get_contents($pipes[2]));
  fclose($pipes[1]);fclose($pipes[2]);
  $exit=proc_close($proc);
  $out[]=['exit'=>$exit,'result'=>$stdout,'error'=>$stderr];
 }
 return $out;
}
$root=sys_get_temp_dir().'/guardian-concurrency-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
$env=['GUARDIAN_STORAGE_DIR'=>$root.'/vault','GUARDIAN_ENCRYPTION_KEY'=>bin2hex(random_bytes(32))];
foreach($env as $name=>$value){$_ENV[$name]=$_SERVER[$name]=$value;putenv($name.'='.$value);}
$store=new FileStore($root.'/vault');$store->initialize();
$errors=[];$assertions=0;$detail=[];
$assert=static function(bool $condition,string $message)use(&$errors,&$assertions){$assertions++;if(!$condition)$errors[]=$message;};
try{
 for($round=1;$round<=ROUNDS;$round++){
  $res=executeRace('increment',WORKERS,$env,INCREMENTS);
  foreach($res as $i=>$v)$assert($v['exit']===0&&$v['result']==='INCREMENTED','worker failed at round '.$round.' slot '.$i.': '.$v['error']);
  $expected=WORKERS*INCREMENTS*$round;
  $observed=(int)($store->read('counter')['value']??-1);
  $assert($observed===$expected,'lost counter writes: expected '.$expected.' saw '.$observed);
  $detail[]=['round'=>$round,'expected'=>$expected,'observed'=>$observed];
 }
 $responses=executeRace('throttle',THROTTLE_WORKERS,$env,1);
 $accepted=0;$blocked=0;
 foreach($responses as $i=>$r){
  $assert($r['exit']===0,'throttle worker returned nonzero at '.$i.': '.$r['error']);
  if($r['result']==='ACCEPT')$accepted++;
  elseif($r['result']==='BLOCK')$blocked++;
  else $assert(false,'unrecognized throttle worker result '.$i);
 }
 $assert($accepted<=10,'concurrent login throttle accepted more than ten attempts');
 $assert($accepted+$blocked===THROTTLE_WORKERS,'missing throttle decisions');
 $assert($blocked>0,'concurrent throttle failed to block excess attempts');
 $detail[]=['throttle_accepted'=>$accepted,'throttle_blocked'=>$blocked];
 // Simulate two hostile storage faults, verify authenticated AES-GCM read rejects both.
 $counterFile=$store->root().'/counter.json';
 $before=file_get_contents($counterFile);
 try{
  file_put_contents($counterFile,'{"schema":2,"nonce":"invalid","tag":"bad","ciphertext":"broken"}');
  $rejected=false;try{$store->read('counter');}catch(\Throwable){$rejected=true;}
  $assert($rejected,'corrupt authenticated envelope accepted');
 }finally{file_put_contents($counterFile,$before);}
 $assert((int)$store->read('counter')['value']===ROUNDS*WORKERS*INCREMENTS,'counter corrupted after recovery');
}catch(\Throwable $e){$errors[]='race harness failed: '.get_class($e).': '.$e->getMessage();}
finally{
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as $file){$file->isDir()?@rmdir($file->getPathname()):@unlink($file->getPathname());}
 @rmdir($root);
}
$report=['suite'=>'independent vault and login concurrency','symfony'=>\Symfony\Component\HttpKernel\Kernel::VERSION,'php'=>PHP_VERSION,
 'rounds'=>ROUNDS,'workers_per_round'=>WORKERS,'increments_per_worker'=>INCREMENTS,'total_increment_operations'=>ROUNDS*WORKERS*INCREMENTS,
 'throttle_requests'=>THROTTLE_WORKERS,'assertions'=>$assertions,'status'=>$errors?'FAIL':'PASS','details'=>$detail,'errors'=>$errors];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
if($errors)exit(1);
