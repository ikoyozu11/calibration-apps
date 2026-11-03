<?php

namespace App\Http\Controllers;

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Exports\CalibrationHistoryCsvExport;
use App\Exports\CsvDownload;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalibrationHistoryCsvExportController extends Controller
{
    public function __invoke(CalibrationSessionHttpRead $reads): StreamedResponse
    {
        Gate::authorize(ApplicationCapability::HistoryView->value);

        return CsvDownload::response(
            'calibration-history.csv',
            CalibrationHistoryCsvExport::header(),
            CalibrationHistoryCsvExport::rows($reads->connection()),
        );
    }
}
