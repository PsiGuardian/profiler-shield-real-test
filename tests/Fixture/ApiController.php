<?php
namespace RealTest\Fixture;
use Symfony\Component\HttpFoundation\{JsonResponse,Response,RedirectResponse,StreamedResponse};
use Symfony\Component\HttpKernel\Exception\HttpException;
final class ApiController {
 public static function health():Response{return new JsonResponse(['ok'=>true,'version'=>'fixture'],200,['X-Fixture'=>'clean']);}
 public static function validation():Response{return new JsonResponse(['error'=>['code'=>'VALIDATION_FAILED','fields'=>['email'=>'required']]],422,['X-Fixture'=>'clean']);}
 public static function xml():Response{return new Response('<error><code>VALIDATION_FAILED</code></error>',422,['Content-Type'=>'application/xml']);}
 public static function throttle():Response{return new JsonResponse(['error'=>['code'=>'RATE_LIMIT']],429,['Retry-After'=>'37']);}
 public static function redirect():Response{return new RedirectResponse('/api/health',303);}
 public static function emptyResponse():Response{return new Response('',204);}
 public static function debugHeader():Response{return new JsonResponse(['ok'=>true],200,['X-Debug-Token'=>'GPS_PRIVATE_TOKEN_SENTINEL_2026']);}
 public static function stream():Response{return new StreamedResponse(static function(){echo 'safe-stream-data';},200,['Content-Type'=>'text/plain']);}
 public static function thrown(string $kind):Response {
  switch($kind){
   case 'validation':throw new HttpException(422,'GPS_PRIVATE_ENV_SENTINEL_2026');
   case 'auth':throw new HttpException(401,'GPS_PRIVATE_PATH_SENTINEL_2026',null,['WWW-Authenticate'=>'Bearer realm="fixture"']);
   case 'throttle':throw new HttpException(429,'GPS_PRIVATE_SQL_SENTINEL_2026',null,['Retry-After'=>'37']);
   case 'method':throw new HttpException(405,'GPS_PRIVATE_PATH_SENTINEL_2026',null,['Allow'=>'GET, HEAD']);
   case 'missing':throw new HttpException(404,'GPS_PRIVATE_PATH_SENTINEL_2026');
   default:throw new \RuntimeException('GPS_PRIVATE_SQL_SENTINEL_2026');
  }
 }
}