<?php

declare(strict_types=1);

namespace Oxhq\Cachelet\Events;

class CacheletInvalidated
{
    public function __construct(
        public string $prefix,
        public array $keys,
        public string $reason = 'manual',
        public ?string $modelClass = null,
        public int|string|null $modelKey = null,
    ) {}
}
