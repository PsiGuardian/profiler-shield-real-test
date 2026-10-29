<?php
namespace RealTest\Fixture;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
final class ApiExceptionSubscriber implements EventSubscriberInterface {
 public static function getSubscribedEvents():array{return [KernelEvents::EXCEPTION=>['handle',1024]];}
 public function handle(ExceptionEvent $event):void{
  if(!str_starts_with($event->getRequest()->getPathInfo(),'/api/'))return;
  $exception=$event->getThrowable();
  $status=$exception instanceof HttpExceptionInterface?$exception->getStatusCode():500;
  $headers=$exception instanceof HttpExceptionInterface?$exception->getHeaders():[];
  // Fixture deliberately returns ONLY a safe public code, never Throwable::getMessage.
  $event->setResponse(new JsonResponse(['error'=>['code'=>'API_DOMAIN_ERROR','status'=>$status,'safe_message'=>'Request rejected']],$status,array_merge(['X-Fixture'=>'clean-api'],array_intersect_key($headers,array_flip(['Allow','Retry-After','WWW-Authenticate'])))));
 }
}