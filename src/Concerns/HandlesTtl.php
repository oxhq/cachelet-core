<?php

namespace Oxhq\Cachelet\Concerns;

use Closure;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

trait HandlesTtl
{
    protected mixed $ttl = null;

    protected ?int $computedSeconds = null;

    protected bool $storesForever = false;

    public function ttl(null|int|string|\DateTimeInterface|Closure $ttl): static
    {
        $this->ttl = $ttl;
        $this->computedSeconds = null;
        $this->storesForever = false;

        return $this;
    }

    public function duration(): ?int
    {
        if ($this->storesForever) {
            return null;
        }

        return $this->computedSeconds ??= $this->parseTtl($this->ttl ?? $this->getDefaultTtl());
    }

    public function expiresAt(): ?Carbon
    {
        $duration = $this->duration();

        return $duration === null ? null : Carbon::now()->addSeconds($duration);
    }

    protected function parseTtl(mixed $ttl): int
    {
        return match (true) {
            $ttl instanceof Closure => $this->parseTtl(value($ttl)),
            is_int($ttl) => $this->validateSeconds($ttl),
            $ttl instanceof \DateTimeInterface => $this->secondsUntil($ttl),
            is_string($ttl) => $this->parseStringTtl($ttl),
            default => throw new InvalidArgumentException('Unsupported TTL value.'),
        };
    }

    protected function getDefaultTtl(): mixed
    {
        return $this->config['defaults']['ttl'] ?? 3600;
    }

    protected function validateSeconds(int $seconds): int
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException("TTL must be positive, got {$seconds}");
        }

        return (int) $seconds;
    }

    protected function secondsUntil(\DateTimeInterface $ttl): int
    {
        $seconds = Carbon::now()->diffInSeconds(Carbon::instance($ttl), false);

        if ($seconds <= 0) {
            throw new InvalidArgumentException('TTL date must be in the future.');
        }

        return (int) $seconds;
    }

    protected function parseStringTtl(string $ttl): int
    {
        $trimmed = trim($ttl);

        if ($trimmed === '') {
            throw new InvalidArgumentException('TTL string cannot be empty.');
        }

        if (ctype_digit($trimmed)) {
            return $this->validateSeconds((int) $trimmed);
        }

        try {
            $parsed = Carbon::parse($trimmed);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException("Invalid TTL string: {$ttl}", previous: $exception);
        }

        return $this->secondsUntil($parsed);
    }

    protected function markStoreForever(): void
    {
        $this->storesForever = true;
        $this->computedSeconds = null;
    }
}
