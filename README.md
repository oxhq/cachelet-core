# cachelet-core

Read-only split of the Cachelet monorepo package at `packages/cachelet-core`.

Core cache orchestration runtime for Laravel.

## Install

```bash
composer require oxhq/cachelet-core
```

## Features

- Deterministic cache keys from normalized payloads
- TTL and stale-while-revalidate helpers
- Exact-key and prefix invalidation
- Registry inspection commands
- Typed cache lifecycle events
- Canonical `cachelet.coordinate.v1` and `cachelet.telemetry.v1` projections

## Example

```php
use Oxhq\Cachelet\Facades\Cachelet;

$value = Cachelet::for('users.index')
    ->from(['page' => 1])
    ->ttl(300)
    ->remember(fn () => User::paginate());
```
