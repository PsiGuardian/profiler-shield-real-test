<?php
declare(strict_types=1);
namespace RealTest\Matrix;
use RealTest\Fixture\TestKernel;
use Symfony\Component\HttpFoundation\{Request,Response,StreamedResponse};
use OmegaGuardian\ProfilerShield\Storage\FileStore;
use OmegaGuardian\ProfilerShield\Security\{Accounts,Settings,Sessions,Policy,Totp,LoginThrottle,Audit};
final class Context {
 public FileStore $store;
 public Settings $settings;
 public Accounts $accounts;
 public Sessions $sessions;
 public Policy $policy;
 public Totp $totp;
 public Audit $audit;
 public TestKernel $clean;
 public TestKernel $guard;
 public string $password;
 public string $portal;
 public function __construct(public string $root,public string $mode,public bool $debug) {
  $this->portal='/internal/lab-'.bin2hex(random_bytes(18));
  $vars=[
   'GUARDIAN_STORAGE_DIR'=>$root.'/vault','GUARDIAN_ENCRYPTION_KEY'=>bin2hex(random_bytes(32)),
   'GUARDIAN_LOGIN_PATH'=>$this->portal,'GUARDIAN_COLLECTION_BRIDGE'=>'0',
   'GUARDIAN_DEBUG_ERRORS_FOR_AUTHENTICATED'=>'0','GUARDIAN_SHOW_PHP_VERSION'=>'0'
  ];
  foreach($vars as $k=>$v)self::env($k,$v);
  $this->store=new FileStore($root.'/vault');$this->store->initialize();
  $this->settings=new Settings($this->store);$this->settings->set(['error_mode'=>$mode,'error_custom_text'=>'Public safe error']);
  $this->accounts=new Accounts($this->store);$this->sessions=new Sessions($this->store,$this->settings);
  $this->policy=new Policy($this->settings,$this->store);
  $this->totp=new Totp();$this->audit=new Audit($this->store);
  $this->password=bin2hex(random_bytes(24));
  $this->accounts->add('labadmin',$this->password);
  self::env('REAL_TEST_GUARDED','0');
  $this->clean=new TestKernel($root,false,$debug);$this->clean->boot();
  self::env('REAL_TEST_GUARDED','1');
  $this->guard=new TestKernel($root,true,$debug);$this->guard->boot();
 }
 public static function env(string $key,string $value):void{$_SERVER[$key]=$_ENV[$key]=$value;putenv($key.'='.$value);}
 public function http(bool $guardian,string $method,string $path,string $accept='application/json',array $headers=[],array $cookies=[],array $post=[]):array {
  $server=['HTTPS'=>'on','REMOTE_ADDR'=>'203.0.113.17','HTTP_ACCEPT'=>$accept];
  foreach($headers as $name=>$value)$server[strtoupper(str_replace('-','_','HTTP_'.$name))]=$value;
  $req=Request::create('https://localhost'.$path,$method,$post,$cookies,[],$server);
  $kernel=$guardian?$this->guard:$this->clean;
  $res=$kernel->handle($req);
  $body=$res->getContent();
  if($res instanceof StreamedResponse){ob_start();$res->sendContent();$body=ob_get_clean();}
  $headersOut=[];
  foreach($res->headers->all() as $name=>$value)$headersOut[strtolower($name)]=implode(', ',(array)$value);
  $cookieObjects=$res->headers->getCookies();
  $result=['status'=>$res->getStatusCode(),'body'=>is_string($body)?$body:'','mime'=>strtolower(trim(explode(';',$res->headers->get('Content-Type',''))[0])),
   'headers'=>$headersOut,'cookies'=>array_map(fn($cookie)=>['name'=>$cookie->getName(),'value'=>$cookie->getValue(),'secure'=>$cookie->isSecure(),'httpOnly'=>$cookie->isHttpOnly(),'sameSite'=>$cookie->getSameSite(),'path'=>$cookie->getPath(),'domain'=>$cookie->getDomain()],$cookieObjects)];
  $kernel->terminate($req,$res);
  return $result;
 }
 public function session():array{
  $r=new Response();$this->sessions->begin('labadmin',$r);
  $cookies=[];
  foreach($r->headers->getCookies() as $c)$cookies[$c->getName()]=$c->getValue();
  return $cookies;
 }
 public function requestWithCookies(array $cookies):Request{return Request::create('https://localhost/', 'GET',[], $cookies,[],['REMOTE_ADDR'=>'203.0.113.17','HTTPS'=>'on']);}
 public function cleanup():void{$this->clean->shutdown();$this->guard->shutdown();}
}
