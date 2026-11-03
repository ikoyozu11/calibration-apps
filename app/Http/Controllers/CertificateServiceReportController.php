<?php

namespace App\Http\Controllers;

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Certificates\ServiceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CertificateServiceReportController extends Controller
{
    public function __invoke(int $certificate, CalibrationSessionHttpRead $reads): Response
    {
        Gate::authorize(ApplicationCapability::CertificatesView->value);

        $model = $reads->certificate($certificate);
        $report = ServiceReport::from($model);
        $filename = filled($model->certificate_number)
            ? $model->certificate_number.'.pdf'
            : 'service-report.pdf';

        return Pdf::loadView('certificates.service-report', ['report' => $report])
            ->setPaper('a4', 'portrait')
            ->stream($filename);
    }
}
