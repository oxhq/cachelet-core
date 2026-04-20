<?php

namespace Oxhq\Cachelet\ValueObjects;

use Illuminate\Support\Carbon;

readonly class CacheCoordinate
{
    public function __construct(
        public string $prefix,
        public string $key,
        public ?int $ttl,
        public array $tags = [],
        public array $metadata = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            prefix: (string) ($data['prefix'] ?? 'generic'),
            key: (string) $data['key'],
            ttl: isset($data['ttl']) ? (int) $data['ttl'] : null,
            tags: array_values($data['tags'] ?? []),
            metadata: $data['metadata'] ?? []
        );
    }

    public function expiresAt(): ?Carbon
    {
        return $this->ttl === null ? null : Carbon::now()->addSeconds($this->ttl);
    }

    public function toArray(): array
    {
        return [
            'prefix' => $this->prefix,
            'key' => $this->key,
            'ttl' => $this->ttl,
            'expires_at' => $this->expiresAt()?->toIso8601String(),
            'tags' => array_values($this->tags),
            'metadata' => $this->metadata,
        ];
    }
}
