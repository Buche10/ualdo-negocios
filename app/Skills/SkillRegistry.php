<?php

namespace App\Skills;

class SkillRegistry
{
    public function for(?string $vertical): SkillProvider
    {
        return match ($vertical) {
            'health' => app(HealthSkillProvider::class),
            'restaurant' => app(RestaurantSkillProvider::class),
            'retail' => app(RetailSkillProvider::class),
            default => app(GenericSkillProvider::class),
        };
    }
}
