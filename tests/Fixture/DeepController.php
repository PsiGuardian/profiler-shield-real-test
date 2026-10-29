<?php
namespace RealTest\Fixture;
use Symfony\Component\HttpFoundation\{Response,JsonResponse,RedirectResponse,StreamedResponse,Cookie};
use Symfony\Component\HttpKernel\Exception\HttpException;
final class DeepController {
 public static function status(string $code):Response {
  $n=(int)$code;
  if(!in_array($n,[400,401,403,404,405,409,422,429,500,503],true)) return new Response('unsupported',400);
  $headers=match($n){401=>['WWW-Authenticate'=>'Bearer realm="fixture"'],405=>['Allow'=>'GET, HEAD'],429=>['Retry-After'=>'37'],default=>[]};
  throw new HttpException($n,'GPS_PRIVATE_PATH_SENTINEL_2026',null,$headers);
 }
 public static function nested():Response {throw new \RuntimeException('GPS_PRIVATE_ENV_SENTINEL_2026',0,new \RuntimeException('GPS_PRIVATE_SQL_SENTINEL_2026'));}
 public static function cors():Response {return new Response('',204,['Access-Control-Allow-Origin'=>'https://client.example','Access-Control-Allow-Methods'=>'GET, OPTIONS','Vary'=>'Origin']);}
 public static function corsError():Response {return new JsonResponse(['error'=>['code'=>'SAFE_ERROR']],422,['Access-Control-Allow-Origin'=>'https://client.example','Vary'=>'Origin']);}
 public static function response304():Response {return new Response('',304,['ETag'=>'"fixture-304"']);}
 public static function cookie():Response {$r=new RedirectResponse('/api/health',303);$r->headers->setCookie(new Cookie('fixture','opaque','+1 hour','/',null,true,true,false,'Lax'));return $r;}
 public static function sse():Response {return new StreamedResponse(static function(){for($i=0;$i<10;$i++)echo 'data: '.$i."\n\n";},200,['Content-Type'=>'text/event-stream']);}
 public static function htmlError():Response {return new Response('<!doctype html><html><body><h1>Public safe 404</h1></body></html>',404,['Content-Type'=>'text/html']);}
 public static function htmlErrorHeaders():Response {return new Response('<!doctype html><html><body><h1>Public safe error</h1></body></html>',500,['Content-Type'=>'text/html','Content-Length'=>'555','ETag'=>'"old"','Content-Encoding'=>'gzip']);}
 public static function htmlTrusted():Response {return new Response('<!doctype html><html><body><h1>Trusted host page</h1></body></html>',404,['Content-Type'=>'text/html']);}
 public static function post(string $id):Response {
  if(!in_array($id,['order','payment','inventory'],true))return new Response('unknown',404);
  return new JsonResponse(['created'=>true,'reference'=>$id],201,['X-Fixture'=>'clean']);
 }
}
