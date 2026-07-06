<?php

namespace OGame\GameObjects\Models\Fields;

use Closure;

class GameObjectStorage
{
    /**
     * Storage formulas as closures with signature: fn (int $object_level): int|float.
     *
     * String formulas (evaluated via eval()) are deprecated and only supported
     * for backward compatibility. Define new formulas as closures.
     */
    public Closure|string $metal;
    public Closure|string $crystal;
    public Closure|string $deuterium;
    public Closure|string $energy;

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
        return $this->evaluate($this->metal, $object_level);
    }

    /**
     * Calculates the crystal storage for the given object level.
     */
    public function calculateCrystal(int $object_level): float
    {
        return $this->evaluate($this->crystal, $object_level);
    }

    /**
     * Calculates the deuterium storage for the given object level.
     */
    public function calculateDeuterium(int $object_level): float
    {
        return $this->evaluate($this->deuterium, $object_level);
    }

    /**
     * Calculates the energy storage for the given object level.
     */
    public function calculateEnergy(int $object_level): float
    {
        return $this->evaluate($this->energy, $object_level);
    }

    /**
     * Evaluates a storage formula for the given object level.
     *
     * Closures are invoked directly. String formulas fall back to eval() for
     * backward compatibility and trigger a deprecation warning.
     *
     * @param Closure|string $formula
     * @param int $object_level
     * @return float
     */
    private function evaluate(Closure|string $formula, int $object_level): float
    {
        if ($formula instanceof Closure) {
            return (float)$formula($object_level);
        }

        // Legacy string formula evaluated via eval().
        // @deprecated Define storage formulas as closures instead, e.g.:
        // fn (int $object_level) => 5000 * floor(2.5 * exp(20 * $object_level / 33))
        trigger_error(
            'Defining storage formulas as eval() strings is deprecated and will be removed. Use a Closure instead: fn (int $object_level) => ...',
            E_USER_DEPRECATED
        );

        return (float)eval($formula);
    }
}
