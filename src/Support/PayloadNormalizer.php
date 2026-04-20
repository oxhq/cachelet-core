<?php

namespace Oxhq\Cachelet\Support;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use stdClass;

class PayloadNormalizer
{
    public function __construct(
        protected array $options = [],
        protected ?PayloadNormalizerRegistry $registry = null,
    ) {}

    public function normalize(mixed $value): mixed
    {
        $custom = $this->registry?->normalize($value, $this) ?? self::unhandled();

        if ($custom !== self::unhandled()) {
            return $custom;
        }

        return match (true) {
            is_array($value) => $this->normalizeArray($value),
            $value instanceof Arrayable => $this->normalize($value->toArray()),
            $value instanceof JsonSerializable => $this->normalize($value->jsonSerialize()),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_object($value) => $this->normalizeObject($value),
            default => $value,
        };
    }

    protected function normalizeArray(array $array): array
    {
        $array = $this->applyFieldFilters($array);

        ksort($array);

        return array_map([$this, 'normalize'], $array);
    }

    protected function normalizeObject(object $value): array
    {
        return $this->normalizeArray(get_object_vars($value));
    }

    protected function applyFieldFilters(array $array): array
    {
        if (isset($this->options['only'])) {
            $array = array_intersect_key($array, array_flip($this->options['only']));
        }

        if (isset($this->options['exclude'])) {
            $array = array_diff_key($array, array_flip($this->options['exclude']));
        }

        return $array;
    }

    public static function unhandled(): stdClass
    {
        static $sentinel;

        if (! $sentinel instanceof stdClass) {
            $sentinel = new stdClass;
        }

        return $sentinel;
    }
}
