<?php

namespace Oxhq\Cachelet\Events;

class CacheletMiss
{
    public function __construct(
        public string $key
    ) {}
}
