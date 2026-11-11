<?php

namespace Tests\Support;

use RuntimeException;

final class NotificationE2e03bExecutionMarker
{
    public function claim(string $path): void
    {
        if (trim($path) === '') {
            throw new RuntimeException('The one-time execution marker path is empty.');
        }

        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException('The one-time execution marker already exists or cannot be created.');
        }

        try {
            fwrite($handle, json_encode([
                'checkpoint' => 'NOTIF-E2E-03B',
                'claimed_at' => gmdate(DATE_ATOM),
            ], JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            fclose($handle);
        }
    }
}
