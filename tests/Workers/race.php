<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use OmegaGuardian\ProfilerShield\Storage\FileStore;
use OmegaGuardian\ProfilerShield\Security\{Settings,LoginThrottle,LoginRateLimited};
$mode=$argv[1]??'';
$times=(int)($argv[2]??0);
$root=(string)getenv('GUARDIAN_STORAGE_DIR');
$key=(string)getenv('GUARDIAN_ENCRYPTION_KEY');
if($root===''||!preg_match('/^[a-f0-9]{64}$/',$key)){fwrite(STDERR,'Missing isolated fixture env'.PHP_EOL);exit(2);}
try{
 $store=new FileStore($root);
 if($mode==='increment'){
  if($times<1||$times>500)exit(2);
  for($i=0;$i<$times;$i++){
   $store->change('counter',static fn(array $v):array=>['value'=>(int)($v['value']??0)+1]);
  }
  echo 'INCREMENTED'.PHP_EOL;
 }elseif($mode==='throttle'){
  $throttle=new LoginThrottle($store,new Settings($store));
  try{
   $ticket=$throttle->begin('one-user','198.51.100.23');
   usleep(15000);
   // Failed credentials must be counted; finish(false) intentionally returns false.
   $throttle->finish($ticket,false);
   echo 'ACCEPT'.PHP_EOL;
  }catch(LoginRateLimited $e){echo 'BLOCK'.PHP_EOL;}
 }else exit(2);
}catch(\Throwable $e){fwrite(STDERR,get_class($e).': '.substr($e->getMessage(),0,120).PHP_EOL);exit(3);}
