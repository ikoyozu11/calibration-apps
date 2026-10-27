<?php

namespace App\StagingSqlDeployment;

/**
 * Fixed allow-list for the atomic staging SQL deployment package (2C.2B-21X/21Z).
 *
 * Original SQL files under database/sql remain the source of truth. Hashes must
 * match before any future execution adapter may run.
 */
final class ApprovedStagingSqlArtifacts
{
    public const APPROVED_HEAD = 'efa679eae287de7a0754131297b6ac50f27e3578';

    public const APPROVED_DATABASE = 'calibration_staging_db';

    public const FORBIDDEN_DATABASE_PRODUCTION = 'calibration_db';

    public const FORBIDDEN_DATABASE_MAINTENANCE = 'postgres';

    public const APPROVED_HOSTS = ['127.0.0.1', 'localhost'];

    public const APPROVED_PORT = 5432;

    /**
     * Historical 21V live baseline for public.trg_calibration_session_guard().
     * Fresh inventory must reconfirm before any write approval.
     */
    public const APPROVED_BASELINE_TRIGGER_MD5 = '25d589b636de66977f2baf79a21b9b79';

    public const APPROVED_BASELINE_TRIGGER_LENGTH = 2273;

    public const APPROVED_BACKUP_SHA256 = '69a8a8cf46a341452251f473821b34a9361462215d85a7471ebaa72a27a35b78';

    /**
     * Ordered artifact descriptors.
     *
     * @return list<array{relative_path: string, sha256: string}>
     */
    public static function ordered(): array
    {
        return [
            [
                'relative_path' => 'database/sql/roles.sql',
                'sha256' => 'cacc3b7b8beb0ea4b76324608fa1f505c47d8593ea20f96093e91f2b5a848a3c',
            ],
            [
                'relative_path' => 'database/sql/calibration_started_by_app_user.sql',
                'sha256' => 'fd9c50e897e02ba962d66d5e11cc87807f2880477f50b52c4b95c8430a5c3a2e',
            ],
            [
                'relative_path' => 'database/sql/calibration_started_by_immutability_guard.sql',
                'sha256' => '69fd8605471ea50dab130eeedebcb69f0f821bab53f73b1f72bbada276e762cd',
            ],
        ];
    }
}
