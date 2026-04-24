<?php

namespace Oxhq\Cachelet\ValueObjects;

readonly class CacheScope
{
    public function __construct(
        public string $identifier,
        public string $source = 'explicit',
    ) {}

    public static function named(string $identifier): self
    {
        return new self(static::normalize($identifier), 'explicit');
    }

    public static function explicit(string $identifier): self
    {
        return new self(static::normalize($identifier), 'explicit');
    }

    public static function inferred(string $identifier): self
    {
        return new self(static::normalize($identifier), 'inferred');
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromData(?array $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        $identifier = trim((string) ($data['identifier'] ?? ''));

        if ($identifier === '') {
            return null;
        }

        return new self(
            static::normalize($identifier),
            in_array(($data['source'] ?? 'explicit'), ['explicit', 'inferred'], true)
                ? (string) $data['source']
                : 'explicit',
        );
    }

    public function asExplicit(): self
    {
        return new self($this->identifier, 'explicit');
    }

    public function toProjection(): array
    {
        return [
            'contract' => 'cachelet.scope.v1',
            'identifier' => $this->identifier,
            'source' => $this->source,
        ];
    }

    protected static function normalize(string $identifier): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9._:-]+/', '.', trim($identifier));
        $normalized = trim((string) $normalized, '.');

        return $normalized === '' ? 'cache.default' : $normalized;
    }
}
