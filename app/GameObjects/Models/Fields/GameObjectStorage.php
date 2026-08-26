<?php

namespace OGame\GameObjects\Models\Fields;

use Closure;

class GameObjectStorage
{
    /**
     * Storage formulas as closures with signature: fn (int $object_level): int|float.
     */
    public Closure $metal;
    public Closure $crystal;
    public Closure $deuterium;
    public Closure $energy;

    public function __construct()
    {
        $zero = static fn (int $object_level): int => 0;
        $this->metal = $zero;
        $this->crystal = $zero;
        $this->deuterium = $zero;
        $this->energy = $zero;
    }

    /**
     * Calculates the metal storage for the given object level.
     */
    public function calculateMetal(int $object_level): float
    {
        return (float)($this->metal)($object_level);
    }

    /**
     * Calculates the crystal storage for the given object level.
     */
    public function calculateCrystal(int $object_level): float
    {
        return (float)($this->crystal)($object_level);
    }

    /**
     * Calculates the deuterium storage for the given object level.
     */
    public function calculateDeuterium(int $object_level): float
    {
        return (float)($this->deuterium)($object_level);
    }

    /**
     * Calculates the energy storage for the given object level.
     */
    public function calculateEnergy(int $object_level): float
    {
        return (float)($this->energy)($object_level);
    }
}
