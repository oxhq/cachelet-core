<?php

namespace Oxhq\Cachelet;

use Illuminate\Support\ServiceProvider;
use Oxhq\Cachelet\Console\Commands\CacheletFlushCommand;
use Oxhq\Cachelet\Console\Commands\CacheletInspectCommand;
use Oxhq\Cachelet\Console\Commands\CacheletListCommand;
use Oxhq\Cachelet\Console\Commands\CacheletPruneCommand;
use Oxhq\Cachelet\Interventions\InterventionManager;
use Oxhq\Cachelet\Support\CacheTelemetryEmitter;
use Oxhq\Cachelet\Support\CoordinateLogger;
use Oxhq\Cachelet\Support\PayloadNormalizerRegistry;
use Oxhq\Cachelet\Support\TelemetryStore;

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
        $this->app->singleton(CoordinateLogger::class, function ($app) {
            $config = (array) $app['config']->get('cachelet.registry', []);

            return new CoordinateLogger(
                store: $config['store'] ?? null,
                prefix: (string) ($config['prefix'] ?? 'cachelet:registry'),
                metadataTtl: isset($config['metadata_ttl']) ? (int) $config['metadata_ttl'] : null,
                lockTtl: (int) ($config['lock_ttl'] ?? 10),
                lockWait: (int) ($config['lock_wait'] ?? 5),
            );
        });
        $this->app->singleton(TelemetryStore::class, function ($app) {
            $config = (array) $app['config']->get('cachelet.telemetry', []);

            return new TelemetryStore(
                store: $config['store'] ?? null,
                prefix: (string) ($config['prefix'] ?? 'cachelet:telemetry'),
                perScopeLimit: (int) ($config['per_scope_limit'] ?? 100),
                retention: isset($config['retention']) ? (int) $config['retention'] : 86400,
            );
        });
        $this->app->singleton(CacheTelemetryEmitter::class, fn ($app) => new CacheTelemetryEmitter($app->make(TelemetryStore::class)));
        $this->app->singleton(InterventionManager::class, fn ($app) => new InterventionManager(
            $app->make(CoordinateLogger::class),
            $app->make(TelemetryStore::class),
        ));
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
                CacheletPruneCommand::class,
            ]);
        }
    }
}
