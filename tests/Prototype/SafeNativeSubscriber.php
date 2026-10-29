<?php
declare(strict_types=1);
namespace RealTest\Prototype;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Security proof of concept in the independent test repository only.
 * Priority 8192 runs before released Guardian's PrivacySubscriber (4096).
 * Does not run on any profiler path, any non-API route, or subrequests.
 */
final class SafeNativeSubscriber implements EventSubscriberInterface {
    public function __construct(
        private readonly TrustedErrorMapper $mapper=new TrustedErrorMapper(),
        private readonly SafeNativeRenderer $renderer=new SafeNativeRenderer()
    ) {}

    public static function getSubscribedEvents():array {
        return [KernelEvents::EXCEPTION=>['onException',8192]];
    }

    public function onException(ExceptionEvent $event):void {
        if (!$event->isMainRequest())return;
        $request=$event->getRequest();
        $path=$request->getPathInfo();
        if (!str_starts_with($path,'/api/'))return;
        if (\OmegaGuardian\ProfilerShield\EventListener\GuardianSubscriber::isDiagnosticPath($path))return;
        $exception=$event->getThrowable();
        try {
            $public=$this->mapper->map($exception,$request);
        } catch(\Throwable) {
            $public=null; // fail closed on throwing or invalid mapper
        }
        try {
            $event->setResponse($this->renderer->render($request,$exception,$public));
        } catch (\Throwable) {
            // If even the renderer fails, let the original Guardian security
            // listener neutralize the exception; never throw a secondary failure.
        }
    }
}
