<?php

namespace Oxhq\Cachelet\Contracts;

use Oxhq\Cachelet\Support\PayloadNormalizer;

interface PayloadValueNormalizer
{
    public function supports(mixed $value): bool;

    public function normalize(mixed $value, PayloadNormalizer $normalizer): mixed;
}
