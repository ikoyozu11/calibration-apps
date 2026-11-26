<?php

namespace App\CalibrationDueNotifications;

use Illuminate\Support\Collection;

final readonly class AuthoritativeCalibrationPairEnumeration
{
    /**
     * @param  Collection<int, AuthoritativeCalibrationPair>  $pairs
     */
    public function __construct(
        public bool $complete,
        public Collection $pairs,
        public ?string $failureCategory = null,
    ) {}

    public static function complete(Collection $pairs): self
    {
        return new self(true, $pairs->values());
    }

    public static function incomplete(string $failureCategory): self
    {
        return new self(false, collect(), $failureCategory);
    }
}
