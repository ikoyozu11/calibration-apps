<?php

namespace App\Http\Controllers;

use App\Authorization\ApplicationCapability;
use App\CalibrationLabels\CalibrationLabelException;
use App\CalibrationLabels\CalibrationLabelRenderer;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Models\Calibration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class CalibrationLabelController
{
    public function preview(int $calibration, CalibrationSessionHttpRead $reads, CalibrationLabelRenderer $renderer): Response
    {
        return $this->image($calibration, $reads, $renderer, HeaderUtils::DISPOSITION_INLINE);
    }

    public function download(int $calibration, CalibrationSessionHttpRead $reads, CalibrationLabelRenderer $renderer): Response
    {
        return $this->image($calibration, $reads, $renderer, HeaderUtils::DISPOSITION_ATTACHMENT);
    }

    public function print(int $calibration, CalibrationSessionHttpRead $reads, CalibrationLabelRenderer $renderer): View
    {
        $this->authorizeLabel($calibration, $reads);

        return view('calibration-labels.print', [
            'calibration' => $calibration,
            'previewUrl' => route('calibrations.label.preview', $calibration),
        ]);
    }

    private function image(
        int $calibration,
        CalibrationSessionHttpRead $reads,
        CalibrationLabelRenderer $renderer,
        string $dispositionType,
    ): Response {
        $model = $this->authorizeLabel($calibration, $reads);
        try {
            $png = $renderer->render($renderer->dataFromCalibration($model));
        } catch (CalibrationLabelException $exception) {
            abort(422, $exception->getMessage());
        }

        $filename = $this->filename($model);
        $disposition = HeaderUtils::makeDisposition($dispositionType, $filename, $filename);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeLabel(int $calibration, CalibrationSessionHttpRead $reads): Calibration
    {
        Gate::authorize(ApplicationCapability::SessionComplete->value);

        $model = $reads->calibration($calibration);
        if ((string) $model->calibration_method !== Calibration::METHOD_INTERNAL) {
            abort(403);
        }

        if ((string) $model->session_status !== Calibration::STATUS_COMPLETED) {
            abort(422, CalibrationLabelException::unsupportedSession()->getMessage());
        }

        return $model;
    }

    private function filename(Calibration $calibration): string
    {
        $serial = (string) ($calibration->snapshot_serial_number ?? $calibration->instrument?->serial_number ?? $calibration->calibration_id);
        $safeSerial = preg_replace('/[^A-Za-z0-9_-]+/', '_', $serial) ?: (string) $calibration->calibration_id;

        return 'Calibration_Label_'.$safeSerial.'.png';
    }
}
