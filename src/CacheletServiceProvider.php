<?php

namespace Oxhq\Cachelet;

use Illuminate\Support\ServiceProvider;
use Oxhq\Cachelet\Console\Commands\CacheletFlushCommand;
use Oxhq\Cachelet\Console\Commands\CacheletInspectCommand;
use Oxhq\Cachelet\Console\Commands\CacheletListCommand;
use Oxhq\Cachelet\Support\CoordinateLogger;
use Oxhq\Cachelet\Support\PayloadNormalizerRegistry;

class CacheletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cachelet.php', 'cachelet');

        $this->app->singleton(CacheletManager::class, function ($app) {
            return new CacheletManager(
                (array) $app['config']->get('cachelet', []),
                $app->make(PayloadNormalizerRegistry::class),
            );
        });

        $this->app->alias(CacheletManager::class, 'cachelet');
        $this->app->singleton(CoordinateLogger::class, fn () => new CoordinateLogger);
        $this->app->singleton(PayloadNormalizerRegistry::class, fn () => new PayloadNormalizerRegistry);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/cachelet.php' => config_path('cachelet.php'),
        ], 'cachelet-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CacheletListCommand::class,
                CacheletInspectCommand::class,
                CacheletFlushCommand::class,
            ]);
        }
    }
}
