<?php

namespace App\CalibrationDueNotifications;

final class CalibrationDueRecipients
{
    /**
     * @return list<string>
     */
    public static function fromEnvironment(?string $multiple, ?string $single): array
    {
        $source = trim((string) $multiple) !== '' ? $multiple : $single;

        if ($source === null || trim($source) === '') {
            return [];
        }

        $recipients = array_map(
            static fn (string $recipient): string => mb_strtolower(trim($recipient)),
            explode(',', $source),
        );

        $recipients = array_filter(
            $recipients,
            static fn (string $recipient): bool => filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false,
        );

        return array_values(array_unique($recipients));
    }
}
