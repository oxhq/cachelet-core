<?php

namespace Oxhq\Cachelet\Contracts;

use Closure;
use DateTimeInterface;
use Oxhq\Cachelet\ValueObjects\CacheCoordinate;
use Oxhq\Cachelet\ValueObjects\CacheScope;

interface CacheletBuilderInterface
{
    public function from(mixed $payload): static;

    public function ttl(null|int|string|DateTimeInterface|Closure $ttl): static;

    public function withTags(string|array $tags): static;

    public function withMetadata(array $metadata): static;

    public function scope(CacheScope $scope): static;

    public function withInferredScope(CacheScope $scope): static;

    public function versioned(?string $version = null): static;

    public function only(array $fields): static;

    public function exclude(array $fields): static;

    public function key(): string;

    public function duration(): ?int;

    public function fetch(?Closure $callback = null): mixed;

    public function remember(Closure $callback): mixed;

    public function rememberForever(Closure $callback): mixed;

    public function staleWhileRevalidate(Closure $callback, ?Closure $fallback = null): mixed;

    public function invalidate(): void;

    public function invalidatePrefix(string $reason = 'manual'): array;

    public function coordinate(): CacheCoordinate;
}
