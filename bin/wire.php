<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$strict=in_array('--strict',$argv,true);
$results=[];$summary=['PASS'=>0,'FAIL'=>0,'NOT_RUN'=>0];$rootBase=sys_get_temp_dir().'/guardian-wire-'.bin2hex(random_bytes(8));
mkdir($rootBase,0700,true);
function takePort():int{
 $s=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$s)throw new RuntimeException('unable to find loopback port');
 $a=stream_socket_get_name($s,false);fclose($s);return (int)substr(strrchr($a,':'),1);
}
function wireRequest(int $port,string $method,string $path):array{
 $ch=curl_init('http://127.0.0.1:'.$port.$path);$headers=[];
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_CONNECTTIMEOUT=>2,
  CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_NOBODY=>$method==='HEAD',
  CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);}
 ]);
 $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
 return ['status'=>$status,'body'=>$body===false?'':$body,'headers'=>$headers,'error'=>$error];
}
function testWire(string $mode,bool $debug,bool $guarded,string $root):array{
 if(!function_exists('proc_open')||!function_exists('curl_init'))return ['status'=>'NOT_RUN','checks'=>0,'reasons'=>['proc_open or cURL not available']];
 $dir=$root.'/'.($guarded?'guardian':'clean').'-'.$mode.'-'.($debug?'debug':'release');
 mkdir($dir,0700,true);
 $log=$dir.'/server.log';$port=takePort();
 $env=array_merge($_ENV,[
  'PATH'=>getenv('PATH')?:'/usr/bin:/bin',
  'REAL_TEST_WIRE_ROOT'=>$dir,'REAL_TEST_GUARDED'=>$guarded?'1':'0',
  'REAL_TEST_WIRE_DEBUG'=>$debug?'1':'0'
 ]);
 $command=[PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/tests/Wire',dirname(__DIR__).'/tests/Wire/router.php'];
 $spec=[0=>['pipe','r'],1=>['file',$log,'w'],2=>['file',$dir.'/errors.log','w']];
 $p=proc_open($command,$spec,$pipes,dirname(__DIR__), $env);
 if(!is_resource($p))return ['status'=>'NOT_RUN','checks'=>0,'reasons'=>['Unable to start PHP built-in server']];
 fclose($pipes[0]);
 $errors=[];$checks=0;
 $assert=static function(bool $condition,string $why)use(&$errors,&$checks){$checks++;if(!$condition)$errors[]=$why;};
 try{
  $ready=false;for($i=0;$i<60;$i++){usleep(75000);$v=wireRequest($port,'GET','/api/health');if($v['status']===200){$ready=true;break;}}
  if(!$ready)return ['status'=>'NOT_RUN','checks'=>0,'reasons'=>['Fixture HTTP server not ready; examine server logs']];
  $get=wireRequest($port,'GET','/api/health');
  $head=wireRequest($port,'HEAD','/api/health');
  $assert($get['status']===200,'baseline GET not 200');
  $assert($head['status']===200,'HEAD healthy not 200');
  $assert($head['body']==='','HEAD healthy transmitted body');
  $missing=wireRequest($port,'HEAD','/api/not-found');
  $assert($missing['status']===404,'HEAD missing resource status changed');
  $assert($missing['body']==='','HEAD error transmitted body');
  $cors=wireRequest($port,'OPTIONS','/api/cors');
  $assert($cors['status']===204,'CORS preflight status');
  $assert(($cors['headers']['access-control-allow-origin']??'')==='https://client.example','CORS origin lost');
  $stream=wireRequest($port,'GET','/api/stream');
  $assert($stream['status']===200,'stream HTTP status');
  $assert($stream['body']==='safe-stream-data','real streamed content differs');
  $sse=wireRequest($port,'GET','/api/sse');
  $assert($sse['status']===200,'SSE HTTP status');
  $assert(substr_count($sse['body'],'data: ')===10,'SSE wire events missing');
  if($guarded){$profile=wireRequest($port,'GET','/_profiler/');
   $assert(in_array($profile['status'],[403,404],true),'unauthorized real HTTP profiler access');}
 }catch(Throwable $e){$errors[]='wire test harness: '.get_class($e).': '.substr($e->getMessage(),0,160);}
 finally{proc_terminate($p);proc_close($p);}
 return ['status'=>$errors?'FAIL':'PASS','checks'=>$checks,'reasons'=>$errors];
}
try{
 foreach(['native','generic','custom'] as $mode){
  foreach([false,true] as $debug){
   foreach([false,true] as $guard){
    $r=testWire($mode,$debug,$guard,$rootBase);
    ++$summary[$r['status']];
    $results[]=['mode'=>$mode,'debug'=>$debug,'guarded'=>$guard,'status'=>$r['status'],'assertions'=>$r['checks'],'reasons'=>$r['reasons']];
   }
  }
 }
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootBase,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}
 @rmdir($rootBase);
}
$report=['suite'=>'Real HTTP wire semantics', 'php_version'=>PHP_VERSION,
 'symfony_version'=>\Symfony\Component\HttpKernel\Kernel::VERSION,
 'variants'=>count($results),'status_counts'=>$summary,'results'=>$results];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
if($strict&&($summary['FAIL']>0||$summary['NOT_RUN']>0))exit(1);
