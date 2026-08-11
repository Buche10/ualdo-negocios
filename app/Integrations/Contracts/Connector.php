<?php

namespace App\Integrations\Contracts;

use App\Integrations\Dto\CanonicalItem;

interface Connector
{
    public function provider(): string;

    /**
     * @return array<int, CanonicalItem>
     */
    public function pull(): array;
}
