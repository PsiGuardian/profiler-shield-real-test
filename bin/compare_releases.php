<?php
declare(strict_types=1);

/**
 * Machine-enforced v1.0.0 -> actual dev-1.0.1 comparison.
 * PASS -> FAIL/NOT_RUN/SKIP is a blocking regression.
 * NOT_RUN is never counted as PASS.
 */
if ($argc !== 3) {fwrite(STDERR, "Usage: php bin/compare_releases.php baseline.json candidate.json\n");exit(2);}
$baseline=json_decode((string)@file_get_contents($argv[1]),true);
$candidate=json_decode((string)@file_get_contents($argv[2]),true);
foreach(['baseline'=>$baseline,'candidate'=>$candidate] as $name=>$r) {
    if (!is_array($r) || ($r['schema']??null)!==2 || ($r['scenarios_declared']??null)!==84
        || ($r['executions_observed']??null)!==504 || ($r['variants_per_scenario']??null)!==6
        || !isset($r['results']) || count($r['results'])!==504
        || ($r['test_only_prototype']??null)!==false) {
        fwrite(STDERR, "Invalid or simulated {$name} report: require 84 cases, 504 real executions, no prototype\n");
        exit(2);
    }
}
if (($baseline['release']??null)!=='v1.0.0' || ($candidate['release']??null)!=='dev-1.0.1'
    || !preg_match('/^[0-9a-f]{40}$/D',(string)($candidate['source_commit']??''))) {
    fwrite(STDERR,"Release or source SHA not verified\n");exit(2);
}
if ($baseline['symfony_version']!==$candidate['symfony_version']
    || $baseline['php_version']!==$candidate['php_version']) {
    fwrite(STDERR,"Baseline and candidate have mismatched PHP/Symfony versions\n");exit(2);
}
$index=static function(array $report):array{
    $mapped=[];
    foreach($report['results'] as $entry){
        $key=$entry['test_id'].'|'.$entry['mode'].'|'.($entry['debug']?'1':'0');
        if(isset($mapped[$key]))throw new RuntimeException('Duplicated variant: '.$key);
        $mapped[$key]=$entry;
    }
    return $mapped;
};
try{$before=$index($baseline);$after=$index($candidate);}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}
if(count($before)!==504||count($after)!==504
    ||array_diff_key($before,$after)||array_diff_key($after,$before)) {
    fwrite(STDERR,"Scenario key set mismatch\n");exit(2);
}
$changes=[];$categories=['PASS'=>0,'FAIL'=>0,'NOT_RUN'=>0,'SKIP'=>0];
$improved=0;$regressed=0;$stillFailed=0;$notRun=0;$newFailures=0;
foreach($before as $key=>$old){
    $new=$after[$key];$from=$old['status'];$to=$new['status'];
    if(!isset($categories[$to])){fwrite(STDERR,"Unrecognized result status ".$to."\n");exit(2);}
    $categories[$to]++;
    if($from==='PASS'&&$to!=='PASS'){$regressed++;$changes[]=['test'=>$key,'change'=>"$from->$to",'why'=>$new['reasons']??[]];}
    elseif($from!=='PASS'&&$to==='PASS'){$improved++;$changes[]=['test'=>$key,'change'=>"$from->$to"];}
    elseif($from==='NOT_RUN'&&$to==='FAIL'){$newFailures++;$changes[]=['test'=>$key,'change'=>"$from->$to",'why'=>$new['reasons']??[]];}
    if($to==='FAIL')$stillFailed++;
    if($to==='NOT_RUN')$notRun++;
}
$report=[
 'schema'=>1,'name'=>'Released v1.0.0 vs actual GitHub dev-1.0.1','prototype'=>false,
 'php_version'=>$candidate['php_version'],'symfony_version'=>$candidate['symfony_version'],
 'baseline_release'=>$baseline['release'],'candidate_release'=>$candidate['release'],
 'candidate_sha'=>$candidate['source_commit'],
 'scenarios'=>84,'variants'=>504,'baseline_counts'=>$baseline['status_counts'],
 'candidate_counts'=>$categories,'improved'=>$improved,'regressed'=>$regressed,
 'new_failures_from_unrun'=>$newFailures,'remaining_failed'=>$stillFailed,'remaining_not_run'=>$notRun,
 'baseline_p0_unsatisfied'=>$baseline['p0_unsatisfied'],
 'candidate_p0_unsatisfied'=>$candidate['p0_unsatisfied'],
 'changes'=>$changes
];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";
if($regressed>0)exit(1);
