<?php
declare(strict_types=1);
namespace RealTest\Matrix;
final class HttpProbes {
 public static function run(string $id, Context $x):array {
  $errors=[];$notes=[];
  $ok=static function(bool $condition,string $message)use(&$errors){if(!$condition)$errors[]=$message;};
  $g=fn(string $method,string $path,string $accept='application/json',array $headers=[],array $cookies=[])=>$x->http(true,$method,$path,$accept,$headers,$cookies);
  $c=fn(string $method,string $path,string $accept='application/json')=>$x->http(false,$method,$path,$accept);
  $same=static function(array $a,array $b,string $label)use($ok){
   $ok($a['status']===$b['status'],$label.': status');
   $ok($a['body']===$b['body'],$label.': body');
   $ok($a['mime']===$b['mime'],$label.': mime');
  };
  $secret=static function(array $r)use($ok){
   foreach(['GPS_PRIVATE_SQL_SENTINEL_2026','GPS_PRIVATE_ENV_SENTINEL_2026','GPS_PRIVATE_PATH_SENTINEL_2026','GPS_PRIVATE_TOKEN_SENTINEL_2026'] as $sentinel){
    $ok(!str_contains($r['body'].' '.json_encode($r['headers']),$sentinel),'private sentinel disclosure');
   }
  };
  $json=static function(array $r)use($ok):array{
   $v=json_decode($r['body'],true);
   $ok(is_array($v),'not parseable JSON');
   return is_array($v)?$v:[];
  };
  $xml=static function(array $r)use($ok):void{
   $ok(str_contains($r['mime'],'xml'),'Content-Type not XML');
   $previous=libxml_use_internal_errors(true);$dom=new \DOMDocument();
   $valid=@$dom->loadXML($r['body'],LIBXML_NONET);
   $ok($valid,'malformed XML');
   libxml_clear_errors();libxml_use_internal_errors($previous);
  };
  $status=static function(array $r,int $expected)use($ok){$ok($r['status']===$expected,'HTTP status expected '.$expected.', found '.$r['status']);};
  $unsupported=static fn(string $why)=>['status'=>'NOT_RUN','assertions'=>0,'reasons'=>[$why]];
  $missing=static function(string $name)use($ok){$ok(interface_exists($name)||class_exists($name),'public feature contract not yet implemented: '.$name);};
  try {
   switch($id) {
    case 'BASE-01':$same($c('GET','/api/health'),$g('GET','/api/health'),'healthy API');break;
    case 'BASE-02':$a=$c('GET','/api/validation');$b=$g('GET','/api/validation');$same($a,$b,'validation response');$status($b,422);$v=$json($b);$ok(($v['error']['code']??'')==='VALIDATION_FAILED','validation code lost');break;
    case 'BASE-03':$a=$c('GET','/api/xml','application/xml');$b=$g('GET','/api/xml','application/xml');$same($a,$b,'XML response');$xml($b);break;
    case 'BASE-04':$a=$c('GET','/api/throw/validation');$b=$g('GET','/api/throw/validation');$status($b,422);$av=$json($a);$bv=$json($b);$ok(($bv['error']['code']??null)===($av['error']['code']??'API_DOMAIN_ERROR'),'approved domain error code removed');$secret($b);break;
    case 'BASE-05':$a=$json($c('GET','/api/throw/validation'));$b=$json($g('GET','/api/throw/validation'));$ok(($a['error']['code']??'')==='API_DOMAIN_ERROR','clean consumer fixture invalid');$ok(($b['error']['code']??'')==='API_DOMAIN_ERROR','API consumer cannot select error behavior');break;
    case 'BASE-06':$a=$c('GET','/api/throttle');$b=$g('GET','/api/throttle');$same($a,$b,'rate response');$status($b,429);$ok(($b['headers']['retry-after']??'')==='37','Retry-After missing');break;
    case 'BASE-07':$b=$g('POST','/api/health');$status($b,405);$ok(str_contains(($b['headers']['allow']??''),'GET'),'Allow header lost');break;
    case 'BASE-08':foreach([['GET','/api/missing',404],['OPTIONS','/api/health',405]] as [$method,$path,$n]){$b=$g($method,$path);$status($b,$n);$secret($b);}break;
    case 'SAFE-01':$b=$g('GET','/api/throw/fatal');$status($b,500);$secret($b);break;
    case 'SAFE-02':$b=$g('GET','/api/throw/fatal');$status($b,500);$secret($b);break;
    case 'SAFE-03':$b=$g('GET','/api/throw/validation');$status($b,422);$secret($b);$ok(!str_contains($b['body'],'GPS_PRIVATE_'),'untrusted 4xx content exposed');break;
    case 'SAFE-04':$missing('OmegaGuardian\\ProfilerShield\\Security\\PublicErrorMapperInterface');$b=$g('GET','/api/status/409');$status($b,409);$secret($b);break;
    case 'SAFE-05':$missing('OmegaGuardian\\ProfilerShield\\Security\\PublicErrorRegistry');break;
    case 'SAFE-06':$missing('OmegaGuardian\\ProfilerShield\\Security\\PublicErrorDescriptor');break;
    case 'SAFE-07':$b=$g('GET','/api/nested');$status($b,500);$secret($b);break;
    case 'SAFE-08':$a=$c('GET','/api/debug-header');$b=$g('GET','/api/debug-header');$status($b,200);$ok($a['body']===$b['body'],'business body changed');$ok(!isset($b['headers']['x-debug-token']),'diagnostic header leaked');break;
    case 'SAFE-09':$cookie=$x->session();$req=$x->requestWithCookies($cookie);$access=new \OmegaGuardian\ProfilerShield\Security\DiagnosticAccess($x->sessions,$x->accounts,$x->policy);$ok($access->allowed($req),'approved session not recognized');$b=$g('GET','/_profiler/','text/html',[],$cookie);$ok($b['status']!==403&&$b['status']!==404,'profiler refused valid authorized session');break;
    case 'SAFE-10':$vault=$x->store->root().'/accounts.json';$backup=is_file($vault)?file_get_contents($vault):null;try{if($backup!==null)file_put_contents($vault,'{bad json');$b=$g('GET','/api/throw/fatal');$status($b,500);$secret($b);}finally{if($backup!==null)file_put_contents($vault,$backup);}break;
    case 'SAFE-11':$a=$c('GET','/web/trusted','text/html');$b=$g('GET','/web/trusted','text/html');$status($b,404);if($x->mode==='native')$same($a,$b,'native HTML safe page');else{$ok(!str_contains($b['body'],'Trusted host page'),'explicit obfuscation not performed');$secret($b);}break;
    case 'SAFE-12':$b=$g('GET','/web/error','text/html');$status($b,404);$ok($b['mime']==='text/html','HTML error MIME invalid');if($x->mode==='custom')$ok(str_contains($b['body'],'Public safe error'),'configured public text absent');break;
    case 'SAFE-13':$renderer=new \OmegaGuardian\ProfilerShield\Security\PublicErrorRenderer($x->settings,$x->root);$b=$renderer->neutral(500,['error_mode'=>'custom','error_custom_text'=>'<script>alert(1)</script>']);$ok(!str_contains($b->getContent(),'<script>'),'unescaped markup');$ok(str_contains($b->getContent(),'&lt;script&gt;'),'public text not escaped');break;
    case 'SAFE-14':$cookie=$x->session();$req=$x->requestWithCookies($cookie);$before=$x->sessions->current($req);$oldTotp=$x->accounts->find('labadmin')['totp'];try{$x->accounts->update('labadmin',static function($u){$u['totp']='ENROLLED_TEST_ONLY';return $u;});$after=$x->sessions->current($req);$ok($before!==null&&$after!==null,'current session revoked after MFA enrolment');}finally{$x->accounts->update('labadmin',static function($u)use($oldTotp){$u['totp']=$oldTotp;return $u;});}break;
    case 'HTTP-01':foreach([400,401,403,404,405,409,422,429,500,503] as $n){$b=$g('GET','/api/status/'.$n);$status($b,$n);$secret($b);}break;
    case 'HTTP-02':$b=$g('GET','/api/throw/auth');$status($b,401);$ok(str_contains(($b['headers']['www-authenticate']??''),'Bearer'),'WWW-Authenticate lost');break;
    case 'HTTP-03':$b=$g('GET','/api/throw/throttle');$status($b,429);$ok(($b['headers']['retry-after']??'')==='37','Retry-After lost');break;
    case 'HTTP-04':$b=$g('GET','/api/throw/method');$status($b,405);$ok(str_contains(($b['headers']['allow']??''),'GET'),'Allow lost');break;
    case 'HTTP-05':return $unsupported('HEAD transport body semantics need HTTP server-level request; Kernel::handle alone does not establish bytes sent on the wire.');
    case 'HTTP-06':foreach([['/api/empty',204],['/api/not-modified',304]] as [$path,$n]){$b=$g('GET',$path);$status($b,$n);$ok($b['body']==='','no-body status returned content');}break;
    case 'HTTP-07':$a=$c('OPTIONS','/api/cors');$b=$g('OPTIONS','/api/cors');$same($a,$b,'CORS preflight');$ok(($b['headers']['access-control-allow-origin']??'')==='https://client.example','CORS origin lost');break;
    case 'HTTP-08':$a=$c('GET','/api/cors-error');$b=$g('GET','/api/cors-error');$same($a,$b,'public safe CORS error');$ok(isset($b['headers']['access-control-allow-origin']),'error CORS origin lost');break;
    case 'HTTP-09':$b=$g('GET','/api/throw/fatal','application/json');$status($b,500);$ok(str_contains($b['mime'],'json'),'Accept JSON not honored');$json($b);break;
    case 'HTTP-10':$b=$g('GET','/api/throw/fatal','application/xml');$status($b,500);$xml($b);$secret($b);break;
    case 'HTTP-11':$b=$g('GET','/api/throw/fatal','application/problem+json');$status($b,500);$ok($b['mime']==='application/problem+json','RFC9457 MIME not used');$v=$json($b);$ok(($v['status']??null)===500,'RFC9457 status absent');$secret($b);break;
    case 'HTTP-12':$b=$g('GET','/api/throw/fatal','application/problem+xml');$status($b,500);$ok($b['mime']==='application/problem+xml','RFC9457 XML MIME absent');$xml($b);$secret($b);break;
    case 'HTTP-13':$b=$g('GET','/api/throw/fatal','text/html;q=0.1, application/json;q=0.9');$ok(str_contains($b['mime'],'json'),'content negotiation ignores q-weight priority');$secret($b);break;
    case 'HTTP-14':foreach(['application/*+json','application/json,*/*;q=0.1','application/problem+json','*/*'] as $accept){$b=$g('GET','/api/throw/fatal',$accept);$status($b,500);$secret($b);$ok($b['body']!=='','empty fallback response');}break;
    case 'HTTP-15':$b=$g('GET','/web/error-headers','text/html');if($x->mode!=='native'){$ok(!isset($b['headers']['etag']),'stale ETag retained');$ok(!isset($b['headers']['content-encoding']),'stale compression retained');$ok(!isset($b['headers']['content-length']),'stale content length retained');}else $ok($b['status']===500,'native response status changed');break;
    case 'HTTP-16':$a=$c('POST','/api/cookie','text/html');$b=$g('POST','/api/cookie','text/html');$same($a,$b,'redirect response');$ok(($a['headers']['location']??'')===($b['headers']['location']??''),'Location changed');$ok(count($b['cookies'])===count($a['cookies'])&&count($b['cookies'])>0,'cookie missing');break;
    case 'HTTP-17':foreach(['/api/stream'] as $path){$a=$c('GET',$path,'text/plain');$b=$g('GET',$path,'text/plain');$same($a,$b,'streamed body');$ok(str_contains($b['body'],'safe-stream-data'),'stream output absent');}break;
    case 'HTTP-18':$a=$c('GET','/api/sse','text/event-stream');$b=$g('GET','/api/sse','text/event-stream');$same($a,$b,'SSE body');$ok(substr_count($b['body'],'data: ')===10,'SSE events missing');break;
    case 'HTTP-19':$a=$c('GET','/api/validation');$b=$g('GET','/api/validation');$same($a,$b,'error mode must not alter API');break;
    case 'HTTP-20':foreach(['application/xml','application/json'] as $accept){$b=$g('GET','/api/throw/fatal',$accept);$ok(($accept==='application/xml'?str_contains($b['mime'],'xml'):str_contains($b['mime'],'json')),'contradictory/explicit API format unsupported');}break;
    case 'PROF-01':foreach(['/_profiler/','/_wdt/x'] as $path){$b=$g('GET',$path,'text/html');$ok(in_array($b['status'],[403,404],true),'unauthorized profiler reachable: '.$path);$secret($b);}break;
    case 'PROF-02':foreach(['/%5fprofiler/','/%255fprofiler/','/index.php/_profiler/','/app_dev.php/_wdt/x','/_profiler%2fabc'] as $path){$b=$g('GET',$path,'text/html');$ok(in_array($b['status'],[403,404],true),'encoded diagnostic reached: '.$path);$secret($b);} $ok(\OmegaGuardian\ProfilerShield\EventListener\GuardianSubscriber::isDiagnosticPath('/_wdt\\x'),'backslash path not recognized by direct gate');break;
    case 'PROF-03':$same($c('GET','/docs/_profiler/guide'),$g('GET','/docs/_profiler/guide'),'legitimate path');break;
    case 'PROF-04':$cookies=$x->session();$req=$x->requestWithCookies($cookies);$x->sessions->modify($req,static function($s){$s['expires']=time()-1;return $s;});$b=$g('GET','/_profiler/','text/html',[],$cookies);$ok(in_array($b['status'],[403,404],true),'expired session permits profiler');break;
    case 'PROF-05':$cookies=$x->session();$x->accounts->update('labadmin',static function($u){$u['disabled']=true;return $u;});try{$b=$g('GET','/_profiler/','text/html',[],$cookies);$ok(in_array($b['status'],[403,404],true),'disabled account permits profiler');}finally{$x->accounts->update('labadmin',static function($u){$u['disabled']=false;return $u;});}break;
    case 'PROF-06':$cookies=$x->session();$x->settings->set(['ip_allowlist'=>'192.0.2.0/24']);try{$b=$g('GET','/_profiler/','text/html',[],$cookies);$ok(in_array($b['status'],[403,404],true),'non-allowlisted IP permitted');}finally{$x->settings->set(['ip_allowlist'=>'']);}break;
    case 'PROF-07':$cookies=$x->session();$x->settings->set(['jit_enabled'=>true]);try{$b=$g('GET','/_profiler/','text/html',[],$cookies);$ok(in_array($b['status'],[403,404],true),'JIT-disabled user permitted');}finally{$x->settings->set(['jit_enabled'=>false]);}break;
    case 'PROF-08':$cookies=$x->session();$x->store->change('control',static fn($v)=>array_replace($v,['lockdown'=>true]));try{$b=$g('GET','/_profiler/','text/html',[],$cookies);$ok(in_array($b['status'],[403,404],true),'lockdown not enforced');$status($g('GET','/api/health'),200);}finally{$x->store->change('control',static fn($v)=>array_replace($v,['lockdown'=>false]));}break;
    case 'PROF-09':Context::env('GUARDIAN_COLLECTION_BRIDGE','0');$b=$g('GET','/api/health');$ok(!isset($b['headers']['x-debug-token']),'anonymous token with bridge disabled');break;
    case 'PROF-10':Context::env('GUARDIAN_COLLECTION_BRIDGE','1');try{$b=$g('GET','/api/health');$ok(!isset($b['headers']['x-debug-token']),'anonymous token with bridge enabled');}finally{Context::env('GUARDIAN_COLLECTION_BRIDGE','0');}break;
    case 'PROF-11':Context::env('GUARDIAN_COLLECTION_BRIDGE','1');$cookies=$x->session();try{$b=$g('GET','/api/health','application/json',[],$cookies);$ok(isset($b['headers']['x-debug-token']),'authorized collection unavailable');}finally{Context::env('GUARDIAN_COLLECTION_BRIDGE','0');}break;
    case 'PROF-12':Context::env('GUARDIAN_COLLECTION_BRIDGE','1');$cookies=$x->session();$req=$x->requestWithCookies($cookies);$x->sessions->end($req,new \Symfony\Component\HttpFoundation\Response());try{$b=$g('GET','/api/health','application/json',[],$cookies);$ok(!isset($b['headers']['x-debug-token']),'token persists after logout');}finally{Context::env('GUARDIAN_COLLECTION_BRIDGE','0');}break;
    case 'PROF-13':$ev=$x->guard->getContainer()->get('event_dispatcher');$ls=$ev->getListeners(\Symfony\Component\HttpKernel\KernelEvents::REQUEST);$found=false;foreach($ls as $entry){if(is_array($entry)&&isset($entry[0])&&$entry[0] instanceof \OmegaGuardian\ProfilerShield\EventListener\GuardianSubscriber)$found=true;}$ok($found,'pre-router guard not registered');break;
    case 'PROF-14':
     $prev=$_SERVER['REAL_TEST_GUARDED']??'1';
     \RealTest\Matrix\Context::env('REAL_TEST_GUARDED','0');
     $prod=new \RealTest\Fixture\ProductionKernel($x->root);
     try{$prod->boot();$req=\Symfony\Component\HttpFoundation\Request::create('https://localhost/_profiler/','GET');
      $res=$prod->handle($req);$ok(in_array($res->getStatusCode(),[403,404],true),'production profiler route reachable');
      $prod->terminate($req,$res);
      $req2=\Symfony\Component\HttpFoundation\Request::create('https://localhost/api/health','GET');
      $business=$prod->handle($req2);$ok($business->getStatusCode()===200,'production business route unavailable');$prod->terminate($req2,$business);
      $ok(!$prod->getContainer()->has('web_profiler.controller.profiler'),'web profiler controller active in prod');
     }finally{$prod->shutdown();\RealTest\Matrix\Context::env('REAL_TEST_GUARDED',$prev);}
     break;
    default:return $unsupported('No HTTP-specific executable oracle for '.$id);
   }
  }catch(\Throwable $e){$errors[]='test infrastructure exception: '.get_class($e).': '.substr($e->getMessage(),0,180);}
  return ['status'=>$errors?'FAIL':'PASS','assertions'=>1+count($errors),'reasons'=>array_values(array_unique($errors))];
 }
}
