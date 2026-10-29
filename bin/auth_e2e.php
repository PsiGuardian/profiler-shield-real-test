<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use RealTest\Matrix\Context;
use OmegaGuardian\ProfilerShield\Security\{Sessions,Totp};

ob_start();
$rootBase=sys_get_temp_dir().'/guardian-auth-e2e-'.bin2hex(random_bytes(8));mkdir($rootBase,0700,true);
$cases=[];$secrets=['GPS_PRIVATE_SQL_SENTINEL_2026','GPS_PRIVATE_ENV_SENTINEL_2026'];
function runCase(string $id,callable $test):void {
 global $cases,$rootBase;
 $root=$rootBase.'/'.$id;mkdir($root,0700,true);$ctx=null;$errors=[];$n=0;
 $assert=static function(bool $ok,string $why)use(&$errors,&$n){$n++;if(!$ok)$errors[]=$why;};
 try{$ctx=new Context($root,'native',false);$test($ctx,$assert);}
 catch(Throwable $e){$errors[]='fixture or HTTP exception: '.get_class($e).': '.substr($e->getMessage(),0,130);}
 finally{if($ctx!==null)$ctx->cleanup();}
 $cases[]=['id'=>$id,'status'=>$errors?'FAIL':'PASS','assertions'=>$n,'reasons'=>$errors];
}
function cookieMap(array $response):array{
 $cookies=[];foreach($response['cookies'] as $cookie)$cookies[$cookie['name']]=$cookie['value'];return $cookies;
}
function form(Context $ctx):array {
 $r=$ctx->http(true,'GET',$ctx->portal,'text/html');
 if(!preg_match('/name="_csrf" value="([^"]+)"/',$r['body'],$m))throw new RuntimeException('CSRF form missing');
 return [$m[1],cookieMap($r),$r];
}
function login(Context $ctx,string $username,string $password,string $otp='',bool $remember=false):array{
 [$csrf,$cookies]=form($ctx);
 $post=['action'=>'login','_csrf'=>$csrf,'username'=>$username,'password'=>$password,'otp'=>$otp];
 if($remember)$post['remember']='1';
 return $ctx->http(true,'POST',$ctx->portal,'text/html',[],$cookies,$post);
}
function cookiesForSession(Context $ctx,array $login):array {
 $cookies=cookieMap($login);return [Sessions::COOKIE=>$cookies[Sessions::COOKIE]??''];
}
function otpFor(string $secret,int $counter):string {
 $bits='';foreach(str_split($secret) as $char){$pos=strpos(Totp::BASE32,$char);if($pos===false)throw new RuntimeException('Invalid TOTP secret');$bits.=str_pad(decbin($pos),5,'0',STR_PAD_LEFT);}
 $key='';foreach(str_split($bits,8) as $byte)if(strlen($byte)===8)$key.=chr(bindec($byte));
 $mac=hash_hmac('sha1',pack('N2',0,$counter),$key,true);
 $offset=ord(substr($mac,-1))&15;
 return str_pad((string)((unpack('N',substr($mac,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
}
try {
 runCase('AUTH4-01-verified-login-and-session',static function(Context $ctx,$check){
  $r=login($ctx,'labadmin',$ctx->password);$check($r['status']===303,'valid login failed');
  $cookies=cookiesForSession($ctx,$r);
  $check(strlen($cookies[Sessions::COOKIE])===64,'session token missing');
  $session=$ctx->sessions->current($ctx->requestWithCookies($cookies));
  $check($session!==null && $session['username']==='labadmin','session not valid after login');
  $dashboard=$ctx->http(true,'GET',$ctx->portal,'text/html',[],$cookies);
  $check($dashboard['status']===200,'authenticated dashboard denied');
 });
 runCase('AUTH4-02-login-csrf-and-unknown-user',static function(Context $ctx,$check){
  $r=$ctx->http(true,'POST',$ctx->portal,'text/html',[],[],['action'=>'login','_csrf'=>str_repeat('0',64),'username'=>'labadmin','password'=>$ctx->password]);
  $check(!isset(cookieMap($r)[Sessions::COOKIE]),'invalid CSRF yielded login session');
  $bad=login($ctx,'unknown-lab-user','invalid-long-password-do-not-use');
  $check(!isset(cookieMap($bad)[Sessions::COOKIE]),'unknown user yielded session');
  $valid=login($ctx,'labadmin',$ctx->password);
  $check($valid['status']===303,'legitimate account broken after negative login');
 });
 runCase('AUTH4-03-logout-and-cookie-revocation',static function(Context $ctx,$check){
  $r=login($ctx,'labadmin',$ctx->password);$cookies=cookiesForSession($ctx,$r);
  $req=$ctx->requestWithCookies($cookies);$session=$ctx->sessions->current($req);
  $check(is_array($session),'initial session unavailable');
  if(!is_array($session))return;
  $response=$ctx->http(true,'POST',$ctx->portal,'text/html',[],$cookies,['action'=>'logout','_csrf'=>$session['csrf']]);
  $check(in_array($response['status'],[302,303],true),'logout did not redirect');
  $check($ctx->sessions->current($req)===null,'session valid after logout');
  $check($ctx->http(true,'GET',$ctx->portal,'text/html',[],$cookies)['status']===200,'portal no longer available after logout');
 });
 runCase('AUTH4-04-forged-admin-action-denied',static function(Context $ctx,$check){
  $r=login($ctx,'labadmin',$ctx->password);$cookies=cookiesForSession($ctx,$r);
  $before=$ctx->settings->all();
  $out=$ctx->http(true,'POST',$ctx->portal,'text/html',[],$cookies,['action'=>'settings','_csrf'=>'WRONG','stealth'=>'0']);
  $check($out['status']===403,'CSRF forgery on admin request not denied');
  $check($ctx->settings->all()===$before,'security settings mutated by forged action');
 });
 runCase('AUTH4-05-disable-account-and-password-rotation',static function(Context $ctx,$check){
  $r=login($ctx,'labadmin',$ctx->password);$cookies=cookiesForSession($ctx,$r);$req=$ctx->requestWithCookies($cookies);
  $check($ctx->sessions->current($req)!==null,'initial session missing');
  $ctx->accounts->update('labadmin',static function($u){$u['disabled']=true;return $u;});
  $check($ctx->sessions->current($req)===null,'disabled account retained authorized session');
  $denied=$ctx->http(true,'GET','/_profiler/','text/html',[],$cookies);
  $check(in_array($denied['status'],[403,404],true),'disabled account gained profiler access');
  $ctx->accounts->update('labadmin',static function($u){$u['disabled']=false;return $u;});
  $newPassword=bin2hex(random_bytes(24));$ctx->accounts->setPassword('labadmin',$newPassword);
  $check($ctx->sessions->current($req)===null,'old session survived password rotation');
  $check(!isset(cookieMap(login($ctx,'labadmin',$ctx->password))[Sessions::COOKIE]),'old password login accepted');
  $check(login($ctx,'labadmin',$newPassword)['status']===303,'new password login rejected');
 });
 runCase('AUTH4-06-real-totp-login-and-replay',static function(Context $ctx,$check){
  $secret=$ctx->totp->generateSecret();$ctx->accounts->update('labadmin',static function($u)use($ctx,$secret){$u['totp']=$ctx->totp->encrypt($secret);$u['last_otp_counter']=-1;return $u;});
  $check(!isset(cookieMap(login($ctx,'labadmin',$ctx->password))[Sessions::COOKIE]),'MFA account logged in without code');
  $counter=(int)floor(time()/30);$otp=otpFor($secret,$counter);
  $success=login($ctx,'labadmin',$ctx->password,$otp);
  $check($success['status']===303,'valid OTP not accepted');
  $check(isset(cookieMap($success)[Sessions::COOKIE]),'MFA login session missing');
  $replayed=login($ctx,'labadmin',$ctx->password,$otp);
  $check(!isset(cookieMap($replayed)[Sessions::COOKIE]),'TOTP replay yielded session');
  $check($replayed['status']!==303,'TOTP replay accepted');
 });
 runCase('AUTH4-07-mfa-enrolment-preserves-existing-session',static function(Context $ctx,$check){
  $r=login($ctx,'labadmin',$ctx->password);$cookies=cookiesForSession($ctx,$r);$req=$ctx->requestWithCookies($cookies);
  $check($ctx->sessions->current($req)!==null,'login session missing');
  $secret=$ctx->totp->generateSecret();
  $ctx->accounts->update('labadmin',static function($u)use($ctx,$secret){$u['totp']=$ctx->totp->encrypt($secret);return $u;});
  $check($ctx->sessions->current($req)!==null,'enrolling MFA disconnected current session');
 });
 runCase('AUTH4-08-device-cookie-is-not-login',static function(Context $ctx,$check){
  $r=login($ctx,'labadmin',$ctx->password,'',true);
  $cookies=cookieMap($r);
  $check(isset($cookies[Sessions::DEVICE_COOKIE]),'remember device cookie missing');
  $request=$ctx->requestWithCookies([Sessions::DEVICE_COOKIE=>$cookies[Sessions::DEVICE_COOKIE]??'']);
  $check($ctx->sessions->current($request)===null,'device token bypassed login');
  $check($ctx->http(true,'GET','/_profiler/','text/html',[],[Sessions::DEVICE_COOKIE=>$cookies[Sessions::DEVICE_COOKIE]??''])['status']===404,'device token permitted profiler');
 });
 runCase('AUTH4-09-session-cookie-hardening',static function(Context $ctx,$check){
  $r=login($ctx,'labadmin',$ctx->password);$cookies=$r['cookies'];$found=false;
  foreach($cookies as $cookie)if($cookie['name']===Sessions::COOKIE){
   $found=true;
   $check(str_starts_with($cookie['name'],'__Host-'),'wrong cookie prefix');
   $check($cookie['secure']&&$cookie['httpOnly'],'session cookie lacks Secure/HttpOnly');
   $check($cookie['path']==='/'&&$cookie['domain']===null,'session cookie has Domain or wrong Path');
   $check(strtolower((string)$cookie['sameSite'])==='lax','session cookie SameSite mismatch');
  }
  $check($found,'session cookie missing');
 });
 runCase('AUTH4-10-portal-storage-failure-closed',static function(Context $ctx,$check){
  $loginResponse=login($ctx,'labadmin',$ctx->password);$cookies=cookiesForSession($ctx,$loginResponse);
  $sessionsFile=$ctx->store->root().'/sessions.json';
  $original=file_get_contents($sessionsFile);
  try{
   file_put_contents($sessionsFile,'{"not":"valid-GCM"}');
   $r=$ctx->http(true,'GET',$ctx->portal,'text/html',[],$cookies);
   $check($r['status']===503,'corrupt storage did not produce safe service unavailable');
   $check(!str_contains($r['body'],'valid-GCM'),'internal storage marker disclosed');
   $check($ctx->http(true,'GET','/api/health')['status']===200,'business route unavailable during portal storage failure');
   $debug=$ctx->http(true,'GET','/api/debug-header');
   $check(!isset($debug['headers']['x-debug-token']),'debug token remained visible during storage failure');
  }finally{file_put_contents($sessionsFile,$original);}
 });
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootBase,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isLink())@unlink($f->getPathname());elseif($f->isDir())@rmdir($f->getPathname());else @unlink($f->getPathname());}
 @rmdir($rootBase);
}
$debug=ob_get_clean();if($debug!=='')fwrite(STDERR,'Fixture stdout suppressed: '.strlen($debug).' bytes'.PHP_EOL);
$passed=count(array_filter($cases,static fn($r)=>$r['status']==='PASS'));
$report=['suite'=>'complete Guardian authentication lifecycle','release'=>'v1.0.0','php'=>PHP_VERSION,'symfony'=>\Symfony\Component\HttpKernel\Kernel::VERSION,
 'scenarios'=>count($cases),'passed'=>$passed,'failed'=>count($cases)-$passed,'results'=>$cases];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
if(count($cases)!==10||$passed!==count($cases))exit(1);
