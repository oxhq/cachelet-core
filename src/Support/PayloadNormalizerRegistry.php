<?php

namespace Oxhq\Cachelet\Support;

use Oxhq\Cachelet\Contracts\PayloadValueNormalizer;

class PayloadNormalizerRegistry
{
    /**
     * @var array<int, callable|PayloadValueNormalizer>
     */
    protected array $normalizers = [];

    public function register(callable|PayloadValueNormalizer $normalizer): void
    {
        $this->normalizers[] = $normalizer;
    }

    public function prepend(callable|PayloadValueNormalizer $normalizer): void
    {
        array_unshift($this->normalizers, $normalizer);
    }

    /**
     * @return array<int, callable|PayloadValueNormalizer>
     */
    public function all(): array
    {
        return $this->normalizers;
    }

    public function normalize(mixed $value, PayloadNormalizer $normalizer): mixed
    {
        foreach ($this->normalizers as $candidate) {
            if ($candidate instanceof PayloadValueNormalizer) {
                if ($candidate->supports($value)) {
                    return $candidate->normalize($value, $normalizer);
                }

                continue;
            }

            $resolved = $candidate($value, $normalizer, static function (): mixed {
                return PayloadNormalizer::unhandled();
            });

            if ($resolved !== PayloadNormalizer::unhandled()) {
                return $resolved;
            }
        }

        return PayloadNormalizer::unhandled();
    }
}
