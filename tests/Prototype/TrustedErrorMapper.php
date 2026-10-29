<?php
declare(strict_types=1);
namespace RealTest\Prototype;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Explicit route + method + class + status policy, fully synthetic.
 * Do not transfer the exception message, previous exception or trace.
 */
final class TrustedErrorMapper {
    public function map(\Throwable $error, Request $request): ?PublicErrorDescriptor {
        if ($request->isMethod('GET')
            && $request->getPathInfo() === '/api/throw/validation'
            && $error instanceof HttpExceptionInterface
            && $error->getStatusCode() === 422) {
            return new PublicErrorDescriptor(
                422, 'API_DOMAIN_ERROR', 'Request rejected',
                ['X-Fixture' => 'clean-api']
            );
        }

        // A deliberately bad mapper to exercise fallback without exposing internal data.
        if ($request->getPathInfo() === '/api/throw/mapper-broken') {
            throw new \RuntimeException('GPS_PRIVATE_ENV_SENTINEL_2026');
        }
        return null;
    }
}
