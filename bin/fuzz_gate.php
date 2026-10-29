<?php
declare(strict_types=1);
$path=$argv[1]??'reports/fuzz.json';
$data=json_decode((string)@file_get_contents($path),true);
if(!is_array($data)||($data['schema']??0)!==1||($data['total']??0)!==900){
 fwrite(STDERR,"Invalid fuzz report, expected 900 generated cases.\n");exit(2);
}
foreach($data['counts'] as $status=>$n)echo $status.'='.$n.' ';
echo PHP_EOL;
foreach($data['runs'] as $run){
 echo $run['mode'].' DEBUG='.($run['debug']?'1':'0').' '.json_encode($run['counts']).PHP_EOL;
 foreach($run['samples'] as $example)echo json_encode($example,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
}
exit(($data['counts']['FAIL']??0)>0)?1:0);
