<?php

namespace App\Http\Controllers;

use App\Authorization\ApplicationCapability;
use App\Exports\CalibrationHistoryCsvExport;
use App\Exports\CsvDownload;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalibrationHistoryCsvExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        Gate::authorize(ApplicationCapability::HistoryView->value);

        return CsvDownload::response(
            'calibration-history.csv',
            CalibrationHistoryCsvExport::header(),
            CalibrationHistoryCsvExport::rows(),
        );
    }
}
