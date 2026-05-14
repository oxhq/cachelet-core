# cachelet-core

Generic cache operations runtime for Laravel.

`cachelet-core` is the foundation of the Cachelet family: deterministic keys, TTL/SWR behavior, inspection, invalidation, telemetry, and sidecar maintenance without model, query, or request-specific integrations.

## Install

```bash
composer require oxhq/cachelet-core
```

## Best Fit

Use this package when the app already has its own caching layer and you want the Cachelet operating model around it:

- stable coordinates
- normalized payload keys
- exact-key and prefix invalidation
- `onStore(...)` routing
- telemetry and intervention contracts
- `cachelet:list`, `cachelet:inspect`, `cachelet:flush`, and `cachelet:prune`

## Example

```php
use Oxhq\Cachelet\Facades\Cachelet;

$report = Cachelet::for('reports.sales')
    ->from(['from' => '2026-01-01', 'to' => '2026-01-31'])
    ->onStore('redis')
    ->ttl('+30 minutes')
    ->remember(fn () => $service->salesReport());
```

## Docs

- [`../../docs/operations.md`](../../docs/operations.md)
- [`../../docs/operator-questions.md`](../../docs/operator-questions.md)
- [`../../docs/install-matrix.md`](../../docs/install-matrix.md)
