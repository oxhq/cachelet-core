<?php

namespace Oxhq\Cachelet\Console\Commands;

use Illuminate\Console\Command;
use Oxhq\Cachelet\Support\CoordinateLogger;

class CacheletInspectCommand extends Command
{
    protected $signature = 'cachelet:inspect {prefix}';

    protected $description = 'Inspect cachelet metadata for a prefix';

    public function handle(CoordinateLogger $logger): int
    {
        foreach ($logger->inspect((string) $this->argument('prefix')) as $metadata) {
            $this->line(json_encode($metadata, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
