<?php

namespace Tests\Support;

use App\CalibrationDueNotifications\CalibrationDueRecipients;
use InvalidArgumentException;

final class NotificationE2eRecipientAllowlist
{
    public const ALLOWED_RECIPIENT = 'service.malang@vision-teknik.com';

    /**
     * @return list<string>
     */
    public static function assertEffectiveRecipients(): array
    {
        $configured = config('calibration_due_notifications.recipients');
        $legacy = config('calibration_due_notifications.recipient');

        if (is_array($configured) && $configured === [] && is_string($legacy) && trim($legacy) !== '') {
            throw new InvalidArgumentException('The E2E recipient configuration relies on a fallback recipient.');
        }

        $rawRecipients = is_array($configured) && $configured !== []
            ? array_values($configured)
            : (is_string($legacy) ? [$legacy] : []);

        if ($rawRecipients === []) {
            throw new InvalidArgumentException('The E2E recipient configuration is empty.');
        }

        $recipients = array_map(static function (mixed $recipient): string {
            if (! is_string($recipient)) {
                throw new InvalidArgumentException('The E2E recipient configuration contains a non-string value.');
            }

            $recipient = mb_strtolower(trim($recipient));

            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('The E2E recipient configuration contains an invalid email address.');
            }

            return $recipient;
        }, $rawRecipients);

        if (count($recipients) !== count(array_unique($recipients))) {
            throw new InvalidArgumentException('The E2E recipient configuration contains duplicate recipients.');
        }

        if ($recipients !== [self::ALLOWED_RECIPIENT]) {
            throw new InvalidArgumentException('The E2E recipient configuration is outside the approved allowlist.');
        }

        foreach (['cc', 'bcc'] as $field) {
            $value = config('mail.'.$field);

            if ($value !== null && $value !== [] && $value !== '') {
                throw new InvalidArgumentException('The E2E mail configuration contains '.$field.' recipients.');
            }
        }

        $resolved = CalibrationDueRecipients::fromEnvironment(implode(',', $recipients), null);

        if ($resolved !== $recipients) {
            throw new InvalidArgumentException('The effective E2E recipient list differs from the allowlist.');
        }

        return $resolved;
    }
}
