<?php

namespace Oxhq\Cachelet\Console\Commands;

use Illuminate\Console\Command;
use Oxhq\Cachelet\Support\CoordinateLogger;

class CacheletListCommand extends Command
{
    protected $signature = 'cachelet:list {prefix}';

    protected $description = 'List cachelet keys for a prefix';

    public function handle(CoordinateLogger $logger): int
    {
        foreach ($logger->keys((string) $this->argument('prefix')) as $key) {
            $this->line($key);
        }

        return self::SUCCESS;
    }
}
