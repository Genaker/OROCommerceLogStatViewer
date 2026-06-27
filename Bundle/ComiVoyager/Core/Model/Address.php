<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

/**
 * A delivery stop: a human-readable label paired with its resolved coordinate.
 */
final class Address
{
    public function __construct(
        public readonly string $label,
        public readonly Coordinate $coordinate,
    ) {
    }

    /**
     * @return array{label: string, coordinate: array{lat: float, lng: float}}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'coordinate' => $this->coordinate->toArray(),
        ];
    }
}
