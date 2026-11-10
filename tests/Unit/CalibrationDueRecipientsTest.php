<?php

use App\CalibrationDueNotifications\CalibrationDueRecipients;

it('normalizes valid comma-separated recipients and removes case-insensitive duplicates', function (): void {
    expect(CalibrationDueRecipients::fromEnvironment(
        ' service.malang@vision-teknik.com, Indra_Tri_Sutanto@bat.com, SERVICE.MALANG@VISION-TEKNIK.COM, invalid ',
        null,
    ))->toBe([
        'service.malang@vision-teknik.com',
        'indra_tri_sutanto@bat.com',
    ]);
});

it('falls back to the legacy single-recipient setting', function (): void {
    expect(CalibrationDueRecipients::fromEnvironment(null, ' Legacy@Example.com '))
        ->toBe(['legacy@example.com']);
});

it('returns no recipients when all configured addresses are invalid', function (): void {
    expect(CalibrationDueRecipients::fromEnvironment('not-an-email,also-invalid', null))
        ->toBe([]);
});
