<?php
declare(strict_types=1);
namespace RealTest\Prototype;

/**
 * Test-only immutable public representation. Never carries Throwable objects.
 * This is deliberately NOT part of the published Profiler Shield package.
 */
final class PublicErrorDescriptor {
    public function __construct(
        public readonly int $status,
        public readonly string $code,
        public readonly string $publicMessage,
        public readonly array $headers = []
    ) {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP error status');
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $code)) {
            throw new \InvalidArgumentException('Public error code not allowed');
        }
        if (strlen($publicMessage) > 160 || preg_match('/[\x00-\x1f\x7f]/', $publicMessage)) {
            throw new \InvalidArgumentException('Unsafe public error message');
        }
        foreach ($headers as $name => $value) {
            if (!in_array(strtolower((string)$name), ['allow', 'retry-after', 'www-authenticate', 'x-fixture'], true)
                || !is_string($value) || strlen($value) > 160 || preg_match('/[\r\n]/', $value)) {
                throw new \InvalidArgumentException('Unapproved header');
            }
        }
    }
}
