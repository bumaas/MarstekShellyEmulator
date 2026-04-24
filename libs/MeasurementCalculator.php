<?php

declare(strict_types=1);

require_once __DIR__ . '/MeasurementSnapshot.php';

/**
 * Builds a consistent measurement snapshot from configured Symcon variables.
 *
 * All fallback and derivation rules live here so the protocol layer can work
 * exclusively with a validated snapshot instead of piecing together raw values.
 */
final class MeasurementCalculator
{
    private const string PROP_POWER_TOTAL_VAR_ID = 'powerTotalVarId';
    private const string PROP_GRID_IMPORT_POWER_VAR_ID = 'gridImportPowerVarId';
    private const string PROP_GRID_EXPORT_POWER_VAR_ID = 'gridExportPowerVarId';
    private const string PROP_POWER_L1_VAR_ID = 'powerL1VarId';
    private const string PROP_GRID_IMPORT_POWER_L1_VAR_ID = 'gridImportPowerL1VarId';
    private const string PROP_GRID_EXPORT_POWER_L1_VAR_ID = 'gridExportPowerL1VarId';
    private const string PROP_POWER_L2_VAR_ID = 'powerL2VarId';
    private const string PROP_GRID_IMPORT_POWER_L2_VAR_ID = 'gridImportPowerL2VarId';
    private const string PROP_GRID_EXPORT_POWER_L2_VAR_ID = 'gridExportPowerL2VarId';
    private const string PROP_POWER_L3_VAR_ID = 'powerL3VarId';
    private const string PROP_GRID_IMPORT_POWER_L3_VAR_ID = 'gridImportPowerL3VarId';
    private const string PROP_GRID_EXPORT_POWER_L3_VAR_ID = 'gridExportPowerL3VarId';
    private const string PROP_VOLTAGE_L1_VAR_ID = 'voltageL1VarId';
    private const string PROP_VOLTAGE_L2_VAR_ID = 'voltageL2VarId';
    private const string PROP_VOLTAGE_L3_VAR_ID = 'voltageL3VarId';
    private const string PROP_CURRENT_L1_VAR_ID = 'currentL1VarId';
    private const string PROP_CURRENT_L2_VAR_ID = 'currentL2VarId';
    private const string PROP_CURRENT_L3_VAR_ID = 'currentL3VarId';
    private const string PROP_IMPORT_ENERGY_VAR_ID = 'importEnergyVarId';
    private const string PROP_EXPORT_ENERGY_VAR_ID = 'exportEnergyVarId';
    private const string PROP_MAX_MEASUREMENT_AGE = 'maxMeasurementAge';
    private const string PROP_PHASE_MODE = 'phaseMode';
    private const string PROP_ENERGY_SCALE_FACTOR = 'energyScaleFactor';
    private const float DEFAULT_VOLTAGE_V = 230.0;

    /**
     * @param array<string, int> $integerProperties
     * @param array<string, string> $stringProperties
     * @param array<string, float> $floatProperties
     */
    public static function fromConfiguration(array $integerProperties, array $stringProperties, array $floatProperties = []): MeasurementSnapshot
    {
        $snapshot = new MeasurementSnapshot();
        $snapshot->timestamp = self::resolveSnapshotTimestamp($integerProperties);

        $snapshot->totalActivePowerW = self::readFloat($integerProperties, self::PROP_POWER_TOTAL_VAR_ID);
        self::deriveTotalPowerFromImportExport($integerProperties, $snapshot);
        $snapshot->phaseAActivePowerW = self::readFloat($integerProperties, self::PROP_POWER_L1_VAR_ID);
        $snapshot->phaseAActivePowerW = self::derivePowerFromImportExport(
            $integerProperties,
            $snapshot->phaseAActivePowerW,
            self::PROP_GRID_IMPORT_POWER_L1_VAR_ID,
            self::PROP_GRID_EXPORT_POWER_L1_VAR_ID
        );
        $snapshot->phaseBActivePowerW = self::readFloat($integerProperties, self::PROP_POWER_L2_VAR_ID);
        $snapshot->phaseBActivePowerW = self::derivePowerFromImportExport(
            $integerProperties,
            $snapshot->phaseBActivePowerW,
            self::PROP_GRID_IMPORT_POWER_L2_VAR_ID,
            self::PROP_GRID_EXPORT_POWER_L2_VAR_ID
        );
        $snapshot->phaseCActivePowerW = self::readFloat($integerProperties, self::PROP_POWER_L3_VAR_ID);
        $snapshot->phaseCActivePowerW = self::derivePowerFromImportExport(
            $integerProperties,
            $snapshot->phaseCActivePowerW,
            self::PROP_GRID_IMPORT_POWER_L3_VAR_ID,
            self::PROP_GRID_EXPORT_POWER_L3_VAR_ID
        );
        $snapshot->phaseAVoltageV = self::readFloat($integerProperties, self::PROP_VOLTAGE_L1_VAR_ID);
        $snapshot->phaseBVoltageV = self::readFloat($integerProperties, self::PROP_VOLTAGE_L2_VAR_ID);
        $snapshot->phaseCVoltageV = self::readFloat($integerProperties, self::PROP_VOLTAGE_L3_VAR_ID);
        $snapshot->phaseACurrentA = self::readFloat($integerProperties, self::PROP_CURRENT_L1_VAR_ID);
        $snapshot->phaseBCurrentA = self::readFloat($integerProperties, self::PROP_CURRENT_L2_VAR_ID);
        $snapshot->phaseCCurrentA = self::readFloat($integerProperties, self::PROP_CURRENT_L3_VAR_ID);
        $energyScaleFactor = self::resolveEnergyScaleFactor($floatProperties);
        $snapshot->totalImportedEnergyWh = self::scaleEnergyValue(
            self::readFloat($integerProperties, self::PROP_IMPORT_ENERGY_VAR_ID),
            $energyScaleFactor
        );
        $snapshot->totalExportedEnergyWh = self::scaleEnergyValue(
            self::readFloat($integerProperties, self::PROP_EXPORT_ENERGY_VAR_ID),
            $energyScaleFactor
        );

        self::applyPhaseMode($stringProperties, $snapshot);
        self::deriveTotalsFromPhases($snapshot);
        self::deriveCurrents($snapshot);

        $snapshot->isValid = self::isSnapshotValid($integerProperties, $snapshot);

        return $snapshot;
    }

