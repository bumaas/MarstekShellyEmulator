<?php

declare(strict_types=1);

final class Validation
{
    public static function isPositiveFactor(float $factor): bool
    {
        return $factor > 0.0;
    }
}
