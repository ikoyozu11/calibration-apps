<?php

namespace App\Http\Controllers;

use App\Authorization\ApplicationCapability;
use App\Certificates\ServiceReport;
use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CertificateServiceReportController extends Controller
{
    public function __invoke(Certificate $certificate): Response
    {
        Gate::authorize(ApplicationCapability::CertificatesView->value);

        $report = ServiceReport::from($certificate);
        $filename = filled($certificate->certificate_number)
            ? $certificate->certificate_number.'.pdf'
            : 'service-report.pdf';

        return Pdf::loadView('certificates.service-report', ['report' => $report])
            ->setPaper('a4', 'portrait')
            ->stream($filename);
    }
}
