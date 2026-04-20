<?php

namespace Oxhq\Cachelet\Testing;

use Illuminate\Support\Facades\Cache;

class ExpectCachelet
{
    public function __construct(
        public string $key
    ) {}

    public function toBeStored(): static
    {
        if (! Cache::has($this->key)) {
            throw new \RuntimeException("Cachelet not stored: {$this->key}");
        }

        return $this;
    }

    public function toHaveValue(mixed $expected): static
    {
        $payload = Cache::get($this->key);
        $value = is_array($payload) && array_key_exists('value', $payload)
            ? $payload['value']
            : $payload;

        if ($value !== $expected) {
            throw new \RuntimeException("Cachelet value mismatch for {$this->key}");
        }

        return $this;
    }
}
