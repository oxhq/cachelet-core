<?php

namespace Oxhq\Cachelet\Console\Commands;

use Illuminate\Console\Command;
use Oxhq\Cachelet\Support\CoordinateLogger;

class CacheletFlushCommand extends Command
{
    protected $signature = 'cachelet:flush {prefix}';

    protected $description = 'Flush cachelet keys for a prefix';

    public function handle(CoordinateLogger $logger): int
    {
        foreach ($logger->flush((string) $this->argument('prefix')) as $key) {
            $this->line("Deleted: {$key}");
        }

        return self::SUCCESS;
    }
}