    /**
     * @param array<string, int> $integerProperties
     */
    private static function readFloat(array $integerProperties, string $propertyName): ?float
    {
        $variableId = $integerProperties[$propertyName] ?? 0;
        if ($variableId <= 0 || !@IPS_VariableExists($variableId)) {
            return null;
        }

        $value = GetValue($variableId);
        if (!is_int($value) && !is_float($value) && !is_bool($value) && !is_string($value)) {
            return null;
        }

        if (!is_numeric((string) $value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<string, int> $integerProperties
     */
    private static function resolveSnapshotTimestamp(array $integerProperties): int
    {
        $timestamps = [];

        foreach (self::getConfiguredVariableIds($integerProperties) as $variableId) {
            $variable = @IPS_GetVariable($variableId);
            if ($variable === false || !isset($variable['VariableUpdated'])) {
                continue;
            }

            $timestamps[] = (int) $variable['VariableUpdated'];
        }

        if ($timestamps === []) {
            return time();
        }

        return max($timestamps);
    }

    /**
     * @return int[]
     */
    private static function getConfiguredVariableIds(array $integerProperties): array
    {
        $propertyNames = [
            self::PROP_POWER_TOTAL_VAR_ID,
            self::PROP_GRID_IMPORT_POWER_VAR_ID,
            self::PROP_GRID_EXPORT_POWER_VAR_ID,
            self::PROP_POWER_L1_VAR_ID,
            self::PROP_GRID_IMPORT_POWER_L1_VAR_ID,
            self::PROP_GRID_EXPORT_POWER_L1_VAR_ID,
            self::PROP_POWER_L2_VAR_ID,
            self::PROP_GRID_IMPORT_POWER_L2_VAR_ID,
            self::PROP_GRID_EXPORT_POWER_L2_VAR_ID,
            self::PROP_POWER_L3_VAR_ID,
            self::PROP_GRID_IMPORT_POWER_L3_VAR_ID,
            self::PROP_GRID_EXPORT_POWER_L3_VAR_ID,
            self::PROP_VOLTAGE_L1_VAR_ID,
            self::PROP_VOLTAGE_L2_VAR_ID,
            self::PROP_VOLTAGE_L3_VAR_ID,
            self::PROP_CURRENT_L1_VAR_ID,
            self::PROP_CURRENT_L2_VAR_ID,
            self::PROP_CURRENT_L3_VAR_ID,
            self::PROP_IMPORT_ENERGY_VAR_ID,
            self::PROP_EXPORT_ENERGY_VAR_ID,
        ];

        $variableIds = [];
        foreach ($propertyNames as $propertyName) {
            $variableId = $integerProperties[$propertyName] ?? 0;
            if ($variableId > 0) {
                $variableIds[] = $variableId;
            }
        }

        return array_values(array_unique($variableIds));
    }

    /**
     * Allows direct mapping from separate grid import/export power sources
     * without requiring an extra helper variable in Symcon.
     *
     * Positive import minus positive export yields the emulator's signed total power.
     *
     * @param array<string, int> $integerProperties
     */
    private static function deriveTotalPowerFromImportExport(array $integerProperties, MeasurementSnapshot $snapshot): void
    {
        if ($snapshot->totalActivePowerW !== null) {
            return;
        }

        $importPower = self::readFloat($integerProperties, self::PROP_GRID_IMPORT_POWER_VAR_ID);
        $exportPower = self::readFloat($integerProperties, self::PROP_GRID_EXPORT_POWER_VAR_ID);

        if ($importPower === null && $exportPower === null) {
            return;
        }

        $snapshot->totalActivePowerW = ($importPower ?? 0.0) - ($exportPower ?? 0.0);
    }

    /**
     * Derives a signed power value from positive import/export channels.
     *
     * @param array<string, int> $integerProperties
     */
    private static function derivePowerFromImportExport(
        array $integerProperties,
        ?float $currentPower,
        string $importPropertyName,
        string $exportPropertyName
    ): ?float {
        if ($currentPower !== null) {
            return $currentPower;
        }

        $importPower = self::readFloat($integerProperties, $importPropertyName);
        $exportPower = self::readFloat($integerProperties, $exportPropertyName);

        if ($importPower === null && $exportPower === null) {
            return null;
        }

        return ($importPower ?? 0.0) - ($exportPower ?? 0.0);
    }

    /**
     * @param array<string, string> $stringProperties
     */
    private static function applyPhaseMode(array $stringProperties, MeasurementSnapshot $snapshot): void
    {
        if ($snapshot->totalActivePowerW === null) {
            return;
        }

        $phaseMode = $stringProperties[self::PROP_PHASE_MODE] ?? 'direct';
        $phasesMissing = $snapshot->phaseAActivePowerW === null
            && $snapshot->phaseBActivePowerW === null
            && $snapshot->phaseCActivePowerW === null;

        if (!$phasesMissing) {
            return;
        }

        // This fallback only applies when the source exposes a total power value.
        if ($phaseMode === 'split_total') {
            $perPhase = $snapshot->totalActivePowerW / 3.0;
            $snapshot->phaseAActivePowerW = $perPhase;
            $snapshot->phaseBActivePowerW = $perPhase;
            $snapshot->phaseCActivePowerW = $perPhase;
            return;
        }

        if ($phaseMode === 'total_on_l1') {
            $snapshot->phaseAActivePowerW = $snapshot->totalActivePowerW;
            $snapshot->phaseBActivePowerW = 0.0;
            $snapshot->phaseCActivePowerW = 0.0;
        }
    }

    private static function deriveTotalsFromPhases(MeasurementSnapshot $snapshot): void
    {
        $phases = [
            $snapshot->phaseAActivePowerW,
            $snapshot->phaseBActivePowerW,
            $snapshot->phaseCActivePowerW,
        ];

        if ($snapshot->totalActivePowerW === null && !in_array(null, $phases, true)) {
            $snapshot->totalActivePowerW = array_sum($phases);
        }
    }

    private static function deriveCurrents(MeasurementSnapshot $snapshot): void
    {
        $snapshot->phaseACurrentA = self::deriveCurrent($snapshot->phaseACurrentA, $snapshot->phaseAActivePowerW, $snapshot->phaseAVoltageV);
        $snapshot->phaseBCurrentA = self::deriveCurrent($snapshot->phaseBCurrentA, $snapshot->phaseBActivePowerW, $snapshot->phaseBVoltageV);
        $snapshot->phaseCCurrentA = self::deriveCurrent($snapshot->phaseCCurrentA, $snapshot->phaseCActivePowerW, $snapshot->phaseCVoltageV);
    }

    private static function deriveCurrent(?float $currentA, ?float $powerW, ?float $voltageV): ?float
    {
        if ($currentA !== null) {
            return $currentA;
        }

        if ($powerW === null) {
            return null;
        }

        // Current derivation needs a defined voltage even if the data source omits it.
        $effectiveVoltage = $voltageV ?? self::DEFAULT_VOLTAGE_V;
        if ($effectiveVoltage <= 0.0) {
            return null;
        }

        return abs($powerW) / $effectiveVoltage;
    }

    /**
     * @param array<string, float> $floatProperties
     */
    private static function resolveEnergyScaleFactor(array $floatProperties): float
    {
        $factor = $floatProperties[self::PROP_ENERGY_SCALE_FACTOR] ?? 1.0;
        if ($factor <= 0.0) {
            return 1.0;
        }

        return $factor;
    }

    private static function scaleEnergyValue(?float $energyValue, float $factor): ?float
    {
        if ($energyValue === null) {
            return null;
        }

        return $energyValue * $factor;
    }

    /**
     * @param array<string, int> $integerProperties
     */
    private static function isSnapshotValid(array $integerProperties, MeasurementSnapshot $snapshot): bool
    {
        if ($snapshot->totalActivePowerW === null
            && $snapshot->phaseAActivePowerW === null
            && $snapshot->phaseBActivePowerW === null
            && $snapshot->phaseCActivePowerW === null) {
            return false;
        }

        $maxMeasurementAge = max(0, $integerProperties[self::PROP_MAX_MEASUREMENT_AGE] ?? 0);
        if ($maxMeasurementAge === 0) {
            return true;
        }

        return (time() - $snapshot->timestamp) <= $maxMeasurementAge;
    }
}
