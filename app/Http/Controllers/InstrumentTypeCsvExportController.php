<?php

namespace App\Http\Controllers;

use App\Exports\CsvDownload;
use App\Exports\InstrumentTypeCsvExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstrumentTypeCsvExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        return CsvDownload::response(
            'instrument-types.csv',
            InstrumentTypeCsvExport::header(),
            InstrumentTypeCsvExport::rows(),
        );
    }
}
