<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
// Kernel error handlers on some Symfony branches may emit content; keep JSON reports machine-readable.
ob_start();
use RealTest\Matrix\{Context,HttpProbes,StateProbes};

$strict=in_array('--strict',$argv,true);
$prototype=in_array('--prototype',$argv,true);
$catalog=json_decode((string)file_get_contents(dirname(__DIR__).'/tests/catalog.json'),true,512,JSON_THROW_ON_ERROR);
$specs=$catalog['scenarios']??[];
if(count($specs)!==84||count(array_unique(array_column($specs,'id')))!==84){fwrite(STDERR,"Incomplete or duplicated test catalogue\n");exit(2);}
$expectedFamilies=['BASE'=>8,'SAFE'=>14,'HTTP'=>20,'PROF'=>14,'AUTH'=>10,'FAULT'=>10,'DOCTOR'=>8];
foreach($expectedFamilies as $prefix=>$count){if(count(array_filter($specs,static fn($x)=>str_starts_with($x['id'],$prefix.'-')))!==$count){fwrite(STDERR,"Missing family ".$prefix."\n");exit(2);}}

$results=[];$summary=['PASS'=>0,'FAIL'=>0,'NOT_RUN'=>0,'SKIP'=>0];$names=[];$allStart=hrtime(true);
foreach(['native','generic','custom'] as $mode) {
 foreach([false,true] as $debug) {
  $root=sys_get_temp_dir().'/guardian-matrix84-'.bin2hex(random_bytes(9));
  if(!mkdir($root,0700,true)){fwrite(STDERR,"Cannot allocate test root\n");exit(2);}
  $ctx=null;
  try {
   $ctx=new Context($root,$mode,$debug,$prototype);
   foreach($specs as $spec){
    $id=$spec['id'];$start=hrtime(true);
    $result=str_starts_with($id,'AUTH-')||str_starts_with($id,'FAULT-')||str_starts_with($id,'DOCTOR-')?
     StateProbes::run($id,$ctx):HttpProbes::run($id,$ctx);
    $status=$result['status'];
    if(!isset($summary[$status])){fwrite(STDERR,"Invalid status for ".$id."\n");exit(2);}
    if($status==='PASS'&&($result['assertions']??0)<1){$status='NOT_RUN';$result['reasons'][]='No positive assertion executed';}
    ++$summary[$status];
    $names[$id][$status]=($names[$id][$status]??0)+1;
    $results[]=[
      'test_id'=>$id,'priority'=>$spec['priority'],'name'=>$spec['name'],'mode'=>$mode,'debug'=>$debug,
      'status'=>$status,'assertions'=>$result['assertions']??0,
      'reasons'=>array_values(array_unique($result['reasons']??[])),
      'duration_ms'=>round((hrtime(true)-$start)/1e6,3)
    ];
   }
  }catch(\Throwable $e){
   // Always report missing results; do not masquerade a broken harness as passing tests.
   $already=array_column(array_filter($results,static fn($v)=>$v['mode']===$mode&&$v['debug']===$debug),'test_id');
   foreach($specs as $spec)if(!in_array($spec['id'],$already,true)){
    ++$summary['NOT_RUN'];$names[$spec['id']]['NOT_RUN']=($names[$spec['id']]['NOT_RUN']??0)+1;
    $results[]=['test_id'=>$spec['id'],'priority'=>$spec['priority'],'name'=>$spec['name'],'mode'=>$mode,'debug'=>$debug,
      'status'=>'NOT_RUN','assertions'=>0,'reasons'=>['Harness exception '.get_class($e).': '.substr($e->getMessage(),0,160)],'duration_ms'=>0];
   }
  }finally{
   if($ctx!==null)$ctx->cleanup();
   if(is_dir($root)){
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}
    @rmdir($root);
   }
  }
 }
}
$perId=[];foreach($specs as $s){
 $stats=$names[$s['id']]??[];
 $perId[]=['test_id'=>$s['id'],'priority'=>$s['priority'],'pass'=>$stats['PASS']??0,'fail'=>$stats['FAIL']??0,'not_run'=>$stats['NOT_RUN']??0,'skip'=>$stats['SKIP']??0,
 'aggregate'=>isset($stats['FAIL'])?'FAIL':(isset($stats['NOT_RUN'])?'NOT_RUN':(isset($stats['SKIP'])?'SKIP':'PASS'))];
}
$p0Unsatisfied=array_values(array_filter($perId,static fn($s)=>$s['priority']==='P0'&&$s['aggregate']!=='PASS'));
$report=[
 'schema'=>2,'suite'=>'Profiler Shield independent 84-scenario security and API matrix',
 'source'=>'tests/catalog.json','release'=>getenv('LAB_RELEASE_ID')?:'unspecified',
 'source_commit'=>getenv('LAB_RELEASE_SHA')?:null,
 'installed_package_version'=>\Composer\InstalledVersions::getPrettyVersion('omegaguardian/profiler-shield'),
 'test_only_prototype'=>$prototype,
 'symfony_version'=>\Symfony\Component\HttpKernel\Kernel::VERSION,'php_version'=>PHP_VERSION,
 'modes'=>['native','generic','custom'],'debug_states'=>[false,true],
 'scenarios_declared'=>84,'variants_per_scenario'=>6,'executions_expected'=>504,'executions_observed'=>count($results),
 'status_counts'=>$summary,'scenario_summary'=>$perId,'p0_unsatisfied'=>array_column($p0Unsatisfied,'test_id'),
 'duration_ms'=>round((hrtime(true)-$allStart)/1e6,1),'results'=>$results
];
if(count($results)!==504){fwrite(STDERR,"Incomplete matrix execution\n");exit(2);}
$unexpectedOutput=ob_get_clean();
if($unexpectedOutput!=='')fwrite(STDERR,'Suppressed non-JSON fixture output bytes: '.strlen($unexpectedOutput).PHP_EOL);
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR),PHP_EOL;
if($strict && $p0Unsatisfied)exit(1);
