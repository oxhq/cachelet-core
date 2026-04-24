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
        public array $metadata = [],
        public string $module = 'core',
        public ?string $version = null,
        public ?string $store = null,
        public array $swr = [],
        public ?CacheScope $scope = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            prefix: (string) ($data['prefix'] ?? 'generic'),
            key: (string) $data['key'],
            ttl: isset($data['ttl']) ? (int) $data['ttl'] : null,
            tags: array_values($data['tags'] ?? []),
            metadata: $data['metadata'] ?? [],
            module: (string) ($data['module'] ?? data_get($data, 'metadata.module', 'core')),
            version: isset($data['version']) ? (string) $data['version'] : null,
            store: isset($data['store']) ? (string) $data['store'] : null,
            swr: is_array($data['swr'] ?? null) ? $data['swr'] : [],
            scope: CacheScope::fromData($data['scope'] ?? [
                'identifier' => data_get($data, 'metadata.scope', (string) ($data['prefix'] ?? 'generic')),
                'source' => data_get($data, 'metadata.scope_source', 'inferred'),
            ]),
        );
    }

    public function expiresAt(): ?Carbon
    {
        return $this->ttl === null ? null : Carbon::now()->addSeconds($this->ttl);
    }

    public function toArray(): array
    {
        return $this->toProjection();
    }

    public function toProjection(): array
    {
        return [
            'contract' => 'cachelet.coordinate.v1',
            'module' => $this->module,
            'prefix' => $this->prefix,
            'key' => $this->key,
            'ttl' => $this->ttl,
            'version' => $this->version,
            'store' => $this->store,
            'expires_at' => $this->expiresAt()?->toIso8601String(),
            'tags' => array_values($this->tags),
            'swr' => $this->swr,
            'scope' => $this->scope?->toProjection(),
            'metadata' => $this->metadata,
        ];
    }
}
