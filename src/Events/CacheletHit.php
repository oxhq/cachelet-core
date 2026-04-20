<?php

namespace Oxhq\Cachelet\Events;

class CacheletHit
{
    public function __construct(
        public string $key,
        public mixed $value
    ) {}
}
