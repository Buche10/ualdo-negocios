<?php

namespace Tests\Feature;

use App\Skills\GenericSkillProvider;
use App\Skills\HealthSkillProvider;
use App\Skills\RestaurantSkillProvider;
use App\Skills\RetailSkillProvider;
use App\Skills\SkillRegistry;
use Tests\TestCase;

class SkillRegistryTest extends TestCase
{
    public function test_skill_registry_resolves_health_provider(): void
    {
        $registry = new SkillRegistry;
        $provider = $registry->for('health');

        $this->assertInstanceOf(HealthSkillProvider::class, $provider);
        $this->assertEquals('health', $provider->vertical());
    }

    public function test_skill_registry_resolves_restaurant_provider(): void
    {
        $registry = new SkillRegistry;
        $provider = $registry->for('restaurant');

        $this->assertInstanceOf(RestaurantSkillProvider::class, $provider);
        $this->assertEquals('restaurant', $provider->vertical());
    }

    public function test_skill_registry_resolves_retail_provider(): void
    {
        $registry = new SkillRegistry;
        $provider = $registry->for('retail');

        $this->assertInstanceOf(RetailSkillProvider::class, $provider);
        $this->assertEquals('retail', $provider->vertical());
    }

    public function test_skill_registry_falls_back_to_generic_provider_on_null_or_unknown(): void
    {
        $registry = new SkillRegistry;

        $providerNull = $registry->for(null);
        $this->assertInstanceOf(GenericSkillProvider::class, $providerNull);
        $this->assertEquals('generic', $providerNull->vertical());

        $providerUnknown = $registry->for('unknown_vertical');
        $this->assertInstanceOf(GenericSkillProvider::class, $providerUnknown);
        $this->assertEquals('generic', $providerUnknown->vertical());
    }
}
