<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use RealTest\Matrix\Context;
use RealTest\Prototype\{PublicErrorDescriptor,SafeNativeRenderer};
use Symfony\Component\HttpFoundation\Request;

ob_start();
$strict=in_array('--strict',$argv,true);
$rows=[];$checks=0;$passes=0;$fails=0;$baselineDefects=[];
$secrets=['GPS_PRIVATE_SQL_SENTINEL_2026','GPS_PRIVATE_PATH_SENTINEL_2026','GPS_PRIVATE_ENV_SENTINEL_2026','GPS_PRIVATE_TOKEN_SENTINEL_2026'];

function evaluate(string $id,callable $perform):void{
 global $rows,$checks,$passes,$fails;
 $errors=[];$count=0;
 $ok=static function(bool $pass,string $reason)use(&$errors,&$count):void{$count++;if(!$pass)$errors[]=$reason;};
 try{$perform($ok);}catch(\Throwable $e){$errors[]='probe exception: '.get_class($e).': '.substr($e->getMessage(),0,150);++$count;}
 $status=$errors?'FAIL':'PASS';$checks++;if($errors)$fails++;else$passes++;
 $rows[]=['id'=>$id,'status'=>$status,'assertions'=>$count,'errors'=>array_values(array_unique($errors))];
}
function privateData(array $response,callable $ok):void {
 global $secrets;
 $published=$response['body'].' '.json_encode($response['headers']);
 foreach($secrets as $secret)$ok(!str_contains($published,$secret),'private sentinel exposed');
}
function jsonBody(array $response,callable $ok):array {
 $j=json_decode($response['body'],true);
 $ok(is_array($j),'invalid JSON');
 return is_array($j)?$j:[];
}
function compareBusiness(Context $x,string $path,string $accept,callable $ok,string $method='GET'):void {
 $a=$x->http(false,$method,$path,$accept);$b=$x->http(true,$method,$path,$accept);
 $ok($a['status']===$b['status'],'business HTTP status changed');
 $ok($a['body']===$b['body'],'business response body changed');
 $ok($a['mime']===$b['mime'],'business Content-Type changed');
 privateData($b,$ok);
}
foreach(['native','generic','custom'] as $mode){
 foreach([false,true] as $debug){
  $root=sys_get_temp_dir().'/guardian-prototype-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
  $ctx=null;$label=$mode.'-debug'.(int)$debug;
  try{
   $ctx=new Context($root,$mode,$debug,true);
   evaluate($label.'-contract-json',static function($ok)use($ctx){
    $clean=$ctx->http(false,'GET','/api/throw/validation');
    $guard=$ctx->http(true,'GET','/api/throw/validation');
    $j=jsonBody($guard,$ok);
    $ok($guard['status']===422,'trusted 422 status changed');
    $ok($guard['mime']==='application/json','trusted error MIME changed');
    $ok($clean['body']===$guard['body'],'trusted error contract differs from clean app');
    $ok(($j['error']['code']??null)==='API_DOMAIN_ERROR','trusted public code removed');
    $ok(($guard['headers']['x-fixture']??'')==='clean-api','approved public header missing');
    privateData($guard,$ok);
   });
   evaluate($label.'-healthy',static fn($ok)=>compareBusiness($ctx,'/api/health','application/json',$ok));
   evaluate($label.'-json-prebuilt-422',static fn($ok)=>compareBusiness($ctx,'/api/validation','application/json',$ok));
   evaluate($label.'-xml-prebuilt-422',static fn($ok)=>compareBusiness($ctx,'/api/xml','application/xml',$ok));
   evaluate($label.'-cors-prebuilt-422',static fn($ok)=>compareBusiness($ctx,'/api/cors-error','application/json',$ok));
   evaluate($label.'-cors-options',static fn($ok)=>compareBusiness($ctx,'/api/cors','application/json',$ok,'OPTIONS'));
   evaluate($label.'-fatal-json',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/throw/fatal');
    $j=jsonBody($r,$ok);$ok($r['status']===500,'fatal exception status changed');
    $ok($r['mime']==='application/json','JSON fallback MIME wrong');
    $ok(($j['error']['code']??null)==='REQUEST_FAILED','unknown exception not neutralized');
    privateData($r,$ok);
   });
   evaluate($label.'-throwing-mapper',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/throw/mapper-broken');
    $j=jsonBody($r,$ok);$ok($r['status']===500,'throwing mapper changed status');
    $ok(($j['error']['code']??null)==='REQUEST_FAILED','throwing mapper failed closed');
    privateData($r,$ok);
   });
   evaluate($label.'-nested-exception',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/nested');
    $ok($r['status']===500,'nested exception status changed');privateData($r,$ok);
   });
   foreach([['auth',401,'www-authenticate','Bearer'],['throttle',429,'retry-after','37'],['method',405,'allow','GET']] as [$kind,$status,$header,$value]){
    evaluate($label.'-protocol-'.$kind,static function($ok)use($ctx,$kind,$status,$header,$value){
     $r=$ctx->http(true,'GET','/api/throw/'.$kind);
     $ok($r['status']===$status,'protocol status changed');
     $ok(str_contains($r['headers'][$header]??'',$value),'required protocol header dropped');
     privateData($r,$ok);
    });
   }
   evaluate($label.'-unknown-xml',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/throw/fatal','application/xml');
    $ok($r['mime']==='application/xml','XML Content-Type wrong');
    $dom=new \DOMDocument();
    $ok(@$dom->loadXML($r['body'],LIBXML_NONET),'fallback XML is not well-formed');
    $ok($r['status']===500,'XML fallback status changed');privateData($r,$ok);
   });
   evaluate($label.'-problem-json',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/throw/fatal','application/problem+json');
    $j=jsonBody($r,$ok);
    $ok($r['mime']==='application/problem+json','RFC 9457 JSON MIME wrong');
    $ok(($j['status']??0)===500 && ($j['type']??'')==='about:blank','RFC 9457 JSON shape invalid');
    privateData($r,$ok);
   });
   evaluate($label.'-problem-xml',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/throw/fatal','application/problem+xml');
    $ok($r['mime']==='application/problem+xml','RFC 9457 XML MIME wrong');
    $dom=new \DOMDocument();$parsed=@$dom->loadXML($r['body'],LIBXML_NONET);
    $ok($parsed,'RFC 9457 XML not well formed');
    if($parsed)$ok($dom->documentElement->localName==='problem' && $dom->documentElement->namespaceURI==='urn:ietf:rfc:7807','RFC 9457 XML namespace wrong');
    privateData($r,$ok);
   });
   evaluate($label.'-weighted-json',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/throw/fatal','text/html;q=0.1,application/json;q=0.9');
    $ok($r['mime']==='application/json','Accept q weights ignored');
   });
   evaluate($label.'-weighted-xml',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/throw/fatal','application/json;q=0,application/xml;q=1');
    $ok($r['mime']==='application/xml','q=0 JSON was accepted or XML was ignored');
   });
   evaluate($label.'-wildcard-json',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/throw/fatal','application/*+json');
    $ok($r['mime']==='application/json','structured suffix wildcard not recognized');
   });
   evaluate($label.'-anonymous-profiler-deny',static function($ok)use($ctx){
    foreach(['/_profiler/','/_wdt/x','/%5fprofiler/'] as $path){
     $r=$ctx->http(true,'GET',$path,'text/html');
     $ok(in_array($r['status'],[403,404],true),'profiler granted access to anonymous user');
     privateData($r,$ok);
    }
   });
   evaluate($label.'-debug-metadata',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/api/debug-header');
    $ok($r['status']===200,'normal response status changed');
    $ok(!array_key_exists('x-debug-token',$r['headers']),'debug token exposed');
    $j=jsonBody($r,$ok);$ok(($j['ok']??false)===true,'business JSON changed');
    privateData($r,$ok);
   });
   evaluate($label.'-html-unchanged-security',static function($ok)use($ctx){
    $r=$ctx->http(true,'GET','/web/error','text/html');
    $ok($r['status']===404,'public HTML status changed');
    $ok(str_contains($r['mime'],'html'),'HTML mode response changed type');
    privateData($r,$ok);
   });
   evaluate($label.'-descriptor-rejects-header',static function($ok){
    $rejected=false;try{new PublicErrorDescriptor(422,'VALIDATION_FAILED','Public',['X-Debug-Token'=>'secret']);}catch(\InvalidArgumentException){$rejected=true;}
    $ok($rejected,'unapproved header accepted by descriptor');
    $rejected=false;try{new PublicErrorDescriptor(422,'VALIDATION_FAILED','Public',['Allow'=>"GET\r\nX-Test: 1"]);}catch(\InvalidArgumentException){$rejected=true;}
    $ok($rejected,'CRLF header injection accepted by descriptor');
   });
   evaluate($label.'-descriptor-rejects-status',static function($ok){
    $rejected=false;try{new PublicErrorDescriptor(200,'VALIDATION_FAILED','Public');}catch(\InvalidArgumentException){$rejected=true;}
    $ok($rejected,'public error allowed status 200');
   });
   evaluate($label.'-negotiation-no-accept',static function($ok){
    $neg=new SafeNativeRenderer();
    $request=Request::create('https://localhost/api/test','GET',[],[],[],[]);
    $ok($neg->negotiate($request)==='text','missing Accept is not deterministic');
   });
  }catch(\Throwable $e){
   evaluate($label.'-fixture-init',static function($ok)use($e){$ok(false,'Unable to boot test-only prototype: '.get_class($e).': '.substr($e->getMessage(),0,160));});
  }finally{
   if($ctx!==null)$ctx->cleanup();
   $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
   foreach($it as $entry){$entry->isDir()?@rmdir($entry->getPathname()):@unlink($entry->getPathname());}
   @rmdir($root);
  }
 }
}
$discard=ob_get_clean();
if($discard!=='')fwrite(STDERR,'Fixture stdout suppressed: '.strlen($discard).' bytes'.PHP_EOL);
$report=[
 'suite'=>'test-only Safe Native proof of concept',
 'disclaimer'=>'Experimental listener only in PsiGuardian/profiler-shield-real-test, not bundled/released',
 'symfony'=>\Symfony\Component\HttpKernel\Kernel::VERSION,'php'=>PHP_VERSION,
 'configurations'=>6,'expected_probes'=>144,'observed_probes'=>$checks,
 'passed'=>$passes,'failed'=>$fails,'rows'=>$rows
];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
if($strict && ($fails || $checks!==144))exit(1);
