<?php

namespace App\Integrations\Dto;

final class CanonicalItem
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public string $unit,
        public ?string $category,
        public int $stock,
        public float $stockExact,
        public int $minStock,
        public bool $isActive,
        public array $meta = [],
        public ?string $externalUid = null,
    ) {}

    public function hash(): string
    {
        return hash('sha256', implode('|', [
            $this->externalId,
            (string) $this->externalUid,
            $this->name,
            $this->unit,
            (string) $this->category,
            (string) $this->stockExact,
            (string) $this->minStock,
            $this->isActive ? '1' : '0',
        ]));
    }
}
