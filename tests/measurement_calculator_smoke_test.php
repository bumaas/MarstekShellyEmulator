<?php

declare(strict_types=1);

if (!class_exists('IPSModuleStrict')) {
    class IPSModuleStrict
    {
        public function ReadPropertyInteger(string $name): int
        {
            return 0;
        }

        public function ReadPropertyString(string $name): string
        {
            return '';
        }
    }
}

$GLOBALS['IPS_TEST_VARIABLES'] = [];
$GLOBALS['IPS_TEST_UPDATES'] = [];

function IPS_VariableExists(int $variableId): bool
{
    return array_key_exists($variableId, $GLOBALS['IPS_TEST_VARIABLES']);
}

function IPS_GetVariable(int $variableId): array|false
{
    if (!array_key_exists($variableId, $GLOBALS['IPS_TEST_UPDATES'])) {
        return false;
    }

    return [
        'VariableUpdated' => $GLOBALS['IPS_TEST_UPDATES'][$variableId],
    ];
}

function GetValue(int $variableId): mixed
{
    return $GLOBALS['IPS_TEST_VARIABLES'][$variableId];
}

require_once dirname(__DIR__) . '/libs/MeasurementSnapshot.php';
require_once dirname(__DIR__) . '/libs/MeasurementCalculator.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assertNear(float $actual, float $expected, float $delta, string $message): void
{
    assertTrue(abs($actual - $expected) <= $delta, $message . sprintf(' (expected %.4f, got %.4f)', $expected, $actual));
}

function setVariables(array $values, array $timestamps): void
{
    $GLOBALS['IPS_TEST_VARIABLES'] = $values;
    $GLOBALS['IPS_TEST_UPDATES'] = $timestamps;
}

$now = time();

setVariables(
    [101 => 900.0],
    [101 => $now]
);
$splitSnapshot = MeasurementCalculator::fromConfiguration(
    [
        'powerTotalVarId' => 101,
        'maxMeasurementAge' => 10,
    ],
    [
        'phaseMode' => 'split_total',
    ]
);
assertTrue($splitSnapshot->isValid, 'split_total snapshot should be valid');
assertNear($splitSnapshot->phaseAActivePowerW ?? -1, 300.0, 0.0001, 'phase A should receive one third of total power');
assertNear($splitSnapshot->phaseBActivePowerW ?? -1, 300.0, 0.0001, 'phase B should receive one third of total power');
assertNear($splitSnapshot->phaseCCurrentA ?? -1, 300.0 / 230.0, 0.0001, 'phase current should be derived from fallback voltage');

setVariables(
    [
        111 => 500.0,
        112 => 1250.0,
    ],
    [
        111 => $now,
        112 => $now,
    ]
);
$gridSnapshot = MeasurementCalculator::fromConfiguration(
    [
        'gridImportPowerVarId' => 111,
        'gridExportPowerVarId' => 112,
        'maxMeasurementAge' => 10,
    ],
    [
        'phaseMode' => 'total_on_l1',
    ]
);
assertTrue($gridSnapshot->isValid, 'import/export derived snapshot should be valid');
assertNear($gridSnapshot->totalActivePowerW ?? -1, -750.0, 0.0001, 'total power should be derived as import minus export');

setVariables(
    [
        201 => 100.0,
        202 => 200.0,
        203 => 300.0,
        204 => 230.0,
        205 => 231.0,
        206 => 229.0,
    ],
    [
        201 => $now,
        202 => $now,
        203 => $now,
        204 => $now,
        205 => $now,
        206 => $now,
    ]
);
$phaseSnapshot = MeasurementCalculator::fromConfiguration(
    [
        'powerL1VarId' => 201,
        'powerL2VarId' => 202,
        'powerL3VarId' => 203,
        'voltageL1VarId' => 204,
        'voltageL2VarId' => 205,
        'voltageL3VarId' => 206,
        'maxMeasurementAge' => 10,
    ],
    [
        'phaseMode' => 'direct',
    ]
);
assertTrue($phaseSnapshot->isValid, 'phase snapshot should be valid');
assertNear($phaseSnapshot->totalActivePowerW ?? -1, 600.0, 0.0001, 'total power should be derived from phase powers');
assertNear($phaseSnapshot->phaseBCurrentA ?? -1, 200.0 / 231.0, 0.0001, 'phase B current should be derived from phase voltage');

setVariables(
    [
        211 => 50.0,
        212 => 10.0,
        213 => 100.0,
        214 => 0.0,
        215 => 25.0,
        216 => 75.0,
    ],
    [
        211 => $now,
        212 => $now,
        213 => $now,
        214 => $now,
        215 => $now,
        216 => $now,
    ]
);
$phaseImportExportSnapshot = MeasurementCalculator::fromConfiguration(
    [
        'gridImportPowerL1VarId' => 211,
        'gridExportPowerL1VarId' => 212,
        'gridImportPowerL2VarId' => 213,
        'gridExportPowerL2VarId' => 214,
        'gridImportPowerL3VarId' => 215,
        'gridExportPowerL3VarId' => 216,
        'maxMeasurementAge' => 10,
    ],
    [
        'phaseMode' => 'direct',
    ]
);
assertTrue($phaseImportExportSnapshot->isValid, 'phase import/export snapshot should be valid');
assertNear($phaseImportExportSnapshot->phaseAActivePowerW ?? -1, 40.0, 0.0001, 'phase A power should be derived from import minus export');
assertNear($phaseImportExportSnapshot->phaseBActivePowerW ?? -1, 100.0, 0.0001, 'phase B power should allow zero export');
assertNear($phaseImportExportSnapshot->phaseCActivePowerW ?? -1, -50.0, 0.0001, 'phase C power should be negative on net export');
assertNear($phaseImportExportSnapshot->totalActivePowerW ?? -1, 90.0, 0.0001, 'total power should be derived from phase import/export values');

setVariables(
    [
        221 => 31.3475238,
        222 => 65.8154915,
        223 => -13198.7,
    ],
    [
        221 => $now,
        222 => $now,
        223 => $now,
    ]
);
$energyScaledSnapshot = MeasurementCalculator::fromConfiguration(
    [
        'importEnergyVarId' => 221,
        'exportEnergyVarId' => 222,
        'powerTotalVarId' => 223,
        'maxMeasurementAge' => 10,
    ],
    [
        'phaseMode' => 'split_total',
    ],
    [
        'energyScaleFactor' => 1000.0,
    ]
);
assertTrue($energyScaledSnapshot->isValid, 'energy-scaled snapshot should be valid');
assertNear($energyScaledSnapshot->totalImportedEnergyWh ?? -1, 31347.5238, 0.0001, 'import energy should be scaled to Wh');
assertNear($energyScaledSnapshot->totalExportedEnergyWh ?? -1, 65815.4915, 0.0001, 'export energy should be scaled to Wh');

setVariables(
    [301 => 450.0],
    [301 => $now - 30]
);
$staleSnapshot = MeasurementCalculator::fromConfiguration(
    [
        'powerTotalVarId' => 301,
        'maxMeasurementAge' => 10,
    ],
    [
        'phaseMode' => 'total_on_l1',
    ]
);
assertTrue(!$staleSnapshot->isValid, 'stale snapshot should be invalid');

setVariables([], []);
$emptySnapshot = MeasurementCalculator::fromConfiguration(
    [
        'maxMeasurementAge' => 10,
    ],
    [
        'phaseMode' => 'direct',
    ]
);
assertTrue(!$emptySnapshot->isValid, 'snapshot without any power values should be invalid');

echo "measurement_calculator_smoke_test: ok\n";
