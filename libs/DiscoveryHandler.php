<?php

declare(strict_types=1);

/**
 * Placeholder for future Marstek/Shelly discovery support.
 *
 * The current emulator does not implement discovery or broadcast handling yet.
 * Keep this file as an explicit extension point until real discovery payloads
 * from the target device have been captured and validated.
 */
final class DiscoveryHandler
{
    /**
     * Discovery is currently not implemented.
     *
     * @param array<string, mixed> $message
     */
    public static function isDiscoveryMessage(array $message): bool
    {
        return false;
    }
}
