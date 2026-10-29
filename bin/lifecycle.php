<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use OmegaGuardian\ProfilerShield\Installation\Uninstaller;
use OmegaGuardian\ProfilerShield\Command\{SetupCommand,UninstallCommand};
use OmegaGuardian\ProfilerShield\Security\Accounts;
use OmegaGuardian\ProfilerShield\Storage\FileStore;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

ob_start();
$base=sys_get_temp_dir().'/guardian-lifecycle-'.bin2hex(random_bytes(9));
mkdir($base,0700,true);
$rows=[];
$envKeys=['GUARDIAN_STORAGE_DIR','GUARDIAN_ENCRYPTION_KEY','GUARDIAN_LOGIN_PATH','APP_ENV'];
$prior=[];foreach($envKeys as $key)$prior[$key]=[$_SERVER[$key]??null,$_ENV[$key]??null,getenv($key)];
function setTestEnv(string $key,string $value):void{$_SERVER[$key]=$value;$_ENV[$key]=$value;putenv($key.'='.$value);}
function project(string $base,string $name):string {
 $root=$base.'/'.$name;
 foreach(['bin','config/routes','config/packages/dev','src','public'] as $folder)mkdir($root.'/'.$folder,0700,true);
 file_put_contents($root.'/bin/console',"<?php // disposable test stub\n");
 file_put_contents($root.'/composer.json',json_encode(['name'=>'disposable/fixture','type'=>'project']));
 file_put_contents($root.'/config/bundles.php',"<?php\nreturn [\n    \\Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => ['all' => true],\n];\n");
 file_put_contents($root.'/.env.local',"APP_ENV=dev\nMY_APP_PUBLIC_VALUE=preserve-this\n");
 file_put_contents($root.'/.gitignore',"/vendor/\n/app-custom-cache/\n");
 file_put_contents($root.'/src/IMPORTANT.php',"<?php // user code must remain\n");
 return $root;
}
function runSetup(string $root):array {
 setTestEnv('GUARDIAN_STORAGE_DIR','');setTestEnv('GUARDIAN_ENCRYPTION_KEY','');setTestEnv('GUARDIAN_LOGIN_PATH','');setTestEnv('APP_ENV','dev');
 $vault=new FileStore($root.'/.guardian-shield');
 $command=new SetupCommand($root,$vault,new Accounts($vault));
 $application=new \Symfony\Component\Console\Application('isolated-setup-fixture');
 $application->add($command); // supplies Symfony's QuestionHelper without any external processes
 $input=new ArrayInput(['--automatic'=>true,'--no-admin'=>true,'--path'=>'/internal/testonly-'.str_repeat('a',32)],$command->getDefinition());
 $input->setInteractive(false);$output=new BufferedOutput();
 $exit=$command->run($input,$output);
 return [$exit,$output->fetch(),$vault];
}
function addRow(string $name,callable $fn):void{
 global $rows,$base;
 $problems=[];$count=0;
 $check=static function(bool $condition,string $why)use(&$problems,&$count):void{$count++;if(!$condition)$problems[]=$why;};
 try{$fn($check,$base);}catch(Throwable $e){$problems[]='Fixture failure '.get_class($e).': '.substr($e->getMessage(),0,150);}
 $rows[]=['id'=>$name,'status'=>$problems?'FAIL':'PASS','assertions'=>$count,'reasons'=>$problems];
}
try {
 addRow('LIFE-01-setup-and-conserve-vault',function($ok,$base){
  $root=project($base,'preserve');[$status,$messages,$vault]=runSetup($root);
  $ok($status===0,'guardian:setup --no-admin did not finish');
  foreach(['.env.local','.gitignore','config/routes/guardian.php','config/routes/guardian.yaml','config/packages/dev/zz_guardian_profiler.yaml','.guardian-shield/.installation.json'] as $f){
   if($f==='config/packages/dev/zz_guardian_profiler.yaml'&&!is_file($root.'/'.$f))continue;
   $ok(is_file($root.'/'.$f),'expected generated artifact absent '.$f);
  }
  $key='';preg_match('/^GUARDIAN_ENCRYPTION_KEY=([0-9a-f]{64})$/m',(string)file_get_contents($root.'/.env.local'),$m);$key=$m[1]??'';
  $ok(strlen($key)===64,'managed encryption key not recorded');
  $vault->change('accounts',static fn(array $v)=>['demo'=>['role'=>'admin','hash'=>'FAKE_HASH','totp'=>'FAKE_SECRET']]);
  $encrypted=file_get_contents($root.'/.guardian-shield/accounts.json');
  $ok(!str_contains($encrypted,'FAKE_SECRET'),'vault stored plaintext secrets');
  $logs=[];(new Uninstaller())->execute($root,static function($message)use(&$logs){$logs[]=$message;},false);
  $ok(is_file($root.'/.guardian-shield/accounts.json'),'default uninstall deleted persisted encrypted vault');
  $ok(str_contains((string)file_get_contents($root.'/.env.local'),$key),'default uninstall removed encryption key');
  $ok(str_contains((string)file_get_contents($root.'/.env.local'),'MY_APP_PUBLIC_VALUE=preserve-this'),'default uninstall changed host env key');
  $ok(file_get_contents($root.'/src/IMPORTANT.php')==="<?php // user code must remain\n",'host source file changed');
  $ok(!is_file($root.'/config/routes/guardian.php'),'generated Guardian route not removed');
  $ok(!is_file($root.'/config/routes/guardian.yaml'),'generated Guardian YAML route not removed');
  $ok(is_file($root.'/.guardian-shield/.installation.json'),'receipt lost when preserving vault');
  $ok(!str_contains((string)file_get_contents($root.'/config/bundles.php'),'OmegaGuardianProfilerShieldBundle'),'bundle config not cleaned');
  $logs2=[];(new Uninstaller())->execute($root,static function($m)use(&$logs2){$logs2[]=$m;},false);
  $ok(is_file($root.'/.guardian-shield/accounts.json'),'repeated uninstall removed data');
 });
 addRow('LIFE-02-personalized-file-remains',function($ok,$base){
  $root=project($base,'customized');[$status,,$vault]=runSetup($root);$ok($status===0,'setup failed');
  $route=$root.'/config/routes/guardian.php';$original=(string)file_get_contents($route);
  file_put_contents($route,$original."// maintainer custom modification\n");
  $profiler=$root.'/config/packages/dev/zz_guardian_profiler.yaml';
  $modifiedProfiler=is_file($profiler);
  if($modifiedProfiler)file_put_contents($profiler,(string)file_get_contents($profiler)."# maintainer settings\n");
  (new Uninstaller())->execute($root,static function(string $message):void{},false);
  $ok(is_file($route)&&str_contains((string)file_get_contents($route),'maintainer custom modification'),'modified host route was removed');
  if($modifiedProfiler)$ok(is_file($profiler)&&str_contains((string)file_get_contents($profiler),'maintainer settings'),'modified profiler file removed');
  $ok(is_file($root.'/src/IMPORTANT.php'),'source code removed');
 });
 addRow('LIFE-03-opt-in-purge',function($ok,$base){
  $root=project($base,'purge');[$status,,$vault]=runSetup($root);$ok($status===0,'setup failed');
  $vault->change('accounts',static fn($a)=>['fixture'=>['hash'=>'synthetic']]);
  (new Uninstaller())->execute($root,static function(string $m):void{},true);
  $ok(!is_dir($root.'/.guardian-shield'),'opt-in purge left fixture vault');
  $ok(!str_contains((string)file_get_contents($root.'/.env.local'),'GUARDIAN_ENCRYPTION_KEY'),'opt-in purge retained managed key');
  $ok(str_contains((string)file_get_contents($root.'/.env.local'),'MY_APP_PUBLIC_VALUE=preserve-this'),'purge deleted application env data');
  $ok(is_file($root.'/src/IMPORTANT.php'),'purge removed host source code');
 });
 addRow('LIFE-04-unknown-vault-file-blocks-purge',function($ok,$base){
  $root=project($base,'unknown');[$status,,$vault]=runSetup($root);$ok($status===0,'setup failed');
  $foreign=$root.'/.guardian-shield/never-delete-manually-owned.txt';
  file_put_contents($foreign,'protect this exact file');
  (new Uninstaller())->execute($root,static function(string $m):void{},true);
  $ok(is_file($foreign)&&file_get_contents($foreign)==='protect this exact file','foreign vault artifact deleted');
  $ok(str_contains((string)file_get_contents($root.'/.env.local'),'GUARDIAN_ENCRYPTION_KEY='),'encryption key removed despite declined purge');
 });
 addRow('LIFE-05-symlink-receipt-refused',function($ok,$base){
  $root=project($base,'symlink');
  mkdir($root.'/.guardian-shield',0700,true);
  $destination=$root.'/src/IMPORTANT.php';
  symlink($destination,$root.'/.guardian-shield/.installation.json');
  $refused=false;try{(new Uninstaller())->execute($root,static function(string $m):void{},false);}catch(Throwable){$refused=true;}
  $ok($refused,'uninstaller accepted symlink receipt');
  $ok(is_file($destination),'source deleted through symlink');
 });
 addRow('LIFE-06-reject-production-uninstall',function($ok,$base){
  $root=project($base,'production');setTestEnv('APP_ENV','prod');
  $command=new UninstallCommand($root);$input=new ArrayInput([],$command->getDefinition());$input->setInteractive(false);
  $output=new BufferedOutput();$code=$command->run($input,$output);
  $ok($code!==0,'uninstall proceeded in production environment');
  $ok(is_file($root.'/src/IMPORTANT.php'),'prod uninstall changed host code');
 });
}finally{
 foreach($prior as $k=>[$server,$env,$process]){
  if($server===null)unset($_SERVER[$k]);else $_SERVER[$k]=$server;
  if($env===null)unset($_ENV[$k]);else $_ENV[$k]=$env;
  if($process===false)putenv($k);else putenv($k.'='.$process);
 }
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){$f->isDir()||$f->isLink()?($f->isLink()?@unlink($f->getPathname()):@rmdir($f->getPathname())):@unlink($f->getPathname());}
 @rmdir($base);
}
$hidden=ob_get_clean();
if($hidden!=='')fwrite(STDERR,'Suppressed fixture output: '.strlen($hidden).' bytes'.PHP_EOL);
$passed=count(array_filter($rows,fn($r)=>$r['status']==='PASS'));
$report=['suite'=>'disposable real setup and uninstall','release'=>'v1.0.0','php'=>PHP_VERSION,'symfony'=>\Symfony\Component\HttpKernel\Kernel::VERSION,
 'scenarios'=>count($rows),'passed'=>$passed,'failed'=>count($rows)-$passed,'tests'=>$rows];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
if(count($rows)!==6||$passed!==count($rows))exit(1);
