<?php

namespace App\Http\Controllers;

use App\Authorization\ApplicationCapability;
use App\Exports\CsvDownload;
use App\Exports\InstrumentTypeCsvExport;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstrumentTypeCsvExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        Gate::authorize(ApplicationCapability::HistoryView->value);

        return CsvDownload::response(
            'instrument-types.csv',
            InstrumentTypeCsvExport::header(),
            InstrumentTypeCsvExport::rows(),
        );
    }
}
