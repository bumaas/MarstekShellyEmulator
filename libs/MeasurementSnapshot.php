<?php

declare(strict_types=1);

final class MeasurementSnapshot
{
    public int $timestamp = 0;
    public ?float $totalActivePowerW = null;
    public ?float $phaseAActivePowerW = null;
    public ?float $phaseBActivePowerW = null;
    public ?float $phaseCActivePowerW = null;
    public ?float $phaseAVoltageV = null;
    public ?float $phaseBVoltageV = null;
    public ?float $phaseCVoltageV = null;
    public ?float $phaseACurrentA = null;
    public ?float $phaseBCurrentA = null;
    public ?float $phaseCCurrentA = null;
    public ?float $frequencyHz = null;
    public ?float $totalImportedEnergyWh = null;
    public ?float $totalExportedEnergyWh = null;
    public bool $isValid = false;
}
