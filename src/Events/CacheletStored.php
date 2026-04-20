<?php

namespace Oxhq\Cachelet\Events;

class CacheletStored
{
    public function __construct(
        public string $key,
        public mixed $value
    ) {}
}
