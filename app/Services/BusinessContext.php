<?php

namespace App\Services;

use App\Models\Business;
use Closure;

class BusinessContext
{
    protected static ?Business $currentBusiness = null;

    protected static bool $isCentralMode = false;

    /**
     * Set the current business context.
     */
    public static function set(?Business $business): void
    {
        static::$currentBusiness = $business;
        static::$isCentralMode = false;
    }

    /**
     * Get the current business context.
     */
    public static function get(): ?Business
    {
        return static::$currentBusiness;
    }

    /**
     * Get current business ID (tenant_id).
     */
    public static function getTenantId(): ?int
    {
        return static::$currentBusiness?->id;
    }

    /**
     * Check if currently running in central/admin mode.
     */
    public static function isCentral(): bool
    {
        return static::$isCentralMode;
    }

    /**
     * Set central mode state.
     */
    public static function setCentral(bool $isCentral = true): void
    {
        static::$isCentralMode = $isCentral;
    }

    /**
     * Clear the business context.
     */
    public static function clear(): void
    {
        static::$currentBusiness = null;
        static::$isCentralMode = false;
    }

    /**
     * Run a callback within the context of a given Business and restore previous context.
     */
    public static function runInContext(?Business $business, Closure $callback)
    {
        $previousBusiness = static::$currentBusiness;
        $previousCentral = static::$isCentralMode;

        static::$currentBusiness = $business;
        static::$isCentralMode = false;

        try {
            return $callback($business);
        } finally {
            static::$currentBusiness = $previousBusiness;
            static::$isCentralMode = $previousCentral;
        }
    }

    /**
     * Run a callback in central/admin mode (disables tenant isolation for system tasks).
     */
    public static function runAsCentral(Closure $callback)
    {
        $previousBusiness = static::$currentBusiness;
        $previousCentral = static::$isCentralMode;

        static::$currentBusiness = null;
        static::$isCentralMode = true;

        try {
            return $callback();
        } finally {
            static::$currentBusiness = $previousBusiness;
            static::$isCentralMode = $previousCentral;
        }
    }
}
