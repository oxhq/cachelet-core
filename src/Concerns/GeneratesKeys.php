<?php

namespace Oxhq\Cachelet\Concerns;

use Oxhq\Cachelet\Support\PayloadNormalizer;
use Oxhq\Cachelet\Support\PayloadNormalizerRegistry;
use RuntimeException;

trait GeneratesKeys
{
    protected ?string $computedKey = null;

    protected ?PayloadNormalizer $normalizer = null;

    public function key(): string
    {
        return $this->computedKey ??= $this->generateKey();
    }

    protected function generateKey(): string
    {
        $segments = array_filter([
            $this->config['defaults']['prefix'] ?? 'cachelet',
            $this->normalizeSegment($this->prefix),
            $this->version,
            $this->hashPayload($this->normalizedPayload()),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return implode(':', $segments);
    }

    protected function normalizedPayload(): mixed
    {
        $this->normalizer ??= new PayloadNormalizer(
            $this->options,
            app()->bound(PayloadNormalizerRegistry::class) ? app(PayloadNormalizerRegistry::class) : null,
        );

        return $this->normalizer->normalize($this->payloadForKey());
    }

    protected function payloadForKey(): mixed
    {
        return $this->payload;
    }

    protected function hashPayload(mixed $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if ($json === false) {
            throw new RuntimeException('Unable to serialize payload for cache key generation.');
        }

        return md5($json);
    }

    protected function resetComputedValues(): void
    {
        $this->computedKey = null;
        $this->normalizer = null;
    }

    protected function normalizeSegment(string $segment): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9:_-]+/', '_', trim($segment));
        $normalized = trim((string) $normalized, '_');

        return $normalized === '' ? 'generic' : $normalized;
    }
}
