<?php

namespace App\CalibrationSessions;

final class CalibrationSessionCompleteness
{
    public const MODE_REQUIRED = 'required';

    public const MODE_OPTIONAL = 'optional';

    public const MODE_NOT_CONFIGURED = 'not_configured';

    /**
     * Frozen Required/Optional modes stay. Not Configured uses a one-time resolved mode.
     */
    public static function effectiveMode(string $frozenMode, ?string $resolvedMode): string
    {
        if (in_array($frozenMode, [self::MODE_REQUIRED, self::MODE_OPTIONAL], true)) {
            return $frozenMode;
        }

        if ($frozenMode === self::MODE_NOT_CONFIGURED && $resolvedMode !== null) {
            return $resolvedMode;
        }

        return self::MODE_NOT_CONFIGURED;
    }

    /**
     * Mirrors public.calibration_session_assert_environment_complete.
     *
     * @param  list<array{requirement_mode: string, value: mixed, inclusion_confirmed?: bool}>  $parameters
     */
    public static function environmentReadyForInternalComplete(
        string $effectiveMode,
        bool $configurationConfirmed,
        array $parameters,
    ): bool {
        if ($effectiveMode === self::MODE_NOT_CONFIGURED) {
            return false;
        }

        if (! $configurationConfirmed) {
            return false;
        }

        foreach ($parameters as $parameter) {
            if (($parameter['inclusion_confirmed'] ?? true) === false) {
                return false;
            }
        }

        $required = array_values(array_filter(
            $parameters,
            fn (array $parameter): bool => $parameter['requirement_mode'] === self::MODE_REQUIRED,
        ));

        if ($effectiveMode === self::MODE_REQUIRED && $required === []) {
            return false;
        }

        foreach ($required as $parameter) {
            if (! self::hasValue($parameter['value'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{completion_rule?: string|null, value?: mixed}>  $cells
     */
    public static function testComponentsReady(array $cells): bool
    {
        foreach ($cells as $cell) {
            $rule = $cell['completion_rule'] ?? self::MODE_NOT_CONFIGURED;

            if ($rule === self::MODE_NOT_CONFIGURED) {
                return false;
            }

            if ($rule === self::MODE_REQUIRED && ! self::hasValue($cell['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public static function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }
}
