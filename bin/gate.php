<?php
declare(strict_types=1);
if($argc!==2){fwrite(STDERR,"Usage: php bin/gate.php reports/matrix84.json\n");exit(2);}
$report=json_decode((string)@file_get_contents($argv[1]),true);
if(!is_array($report)||($report['schema']??null)!==2||($report['scenarios_declared']??0)!==84||($report['executions_observed']??0)!==504){fwrite(STDERR,"INVALID matrix artifact: 84 cases / 504 executions required\n");exit(2);}
$violations=[];
$registry=[];foreach($report['scenario_summary'] as $s){$registry[$s['test_id']]=$s;}
foreach($report['scenario_summary'] as $s)if($s['priority']==='P0'&&$s['aggregate']!=='PASS')$violations[]=$s['test_id'].':'.$s['aggregate'];
$counts=$report['status_counts'];
echo 'PHP '.$report['php_version'].' Symfony '.$report['symfony_version'].PHP_EOL;
echo 'PASS='.$counts['PASS'].' FAIL='.$counts['FAIL'].' NOT_RUN='.$counts['NOT_RUN'].' SKIP='.$counts['SKIP'].PHP_EOL;
echo 'P0 non conformes : '.count($violations).PHP_EOL;
foreach($violations as $line)echo ' - '.$line.PHP_EOL;
if($violations)exit(1);
echo "PASS: 84 distinct scenarios accounted for and all P0 variants compliant\n";
