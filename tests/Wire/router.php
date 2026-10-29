<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use RealTest\Fixture\TestKernel;
use Symfony\Component\HttpFoundation\Request;
$root=(string)getenv('REAL_TEST_WIRE_ROOT');
if($root===''||!is_dir($root)){http_response_code(503);exit;}
$guarded=getenv('REAL_TEST_GUARDED')==='1';
$_SERVER['REAL_TEST_GUARDED']=$guarded?'1':'0';
$_SERVER['GUARDIAN_STORAGE_DIR']=$root.'/vault';putenv('GUARDIAN_STORAGE_DIR='.$root.'/vault');
$_SERVER['GUARDIAN_ENCRYPTION_KEY']=str_repeat('a',64);putenv('GUARDIAN_ENCRYPTION_KEY='.str_repeat('a',64));
$_SERVER['GUARDIAN_LOGIN_PATH']='/internal/wire-fixture-abcdef123456789012345678';putenv('GUARDIAN_LOGIN_PATH='.$_SERVER['GUARDIAN_LOGIN_PATH']);
$_SERVER['GUARDIAN_COLLECTION_BRIDGE']='0';putenv('GUARDIAN_COLLECTION_BRIDGE=0');
$_SERVER['GUARDIAN_DEBUG_ERRORS_FOR_AUTHENTICATED']='0';putenv('GUARDIAN_DEBUG_ERRORS_FOR_AUTHENTICATED=0');
$debug=getenv('REAL_TEST_WIRE_DEBUG')==='1';
$kernel=new TestKernel($root,$guarded,$debug);
$kernel->boot();
$request=Request::createFromGlobals();
$response=$kernel->handle($request);
$response->send();
$kernel->terminate($request,$response);
$kernel->shutdown();
