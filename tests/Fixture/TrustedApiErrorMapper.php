<?php
declare(strict_types=1);
namespace RealTest\Fixture;

use OmegaGuardian\ProfilerShield\Security\PublicErrorDescriptor;
use OmegaGuardian\ProfilerShield\Security\PublicErrorMapperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Deliberately approved host API contract for the actual dev-1.0.1 bundle.
 * No Throwable messages, traces or previous exceptions are ever exposed.
 * Not registered in the v1.0.0 baseline fixture.
 */
final class TrustedApiErrorMapper implements PublicErrorMapperInterface
{
    public function supports(\Throwable $error, Request $request): bool
    {
        return $request->isMethod('GET')
            && $request->getPathInfo() === '/api/throw/validation'
            && $error instanceof HttpExceptionInterface
            && $error->getStatusCode() === 422;
    }

    public function map(\Throwable $error, Request $request): ?PublicErrorDescriptor
    {
        return new PublicErrorDescriptor(
            422,
            'API_DOMAIN_ERROR',
            'Request rejected'
        );
    }
}
