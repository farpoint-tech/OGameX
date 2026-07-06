<?php

namespace Tests\Feature;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use RuntimeException;
use Tests\AccountTestCase;

/**
 * Integration tests for atomic planet resource/unit operations which use
 * framework-level decrement queries (decrement/decrementEach) instead of
 * raw SQL string interpolation. These tests verify that the resulting
 * values are persisted correctly in the database.
 */
class PlanetAtomicOperationsTest extends AccountTestCase
{
    /**
     * Test that deductResourcesAtomic() persists the correct values in the database.
     */
    public function testDeductResourcesAtomicUpdatesDatabase(): void
    {
        $this->planetAddResources(new Resources(10000, 8000, 6000, 0));
        $this->planetService->reloadPlanet();

        $metalBefore = (int)$this->planetService->metal()->get();
        $crystalBefore = (int)$this->planetService->crystal()->get();
        $deuteriumBefore = (int)$this->planetService->deuterium()->get();

        $result = $this->planetService->deductResourcesAtomic(new Resources(1500, 250, 100, 0));
        $this->assertTrue($result, 'Atomic deduction should succeed with sufficient resources');

        // Verify in-memory model was synced.
        $this->assertEquals($metalBefore - 1500, (int)$this->planetService->metal()->get());
        $this->assertEquals($crystalBefore - 250, (int)$this->planetService->crystal()->get());
        $this->assertEquals($deuteriumBefore - 100, (int)$this->planetService->deuterium()->get());

        // Verify values were persisted correctly in the database.
        $this->planetService->reloadPlanet();
        $this->assertEquals($metalBefore - 1500, (int)$this->planetService->metal()->get(), 'Metal should be deducted in database');
        $this->assertEquals($crystalBefore - 250, (int)$this->planetService->crystal()->get(), 'Crystal should be deducted in database');
        $this->assertEquals($deuteriumBefore - 100, (int)$this->planetService->deuterium()->get(), 'Deuterium should be deducted in database');
    }

    /**
     * Test that deductResourcesAtomic() fails and leaves the database unchanged
     * when there are insufficient resources.
     */
    public function testDeductResourcesAtomicFailsWhenInsufficient(): void
    {
        $this->planetService->reloadPlanet();
        $metalBefore = (int)$this->planetService->metal()->get();

        // Attempt to deduct more metal than available.
        $result = $this->planetService->deductResourcesAtomic(new Resources($metalBefore + 1000000, 0, 0, 0));
        $this->assertFalse($result, 'Atomic deduction should fail with insufficient resources');

        // Verify database values are unchanged.
        $this->planetService->reloadPlanet();
        $this->assertEquals($metalBefore, (int)$this->planetService->metal()->get(), 'Metal should be unchanged in database after failed deduction');
    }

    /**
     * Test that removeUnitsAtomic() persists the correct unit amounts in the database.
     */
    public function testRemoveUnitsAtomicUpdatesDatabase(): void
    {
        $this->planetAddUnit('light_fighter', 10);
        $this->planetAddUnit('rocket_launcher', 5);
        $this->planetService->reloadPlanet();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 4);
        $units->addUnit(ObjectService::getUnitObjectByMachineName('rocket_launcher'), 2);

        $result = $this->planetService->removeUnitsAtomic($units);
        $this->assertTrue($result, 'Atomic unit removal should succeed with sufficient units');

        // Verify values were persisted correctly in the database.
        $this->planetService->reloadPlanet();
        $this->assertEquals(6, $this->planetService->getObjectAmount('light_fighter'), 'Light fighter amount should be decremented in database');
        $this->assertEquals(3, $this->planetService->getObjectAmount('rocket_launcher'), 'Rocket launcher amount should be decremented in database');
    }

    /**
     * Test that removeUnitsAtomic() fails and leaves the database unchanged
     * when there are insufficient units.
     */
    public function testRemoveUnitsAtomicFailsWhenInsufficient(): void
    {
        $this->planetAddUnit('light_fighter', 3);
        $this->planetService->reloadPlanet();
        $fightersBefore = $this->planetService->getObjectAmount('light_fighter');

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), $fightersBefore + 1);

        $result = $this->planetService->removeUnitsAtomic($units);
        $this->assertFalse($result, 'Atomic unit removal should fail with insufficient units');

        // Verify database values are unchanged.
        $this->planetService->reloadPlanet();
        $this->assertEquals($fightersBefore, $this->planetService->getObjectAmount('light_fighter'), 'Light fighter amount should be unchanged in database after failed removal');
    }

    /**
     * Test that removeUnit() with save_planet=true atomically decrements the unit
     * amount in the database.
     */
    public function testRemoveUnitAtomicUpdatesDatabase(): void
    {
        $this->planetAddUnit('light_fighter', 10);
        $this->planetService->reloadPlanet();
        $fightersBefore = $this->planetService->getObjectAmount('light_fighter');

        $this->planetService->removeUnit('light_fighter', 3, true);

        // Verify in-memory model was synced.
        $this->assertEquals($fightersBefore - 3, $this->planetService->getObjectAmount('light_fighter'));

        // Verify value was persisted correctly in the database.
        $this->planetService->reloadPlanet();
        $this->assertEquals($fightersBefore - 3, $this->planetService->getObjectAmount('light_fighter'), 'Light fighter amount should be decremented in database');
    }

    /**
     * Test that removeUnit() with save_planet=true throws and leaves the database
     * unchanged when there are insufficient units.
     */
    public function testRemoveUnitAtomicThrowsWhenInsufficient(): void
    {
        $this->planetAddUnit('light_fighter', 2);
        $this->planetService->reloadPlanet();
        $fightersBefore = $this->planetService->getObjectAmount('light_fighter');

        try {
            $this->planetService->removeUnit('light_fighter', $fightersBefore + 1, true);
            $this->fail('Expected RuntimeException when removing more units than available');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not have enough units', $e->getMessage());
        }

        // Verify database values are unchanged.
        $this->planetService->reloadPlanet();
        $this->assertEquals($fightersBefore, $this->planetService->getObjectAmount('light_fighter'), 'Light fighter amount should be unchanged in database after failed removal');
    }
}
