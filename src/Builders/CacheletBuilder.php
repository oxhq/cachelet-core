<?php

namespace Oxhq\Cachelet\Builders;

use Closure;
use Oxhq\Cachelet\Concerns\BuildsCache;
use Oxhq\Cachelet\Concerns\GeneratesKeys;
use Oxhq\Cachelet\Concerns\HandlesTtl;
use Oxhq\Cachelet\Contracts\CacheletBuilderInterface;
use Oxhq\Cachelet\ValueObjects\CacheCoordinate;

class CacheletBuilder implements CacheletBuilderInterface
{
    use BuildsCache;
    use GeneratesKeys;
    use HandlesTtl;

    protected string $prefix;

    protected mixed $payload = null;

    protected array $config;

    protected array $metadata = [];

    protected array $tags = [];

    protected ?string $version = null;

    protected string $module = 'core';

    protected array $options = [
        'excludeTimestamps' => true,
    ];

    public function __construct(string $prefix, array $config = [])
    {
        $this->prefix = $prefix;
        $this->config = $config;
    }

    public function from(mixed $payload): static
    {
        $this->payload = $payload;
        $this->resetComputedValues();

        return $this;
    }

    public function withMetadata(array $metadata): static
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    public function withTags(string|array $tags): static
    {
        $tags = is_string($tags) ? [$tags] : $tags;
        $this->tags = array_values(array_unique(array_merge($this->tags, $tags)));

        return $this;
    }

    public function asModule(string $module): static
    {
        $normalized = trim(strtolower($module));

        if ($normalized !== '') {
            $this->module = $normalized;
        }

        return $this;
    }

    public function versioned(?string $version = null): static
    {
        $this->version = $version ?? 'v1';
        $this->resetComputedValues();

        return $this;
    }

    public function only(array $fields): static
    {
        $this->options['only'] = array_values($fields);
        unset($this->options['exclude']);
        $this->resetComputedValues();

        return $this;
    }

    public function exclude(array $fields): static
    {
        $this->options['exclude'] = array_values($fields);
        $this->resetComputedValues();

        return $this;
    }

    public function remember(Closure $callback): mixed
    {
        return $this->fetch($callback);
    }

    public function rememberForever(Closure $callback): mixed
    {
        $this->markStoreForever();

        return $this->fetch($callback);
    }

    public function invalidate(): void
    {
        $coordinate = $this->coordinate();

        $this->coordinateLogger()->forget($coordinate);
        $this->dispatchInvalidatedEvent([$coordinate->key]);
    }

    public function invalidatePrefix(string $reason = 'manual'): array
    {
        $keys = $this->coordinateLogger()->flush($this->prefix);
        $this->dispatchInvalidatedEvent($keys, $reason);

        return $keys;
    }

    public function coordinate(): CacheCoordinate
    {
        return new CacheCoordinate(
            prefix: $this->prefix,
            key: $this->key(),
            ttl: $this->duration(),
            tags: $this->tags,
            metadata: $this->coordinateMetadata(),
            module: $this->module,
            version: $this->version,
            store: $this->storeName(),
            swr: $this->staleConfiguration(),
        );
    }

    protected function coordinateMetadata(): array
    {
        return array_merge($this->metadata, [
            'module' => $this->module,
            'type' => $this->module,
        ]);
    }

    protected function storeName(): string
    {
        return $this->resolvedStoreName();
    }

    protected function staleConfiguration(): array
    {
        $ttl = $this->duration();
        $graceTtl = max(0, (int) ($this->config['stale']['grace_ttl'] ?? 0));

        return [
            'capable' => $ttl !== null,
            'configured' => $ttl !== null && $graceTtl > 0,
            'refresh' => (string) ($this->config['stale']['refresh'] ?? 'sync'),
            'grace_ttl' => $graceTtl,
            'refresh_lock_ttl' => (int) ($this->config['stale']['lock_ttl'] ?? 0),
            'fill_lock_ttl' => (int) ($this->config['locks']['fill_ttl'] ?? 0),
            'fill_wait' => (int) ($this->config['locks']['fill_wait'] ?? 0),
        ];
    }
}
