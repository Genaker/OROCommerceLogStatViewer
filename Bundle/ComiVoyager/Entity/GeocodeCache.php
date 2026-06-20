<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Caches the result of geocoding a free-text address so repeated lookups
 * (e.g. the same customer address across multiple orders) avoid hitting the
 * external geocoding provider.
 */
#[ORM\Entity(repositoryClass: \Genaker\Bundle\ComiVoyager\Repository\GeocodeCacheRepository::class)]
#[ORM\Table(name: 'genaker_comivoyager_geocode_cache')]
#[ORM\Index(columns: ['created_at'], name: 'idx_genaker_cv_geocode_created_at')]
#[ORM\HasLifecycleCallbacks]
class GeocodeCache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', name: 'address_hash', length: 64, unique: true)]
    private string $addressHash;

    #[ORM\Column(type: 'text', name: 'address_text')]
    private string $addressText;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 6)]
    private string $latitude;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 6)]
    private string $longitude;

    #[ORM\Column(type: 'string', length: 20)]
    private string $provider;

    #[ORM\Column(type: 'datetime', name: 'created_at')]
    private \DateTimeInterface $createdAt;

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getAddressHash(): string
    {
        return $this->addressHash;
    }

    public function setAddressHash(string $addressHash): self
    {
        $this->addressHash = $addressHash;

        return $this;
    }

    public function getAddressText(): string
    {
        return $this->addressText;
    }

    public function setAddressText(string $addressText): self
    {
        $this->addressText = $addressText;

        return $this;
    }

    public function getLatitude(): float
    {
        return (float) $this->latitude;
    }

    public function setLatitude(float $latitude): self
    {
        $this->latitude = (string) $latitude;

        return $this;
    }

    public function getLongitude(): float
    {
        return (float) $this->longitude;
    }

    public function setLongitude(float $longitude): self
    {
        $this->longitude = (string) $longitude;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
