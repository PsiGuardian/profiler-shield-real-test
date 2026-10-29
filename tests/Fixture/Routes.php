<?php
namespace RealTest\Fixture;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
final class Routes {
 public static function load(RoutingConfigurator $routes):void {
  $definitions=[
   ['api_health','/api/health','health',['GET']],
   ['api_validation','/api/validation','validation',['GET']],
   ['api_xml','/api/xml','xml',['GET']],
   ['api_throttle','/api/throttle','throttle',['GET']],
   ['api_redirect','/api/redirect','redirect',['POST']],
   ['api_empty','/api/empty','emptyResponse',['GET']],
   ['api_debug_header','/api/debug-header','debugHeader',['GET']],
   ['api_stream','/api/stream','stream',['GET']]
  ];
  foreach($definitions as [$name,$path,$method,$verbs])$routes->add($name,$path)->controller([ApiController::class,$method])->methods($verbs);
  $routes->add('api_throw','/api/throw/{kind}')->controller([ApiController::class,'thrown'])->methods(['GET']);
  $routes->add('legitimate_doc','/docs/_profiler/guide')->controller([ApiController::class,'health'])->methods(['GET']);

  $deep=[
    ['deep_status','/api/status/{code}','status',['GET']],
    ['deep_nested','/api/nested','nested',['GET']],
    ['deep_cors','/api/cors','cors',['OPTIONS']],
    ['deep_cors_error','/api/cors-error','corsError',['GET']],
    ['deep_304','/api/not-modified','response304',['GET']],
    ['deep_cookie','/api/cookie','cookie',['POST']],
    ['deep_sse','/api/sse','sse',['GET']],
    ['deep_html_error','/web/error','htmlError',['GET']],
    ['deep_html_headers','/web/error-headers','htmlErrorHeaders',['GET']],
    ['deep_html_trusted','/web/trusted','htmlTrusted',['GET']],
    ['deep_post','/api/create/{id}','post',['POST']]
  ];
  foreach($deep as [$name,$path,$method,$verbs])$routes->add($name,$path)->controller([DeepController::class,$method])->methods($verbs);
  if(($_SERVER['REAL_TEST_GUARDED']??'')==='1'){
   $bundle=new \Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
   $path=$bundle->getPath().'/Resources/config/routing/';
   $routes->import($path.(is_file($path.'profiler.php')?'profiler.php':'profiler.xml'))->prefix('/_profiler');
   $routes->import($path.(is_file($path.'wdt.php')?'wdt.php':'wdt.xml'))->prefix('/_wdt');
   $routes->add('guardian_entry',$_SERVER['GUARDIAN_LOGIN_PATH'])->controller('omega_guardian.controller::entry')->methods(['GET','POST']);
  }
 }
}