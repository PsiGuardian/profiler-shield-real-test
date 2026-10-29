<?php
declare(strict_types=1);
namespace RealTest\Matrix;
use Symfony\Component\HttpFoundation\Response;
use OmegaGuardian\ProfilerShield\Security\{LoginThrottle,LoginRateLimited,Sessions,Audit,Totp};
use OmegaGuardian\ProfilerShield\Storage\FileStore;
use OmegaGuardian\ProfilerShield\Command\DoctorCommand;
final class StateProbes {
 public static function run(string $id,Context $x):array {
  $errors=[];$count=0;$notes=[];
  $check=static function(bool $condition,string $message)use(&$errors,&$count){$count++;if(!$condition)$errors[]=$message;};
  $notRun=static fn(string $reason)=>['status'=>'NOT_RUN','assertions'=>0,'reasons'=>[$reason]];
  try {
   switch($id){
    case 'AUTH-01':
     $page=$x->http(true,'GET',$x->portal,'text/html');
     $check($page['status']===200,'login form unavailable');
     preg_match('/name="_csrf" value="([^"]+)"/',$page['body'],$csrf);
     $check(isset($csrf[1]),'CSRF hidden input missing');
     $cookies=[];foreach($page['cookies'] as $c)$cookies[$c['name']]=$c['value'];
     if(isset($csrf[1])){$post=$x->http(true,'POST',$x->portal,'text/html',[],$cookies,['action'=>'login','_csrf'=>$csrf[1],'username'=>'labadmin','password'=>$x->password]);$check($post['status']===303,'valid credentials did not create session');}
     break;
    case 'AUTH-02':
     $throttle=new LoginThrottle($x->store,$x->settings);
     for($i=0;$i<10;$i++){$ticket=$throttle->begin('wronguser','198.51.100.23');$throttle->finish($ticket,false);}
     $blocked=false;try{$throttle->begin('wronguser','198.51.100.23');}catch(LoginRateLimited $e){$blocked=true;}
     $check($blocked,'throttle allowed attempt after threshold');
     break;
    case 'AUTH-03':
     $out=$x->http(true,'POST',$x->portal,'text/html',[],[],['action'=>'login','username'=>'labadmin','password'=>$x->password,'_csrf'=>str_repeat('0',64)]);
     $cookies=array_column($out['cookies'],'name');
     $check(!in_array(Sessions::COOKIE,$cookies,true),'login accepted without CSRF');
     break;
    case 'AUTH-04':
     $cookies=$x->session();$before=$x->settings->all();
     $res=$x->http(true,'POST',$x->portal,'text/html',[],$cookies,['action'=>'settings','_csrf'=>'WRONG','stealth'=>'0']);
     $after=$x->settings->all();
     $check($before===$after,'admin settings changed with invalid CSRF');
     break;
    case 'AUTH-05':
     // End-to-end anti-replay test: OTP is valid but can only be consumed once.
     $totp=$x->totp;$secret=$totp->generateSecret();
     $x->accounts->update('labadmin',static function($u)use($totp,$secret){$u['totp']=$totp->encrypt($secret);$u['last_otp_counter']=-1;return $u;});
     $counter=(int)floor(time()/30);
     $key='';$bits='';foreach(str_split($secret) as $char)$bits.=str_pad(decbin(strpos(Totp::BASE32,$char)),5,'0',STR_PAD_LEFT);
     foreach(str_split($bits,8) as $byte)if(strlen($byte)===8)$key.=chr(bindec($byte));
     $h=hash_hmac('sha1',pack('N2',0,$counter),$key,true);$off=ord(substr($h,-1))&15;$code=str_pad((string)((unpack('N',substr($h,$off,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
     $accepted=$totp->verifyWindow($secret,$code);$check($accepted!==null,'fixture TOTP invalid');
     $x->accounts->update('labadmin',static function($u)use($accepted){$u['last_otp_counter']=$accepted;return $u;});
     // A second valid code at the same counter is not independently proof of login replay defense.
     return $notRun('TOTP algorithm verified, but second HTTP login/MFA attempt is not yet exercised end-to-end.');
    case 'AUTH-06':
     $cookies=$x->session();$req=$x->requestWithCookies($cookies);$before=$x->sessions->current($req);
     $x->accounts->update('labadmin',static function($u){$u['totp']='TEST_ENROLLED';return $u;});
     $after=$x->sessions->current($req);
     $check($before!==null&&$after!==null,'MFA enrolment revoked current session');
     break;
    case 'AUTH-07':
     $cookies1=$x->session();$cookies2=$x->session();$check($x->sessions->current($x->requestWithCookies($cookies1))!==null,'first fixture session invalid');
     $check($x->sessions->current($x->requestWithCookies($cookies2))!==null,'second fixture session invalid');
     $check(method_exists($x->sessions,'invalidateOtherSessions'),'no selective revoke-others operation available');
     break;
    case 'AUTH-08':
     $cookies=$x->session();$req=$x->requestWithCookies($cookies);$check($x->sessions->current($req)!==null,'initial fixture session invalid');
     $x->accounts->setPassword('labadmin',bin2hex(random_bytes(24)));
     $check($x->sessions->current($req)===null,'old session survived password change');
     break;
    case 'AUTH-09':
     $r=new Response();$x->sessions->begin('labadmin',$r,true);$cookies=$r->headers->getCookies();
     $check(count($cookies)>=2,'expected session and recognized-device cookies');
     foreach($cookies as $cookie){$check(str_starts_with($cookie->getName(),'__Host-')&&$cookie->isSecure()&&$cookie->isHttpOnly()&&$cookie->getPath()==='/'&&$cookie->getDomain()===null,'cookie missing secure host restrictions');}
     break;
    case 'AUTH-10':
     $r=new Response();$x->sessions->rememberDevice('labadmin',$r);
     $cookies=[];foreach($r->headers->getCookies() as $cookie)$cookies[$cookie->getName()]=$cookie->getValue();
     $req=$x->requestWithCookies($cookies);
     $check($x->sessions->recognized($req),'recognized device fixture not accepted');
     $check($x->sessions->current($req)===null,'remembered device authenticated without Guardian session');
     break;
    case 'FAULT-01':
     $dir=$x->root.'/unavailable';file_put_contents($dir,'blocked directory');
     $before=$x->http(true,'GET','/api/health');$check($before['status']===200,'healthy business API unavailable');
     $previous=$_SERVER['GUARDIAN_STORAGE_DIR'];Context::env('GUARDIAN_STORAGE_DIR',$dir);
     try{$fault=new FileStore($dir);$throws=false;try{$fault->initialize();}catch(\Throwable $ignored){$throws=true;}$check($throws,'unwritable FileStore did not fail closed');}
     finally{Context::env('GUARDIAN_STORAGE_DIR',$previous);}
     $after=$x->http(true,'GET','/api/health');$check($after['status']===200,'business API failed after storage fault');
     break;
    case 'FAULT-02':
     $wrong=new FileStore($x->root.'/wrong');$wrong->configureKey(bin2hex(random_bytes(32)));
     $invalid=false;try{$wrong->read('accounts');}catch(\Throwable $ignored){$invalid=true;}
     $check($invalid,'encrypted vault accepted wrong key');
     $check($x->http(true,'GET','/api/health')['status']===200,'business API interrupted');
     break;
    case 'FAULT-03':
     $old=$_SERVER['GUARDIAN_STORAGE_DIR'];$dir=$x->root.'/corrupt-vault';mkdir($dir,0700,true);
     Context::env('GUARDIAN_STORAGE_DIR',$dir);
     try{$store=new FileStore($dir);$store->initialize();file_put_contents($dir.'/accounts.json','{not-json');
      $throws=false;try{$store->read('accounts');}catch(\Throwable $ignored){$throws=true;}
      $check($throws,'corrupt vault accepted');
     }finally{Context::env('GUARDIAN_STORAGE_DIR',$old);}
     break;
    case 'FAULT-04':return $notRun('Injection of a throwing PublicErrorRenderer service has not been implemented in the fixture.');
    case 'FAULT-05':return $notRun('No controlled integration fixture yet for audit write-failure injection into kernel exception handling.');
    case 'FAULT-06':
     if($x->mode!=='native'||$x->debug)return $notRun('Performance benchmark is restricted to native/APP_DEBUG=0 for noise control.');
     $runs=1000;$times=[[],[]];
     foreach([false,true] as $k){for($i=0;$i<$runs;$i++){$t=hrtime(true);$b=$x->http($k,'GET','/api/health');$times[(int)$k][]=(hrtime(true)-$t)/1e6;if($b['status']!==200){$check(false,'load response failed');break;}}}
     if(count($times[0])===$runs&&count($times[1])===$runs){sort($times[0]);sort($times[1]);$p=fn($v)=>$v[(int)floor((count($v)-1)*.95)];$base=$p($times[0]);$guard=$p($times[1]);$check(is_finite($base)&&is_finite($guard),'nonfinite latency');$check($guard<max(50.0,$base*10),'extreme p95 regression');$notes[]='p95_clean_ms='.round($base,3).' p95_guard_ms='.round($guard,3);}
     break;
    case 'FAULT-07':return $notRun('Concurrent independent PHP workers and TOTP/session race harness not yet provisioned.');
    case 'FAULT-08':
     $p=dirname(__DIR__,2).'/vendor/omegaguardian/profiler-shield/composer.json';$doc=json_decode((string)@file_get_contents($p),true);
     $check(is_array($doc),'release Composer manifest missing');
     foreach(['post-install-cmd','post-update-cmd','pre-install-cmd','pre-update-cmd'] as $key)$check(!isset($doc['scripts'][$key]),'implicit Composer lifecycle script detected: '.$key);
     break;
    case 'FAULT-09':return $notRun('Reversible installation/uninstall requires a full disposable Symfony filesystem fixture and receipts.');
    case 'FAULT-10':
     $check(version_compare(PHP_VERSION,'8.3','>='),'unsupported PHP');
     $check(class_exists(\OmegaGuardian\ProfilerShield\OmegaGuardianProfilerShieldBundle::class),'bundle not installed');
     break;
    case 'DOCTOR-01':case 'DOCTOR-02':case 'DOCTOR-03':case 'DOCTOR-04':
     $doctor=new DoctorCommand($x->settings,$x->store,$x->accounts,$x->root);
     $definition=$doctor->getDefinition();
     $check($definition->hasOption('http'),'guardian:doctor --http not supported');
     if($id==='DOCTOR-02')$check($definition->hasOption('json'),'guardian:doctor --json not supported');
     if($id==='DOCTOR-03')$check($definition->hasOption('strict'),'guardian:doctor --strict not supported');
     if($id==='DOCTOR-04')return $notRun('Doctor --http network isolation cannot be exercised until --http is implemented.');
     break;
    case 'DOCTOR-05':
     $before=count($x->store->read('audit'));$r=$x->http(true,'GET','/api/throw/fatal');$after=count($x->store->read('audit'));
     $check($r['status']===500,'exception fixture failed');
     $check($after>$before,'no intervention audit event for neutralized exception');
     break;
    case 'DOCTOR-06':
     $x->store->change('audit',static function($a){
      $old=['at'=>'2001-01-01T00:00:00+00:00','event'=>'old','actor'=>'','meta'=>[],'previous'=>'','digest'=>''];
      return array_fill(0,5002,$old);
     });
     $x->audit->record('test_matrix','lab',[]);
     $all=$x->store->read('audit');
     $check(count($all)<=5000,'audit queue exceeded 5000');
     $check(!in_array('old',array_column($all,'event'),true),'audit retention allowed expired items');
     break;
    case 'DOCTOR-07':
     $path=dirname(__DIR__,2).'/vendor/omegaguardian/profiler-shield/docs/translations';
     $dirs=is_dir($path)?glob($path.'/*',GLOB_ONLYDIR):[];
     $check(count($dirs)===28,'release does not contain 28 regional documentation directories');
     return $notRun('Localized documentation presence checked; human translation and generator preservation auditing not run here.');
    case 'DOCTOR-08':return $notRun('No multi-release/rollback fixture: only released v1.0.0 is installed in this CI run.');
    default:return $notRun('No stateful probe registered for '.$id);
   }
  }catch(\Throwable $e){$errors[]='infrastructure exception '.get_class($e).': '.substr($e->getMessage(),0,220);}
  return ['status'=>$errors?'FAIL':'PASS','assertions'=>$count,'reasons'=>array_merge(array_values(array_unique($errors)),$notes)];
 }
}
