<?php

namespace App\Http\Controllers;

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Exports\CalibrationReportXlsxExport;
use App\Reports\CalibrationReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CalibrationReportExportController extends Controller
{
    public function __invoke(Request $request, CalibrationSessionHttpRead $reads): StreamedResponse
    {
        Gate::authorize(ApplicationCapability::HistoryView->value);

        $connection = $reads->connection();
        $year = CalibrationReport::sanitizeYear($request->input('year'), $connection);
        $location = CalibrationReport::sanitizeLocation($request->input('location'));
        if ($location !== '' && ! in_array($location, CalibrationReport::locations($connection), true)) {
            abort(422, 'The selected location is not available.');
        }

        $search = is_string($request->input('search')) ? trim($request->input('search')) : '';
        $export = new CalibrationReportXlsxExport($connection, $year, $location, $search);
        $locationSegment = $location === '' ? 'All_Locations' : Str::slug($location, '_');

        return response()->streamDownload(
            fn (): mixed => $export->writer()->save('php://output'),
            'Calibration_Report_'.$year.'_'.$locationSegment.'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
