<?php

namespace Oxhq\Cachelet;

use Illuminate\Support\Traits\Macroable;
use Oxhq\Cachelet\Builders\CacheletBuilder;
use Oxhq\Cachelet\Contracts\PayloadValueNormalizer;
use Oxhq\Cachelet\Interventions\InterventionManager;
use Oxhq\Cachelet\Support\PayloadNormalizerRegistry;

class CacheletManager
{
    use Macroable;

    public function __construct(
        protected array $config = [],
        protected ?PayloadNormalizerRegistry $normalizers = null,
    ) {}

    public function for(string $prefix): CacheletBuilder
    {
        return new CacheletBuilder($prefix, $this->config);
    }

    public function normalizesPayloadUsing(callable|PayloadValueNormalizer $normalizer): static
    {
        $this->normalizers()?->register($normalizer);

        return $this;
    }

    public function prependPayloadNormalizer(callable|PayloadValueNormalizer $normalizer): static
    {
        $this->normalizers()?->prepend($normalizer);

        return $this;
    }

    public function interventions(): InterventionManager
    {
        return app(InterventionManager::class);
    }

    protected function normalizers(): ?PayloadNormalizerRegistry
    {
        return $this->normalizers;
    }
}
