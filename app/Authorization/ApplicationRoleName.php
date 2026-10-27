<?php

namespace App\Authorization;

/**
 * Trusted application role names stored in the Laravel auth database.
 *
 * These names are not domain actor roles and must never be accepted from
 * browser input as authorization evidence.
 */
enum ApplicationRoleName: string
{
    case Administrator = 'Administrator';
    case Engineer = 'Engineer';
    case Viewer = 'Viewer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
