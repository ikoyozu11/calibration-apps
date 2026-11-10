<?php

namespace App\Certificates;

use DateTimeInterface;
use Illuminate\Database\Connection;

final class CertificateNumberAllocator
{
    public function allocate(Connection $connection, DateTimeInterface $issuedAt): string
    {
        $year = (int) $issuedAt->format('Y');

        if ($connection->getDriverName() === 'pgsql') {
            $connection->selectOne(
                'select pg_advisory_xact_lock(hashtextextended(?, 0))',
                ["internal-certificate-number:{$year}"],
            );
        }

        $start = "{$year}-01-01";
        $end = "{$year}-12-31";
        $highest = 0;

        foreach ($connection->table('certificate')->whereBetween('issued_date', [$start, $end])->pluck('certificate_number') as $number) {
            if (preg_match('/^SR\.VT(\d{3})\/\d{2}\/\d{2}$/', (string) $number, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        $sequence = $highest + 1;

        if ($sequence > 999) {
            throw CertificateIssuanceException::sequenceOverflow();
        }

        return sprintf('SR.VT%03d/%s/%s', $sequence, $issuedAt->format('m'), $issuedAt->format('y'));
    }
}
