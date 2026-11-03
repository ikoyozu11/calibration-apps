<?php

namespace App\TestColumns;

use App\CalibrationSessions\CalibrationHttpConnection;
use App\CalibrationSessions\CalibrationHttpConnectionException;
use Illuminate\Database\Connection;

trait WritesThroughHttpConnection
{
    private function writeConnection(): Connection
    {
        return app(CalibrationHttpConnection::class)->resolve();
    }

    private function writeConnectionName(): string
    {
        $name = $this->writeConnection()->getName();

        if (! is_string($name) || $name === '') {
            throw CalibrationHttpConnectionException::unavailable();
        }

        return $name;
    }
}
