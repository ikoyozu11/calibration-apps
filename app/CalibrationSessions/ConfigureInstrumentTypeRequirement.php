<?php

namespace App\CalibrationSessions;

use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;

/**
 * Sets one master requirement mode for one Instrument Type.
 *
 * Future HTTP callers must authenticate and authorize before calling this
 * service. Master rows have no actor column. Laravel users.id is not an
 * app_user.app_user_id.
 *
 * This write does not update calibration sessions or frozen snapshots.
 * Callers must pass an already chosen Connection. Staging acquisition via
 * resolveNamed('pgsql_staging') stays outside this service and outside Pest.
 */
class ConfigureInstrumentTypeRequirement
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    public function configure(
        int $instrumentTypeId,
        string $requirementCategory,
        string $mode,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);
        $category = $this->category($requirementCategory);
        $mode = $this->mode($mode);

        $connection->transaction(function () use ($connection, $instrumentTypeId, $category, $mode): void {
            $instrumentType = $connection->table('instrument_type')
                ->where('instrument_type_id', $instrumentTypeId)
                ->lockForUpdate()
                ->first();

            if ($instrumentType === null) {
                throw ConfigureInstrumentTypeRequirementException::instrumentTypeMissing();
            }

            if ($category === InstrumentTypeRequirement::CATEGORY_ENVIRONMENT
                && $mode === InstrumentTypeRequirement::MODE_REQUIRED
                && ! $this->hasRequiredEnvironmentParameter($connection, $instrumentTypeId)) {
                throw ConfigureInstrumentTypeRequirementException::environmentRequiredParameterMissing();
            }

            $this->upsert($connection, $instrumentTypeId, $category, $mode);
        });
    }

    private function category(string $requirementCategory): string
    {
        if (! in_array($requirementCategory, InstrumentTypeRequirement::CATEGORIES, true)) {
            throw ConfigureInstrumentTypeRequirementException::unknownCategory();
        }

        return $requirementCategory;
    }

    private function mode(string $mode): string
    {
        if (! in_array($mode, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
            InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
        ], true)) {
            throw ConfigureInstrumentTypeRequirementException::unknownMode();
        }

        return $mode;
    }

    private function hasRequiredEnvironmentParameter(Connection $connection, int $instrumentTypeId): bool
    {
        return $connection->table('instrument_type_environment_parameter')
            ->where('instrument_type_id', $instrumentTypeId)
            ->where('requirement_mode', InstrumentTypeRequirement::MODE_REQUIRED)
            ->exists();
    }

    private function upsert(Connection $connection, int $instrumentTypeId, string $category, string $mode): void
    {
        $timestamp = now();
        $updated = $connection->table('instrument_type_requirement')
            ->where('instrument_type_id', $instrumentTypeId)
            ->where('requirement_category', $category)
            ->update([
                'requirement_mode' => $mode,
                'updated_at' => $timestamp,
            ]);

        if ($updated > 0) {
            return;
        }

        $connection->table('instrument_type_requirement')->insert([
            'instrument_type_id' => $instrumentTypeId,
            'requirement_category' => $category,
            'requirement_mode' => $mode,
            'updated_at' => $timestamp,
        ]);
    }
}
