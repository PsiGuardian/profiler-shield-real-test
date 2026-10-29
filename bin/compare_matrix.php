<?php
declare(strict_types=1);
$baseline=json_decode((string)@file_get_contents($argv[1]??''),true);
$prototype=json_decode((string)@file_get_contents($argv[2]??''),true);
foreach([['baseline',$baseline],['prototype',$prototype]] as [$label,$report]){
 if(!is_array($report)||($report['schema']??0)!==2||($report['scenarios_declared']??0)!==84
   ||($report['executions_observed']??0)!==504){
  fwrite(STDERR,'Invalid '.$label.' full-matrix artifact'.PHP_EOL);exit(2);
 }
}
if(($baseline['test_only_prototype']??false)!==false || ($prototype['test_only_prototype']??false)!==true){
 fwrite(STDERR,'Matrix input order or prototype indicator invalid'.PHP_EOL);exit(2);
}
$key=static fn(array $row)=>$row['test_id'].'|'.$row['mode'].'|'.($row['debug']?'debug':'prodlike');
$before=[];foreach($baseline['results'] as $row)$before[$key($row)]=$row;
$after=[];foreach($prototype['results'] as $row)$after[$key($row)]=$row;
if(count($before)!==504||count($after)!==504||array_diff_key($before,$after)||array_diff_key($after,$before)){
 fwrite(STDERR,"Matrices have different test keys or duplicates\n");exit(2);
}
$improvements=[];$regressions=[];$unchangedFailures=[];$unrun=[];
foreach($before as $id=>$a){
 $b=$after[$id];
 $was=$a['status'];$now=$b['status'];
 if($was==='PASS'&&$now!=='PASS')$regressions[]=['test'=>$id,'was'=>$was,'now'=>$now,'reason'=>$b['reasons']];
 elseif($was!=='PASS'&&$now==='PASS')$improvements[]=['test'=>$id,'was'=>$was,'now'=>$now];
 elseif($now==='FAIL')$unchangedFailures[]=$id;
 elseif($now==='NOT_RUN')$unrun[]=$id;
}
$issues=[...array_unique(array_map(static fn($v)=>explode('|',$v['test'])[0],$regressions))];
$report=[
 'schema'=>1,'suite'=>'full matrix baseline vs test-only Safe Native prototype',
 'php'=>$baseline['php_version'],'symfony'=>$baseline['symfony_version'],
 'variants'=>504,'baseline_counts'=>$baseline['status_counts'],'prototype_counts'=>$prototype['status_counts'],
 'improved_evaluations'=>count($improvements),'regressed_evaluations'=>count($regressions),
 'still_failed_evaluations'=>count($unchangedFailures),'still_not_run_evaluations'=>count($unrun),
 'regressed_ids'=>$issues,'improvements'=>$improvements,'regressions'=>$regressions
];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
if($regressions)exit(1);
