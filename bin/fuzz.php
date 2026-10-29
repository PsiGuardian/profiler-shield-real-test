<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use RealTest\Matrix\Context;
use OmegaGuardian\ProfilerShield\EventListener\GuardianSubscriber;
const SEED=20261029;
mt_srand(SEED);
$paths=['/api/health','/api/validation','/api/xml','/api/throw/fatal','/api/throw/validation','/api/not-found',
 '/docs/_profiler/guide','/_profiler/','/_wdt/x','/%5fprofiler/','/%255fprofiler/','/index.php/_profiler/',
 '/app_dev.php/_wdt/x','/_profiler%2fabc','/_profiler%252fabc','/api/status/401','/api/status/422',
 '/api/status/503','/api/cors-error','/web/error','/api/sse','/api/stream'];
$methods=['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'];
$accepts=['application/json','text/html','application/xml','application/problem+json','application/problem+xml','*/*',
 'text/html;q=0.1, application/json;q=0.9','application/json;q=0, text/html;q=1'];
$headersVariants=[[],['Origin'=>'https://client.example'],['X-Forwarded-For'=>'127.0.0.1'],['X-Original-URL'=>'/_profiler/'],
 ['X-Rewrite-URL'=>'/_wdt/x'],['Content-Type'=>'application/json; charset=utf-8'],['X-Debug-Token'=>'fake'],
 ['Accept-Language'=>'fr-FR,fr;q=0.9']];
$cookieSets=[[],['__Host-GuardianShield'=>str_repeat('0',64)],['__Host-GuardianShield'=>'bogus']];
$secrets=['GPS_PRIVATE_SQL_SENTINEL_2026','GPS_PRIVATE_PATH_SENTINEL_2026','GPS_PRIVATE_ENV_SENTINEL_2026','GPS_PRIVATE_TOKEN_SENTINEL_2026'];
$results=[];$totals=['PASS'=>0,'FAIL'=>0,'NOT_RUN'=>0];
foreach(['native','generic','custom'] as $mode){
 foreach([false,true] as $debug){
  $root=sys_get_temp_dir().'/guardian-fuzz-'.bin2hex(random_bytes(9));mkdir($root,0700,true);
  $ctx=null;$sample=[];$counts=['PASS'=>0,'FAIL'=>0,'NOT_RUN'=>0];
  try{
   $ctx=new Context($root,$mode,$debug);
   for($i=0;$i<150;$i++){
    $path=$paths[mt_rand(0,count($paths)-1)];$method=$methods[mt_rand(0,count($methods)-1)];
    $accept=$accepts[mt_rand(0,count($accepts)-1)];$headers=$headersVariants[mt_rand(0,count($headersVariants)-1)];$cookies=$cookieSets[mt_rand(0,count($cookieSets)-1)];
    try{
     $clean=$ctx->http(false,$method,$path,$accept,$headers,$cookies);
     $guard=$ctx->http(true,$method,$path,$accept,$headers,$cookies);
     $bad=[];
     $diagnostic=GuardianSubscriber::isDiagnosticPath(rawurldecode(rawurldecode($path)));
     // The unprotected reference kernel has no profiler. It is not a valid status oracle for a protected diagnostic route.
     // For diagnostics the security oracle is explicit denial and no leaked content; business routes retain strict parity.
     if(!$diagnostic&&$guard['status']!==$clean['status'])$bad[]='business status differs: '.$clean['status'].' vs '.$guard['status'];
     $public=$guard['body'].' '.json_encode($guard['headers']);
     foreach($secrets as $sentinel)if(str_contains($public,$sentinel))$bad[]='confidential sentinel leaked';
     $decodedPath=rawurldecode(rawurldecode($path));
     if(GuardianSubscriber::isDiagnosticPath($decodedPath)&&!in_array($guard['status'],[403,404],true))$bad[]='diagnostic route reachable';
     if($method==='GET'&&in_array($path,['/api/health','/docs/_profiler/guide'],true)&&$guard['body']!==$clean['body'])$bad[]='normal business response differs';
     if($method==='GET'&&$path==='/api/validation'&&$guard['body']!==$clean['body'])$bad[]='JSON validation response differs';
     $state=$bad?'FAIL':'PASS';
     ++$counts[$state];++$totals[$state];
     if($bad&&count($sample)<25)$sample[]=['index'=>$i,'path'=>$path,'method'=>$method,'accept'=>$accept,'issues'=>array_values(array_unique($bad))];
    }catch(\Throwable $e){
     ++$counts['NOT_RUN'];++$totals['NOT_RUN'];
     if(count($sample)<25)$sample[]=['index'=>$i,'path'=>$path,'method'=>$method,'accept'=>$accept,'issues'=>['harness could not construct request: '.get_class($e)]];
    }
   }
  }catch(\Throwable $e){$counts['NOT_RUN']+=150;$totals['NOT_RUN']+=150;$sample[]=['issues'=>['context initialization failed '.get_class($e)]];}
  finally{
   if($ctx!==null)$ctx->cleanup();
   $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
   foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}
   @rmdir($root);
  }
  $results[]=['mode'=>$mode,'debug'=>$debug,'counts'=>$counts,'samples'=>$sample];
 }
}
$report=['schema'=>1,'suite'=>'Seeded bounded HTTP differential fuzzing','seed'=>SEED,
 'php_version'=>PHP_VERSION,'symfony_version'=>\Symfony\Component\HttpKernel\Kernel::VERSION,
 'per_mode_debug'=>150,'total'=>array_sum($totals),'counts'=>$totals,'runs'=>$results];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
if(in_array('--strict',$argv,true)&&($totals['FAIL']>0||$totals['NOT_RUN']>0))exit(1);
