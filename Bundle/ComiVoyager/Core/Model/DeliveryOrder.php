<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Model;

final class DeliveryOrder
{
    public function __construct(
        private readonly string $id,
        private readonly Coordinate $coordinate,
        private readonly float $weightLbs = 0.0,
        private readonly string $priority = 'normal',
        private readonly ?string $customerId = null,
        private readonly string $label = '',
    ) {
    }

    public function getId(): string { return $this->id; }
    public function getCoordinate(): Coordinate { return $this->coordinate; }
    public function getWeightLbs(): float { return $this->weightLbs; }
    public function getPriority(): string { return $this->priority; }
    public function getCustomerId(): ?string { return $this->customerId; }
    /** Human-readable label (e.g. the delivery address), for routing sheets. */
    public function getLabel(): string { return $this->label; }
    public function isUrgent(): bool { return $this->priority === 'urgent'; }
    public function isHigh(): bool { return $this->priority === 'high' || $this->priority === 'urgent'; }
}
