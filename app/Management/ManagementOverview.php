<?php

namespace App\Management;

use App\Models\CalibrationActivityMaster;
use App\Models\DetailLocation;
use App\Models\Instrument;
use App\Models\Location;
use App\Models\StandardMaster;
use Illuminate\Database\Connection;
use InvalidArgumentException;

final readonly class ManagementOverview
{
    public function __construct(
        public int $instruments,
        public int $locations,
        public int $standards,
        public int $activities,
    ) {}

    public static function current(Connection $connection): self
    {
        $connectionName = $connection->getName();

        if (! is_string($connectionName) || $connectionName === '') {
            throw new InvalidArgumentException('Management overview requires a named read connection.');
        }

        $schema = $connection->getSchemaBuilder();
        $instrumentTable = (new Instrument)->getTable();
        $detailLocationTable = (new DetailLocation)->getTable();
        $locationTable = (new Location)->getTable();
        $standardTable = (new StandardMaster)->getTable();
        $activityTable = (new CalibrationActivityMaster)->getTable();
        $hasInstrumentLocationData = $schema->hasTable($instrumentTable)
            && $schema->hasTable($detailLocationTable)
            && $schema->hasTable($locationTable);

        $locations = 0;

        if ($hasInstrumentLocationData) {
            $instrumentIds = Instrument::on($connectionName)
                ->whereNotNull('detail_location_id')
                ->select('detail_location_id');
            $locationIds = DetailLocation::on($connectionName)
                ->whereIn('detail_location_id', $instrumentIds)
                ->whereNotNull('location_id')
                ->select('location_id');

            $locations = Location::on($connectionName)
                ->whereNotNull('location_name')
                ->whereRaw('trim(location_name) <> \'\'')
                ->whereIn('location_id', $locationIds)
                ->count();
        }

        return new self(
            instruments: $schema->hasTable($instrumentTable)
                ? Instrument::on($connectionName)->count()
                : 0,
            locations: $locations,
            standards: $schema->hasTable($standardTable)
                ? StandardMaster::on($connectionName)->count()
                : 0,
            activities: $schema->hasTable($activityTable)
                ? CalibrationActivityMaster::on($connectionName)->count()
                : 0,
        );
    }
}
