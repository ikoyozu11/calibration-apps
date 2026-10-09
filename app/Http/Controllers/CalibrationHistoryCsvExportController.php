<?php

namespace App\Http\Controllers;

use App\Exports\CalibrationHistoryCsvExport;
use App\Exports\CsvDownload;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalibrationHistoryCsvExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        return CsvDownload::response(
            'calibration-history.csv',
            CalibrationHistoryCsvExport::header(),
            CalibrationHistoryCsvExport::rows(),
        );
    }
}
