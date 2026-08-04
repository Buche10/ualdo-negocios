<?php

namespace Tests;

use App\Services\BusinessContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Limpia el contexto multi-tenant estático tras cada test para evitar fugas entre pruebas.
     */
    protected function tearDown(): void
    {
        BusinessContext::clear();

        parent::tearDown();
    }
}
