<?php

namespace Oxhq\Cachelet\Facades;

use Illuminate\Support\Facades\Facade;

class Cachelet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cachelet';
    }
}
