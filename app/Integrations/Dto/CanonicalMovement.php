<?php

namespace App\Integrations\Dto;

final class CanonicalMovement
{
    public function __construct(
        public string $productExternalUid,
        public string $type,
        public float|int $quantity,
        public string $unitSnapshot = 'unit',
        public ?string $location = 'kitchen',
        public ?string $notes = null,
        public string|int|null $referenceId = null,
    ) {}
}
