<?php

namespace App\StagingSqlDeployment;

/**
 * Documents the only permitted live execution sequence.
 * No PostgreSQL I/O occurs here.
 */
final class StagingSqlAtomicExecutionSteps
{
    public const STEP_OPEN_EXPLICIT_CONNECTION = 'open_explicit_connection';

    public const STEP_BEGIN_TRANSACTION = 'begin_transaction';

    public const STEP_VERIFY_BASELINE = 'verify_baseline';

    public const STEP_EXECUTE_ORDERED_BODIES = 'execute_ordered_bodies';

    public const STEP_VERIFY_RESULTING_SCHEMA = 'verify_resulting_schema';

    public const STEP_COMMIT_ONCE = 'commit_once';

    public const STEP_ROLLBACK_ON_FAILURE = 'rollback_on_failure';

    /**
     * @return list<string>
     */
    public static function ordered(): array
    {
        return [
            self::STEP_OPEN_EXPLICIT_CONNECTION,
            self::STEP_BEGIN_TRANSACTION,
            self::STEP_VERIFY_BASELINE,
            self::STEP_EXECUTE_ORDERED_BODIES,
            self::STEP_VERIFY_RESULTING_SCHEMA,
            self::STEP_COMMIT_ONCE,
        ];
    }

    public static function commitIsLastMutableStep(): bool
    {
        $steps = self::ordered();

        return end($steps) === self::STEP_COMMIT_ONCE;
    }
}
