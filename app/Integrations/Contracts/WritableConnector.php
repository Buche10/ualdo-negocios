<?php

namespace App\Integrations\Contracts;

use App\Integrations\Dto\CanonicalMovement;

interface WritableConnector extends Connector
{
    /**
     * Push a movement to external provider.
     *
     * @return array<string, mixed>
     */
    public function pushMovement(CanonicalMovement $movement): array;
}
