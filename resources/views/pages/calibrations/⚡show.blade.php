<?php

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\CalibrationSessionDetailPresenter;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\CalibrationSessions\CalibrationSessionRequirementPolicy;
use App\CalibrationSessions\CancelCalibrationSession;
use App\CalibrationSessions\CancelCalibrationSessionException;
use App\CalibrationSessions\CompleteCalibrationSession;
use App\CalibrationSessions\CompleteCalibrationSessionException;
use App\CalibrationSessions\MaterializeCalibrationTestTemplateValues;
use App\CalibrationSessions\MaterializeCalibrationTestTemplateValuesException;
use App\CalibrationSessions\PinCalibrationTestTables;
use App\CalibrationSessions\PinCalibrationTestTablesException;
use App\CalibrationSessions\ConfirmEnvironmentConfiguration;
use App\CalibrationSessions\ConfirmEnvironmentConfigurationException;
use App\CalibrationSessions\RecordCalibrationActivity;
use App\CalibrationSessions\RecordCalibrationActivityException;
use App\CalibrationSessions\RecordCalibrationEnvironmentMeasurement;
use App\CalibrationSessions\RecordCalibrationEnvironmentMeasurementException;
use App\CalibrationSessions\RecordCalibrationSessionHttpConnection;
use App\CalibrationSessions\RecordCalibrationSessionHttpException;
use App\CalibrationSessions\RecordCalibrationSessionFields;
use App\CalibrationSessions\RecordCalibrationSessionFieldsException;
use App\CalibrationSessions\RecordCalibrationStandardUsage;
use App\CalibrationSessions\RecordCalibrationStandardUsageException;
use App\CalibrationSessions\RecordCalibrationTestResult;
use App\CalibrationSessions\RecordCalibrationTestResultException;
use App\CalibrationSessions\RecordExternalCalibrationVendor;
use App\CalibrationSessions\RecordExternalCalibrationVendorException;
use App\CalibrationSessions\ResolveCalibrationRequirement;
use App\CalibrationSessions\ResolveCalibrationRequirementException;
use App\CalibrationSessions\ResolveDomainActorException;
use App\CalibrationSessions\StoreExternalCalibrationDocument;
use App\CalibrationSessions\StoreExternalCalibrationDocumentException;
use App\TestColumns\DurationValue;
use App\Models\Calibration;
use App\Models\CalibrationScope;
use App\Models\InstrumentTypeRequirement;
use App\Models\TestPoint;
use App\Models\TestProfileVersion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $calibrationId = 0;

    private ?Calibration $loadedCalibration = null;

    public string $resolvedMode = '';

    public string $resolutionReason = '';

    public ?string $pinStatus = null;

    public ?string $recoverStatus = null;

    public string $completionDate = '';

    public string $completionDue = '';

    public string $resume = '';

    public ?string $dateStatus = null;

    public ?string $resumeStatus = null;


    public bool $cancellationModalOpen = false;

    public string $cancellationReason = '';


    public string $externalVendorName = '';

    public ?string $vendorStatus = null;

    public mixed $externalCertificatePdf = null;

    public ?string $uploadStatus = null;

    /**
     * @var array<string, string>
     */
    public array $resultInputs = [];

    public ?string $resultStatus = null;

    public ?string $resultStatusKey = null;

    public string $standardsResolvedMode = '';

    public string $standardsResolutionReason = '';

    public string $usageStandardId = '';

    /**
     * @var list<string>
     */
    public array $draftStandardIds = [];

    public bool $addingStandards = false;

    public string $standardSearch = '';

    /**
     * @var list<string>
     */
    public array $modalStandardIds = [];

    public string $newUsageRange = '';

    public string $newUsageUnit = '';

    public string $newUsagePurpose = '';

    public string $newUsageRemarks = '';

    /**
     * @var array<string, array{range: string, unit: string, purpose: string, remarks: string}>
     */
    public array $usageDetails = [];

    public ?int $removingUsageId = null;

    public ?string $usageStatus = null;

    public ?string $usageStatusKey = null;

    public string $activityResolvedMode = '';

    public string $activityResolutionReason = '';

    public string $selectedActivityId = '';

    /**
     * @var list<string>
     */
    public array $selectedActivityIds = [];

    public bool $addingActivities = false;

    public string $activitySearch = '';

    /**
     * @var list<string>
     */
    public array $modalActivityIds = [];

    public string $newActivityDescription = '';

    public string $newActivityResult = '';

    /**
     * @var array<string, array{description: string, result: string}>
     */
    public array $activityDetails = [];

    public ?int $removingActivityId = null;

    public ?string $activityStatus = null;

    public ?string $activityStatusKey = null;

    public string $environmentResolvedMode = '';

    public string $environmentResolutionReason = '';

    public string $environmentConfirmationReason = '';

    /**
     * @var array<string, string>
     */
    public array $environmentInputs = [];

    public ?string $environmentStatus = null;

    public ?string $environmentStatusKey = null;

    public function mount(int $calibration): void
    {
        Gate::authorize(ApplicationCapability::HistoryView->value);

        $this->calibrationId = $calibration;
        $this->loadCalibrationDetail();
        $this->syncSelectedActivityIds();
        $this->hydrateDraftStandards();
        $this->syncExternalVendorInput();
    }

    #[Computed]
    public function calibration(): Calibration
    {
        return $this->currentCalibration();
    }

    public function rendering(): void
    {
        $this->loadCalibrationDetail();
    }

    public function resolveTestProfileRequirement(
        ResolveCalibrationRequirement $resolver,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        if (! $this->assertWritablePinningContext()) {
            return;
        }

        $this->validate([
            'resolvedMode' => ['required', Rule::in([
                InstrumentTypeRequirement::MODE_REQUIRED,
                InstrumentTypeRequirement::MODE_OPTIONAL,
            ])],
            'resolutionReason' => ['required', 'string'],
        ]);

        $laravelUserId = (int) Auth::id();

        if ($laravelUserId < 1) {
            abort(403);
        }

        try {
            $resolver->resolve(
                (int) $this->currentCalibration()->calibration_id,
                InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
                $this->resolvedMode,
                $this->resolutionReason,
                $laravelUserId,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('resolvedMode', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('resolvedMode', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (ResolveDomainActorException $exception) {
            $this->addError('resolvedMode', $exception->getMessage());

            return;
        } catch (ResolveCalibrationRequirementException $exception) {
            $this->addError('resolvedMode', $exception->getMessage());

            return;
        }

        $this->resolvedMode = '';
        $this->resolutionReason = '';
        $this->pinStatus = 'The test profile requirement was resolved.';
        $this->dispatchNotification('success', $this->pinStatus);
        $this->loadCalibrationDetail();
    }

    public function pinTestTables(
        PinCalibrationTestTables $pin,
        MaterializeCalibrationTestTemplateValues $materialize,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        if (! $this->assertWritablePinningContext()) {
            return;
        }

        try {
            $connection = $writes->resolve();
            $pin->pin((int) $this->currentCalibration()->calibration_id, $connection);
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('pinTestTables', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('pinTestTables', $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (PinCalibrationTestTablesException $exception) {
            $this->addError('pinTestTables', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        try {
            $materialize->materialize((int) $this->currentCalibration()->calibration_id, $writes->resolve());
            $this->pinStatus = 'Test tables were pinned to this session.';
            $this->dispatchNotification('success', $this->pinStatus);
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->pinStatus = 'Test tables were pinned, but template values could not be copied.';
            $this->addError('pinTestTables', $exception->getMessage());
            $this->dispatchNotification('error', $this->pinStatus);
        } catch (CalibrationSessionConnectionException $exception) {
            $this->pinStatus = 'Test tables were pinned, but template values could not be copied.';
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('pinTestTables', $message);
            $this->dispatchNotification('error', $this->pinStatus);
        } catch (MaterializeCalibrationTestTemplateValuesException $exception) {
            $this->pinStatus = 'Test tables were pinned, but template values could not be copied.';
            $this->addError('pinTestTables', $exception->getMessage());
            $this->dispatchNotification('error', $this->pinStatus);
        }

        $this->loadCalibrationDetail();
    }

    public function requestRecoverTemplateValues(int $scopeId): void
    {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        if (! $this->canRecoverTemplateValues($scopeId)) {
            return;
        }

        $this->dispatch(
            'instruments-desk-confirm-template-recovery',
            componentId: $this->getId(),
            scopeId: $scopeId,
            title: 'Recover Template Values',
            text: 'This will copy the missing template values into the frozen Test Table snapshot.',
            confirmText: 'Recover Values',
            cancelText: 'Cancel',
        );
    }

    public function recoverTemplateValues(
        int $scopeId,
        MaterializeCalibrationTestTemplateValues $materialize,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('recoverTemplateValues');
        $this->recoverStatus = null;
        $this->loadCalibrationDetail();

        if (! $this->isInternalSession()) {
            $this->addError('recoverTemplateValues', MaterializeCalibrationTestTemplateValuesException::unsupportedCalibrationMethod()->getMessage());

            return;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('recoverTemplateValues', MaterializeCalibrationTestTemplateValuesException::invalidSessionStatus()->getMessage());

            return;
        }

        $classification = $this->scopeTemplateMaterialization($scopeId);

        if ($classification === null || $classification['state'] === 'unpinned') {
            $this->addError('recoverTemplateValues', 'This Test Table is not pinned.');

            return;
        }

        if ($this->hasTemplateMaterializationState('invalid_snapshot')) {
            $this->addError('recoverTemplateValues', MaterializeCalibrationTestTemplateValuesException::malformedFrozenSnapshot()->getMessage());

            return;
        }

        if ($classification['state'] === 'complete') {
            $this->addError('recoverTemplateValues', 'Template values for this Test Table are already complete.');

            return;
        }

        if ($classification['state'] === 'conflict' || $this->hasTemplateMaterializationState('conflict')) {
            $this->addError('recoverTemplateValues', MaterializeCalibrationTestTemplateValuesException::conflictingResultCell()->getMessage());

            return;
        }

        if ($classification['state'] !== 'missing_template') {
            $this->addError('recoverTemplateValues', MaterializeCalibrationTestTemplateValuesException::malformedFrozenSnapshot()->getMessage());

            return;
        }

        try {
            $materialize->materialize((int) $this->currentCalibration()->calibration_id, $writes->resolve());
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('recoverTemplateValues', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('recoverTemplateValues', $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (MaterializeCalibrationTestTemplateValuesException $exception) {
            $this->addError('recoverTemplateValues', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->recoverStatus = 'Missing template values were recovered.';
        $this->dispatchNotification('success', $this->recoverStatus);
    }

    public function completeSession(
        CompleteCalibrationSession $complete,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionComplete->value);

        $this->resetErrorBag(['completeSession', 'completionDate', 'completionDue']);
        $this->loadCalibrationDetail();

        if (! $this->isInternalSession()) {
            $this->addError('completeSession', 'This Complete action is available only for an internal calibration session.');

            return;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('completeSession', CompleteCalibrationSessionException::invalidSessionStatus()->getMessage());

            return;
        }

        $calibrationDate = trim($this->completionDate);
        $calibrationDue = trim($this->completionDue);

        if ($calibrationDate === '') {
            $this->addError('completionDate', 'Calibration Date is required.');
        }

        if ($calibrationDue === '') {
            $this->addError('completionDue', 'Calibration Due is required.');
        }

        if ($this->getErrorBag()->has('completionDate') || $this->getErrorBag()->has('completionDue')) {
            return;
        }

        try {
            $complete->complete(
                (int) $this->currentCalibration()->calibration_id,
                $calibrationDate,
                $calibrationDue,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('completeSession', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('completeSession', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (CompleteCalibrationSessionException $exception) {
            $this->addError('completeSession', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->dispatch(
            'instruments-desk-terminal-success',
            icon: 'success',
            title: 'Calibration Completed!',
            text: 'The calibration session has been completed.',
            confirmText: 'View Completed Session',
            calibrationId: (int) $this->currentCalibration()->calibration_id,
        );
    }

    public function saveDates(
        RecordCalibrationSessionFields $fields,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);
        $this->resetErrorBag(['saveDates', 'completionDate', 'completionDue']);
        $this->dateStatus = null;

        try {
            $fields->dates($this->calibrationId, $this->completionDate, $this->completionDue, $writes->resolve());
        } catch (RecordCalibrationSessionHttpException|CalibrationSessionConnectionException $exception) {
            $message = $exception instanceof RecordCalibrationSessionHttpException
                ? $exception->getMessage()
                : RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('saveDates', $message);
            $this->dispatchNotification('error', $message);
            return;
        } catch (RecordCalibrationSessionFieldsException $exception) {
            $this->addError('saveDates', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());
            return;
        }

        $this->loadCalibrationDetail();
        $this->dateStatus = 'Calibration dates saved.';
        $this->dispatchNotification('success', $this->dateStatus);
    }

    public function saveResume(
        RecordCalibrationSessionFields $fields,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);
        $this->resetErrorBag('saveResume');
        $this->resumeStatus = null;

        try {
            $fields->resume($this->calibrationId, $this->resume, $writes->resolve());
        } catch (RecordCalibrationSessionHttpException|CalibrationSessionConnectionException $exception) {
            $message = $exception instanceof RecordCalibrationSessionHttpException
                ? $exception->getMessage()
                : RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('saveResume', $message);
            $this->dispatchNotification('error', $message);
            return;
        } catch (RecordCalibrationSessionFieldsException $exception) {
            $this->addError('saveResume', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());
            return;
        }

        $this->loadCalibrationDetail();
        $this->resumeStatus = 'Resume saved.';
        $this->dispatchNotification('success', $this->resumeStatus);
    }

    public function requestCompleteSession(): void
    {
        Gate::authorize(ApplicationCapability::SessionComplete->value);

        $this->loadCalibrationDetail();

        if (! $this->isInternalSession()) {
            $this->addError('completeSession', 'This Complete action is available only for an internal calibration session.');

            return;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('completeSession', CompleteCalibrationSessionException::invalidSessionStatus()->getMessage());

            return;
        }

        $this->dispatch(
            'instruments-desk-confirm-completion',
            componentId: $this->getId(),
            completionMethod: 'completeSession',
            title: 'Complete Calibration',
            text: 'After completion, this calibration session will become read-only. Do you want to continue?',
            confirmText: 'Confirm Completion',
            cancelText: 'Keep Session',
        );
    }

    public function openCancellationModal(): void
    {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag(['cancelSession', 'cancellationReason']);
        $this->loadCalibrationDetail();

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('cancelSession', CancelCalibrationSessionException::invalidSessionStatus()->getMessage());

            return;
        }

        $this->dispatch(
            'instruments-desk-confirm-cancellation',
            componentId: $this->getId(),
            title: 'Cancel Calibration',
            text: 'This keeps the session and all recorded calibration data in history. Cancellation cannot be undone.',
            confirmText: 'Confirm Cancellation',
            cancelText: 'Keep Session',
        );
    }

    public function closeCancellationModal(): void
    {
        $this->cancellationModalOpen = false;
        $this->cancellationReason = '';
        $this->resetErrorBag(['cancelSession', 'cancellationReason']);
    }

    public function cancelSession(
        CancelCalibrationSession $cancel,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag(['cancelSession', 'cancellationReason']);
        $this->loadCalibrationDetail();

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('cancelSession', CancelCalibrationSessionException::invalidSessionStatus()->getMessage());

            return;
        }

        $this->validate([
            'cancellationReason' => ['required', 'string', 'max:5000'],
        ]);

        $laravelUserId = (int) Auth::id();

        if ($laravelUserId < 1) {
            abort(403);
        }

        try {
            $cancel->cancel(
                (int) $this->currentCalibration()->calibration_id,
                $this->cancellationReason,
                $laravelUserId,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('cancelSession', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('cancelSession', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());
            $this->dispatchNotification('error', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (ResolveDomainActorException $exception) {
            $this->addError('cancelSession', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CancelCalibrationSessionException $exception) {
            $this->addError('cancelSession', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->cancellationModalOpen = false;
        $this->cancellationReason = '';
        $this->dispatch(
            'instruments-desk-terminal-success',
            icon: 'info',
            title: 'Calibration Cancelled',
            text: 'The calibration session has been cancelled.',
            confirmText: 'View Cancelled Session',
            calibrationId: (int) $this->currentCalibration()->calibration_id,
        );
    }

    public function requestCompleteExternalSession(): void
    {
        Gate::authorize(ApplicationCapability::SessionComplete->value);

        $this->loadCalibrationDetail();

        if (! $this->isExternalSession()) {
            $this->addError('completeExternalSession', 'This Complete action is available only for an external calibration session.');

            return;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('completeExternalSession', CompleteCalibrationSessionException::invalidSessionStatus()->getMessage());

            return;
        }

        $this->dispatch(
            'instruments-desk-confirm-completion',
            componentId: $this->getId(),
            completionMethod: 'completeExternalSession',
            title: 'Complete External Calibration',
            text: 'After completion, this calibration session will become read-only. Do you want to continue?',
            confirmText: 'Confirm Completion',
            cancelText: 'Keep Session',
        );
    }

    public function saveExternalVendor(
        RecordExternalCalibrationVendor $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag(['saveExternalVendor', 'externalVendorName']);
        $this->vendorStatus = null;
        $this->loadCalibrationDetail();

        if (! $this->isExternalSession()) {
            $this->addError('saveExternalVendor', RecordExternalCalibrationVendorException::unsupportedCalibrationMethod()->getMessage());

            return;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('saveExternalVendor', RecordExternalCalibrationVendorException::invalidSessionStatus()->getMessage());

            return;
        }

        $laravelUserId = (int) Auth::id();

        if ($laravelUserId < 1) {
            abort(403);
        }

        try {
            $recorder->record(
                (int) $this->currentCalibration()->calibration_id,
                $this->externalVendorName === '' ? null : $this->externalVendorName,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('saveExternalVendor', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('saveExternalVendor', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (RecordExternalCalibrationVendorException $exception) {
            $this->addError('externalVendorName', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->syncExternalVendorInput();
        $this->vendorStatus = 'The vendor name was saved.';
    }

    public function uploadExternalDocument(
        StoreExternalCalibrationDocument $store,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag(['uploadExternalDocument', 'externalCertificatePdf']);
        $this->uploadStatus = null;
        $this->loadCalibrationDetail();

        if (! $this->isExternalSession()) {
            $this->addError('uploadExternalDocument', StoreExternalCalibrationDocumentException::invalidMethod()->getMessage());

            return;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('uploadExternalDocument', StoreExternalCalibrationDocumentException::invalidSessionStatus()->getMessage());

            return;
        }

        $laravelUserId = (int) Auth::id();

        if ($laravelUserId < 1) {
            abort(403);
        }

        if (! $this->externalCertificatePdf instanceof TemporaryUploadedFile
            && ! $this->externalCertificatePdf instanceof UploadedFile) {
            $this->addError('externalCertificatePdf', 'A vendor PDF is required.');

            return;
        }

        try {
            $connection = $writes->resolve();
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('uploadExternalDocument', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('uploadExternalDocument', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        }

        try {
            $contents = $this->externalCertificatePdf->get();
        } catch (\Throwable) {
            $this->addError('externalCertificatePdf', 'The temporary PDF upload could not be read.');

            return;
        }

        if (! is_string($contents) || $contents === '') {
            $this->addError('externalCertificatePdf', StoreExternalCalibrationDocumentException::emptyPdf()->getMessage());

            return;
        }

        $filename = (string) $this->externalCertificatePdf->getClientOriginalName();
        $allowedMime = (string) config('calibration.documents.allowed_mime');
        $detectedMime = (string) $this->externalCertificatePdf->getMimeType();

        if ($allowedMime === '' || $detectedMime !== $allowedMime) {
            $this->addError('uploadExternalDocument', 'The vendor file must be a PDF.');

            return;
        }

        try {
            $store->store(
                (int) $this->currentCalibration()->calibration_id,
                $filename,
                $contents,
                $laravelUserId,
                $connection,
            );
        } catch (ResolveDomainActorException $exception) {
            $this->addError('uploadExternalDocument', $exception->getMessage());

            return;
        } catch (StoreExternalCalibrationDocumentException $exception) {
            $this->addError('uploadExternalDocument', $exception->getMessage());

            return;
        } catch (QueryException) {
            $this->addError('uploadExternalDocument', 'The vendor PDF metadata could not be saved.');

            return;
        }

        $this->externalCertificatePdf = null;
        $this->loadCalibrationDetail();
        $this->uploadStatus = 'The vendor PDF was stored.';
    }

    public function completeExternalSession(
        CompleteCalibrationSession $complete,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionComplete->value);

        $this->resetErrorBag(['completeExternalSession', 'completionDate', 'completionDue']);
        $this->loadCalibrationDetail();

        if (! $this->isExternalSession()) {
            $this->addError('completeExternalSession', 'This Complete action is available only for an external calibration session.');

            return;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('completeExternalSession', CompleteCalibrationSessionException::invalidSessionStatus()->getMessage());

            return;
        }

        $calibrationDate = trim($this->completionDate);
        $calibrationDue = trim($this->completionDue);

        if ($calibrationDate === '') {
            $this->addError('completionDate', 'Calibration Date is required.');
        }

        if ($calibrationDue === '') {
            $this->addError('completionDue', 'Calibration Due is required.');
        }

        if ($this->getErrorBag()->has('completionDate') || $this->getErrorBag()->has('completionDue')) {
            return;
        }

        try {
            $complete->complete(
                (int) $this->currentCalibration()->calibration_id,
                $calibrationDate,
                $calibrationDue,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('completeExternalSession', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('completeExternalSession', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (CompleteCalibrationSessionException $exception) {
            $this->addError('completeExternalSession', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->dispatch(
            'instruments-desk-terminal-success',
            icon: 'success',
            title: 'Calibration Completed!',
            text: 'The calibration session has been completed.',
            confirmText: 'View Completed Session',
            calibrationId: (int) $this->currentCalibration()->calibration_id,
        );
    }

    public function requestSaveTestResult(
        int $calibrationScopeId,
        int $testPointId,
        string $columnKey,
        RecordCalibrationTestResult $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $cellKey = CalibrationSessionDetailPresenter::resultCellKey($calibrationScopeId, $testPointId, $columnKey);
        $submitted = $this->resultInputs[$cellKey] ?? '';
        $existing = $this->persistedInputFor($cellKey);

        if (trim((string) $submitted) === '' && $existing !== '') {
            $this->dispatch(
                'instruments-desk-confirm-test-result-clear',
                componentId: $this->getId(),
                calibrationScopeId: $calibrationScopeId,
                testPointId: $testPointId,
                columnKey: $columnKey,
                title: 'Clear Test Result',
                text: 'This will clear the saved result for this test point.',
                confirmText: 'Clear Result',
                cancelText: 'Keep Result',
            );

            return;
        }

        $this->saveTestResult($calibrationScopeId, $testPointId, $columnKey, $recorder, $writes);
    }

    public function saveTestResult(
        int $calibrationScopeId,
        int $testPointId,
        string $columnKey,
        RecordCalibrationTestResult $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $cellKey = CalibrationSessionDetailPresenter::resultCellKey($calibrationScopeId, $testPointId, $columnKey);
        $this->resetErrorBag('resultInputs.'.$cellKey);
        $this->resultStatus = null;
        $this->resultStatusKey = null;

        if (! $this->assertWritableResultContext($cellKey)) {
            return;
        }

        try {
            $submitted = $this->submittedResultValue($cellKey);
            $recorder->record(
                (int) $this->currentCalibration()->calibration_id,
                $calibrationScopeId,
                $testPointId,
                $columnKey,
                $submitted,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('resultInputs.'.$cellKey, $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('resultInputs.'.$cellKey, $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (RecordCalibrationTestResultException $exception) {
            $this->addError('resultInputs.'.$cellKey, $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->resultInputs[$cellKey] = $this->persistedInputFor($cellKey);
        $this->resultStatus = $submitted === null
            ? 'The test result was cleared.'
            : 'The test result was saved.';
        $this->resultStatusKey = $cellKey;
        $this->dispatchNotification('success', $this->resultStatus);
    }

    public function resolveStandardsRequirement(
        ResolveCalibrationRequirement $resolver,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('standardsResolvedMode');
        $this->resetErrorBag('standardsResolutionReason');
        $this->usageStatus = null;
        $this->usageStatusKey = null;

        if (! $this->assertWritableStandardUsageContext('standardsResolvedMode')) {
            return;
        }

        $this->validate([
            'standardsResolvedMode' => ['required', Rule::in([
                InstrumentTypeRequirement::MODE_REQUIRED,
                InstrumentTypeRequirement::MODE_OPTIONAL,
            ])],
            'standardsResolutionReason' => ['required', 'string'],
        ]);

        $laravelUserId = (int) Auth::id();

        if ($laravelUserId < 1) {
            abort(403);
        }

        try {
            $resolver->resolve(
                (int) $this->currentCalibration()->calibration_id,
                InstrumentTypeRequirement::CATEGORY_STANDARDS,
                $this->standardsResolvedMode,
                $this->standardsResolutionReason,
                $laravelUserId,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('standardsResolvedMode', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('standardsResolvedMode', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (ResolveDomainActorException $exception) {
            $this->addError('standardsResolvedMode', $exception->getMessage());

            return;
        } catch (ResolveCalibrationRequirementException $exception) {
            $this->addError('standardsResolvedMode', $exception->getMessage());

            return;
        }

        $this->standardsResolvedMode = '';
        $this->standardsResolutionReason = '';
        $this->usageStatus = 'The standards requirement was resolved.';
        $this->usageStatusKey = 'resolve';
        $this->loadCalibrationDetail();
        $this->dispatchNotification('success', $this->usageStatus);
    }

    public function addStandardUsage(
        RecordCalibrationStandardUsage $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('usageStandardId');
        $this->resetErrorBag('addStandardUsage');
        $this->usageStatus = null;
        $this->usageStatusKey = null;

        if (! $this->assertWritableStandardUsageContext('addStandardUsage')) {
            return;
        }

        $this->validate([
            'usageStandardId' => ['required', 'integer', 'min:1'],
        ], [
            'usageStandardId.required' => 'Select an assigned standard.',
            'usageStandardId.integer' => 'Select an assigned standard.',
            'usageStandardId.min' => 'Select an assigned standard.',
        ]);

        try {
            $recorder->add(
                (int) $this->currentCalibration()->calibration_id,
                (int) $this->usageStandardId,
                $this->nullableUsageText($this->newUsageRange),
                $this->nullableUsageText($this->newUsageUnit),
                $this->nullableUsageText($this->newUsagePurpose),
                $this->nullableUsageText($this->newUsageRemarks),
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('addStandardUsage', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('addStandardUsage', $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (RecordCalibrationStandardUsageException $exception) {
            $this->addError('addStandardUsage', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        $this->usageStandardId = '';
        $this->newUsageRange = '';
        $this->newUsageUnit = '';
        $this->newUsagePurpose = '';
        $this->newUsageRemarks = '';
        $this->loadCalibrationDetail();
        $this->hydrateDraftStandards();
        $this->usageStatus = 'The standard usage was recorded.';
        $this->usageStatusKey = 'add';
        $this->dispatchNotification('success', $this->usageStatus);
    }

    public function saveStandards(
        RecordCalibrationStandardUsage $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $draftStandardIds = $this->draftStandardIds;

        $this->resetErrorBag('draftStandardIds');
        $this->resetErrorBag('saveStandards');
        $this->usageStatus = null;
        $this->usageStatusKey = null;

        if (! $this->assertWritableStandardUsageContext('saveStandards')) {
            $this->draftStandardIds = $draftStandardIds;

            return;
        }

        $this->draftStandardIds = $draftStandardIds;

        $this->validate([
            'draftStandardIds' => ['array'],
            'draftStandardIds.*' => ['integer', 'min:1'],
        ]);

        $calibrationId = (int) $this->currentCalibration()->calibration_id;

        try {
            $recorder->sync(
                $calibrationId,
                $draftStandardIds,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('saveStandards', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('saveStandards', $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (RecordCalibrationStandardUsageException $exception) {
            $this->addError('saveStandards', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->hydrateDraftStandards();
        $this->usageStatus = 'Standards saved.';
        $this->usageStatusKey = 'save';
        $this->dispatchNotification('success', $this->usageStatus);
    }

    public function openAddStandardModal(): void
    {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        if (! $this->canOfferStandardCandidates()) {
            return;
        }

        $this->addingStandards = true;
        $this->standardSearch = '';
        $this->modalStandardIds = [];
        $this->resetErrorBag('modalStandardIds');
        $this->resetErrorBag('addSelectedStandards');
    }

    public function closeAddStandardModal(): void
    {
        $this->addingStandards = false;
        $this->standardSearch = '';
        $this->modalStandardIds = [];
        $this->resetErrorBag('modalStandardIds');
        $this->resetErrorBag('addSelectedStandards');
    }

    public function addSelectedStandards(
        RecordCalibrationStandardUsage $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void
    {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('modalStandardIds');
        $this->resetErrorBag('addSelectedStandards');

        if (! $this->canOfferStandardCandidates()) {
            return;
        }

        $this->validate([
            'modalStandardIds' => ['array', 'min:1'],
            'modalStandardIds.*' => ['integer', 'min:1'],
        ], [
            'modalStandardIds.min' => 'Select at least one standard.',
        ]);

        $draft = $this->uniqueDraftStandardIds($this->draftStandardIds);

        foreach ($this->modalStandardIds as $standardId) {
            $id = (string) ((int) $standardId);

            if ($id === '0' || in_array($id, $draft, true)) {
                continue;
            }

            $draft[] = $id;
        }

        $this->draftStandardIds = $draft;
        $this->saveStandards($recorder, $writes);

        if (! $this->getErrorBag()->has('saveStandards')) {
            $this->closeAddStandardModal();
        }
    }

    public function askToRemoveDraftStandard(int $standardId): void
    {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        if (! $this->canEditStandardUsages()) {
            return;
        }

        $standard = collect($this->displayedStandards())
            ->firstWhere('standard_id', $standardId);

        if ($standard === null) {
            return;
        }

        $this->dispatch(
            'instruments-desk-confirm-standard-removal',
            componentId: $this->getId(),
            standardId: $standardId,
            standardName: $standard['name'],
            title: 'Remove Standard',
            text: 'This removes the standard from the calibration session. This action cannot be undone.',
            confirmText: 'Confirm Remove',
            cancelText: 'Keep',
        );
    }

    public function removeDraftStandard(
        int $standardId,
        RecordCalibrationStandardUsage $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void
    {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        if (! $this->canEditStandardUsages()) {
            return;
        }

        $draftStandardIds = $this->draftStandardIds;
        $this->draftStandardIds = array_values(array_filter(
            $this->draftStandardIds,
            static fn (string $id): bool => (int) $id !== $standardId,
        ));

        $this->resetErrorBag('removeDraftStandard');

        try {
            $recorder->sync(
                (int) $this->currentCalibration()->calibration_id,
                $this->draftStandardIds,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->draftStandardIds = $draftStandardIds;
            $this->addError('removeDraftStandard', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->draftStandardIds = $draftStandardIds;
            $this->addError('removeDraftStandard', $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (RecordCalibrationStandardUsageException $exception) {
            $this->draftStandardIds = $draftStandardIds;
            $this->addError('removeDraftStandard', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->draftStandardIds = array_map(
            static fn (int $id): string => (string) $id,
            $this->usedStandardIds(),
        );
        $this->dispatchNotification('success', 'Standard removed.');
    }

    public function updateStandardUsage(
        int $usageId,
        RecordCalibrationStandardUsage $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $detailKey = (string) $usageId;
        $this->resetErrorBag('usageDetails.'.$detailKey);
        $this->usageStatus = null;
        $this->usageStatusKey = null;

        if (! $this->assertWritableStandardUsageContext('usageDetails.'.$detailKey)) {
            return;
        }

        $details = $this->usageDetails[$detailKey] ?? [];

        try {
            $recorder->update(
                (int) $this->currentCalibration()->calibration_id,
                $usageId,
                $this->nullableUsageText($details['range'] ?? null),
                $this->nullableUsageText($details['unit'] ?? null),
                $this->nullableUsageText($details['purpose'] ?? null),
                $this->nullableUsageText($details['remarks'] ?? null),
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('usageDetails.'.$detailKey, $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('usageDetails.'.$detailKey, $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (RecordCalibrationStandardUsageException $exception) {
            $this->addError('usageDetails.'.$detailKey, $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->usageDetails[$detailKey] = $this->persistedUsageDetails($usageId);
        $this->usageStatus = 'The standard usage was updated.';
        $this->usageStatusKey = $detailKey;
        $this->dispatchNotification('success', $this->usageStatus);
    }

    public function askToRemoveStandardUsage(int $usageId): void
    {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->removingUsageId = $usageId;
    }

    public function cancelRemovingStandardUsage(): void
    {
        $this->removingUsageId = null;
    }

    public function removeStandardUsage(
        RecordCalibrationStandardUsage $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('removeStandardUsage');
        $this->usageStatus = null;
        $this->usageStatusKey = null;

        if (! $this->assertWritableStandardUsageContext('removeStandardUsage')) {
            return;
        }

        $usageId = (int) ($this->removingUsageId ?? 0);

        if ($usageId < 1) {
            $this->addError('removeStandardUsage', RecordCalibrationStandardUsageException::invalidUsageId()->getMessage());

            return;
        }

        try {
            $recorder->remove(
                (int) $this->currentCalibration()->calibration_id,
                $usageId,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('removeStandardUsage', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('removeStandardUsage', $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (RecordCalibrationStandardUsageException $exception) {
            $this->addError('removeStandardUsage', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        unset($this->usageDetails[(string) $usageId]);
        $this->removingUsageId = null;
        $this->loadCalibrationDetail();
        $this->hydrateDraftStandards();
        $this->usageStatus = 'The standard usage was removed.';
        $this->usageStatusKey = 'remove';
        $this->dispatchNotification('success', $this->usageStatus);
    }

    public function resolveActivityRequirement(
        ResolveCalibrationRequirement $resolver,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('activityResolvedMode');
        $this->resetErrorBag('activityResolutionReason');
        $this->activityStatus = null;
        $this->activityStatusKey = null;

        if (! $this->assertWritableActivityContext('activityResolvedMode')) {
            return;
        }

        $this->validate([
            'activityResolvedMode' => ['required', Rule::in([
                InstrumentTypeRequirement::MODE_REQUIRED,
                InstrumentTypeRequirement::MODE_OPTIONAL,
            ])],
            'activityResolutionReason' => ['required', 'string'],
        ]);

        $laravelUserId = (int) Auth::id();

        if ($laravelUserId < 1) {
            abort(403);
        }

        try {
            $resolver->resolve(
                (int) $this->currentCalibration()->calibration_id,
                InstrumentTypeRequirement::CATEGORY_ACTIVITY,
                $this->activityResolvedMode,
                $this->activityResolutionReason,
                $laravelUserId,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('activityResolvedMode', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('activityResolvedMode', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (ResolveDomainActorException $exception) {
            $this->addError('activityResolvedMode', $exception->getMessage());

            return;
        } catch (ResolveCalibrationRequirementException $exception) {
            $this->addError('activityResolvedMode', $exception->getMessage());

            return;
        }

        $this->activityResolvedMode = '';
        $this->activityResolutionReason = '';
        $this->activityStatus = 'The activity requirement was resolved.';
        $this->activityStatusKey = 'resolve';
        $this->loadCalibrationDetail();
    }

    public function saveActivities(
        RecordCalibrationActivity $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $selectedActivityIds = $this->selectedActivityIds;

        $this->resetErrorBag('selectedActivityIds');
        $this->resetErrorBag('saveActivities');
        $this->activityStatus = null;
        $this->activityStatusKey = null;

        if (! $this->assertWritableActivityContext('saveActivities')) {
            $this->selectedActivityIds = $selectedActivityIds;

            return;
        }

        $this->selectedActivityIds = $selectedActivityIds;

        $this->validate([
            'selectedActivityIds' => ['array'],
            'selectedActivityIds.*' => ['integer', 'min:1'],
        ]);

        $calibrationId = (int) $this->currentCalibration()->calibration_id;

        try {
            $recorder->sync(
                $calibrationId,
                $selectedActivityIds,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('saveActivities', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('saveActivities', $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (RecordCalibrationActivityException $exception) {
            $this->addError('saveActivities', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->syncSelectedActivityIds();
        $this->activityStatus = 'The activities were saved.';
        $this->activityStatusKey = 'save';
        $this->dispatchNotification('success', $this->activityStatus);
    }

    public function addActivity(
        RecordCalibrationActivity $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('selectedActivityId');
        $this->resetErrorBag('addActivity');
        $this->activityStatus = null;
        $this->activityStatusKey = null;

        if (! $this->assertWritableActivityContext('addActivity')) {
            return;
        }

        $this->validate([
            'selectedActivityId' => ['required', 'integer', 'min:1'],
        ], [
            'selectedActivityId.required' => 'Select an activity.',
            'selectedActivityId.integer' => 'Select an activity.',
            'selectedActivityId.min' => 'Select an activity.',
        ]);

        try {
            $recorder->add(
                (int) $this->currentCalibration()->calibration_id,
                (int) $this->selectedActivityId,
                $this->nullableUsageText($this->newActivityDescription),
                $this->nullableUsageText($this->newActivityResult),
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('addActivity', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('addActivity', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (RecordCalibrationActivityException $exception) {
            $this->addError('addActivity', $exception->getMessage());

            return;
        }

        $this->selectedActivityId = '';
        $this->newActivityDescription = '';
        $this->newActivityResult = '';
        $this->loadCalibrationDetail();
        $this->syncSelectedActivityIds();
        $this->activityStatus = 'The activity was recorded.';
        $this->activityStatusKey = 'add';
    }

    public function updateActivity(
        int $activityRowId,
        RecordCalibrationActivity $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $detailKey = (string) $activityRowId;
        $this->resetErrorBag('activityDetails.'.$detailKey);
        $this->activityStatus = null;
        $this->activityStatusKey = null;

        if (! $this->assertWritableActivityContext('activityDetails.'.$detailKey)) {
            return;
        }

        if ($this->activityRequirementUnresolved()) {
            $this->addError('activityDetails.'.$detailKey, RecordCalibrationActivityException::unresolvedActivityMode()->getMessage());

            return;
        }

        $details = $this->activityDetails[$detailKey] ?? [];

        try {
            $recorder->update(
                (int) $this->currentCalibration()->calibration_id,
                $activityRowId,
                $this->nullableUsageText($details['description'] ?? null),
                $this->nullableUsageText($details['result'] ?? null),
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('activityDetails.'.$detailKey, $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('activityDetails.'.$detailKey, RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (RecordCalibrationActivityException $exception) {
            $this->addError('activityDetails.'.$detailKey, $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->activityDetails[$detailKey] = $this->persistedActivityDetails($activityRowId);
        $this->activityStatus = 'The activity was updated.';
        $this->activityStatusKey = $detailKey;
    }

    public function askToRemoveActivity(int $activityRowId): void
    {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $activity = collect($this->recordedActivities())
            ->firstWhere('key', $activityRowId);

        if ($activity === null) {
            return;
        }

        $this->dispatch(
            'instruments-desk-confirm-activity-removal',
            componentId: $this->getId(),
            activityId: $activityRowId,
            activityName: $activity['name'],
            title: 'Remove Activity',
            text: 'This removes the activity from the calibration session. The master activity remains unchanged.',
            confirmText: 'Confirm Remove',
            cancelText: 'Keep',
        );
    }

    public function cancelRemovingActivity(): void
    {
        $this->removingActivityId = null;
    }

    public function removeActivity(
        RecordCalibrationActivity $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('removeActivity');
        $this->activityStatus = null;
        $this->activityStatusKey = null;

        if (! $this->assertWritableActivityContext('removeActivity')) {
            return;
        }

        if ($this->activityRequirementUnresolved()) {
            $this->addError('removeActivity', RecordCalibrationActivityException::unresolvedActivityMode()->getMessage());

            return;
        }

        $activityRowId = (int) ($this->removingActivityId ?? 0);

        if ($activityRowId < 1) {
            $this->addError('removeActivity', RecordCalibrationActivityException::invalidCalibrationActivityId()->getMessage());

            return;
        }

        try {
            $recorder->remove(
                (int) $this->currentCalibration()->calibration_id,
                $activityRowId,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('removeActivity', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError('removeActivity', $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (RecordCalibrationActivityException $exception) {
            $this->addError('removeActivity', $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        unset($this->activityDetails[(string) $activityRowId]);
        $this->removingActivityId = null;
        $this->loadCalibrationDetail();
        $this->syncSelectedActivityIds();
        $this->activityStatus = 'The activity was removed.';
        $this->activityStatusKey = 'remove';
        $this->dispatchNotification('success', $this->activityStatus);
    }

    public function resolveEnvironmentRequirement(
        ResolveCalibrationRequirement $resolver,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('environmentResolvedMode');
        $this->resetErrorBag('environmentResolutionReason');
        $this->environmentStatus = null;
        $this->environmentStatusKey = null;

        if (! $this->assertWritableEnvironmentContext('environmentResolvedMode')) {
            return;
        }

        $this->validate([
            'environmentResolvedMode' => ['required', Rule::in([
                InstrumentTypeRequirement::MODE_REQUIRED,
                InstrumentTypeRequirement::MODE_OPTIONAL,
            ])],
            'environmentResolutionReason' => ['required', 'string'],
        ]);

        $laravelUserId = (int) Auth::id();

        if ($laravelUserId < 1) {
            abort(403);
        }

        try {
            $resolver->resolve(
                (int) $this->currentCalibration()->calibration_id,
                InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
                $this->environmentResolvedMode,
                $this->environmentResolutionReason,
                $laravelUserId,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('environmentResolvedMode', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('environmentResolvedMode', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (ResolveDomainActorException $exception) {
            $this->addError('environmentResolvedMode', $exception->getMessage());

            return;
        } catch (ResolveCalibrationRequirementException $exception) {
            $this->addError('environmentResolvedMode', $exception->getMessage());

            return;
        }

        $this->environmentResolvedMode = '';
        $this->environmentResolutionReason = '';
        $this->environmentStatus = 'The environment requirement was resolved.';
        $this->environmentStatusKey = 'resolve';
        $this->loadCalibrationDetail();
    }

    public function saveEnvironmentMeasurement(
        int $parameterId,
        RecordCalibrationEnvironmentMeasurement $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $inputKey = 'environmentInputs.'.$parameterId;
        $this->resetErrorBag($inputKey);
        $this->resetErrorBag('saveEnvironmentMeasurement');
        $this->environmentStatus = null;
        $this->environmentStatusKey = null;

        if (! $this->assertWritableEnvironmentContext($inputKey)) {
            $this->dispatchNotification('error', $this->getErrorBag()->first($inputKey) ?? 'The environment measurement could not be saved.');

            return;
        }

        if ($this->environmentRequirementUnresolved()) {
            $this->addError($inputKey, RecordCalibrationEnvironmentMeasurementException::unresolvedEnvironmentMode()->getMessage());
            $this->dispatchNotification('error', RecordCalibrationEnvironmentMeasurementException::unresolvedEnvironmentMode()->getMessage());

            return;
        }

        try {
            $recorder->record(
                (int) $this->currentCalibration()->calibration_id,
                $parameterId,
                $this->nullableUsageText($this->environmentInputs[(string) $parameterId] ?? null),
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError($inputKey, $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $message = RecordCalibrationSessionHttpException::writesUnavailable()->getMessage();
            $this->addError($inputKey, $message);
            $this->dispatchNotification('error', $message);

            return;
        } catch (RecordCalibrationEnvironmentMeasurementException $exception) {
            $this->addError($inputKey, $exception->getMessage());
            $this->dispatchNotification('error', $exception->getMessage());

            return;
        }

        $this->loadCalibrationDetail();
        $this->environmentInputs[(string) $parameterId] = $this->persistedEnvironmentInput($parameterId);
        $this->environmentStatus = null;
        $this->environmentStatusKey = null;
        $this->dispatchNotification(
            'success',
            $this->environmentInputs[(string) $parameterId] === ''
                ? 'The environment measurement was cleared.'
                : 'The environment measurement was saved.',
        );
    }

    private function dispatchNotification(string $type, string $message): void
    {
        $this->dispatch('instruments-desk-notification', type: $type, message: $message);
    }

    public function confirmEnvironmentConfiguration(
        ConfirmEnvironmentConfiguration $confirmer,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->resetErrorBag('environmentConfirmationReason');
        $this->resetErrorBag('confirmEnvironmentConfiguration');
        $this->environmentStatus = null;
        $this->environmentStatusKey = null;

        if (! $this->assertWritableEnvironmentContext('confirmEnvironmentConfiguration')) {
            return;
        }

        $this->validate([
            'environmentConfirmationReason' => ['required', 'string'],
        ]);

        $laravelUserId = (int) Auth::id();

        if ($laravelUserId < 1) {
            abort(403);
        }

        try {
            $confirmer->confirm(
                (int) $this->currentCalibration()->calibration_id,
                $this->environmentConfirmationReason,
                $laravelUserId,
                $writes->resolve(),
            );
        } catch (RecordCalibrationSessionHttpException $exception) {
            $this->addError('confirmEnvironmentConfiguration', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('confirmEnvironmentConfiguration', RecordCalibrationSessionHttpException::writesUnavailable()->getMessage());

            return;
        } catch (ResolveDomainActorException $exception) {
            $this->addError('confirmEnvironmentConfiguration', $exception->getMessage());

            return;
        } catch (ConfirmEnvironmentConfigurationException $exception) {
            $this->addError('confirmEnvironmentConfiguration', $exception->getMessage());

            return;
        }

        $this->environmentConfirmationReason = '';
        $this->environmentStatus = 'Environment configuration was confirmed.';
        $this->environmentStatusKey = 'confirm';
        $this->loadCalibrationDetail();
    }

    public function canRecordSession(): bool
    {
        return Gate::allows(ApplicationCapability::SessionRecord->value);
    }

    public function canManageRequirementModes(): bool
    {
        return Gate::allows(ApplicationCapability::MasterdataManage->value);
    }

    public function canShowResolveControl(): bool
    {
        return false;
    }

    public function canShowPinControl(): bool
    {
        return $this->canRecordSession()
            && $this->isWritablePinningContext()
            && $this->testComponents['state'] === 'unpinned';
    }

    public function canRecoverTemplateValues(int $scopeId): bool
    {
        return $this->canRecordSession()
            && $this->isWritablePinningContext()
            && ($this->scopeTemplateMaterialization($scopeId)['recoverable'] ?? false)
            && ! $this->hasTemplateMaterializationState('conflict')
            && ! $this->hasTemplateMaterializationState('invalid_snapshot');
    }

    public function canShowCompleteSection(): bool
    {
        return $this->isInternalSession()
            && (
                (string) $this->currentCalibration()->session_status === Calibration::STATUS_IN_PROGRESS
            );
    }

    public function canShowCancelSection(): bool
    {
        return Gate::allows(ApplicationCapability::SessionRecord->value)
            && (string) $this->currentCalibration()->session_status === Calibration::STATUS_IN_PROGRESS;
    }

    public function canCompleteSession(): bool
    {
        return Gate::allows(ApplicationCapability::SessionComplete->value)
            && $this->isWritablePinningContext();
    }

    public function canShowExternalVendorSection(): bool
    {
        return $this->isExternalSession();
    }

    public function canSaveExternalVendor(): bool
    {
        return $this->canRecordSession()
            && $this->isWritableExternalContext();
    }

    public function canShowExternalDocumentsSection(): bool
    {
        return $this->isExternalSession();
    }

    public function canUploadExternalDocument(): bool
    {
        return $this->canRecordSession()
            && $this->isWritableExternalContext();
    }

    public function canShowExternalCompleteSection(): bool
    {
        return $this->isExternalSession()
            && (
                (string) $this->currentCalibration()->session_status === Calibration::STATUS_IN_PROGRESS
            );
    }

    public function canCompleteExternalSession(): bool
    {
        return Gate::allows(ApplicationCapability::SessionComplete->value)
            && $this->isWritableExternalContext();
    }

    /**
     * @return array{scopes: list<array{key: int, state: string, label: string, recoverable: bool}>}
     */
    public function templateMaterialization(): array
    {
        return CalibrationSessionDetailPresenter::templateMaterialization($this->currentCalibration(), $this->recordedResultValues());
    }

    /**
     * @return array{key: int, state: string, label: string, recoverable: bool}|null
     */
    public function scopeTemplateMaterialization(int $scopeId): ?array
    {
        foreach ($this->templateMaterialization()['scopes'] as $scope) {
            if ((int) $scope['key'] === $scopeId) {
                return $scope;
            }
        }

        return null;
    }

    private function hasTemplateMaterializationState(string $state): bool
    {
        foreach ($this->templateMaterialization()['scopes'] as $scope) {
            if ($scope['state'] === $state) {
                return true;
            }
        }

        return false;
    }

    public function canEditTestResults(): bool
    {
        return $this->canRecordSession()
            && $this->isWritablePinningContext()
            && $this->entryGrid['state'] === 'tables';
    }

    public function canShowStandardsResolveControl(): bool
    {
        return false;
    }

    public function canPreviewAssignedStandards(): bool
    {
        return $this->canRecordSession()
            && $this->isWritablePinningContext()
            && $this->isInternalSession()
            && $this->readSchema()->hasTable('standard_master');
    }

    public function canEditStandardUsages(): bool
    {
        return $this->canPreviewAssignedStandards();
    }

    public function canOfferStandardCandidates(): bool
    {
        return $this->canEditStandardUsages()
            && $this->readSchema()->hasTable('standard_master');
    }

    public function canShowActivityResolveControl(): bool
    {
        return false;
    }

    public function canEditActivities(): bool
    {
        return $this->canRecordSession()
            && $this->isWritablePinningContext()
            && $this->activityRequirementSnapshot() !== null
            && $this->readSchema()->hasTable('calibration_activity_master');
    }

    public function canOfferActivityCandidates(): bool
    {
        return $this->canEditActivities()
            && $this->readSchema()->hasTable('calibration_activity_master');
    }

    public function openAddActivityModal(): void
    {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        if (! $this->canOfferActivityCandidates()) {
            return;
        }

        $this->activitySearch = '';
        $this->modalActivityIds = [];
        $this->resetErrorBag(['modalActivityIds', 'saveActivities']);
        $this->addingActivities = true;
    }

    public function closeAddActivityModal(): void
    {
        $this->addingActivities = false;
        $this->activitySearch = '';
        $this->modalActivityIds = [];
        $this->resetErrorBag(['modalActivityIds', 'saveActivities']);
    }

    public function addSelectedActivities(
        RecordCalibrationActivity $recorder,
        RecordCalibrationSessionHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionRecord->value);

        $this->selectedActivityIds = array_values(array_unique([
            ...$this->selectedActivityIds,
            ...array_map(static fn (string $id): string => (string) ((int) $id), $this->modalActivityIds),
        ]));

        $this->saveActivities($recorder, $writes);

        if (! $this->getErrorBag()->has('saveActivities')) {
            $this->closeAddActivityModal();
        }
    }

    public function canShowEnvironmentResolveControl(): bool
    {
        return false;
    }

    public function canEditEnvironmentMeasurements(): bool
    {
        return $this->canRecordSession()
            && $this->isWritablePinningContext()
            && $this->environmentModeOperable()
            && $this->hasCanonicalEnvironmentSnapshots();
    }

    public function canConfirmEnvironment(): bool
    {
        return $this->canRecordSession()
            && $this->isWritablePinningContext()
            && $this->environmentModeOperable()
            && ! $this->environmentAlreadyConfirmed()
            && $this->readSchema()->hasColumn('calibration_requirement_snapshot', 'configuration_confirmed_at');
    }

    public function hasCanonicalEnvironmentSnapshots(): bool
    {
        return $this->readSchema()->hasTable('calibration_environment_parameter_snapshot')
            && $this->currentCalibration()->relationLoaded('environmentParameterSnapshots');
    }

    public function canShowLegacyEnvironment(): bool
    {
        return (! $this->hasCanonicalEnvironmentSnapshots() || $this->recordedEnvironmentParameters() === [])
            && $this->currentCalibration()->relationLoaded('environment');
    }

    private function isWritablePinningContext(): bool
    {
        return $this->isInternalSession()
            && (string) $this->currentCalibration()->session_status === Calibration::STATUS_IN_PROGRESS;
    }

    private function isWritableExternalContext(): bool
    {
        return $this->isExternalSession()
            && (string) $this->currentCalibration()->session_status === Calibration::STATUS_IN_PROGRESS;
    }

    private function syncExternalVendorInput(): void
    {
        $this->externalVendorName = (string) ($this->currentCalibration()->getAttribute('external_vendor_name') ?? '');
    }

    private function assertWritablePinningContext(): bool
    {
        if (! $this->isInternalSession()) {
            $this->addError('pinTestTables', PinCalibrationTestTablesException::invalidMethod()->getMessage());

            return false;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('pinTestTables', PinCalibrationTestTablesException::invalidSessionStatus()->getMessage());

            return false;
        }

        return true;
    }

    private function assertWritableActivityContext(string $errorKey): bool
    {
        if (! $this->isInternalSession()) {
            $this->addError($errorKey, RecordCalibrationActivityException::unsupportedCalibrationMethod()->getMessage());

            return false;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError($errorKey, RecordCalibrationActivityException::invalidSessionStatus()->getMessage());

            return false;
        }

        return true;
    }

    private function assertWritableEnvironmentContext(string $errorKey): bool
    {
        if (! $this->isInternalSession()) {
            $this->addError($errorKey, RecordCalibrationEnvironmentMeasurementException::unsupportedCalibrationMethod()->getMessage());

            return false;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError($errorKey, RecordCalibrationEnvironmentMeasurementException::invalidSessionStatus()->getMessage());

            return false;
        }

        return true;
    }

    private function assertWritableResultContext(string $cellKey): bool
    {
        if (! $this->isInternalSession()) {
            $this->addError('resultInputs.'.$cellKey, RecordCalibrationTestResultException::unsupportedCalibrationMethod()->getMessage());

            return false;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError('resultInputs.'.$cellKey, RecordCalibrationTestResultException::invalidSessionStatus()->getMessage());

            return false;
        }

        return true;
    }

    private function assertWritableStandardUsageContext(string $errorKey): bool
    {
        if (! $this->isInternalSession()) {
            $this->addError($errorKey, RecordCalibrationStandardUsageException::invalidCalibrationMethod()->getMessage());

            return false;
        }

        if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS) {
            $this->addError($errorKey, RecordCalibrationStandardUsageException::invalidCalibrationStatus()->getMessage());

            return false;
        }

        return true;
    }

    private function submittedResultValue(string $cellKey): ?string
    {
        $input = $this->resultInputs[$cellKey] ?? '';

        if (! is_string($input) || $input === '') {
            return null;
        }

        $cell = $this->entryCell($cellKey);
        $dataType = is_array($cell) ? (string) ($cell['data_type'] ?? '') : '';

        if ($dataType !== 'duration') {
            return $input;
        }

        if (preg_match('/^\d+$/', $input) === 1) {
            return $input;
        }

        $seconds = DurationValue::tryParse($input);

        if ($seconds === null || $seconds < 0) {
            throw RecordCalibrationTestResultException::invalidDurationValue();
        }

        return (string) $seconds;
    }

    /**
     * @return array{id: string, column_key: string, value_source: string, data_type: string, unit: string, editable: bool, display: string, input: string}|null
     */
    private function entryCell(string $cellKey): ?array
    {
        foreach ($this->entryGrid()['tables'] as $table) {
            foreach ($table['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if ($cell['id'] === $cellKey) {
                        return $cell;
                    }
                }
            }
        }

        return null;
    }

    private function persistedInputFor(string $cellKey): string
    {
        $cell = $this->entryCell($cellKey);

        return is_array($cell) ? (string) $cell['input'] : '';
    }

    private function fillMissingResultInputs(): void
    {
        foreach ($this->entryGrid['tables'] as $table) {
            foreach ($table['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if (! $cell['editable']) {
                        continue;
                    }

                    if (! array_key_exists($cell['id'], $this->resultInputs)) {
                        $this->resultInputs[$cell['id']] = $cell['input'];
                    }
                }
            }
        }
    }

    private function testProfileRequirementUnresolved(): bool
    {
        $snapshot = $this->testProfileRequirementSnapshot();

        return $snapshot !== null && ! in_array(
            CalibrationSessionRequirementPolicy::effectiveMode(
                InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
                (string) $snapshot->frozen_mode,
                $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null,
                (string) $this->currentCalibration()->calibration_method,
            ),
            [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL],
            true,
        );
    }

    private function testProfileModeOperable(): bool
    {
        $snapshot = $this->testProfileRequirementSnapshot();

        if ($snapshot === null) {
            return false;
        }

        $effective = CalibrationSessionRequirementPolicy::effectiveMode(
            InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
            (string) $snapshot->frozen_mode,
            $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null,
            (string) $this->currentCalibration()->calibration_method,
        );

        return in_array($effective, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
        ], true);
    }

    private function testProfileRequirementSnapshot(): ?object
    {
        if (! $this->currentCalibration()->relationLoaded('requirementSnapshots')) {
            return null;
        }

        return $this->currentCalibration()->requirementSnapshots->first(
            fn (object $snapshot): bool => (string) $snapshot->requirement_category === InstrumentTypeRequirement::CATEGORY_TEST_PROFILE,
        );
    }

    private function standardsRequirementUnresolved(): bool
    {
        $snapshot = $this->standardsRequirementSnapshot();

        return $snapshot !== null && ! in_array(
            CalibrationSessionRequirementPolicy::effectiveMode(
                InstrumentTypeRequirement::CATEGORY_STANDARDS,
                (string) $snapshot->frozen_mode,
                $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null,
                (string) $this->currentCalibration()->calibration_method,
            ),
            [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL],
            true,
        );
    }

    private function standardsModeOperable(): bool
    {
        $snapshot = $this->standardsRequirementSnapshot();

        if ($snapshot === null) {
            return false;
        }

        $effective = CalibrationSessionRequirementPolicy::effectiveMode(
            InstrumentTypeRequirement::CATEGORY_STANDARDS,
            (string) $snapshot->frozen_mode,
            $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null,
            (string) $this->currentCalibration()->calibration_method,
        );

        return in_array($effective, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
        ], true);
    }

    private function standardsRequirementSnapshot(): ?object
    {
        if (! $this->currentCalibration()->relationLoaded('requirementSnapshots')) {
            return null;
        }

        return $this->currentCalibration()->requirementSnapshots->first(
            fn (object $snapshot): bool => (string) $snapshot->requirement_category === InstrumentTypeRequirement::CATEGORY_STANDARDS,
        );
    }

    private function activityRequirementUnresolved(): bool
    {
        $snapshot = $this->activityRequirementSnapshot();

        return $snapshot !== null && ! in_array(
            CalibrationSessionRequirementPolicy::effectiveMode(
                InstrumentTypeRequirement::CATEGORY_ACTIVITY,
                (string) $snapshot->frozen_mode,
                $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null,
                (string) $this->currentCalibration()->calibration_method,
            ),
            [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL],
            true,
        );
    }

    private function activityModeOperable(): bool
    {
        $snapshot = $this->activityRequirementSnapshot();

        if ($snapshot === null) {
            return false;
        }

        $effective = CalibrationSessionRequirementPolicy::effectiveMode(
            InstrumentTypeRequirement::CATEGORY_ACTIVITY,
            (string) $snapshot->frozen_mode,
            $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null,
            (string) $this->currentCalibration()->calibration_method,
        );

        return in_array($effective, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
        ], true);
    }

    private function activityRequirementSnapshot(): ?object
    {
        if (! $this->currentCalibration()->relationLoaded('requirementSnapshots')) {
            return null;
        }

        return $this->currentCalibration()->requirementSnapshots->first(
            fn (object $snapshot): bool => (string) $snapshot->requirement_category === InstrumentTypeRequirement::CATEGORY_ACTIVITY,
        );
    }

    private function environmentRequirementUnresolved(): bool
    {
        $snapshot = $this->environmentRequirementSnapshot();

        return $snapshot !== null && ! in_array(
            CalibrationSessionRequirementPolicy::effectiveMode(
                InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
                (string) $snapshot->frozen_mode,
                $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null,
                (string) $this->currentCalibration()->calibration_method,
            ),
            [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL],
            true,
        );
    }

    private function rawRequirementIsUnresolved(string $category): bool
    {
        if (! $this->currentCalibration()->relationLoaded('requirementSnapshots')) {
            return false;
        }

        $snapshot = $this->currentCalibration()->requirementSnapshots->first(
            fn (object $item): bool => (string) $item->requirement_category === $category,
        );

        return $snapshot !== null
            && (string) $snapshot->frozen_mode === InstrumentTypeRequirement::MODE_NOT_CONFIGURED
            && $snapshot->resolved_mode === null;
    }

    private function environmentModeOperable(): bool
    {
        $snapshot = $this->environmentRequirementSnapshot();

        if ($snapshot === null) {
            return false;
        }

        $effective = CalibrationSessionRequirementPolicy::effectiveMode(
            InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
            (string) $snapshot->frozen_mode,
            $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null,
            (string) $this->currentCalibration()->calibration_method,
        );

        return in_array($effective, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
        ], true);
    }

    private function environmentAlreadyConfirmed(): bool
    {
        $snapshot = $this->environmentRequirementSnapshot();

        return $snapshot !== null
            && $snapshot->getAttribute('configuration_confirmed_at') !== null;
    }

    private function environmentRequirementSnapshot(): ?object
    {
        if (! $this->currentCalibration()->relationLoaded('requirementSnapshots')) {
            return null;
        }

        return $this->currentCalibration()->requirementSnapshots->first(
            fn (object $snapshot): bool => (string) $snapshot->requirement_category === InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
        );
    }

    /**
     * @return list<array{standard_id: int, instrument_name: string, type_specification: string, standard_code: string, serial_number: string, default_range: string, default_unit: string}>
     */
    public function candidateStandards(): array
    {
        if (! $this->canOfferStandardCandidates()) {
            return [];
        }

        $typeId = (int) ($this->currentCalibration()->instrument_type_id ?? 0);

        if ($typeId < 1) {
            return [];
        }

        $usedIds = $this->usedStandardIds();

        $query = DB::table('instrument_type_standard_assignment as assignment')
            ->join('standard_master as master', 'master.standard_id', '=', 'assignment.standard_id')
            ->where('assignment.instrument_type_id', $typeId)
            ->whereNotNull('assignment.standard_id')
            ->orderBy('assignment.assignment_order')
            ->orderBy('assignment.instrument_type_standard_assignment_id')
            ->select([
                'master.standard_id',
                'master.instrument_name',
                'master.type_specification',
                'master.standard_code',
                'master.serial_number',
                'assignment.default_range',
                'assignment.default_unit',
            ]);

        if ($usedIds !== []) {
            $query->whereNotIn('assignment.standard_id', $usedIds);
        }

        return $query->get()->map(fn (object $row): array => [
            'standard_id' => (int) $row->standard_id,
            'instrument_name' => (string) $row->instrument_name,
            'type_specification' => $row->type_specification !== null ? (string) $row->type_specification : '',
            'standard_code' => (string) $row->standard_code,
            'serial_number' => $row->serial_number !== null ? (string) $row->serial_number : '',
            'default_range' => $row->default_range !== null ? (string) $row->default_range : '',
            'default_unit' => $row->default_unit !== null ? (string) $row->default_unit : '',
        ])->all();
    }

    /**
     * @return list<array{key: int|string, standard_id: int, name: string, code: string, type: string, serial_number: string}>
     */
    public function displayedStandards(): array
    {
        $persisted = [];

        foreach ($this->recordedStandards() as $row) {
            if ($row['standard_id'] !== null) {
                $persisted[$row['standard_id']] = $row;
            }
        }

        $rows = [];

        foreach ($this->uniqueDraftStandardIds($this->draftStandardIds) as $standardId) {
            $id = (int) $standardId;

            if (isset($persisted[$id])) {
                $rows[] = [
                    'key' => $persisted[$id]['key'],
                    'standard_id' => $id,
                    'name' => $persisted[$id]['name'],
                    'code' => $persisted[$id]['code'],
                    'type' => $persisted[$id]['type'],
                    'serial_number' => $persisted[$id]['serial_number'],
                ];

                continue;
            }

            $identity = $this->masterStandardIdentity($id);

            if ($identity === null) {
                continue;
            }

            $rows[] = $identity;
        }

        return $rows;
    }

    /**
     * @return list<array{standard_id: int, instrument_name: string, type_specification: string, standard_code: string, serial_number: string}>
     */
    public function searchableStandards(): array
    {
        if (! $this->addingStandards || ! $this->canOfferStandardCandidates()) {
            return [];
        }

        $excluded = array_map(
            static fn (string $id): int => (int) $id,
            $this->uniqueDraftStandardIds($this->draftStandardIds),
        );

        $query = DB::table('standard_master')
            ->orderBy('standard_code')
            ->orderBy('standard_id')
            ->select([
                'standard_id',
                'instrument_name',
                'type_specification',
                'standard_code',
                'serial_number',
            ]);

        if ($this->readSchema()->hasColumn('standard_master', 'is_active')) {
            $query->where('is_active', true);
        }

        if ($excluded !== []) {
            $query->whereNotIn('standard_id', $excluded);
        }

        $needle = mb_substr(trim($this->standardSearch), 0, 100);

        if ($needle !== '') {
            $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $pattern = '%'.addcslashes($needle, '\\%_').'%';
            $query->where(function ($inner) use ($like, $pattern): void {
                $inner->whereRaw("instrument_name {$like} ? escape '\\'", [$pattern])
                    ->orWhereRaw("coalesce(type_specification, '') {$like} ? escape '\\'", [$pattern])
                    ->orWhereRaw("coalesce(serial_number, '') {$like} ? escape '\\'", [$pattern])
                    ->orWhereRaw("standard_code {$like} ? escape '\\'", [$pattern]);
            });
        }

        return $query->get()->map(fn (object $row): array => [
            'standard_id' => (int) $row->standard_id,
            'instrument_name' => (string) $row->instrument_name,
            'type_specification' => $row->type_specification !== null ? (string) $row->type_specification : '',
            'standard_code' => (string) $row->standard_code,
            'serial_number' => $row->serial_number !== null ? (string) $row->serial_number : '',
        ])->all();
    }

    private function hydrateDraftStandards(): void
    {
        if (! $this->canPreviewAssignedStandards()) {
            $this->draftStandardIds = [];

            return;
        }

        $used = $this->usedStandardIds();

        if ($used !== []) {
            $this->draftStandardIds = array_map(static fn (int $id): string => (string) $id, $used);

            return;
        }

        $this->draftStandardIds = array_map(
            static fn (int $id): string => (string) $id,
            $this->assignedStandardIds(),
        );
    }

    /**
     * @return list<int>
     */
    private function assignedStandardIds(): array
    {
        $typeId = (int) ($this->currentCalibration()->instrument_type_id ?? 0);

        if ($typeId < 1 || ! $this->readSchema()->hasTable('instrument_type_standard_assignment')) {
            return [];
        }

        return DB::table('instrument_type_standard_assignment')
            ->where('instrument_type_id', $typeId)
            ->whereNotNull('standard_id')
            ->orderBy('assignment_order')
            ->orderBy('instrument_type_standard_assignment_id')
            ->pluck('standard_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, standard_id: int, name: string, code: string, type: string, serial_number: string}|null
     */
    private function masterStandardIdentity(int $standardId): ?array
    {
        if (! $this->readSchema()->hasTable('standard_master')) {
            return null;
        }

        $row = DB::table('standard_master')
            ->where('standard_id', $standardId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'key' => 'draft-'.$standardId,
            'standard_id' => $standardId,
            'name' => (string) $row->instrument_name,
            'code' => (string) $row->standard_code,
            'type' => $row->type_specification !== null ? (string) $row->type_specification : '',
            'serial_number' => $row->serial_number !== null ? (string) $row->serial_number : '',
        ];
    }

    /**
     * @param  list<int|string>  $standardIds
     * @return list<string>
     */
    private function uniqueDraftStandardIds(array $standardIds): array
    {
        $desired = [];

        foreach ($standardIds as $standardId) {
            $id = (string) ((int) $standardId);

            if ($id === '0' || in_array($id, $desired, true)) {
                continue;
            }

            $desired[] = $id;
        }

        return $desired;
    }

    /**
     * @return list<array{activity_id: int, activity_code: string, activity_name: string, active: bool}>
     */
    public function selectableActivities(): array
    {
        if (! $this->readSchema()->hasTable('calibration_activity_master')) {
            return [];
        }

        $usedIds = $this->usedActivityIds();
        $frozenNames = $this->recordedActivityNames();

        $query = DB::table('calibration_activity_master')
            ->orderBy('activity_code')
            ->orderBy('activity_id')
            ->select([
                'activity_id',
                'activity_code',
                'activity_name',
                'is_active',
            ]);

        if ($usedIds === []) {
            $query->where('is_active', true);
        } else {
            $query->where(function ($inner) use ($usedIds): void {
                $inner->where('is_active', true)
                    ->orWhereIn('activity_id', $usedIds);
            });
        }

        return $query->get()->map(function (object $row) use ($frozenNames): array {
            $id = (int) $row->activity_id;
            $liveName = (string) $row->activity_name;
            $frozenName = $frozenNames[$id] ?? null;
            $name = is_string($frozenName) && $frozenName !== '' ? $frozenName : $liveName;

            return [
                'activity_id' => $id,
                'activity_code' => ($frozenName !== null && $frozenName !== $liveName)
                    ? ''
                    : (string) $row->activity_code,
                'activity_name' => $name,
                'active' => $row->is_active === true || $row->is_active === 1 || $row->is_active === '1',
            ];
        })->all();
    }

    /**
     * @return list<array{activity_id: int, activity_code: string, activity_name: string}>
     */
    public function candidateActivities(): array
    {
        if (! $this->canOfferActivityCandidates()) {
            return [];
        }

        $usedIds = $this->usedActivityIds();

        $query = DB::table('calibration_activity_master')
            ->where('is_active', true)
            ->orderBy('activity_code')
            ->orderBy('activity_id')
            ->select([
                'activity_id',
                'activity_code',
                'activity_name',
            ]);

        if ($usedIds !== []) {
            $query->whereNotIn('activity_id', $usedIds);
        }

        $needle = mb_substr(trim($this->activitySearch), 0, 100);

        if ($needle !== '') {
            $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $pattern = '%'.addcslashes($needle, '\\%_').'%';
            $query->where(function ($inner) use ($like, $pattern): void {
                $inner->whereRaw("activity_name {$like} ? escape '\\'", [$pattern])
                    ->orWhereRaw("activity_code {$like} ? escape '\\'", [$pattern]);
            });
        }

        return $query->get()->map(fn (object $row): array => [
            'activity_id' => (int) $row->activity_id,
            'activity_code' => (string) $row->activity_code,
            'activity_name' => (string) $row->activity_name,
        ])->all();
    }

    /**
     * @return array<int, string>
     */
    private function recordedActivityNames(): array
    {
        $names = [];

        foreach ($this->recordedActivities() as $activity) {
            $id = $activity['activity_id'];

            if ($id === null || $id < 1) {
                continue;
            }

            if ($activity['name'] !== '') {
                $names[$id] = $activity['name'];
            }
        }

        return $names;
    }

    /**
     * @return list<int>
     */
    private function usedActivityIds(): array
    {
        if (! $this->currentCalibration()->relationLoaded('activities')) {
            return [];
        }

        return $this->currentCalibration()->activities
            ->pluck('activity_id')
            ->filter(fn (mixed $id): bool => $id !== null && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function usedStandardIds(): array
    {
        if (! $this->currentCalibration()->relationLoaded('standardUsages')) {
            return [];
        }

        return $this->currentCalibration()->standardUsages
            ->pluck('standard_id')
            ->filter(fn (mixed $id): bool => $id !== null && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{range: string, unit: string, purpose: string, remarks: string}
     */
    private function persistedUsageDetails(int $usageId): array
    {
        $usage = $this->currentCalibration()->standardUsages->first(
            fn (object $row): bool => (int) $row->calibration_standard_usage_id === $usageId,
        );

        if ($usage === null) {
            return [
                'range' => '',
                'unit' => '',
                'purpose' => '',
                'remarks' => '',
            ];
        }

        return [
            'range' => (string) ($usage->standard_range ?? ''),
            'unit' => (string) ($usage->standard_unit ?? ''),
            'purpose' => (string) ($usage->usage_purpose ?? ''),
            'remarks' => (string) ($usage->remarks ?? ''),
        ];
    }

    private function fillMissingUsageDetails(): void
    {
        if (! $this->currentCalibration()->relationLoaded('standardUsages')) {
            return;
        }

        foreach ($this->currentCalibration()->standardUsages as $usage) {
            $key = (string) $usage->calibration_standard_usage_id;

            if (! array_key_exists($key, $this->usageDetails)) {
                $this->usageDetails[$key] = $this->persistedUsageDetails((int) $usage->calibration_standard_usage_id);
            }
        }
    }

    /**
     * @return array{description: string, result: string}
     */
    private function persistedActivityDetails(int $activityRowId): array
    {
        $activity = $this->currentCalibration()->activities->first(
            fn (object $row): bool => (int) $row->calibration_activity_id === $activityRowId,
        );

        if ($activity === null) {
            return [
                'description' => '',
                'result' => '',
            ];
        }

        return [
            'description' => (string) ($activity->activity_description ?? ''),
            'result' => (string) ($activity->activity_result ?? ''),
        ];
    }

    private function fillMissingActivityDetails(): void
    {
        if (! $this->currentCalibration()->relationLoaded('activities')) {
            return;
        }

        foreach ($this->currentCalibration()->activities as $activity) {
            $key = (string) $activity->calibration_activity_id;

            if (! array_key_exists($key, $this->activityDetails)) {
                $this->activityDetails[$key] = $this->persistedActivityDetails((int) $activity->calibration_activity_id);
            }
        }
    }

    private function persistedEnvironmentInput(int $parameterId): string
    {
        if (! $this->currentCalibration()->relationLoaded('environmentValues')) {
            return '';
        }

        $value = $this->currentCalibration()->environmentValues->first(
            fn (object $row): bool => (int) $row->parameter_id === $parameterId,
        );

        if ($value === null) {
            return '';
        }

        if ($value->numeric_value !== null && $value->numeric_value !== '') {
            return (string) $value->numeric_value;
        }

        return (string) ($value->text_value ?? '');
    }

    private function fillMissingEnvironmentInputs(): void
    {
        if (! $this->currentCalibration()->relationLoaded('environmentParameterSnapshots')) {
            return;
        }

        foreach ($this->currentCalibration()->environmentParameterSnapshots as $snapshot) {
            $key = (string) $snapshot->parameter_id;

            if (! array_key_exists($key, $this->environmentInputs)) {
                $this->environmentInputs[$key] = $this->persistedEnvironmentInput((int) $snapshot->parameter_id);
            }
        }
    }

    private function nullableUsageText(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function currentCalibration(): Calibration
    {
        if (! $this->loadedCalibration instanceof Calibration
            || (int) $this->loadedCalibration->getKey() !== $this->calibrationId) {
            $this->loadCalibrationDetail();
        }

        return $this->loadedCalibration ?? abort(404);
    }

    private function readConnectionName(): string
    {
        return app(CalibrationSessionHttpRead::class)->connectionName();
    }

    private function readSchema(): SchemaBuilder
    {
        return Schema::connection($this->readConnectionName());
    }

    private function loadCalibrationDetail(): void
    {
        $fresh = app(CalibrationSessionHttpRead::class)->calibration($this->calibrationId);
        $this->loadedCalibration = $fresh;
        if ($this->completionDate === '') {
            $this->completionDate = $fresh->calibration_date?->format('Y-m-d') ?? '';
        }

        if ($this->completionDue === '') {
            $this->completionDue = $fresh->calibration_due?->format('Y-m-d') ?? '';
        }

        if ($this->resume === '') {
            $this->resume = (string) ($fresh->resume ?? '');
        }

        $fresh->load([
            'instrument:instrument_id,instrument_name,serial_number',
            'instrumentType:instrument_type_id,instrument_id,type_name,brand,model,asset_number',
            'calibrationScopes' => function (HasMany $query): void {
                $query
                    ->select($this->scopeColumns())
                    ->orderBy('scope_order')
                    ->orderBy('calibration_scope_id');
            },
            'calibrationScopes.instrumentType' => function (BelongsTo $query): void {
                $query->select([
                    'instrument_type_id',
                    'instrument_id',
                    'type_name',
                    'brand',
                    'model',
                    'asset_number',
                ]);
            },
            'calibrationScopes.testProfile' => function (BelongsTo $query): void {
                $query->select([
                    'test_profile_id',
                    'instrument_type_id',
                    'profile_name',
                    'description',
                    'created_at',
                ]);
            },
            'calibrationScopes.testProfile.testPoints' => function (HasMany $query): void {
                $query
                    ->select([
                        'test_point_id',
                        'test_profile_id',
                        'point_order',
                        'nominal_value',
                        'unit',
                        'tolerance_plus',
                        'tolerance_minus',
                        'description',
                        'created_at',
                    ])
                    ->orderBy('point_order')
                    ->orderBy('test_point_id');
            },
            'calibrationScopes.calibrationTestResults' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_test_result_id',
                        'calibration_scope_id',
                        'test_point_id',
                        'result_status',
                        'remarks',
                        'standard_value',
                        'average_value',
                        'correction_value',
                        'created_at',
                    ])
                    ->orderBy(
                        TestPoint::on($this->readConnectionName())
                            ->select('point_order')
                            ->whereColumn('test_point.test_point_id', 'calibration_test_result.test_point_id')
                    )
                    ->orderBy('calibration_test_result_id');
            },
            'calibrationScopes.calibrationTestResults.testPoint' => function (BelongsTo $query): void {
                $query->select([
                    'test_point_id',
                    'test_profile_id',
                    'point_order',
                    'nominal_value',
                    'unit',
                    'tolerance_plus',
                    'tolerance_minus',
                    'description',
                    'created_at',
                ]);
            },
            'calibrationScopes.calibrationTestResults.calibrationReadings' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_reading_id',
                        'calibration_test_result_id',
                        'reading_order',
                        'reference_value',
                        'instrument_value',
                        'error_value',
                        'uncertainty_value',
                        'unit',
                        'created_at',
                    ])
                    ->orderBy('reading_order')
                    ->orderBy('calibration_reading_id');
            },
            'environment' => function (HasOne $query): void {
                $query->select($this->environmentColumns());
            },
            'activities' => function (HasMany $query): void {
                $query
                    ->select($this->activityColumns())
                    ->orderBy('activity_order')
                    ->orderBy('calibration_activity_id');
            },
            'standardUsages' => function (HasMany $query): void {
                $query
                    ->select($this->standardUsageColumns())
                    ->orderBy('usage_order')
                    ->orderBy('calibration_standard_usage_id');
            },
            'standardUsages.instrument' => function (BelongsTo $query): void {
                $query->select([
                    'instrument_id',
                    'instrument_name',
                ]);
            },
        ]);

        if ($this->readSchema()->hasTable('calibration_requirement_snapshot')) {
            $fresh->load([
                'requirementSnapshots' => function (HasMany $query): void {
                    $query->orderBy('requirement_category')
                        ->orderBy('calibration_requirement_snapshot_id');
                },
            ]);
        }

        if ($this->readSchema()->hasTable('calibration_environment_parameter_snapshot')) {
            $fresh->load([
                'environmentParameterSnapshots' => function (HasMany $query): void {
                    $query
                        ->select($this->environmentSnapshotColumns())
                        ->orderBy('display_order')
                        ->orderBy('parameter_id');
                },
            ]);
        }

        if ($this->readSchema()->hasTable('calibration_environment_value')) {
            $fresh->load([
                'environmentValues' => function (HasMany $query): void {
                    $query
                        ->select([
                            'calibration_environment_value_id',
                            'calibration_id',
                            'parameter_id',
                            'numeric_value',
                            'text_value',
                            'recorded_at',
                        ])
                        ->orderBy('parameter_id')
                        ->orderBy('calibration_environment_value_id');
                },
            ]);
        }

        if ($this->readSchema()->hasTable('calibration_external_document')) {
            $fresh->load([
                'externalDocuments' => function (HasMany $query): void {
                    $query
                        ->select([
                            'document_id',
                            'calibration_id',
                            'original_filename',
                            'mime_type',
                            'byte_size',
                            'is_active',
                            'uploaded_at',
                            'superseded_at',
                        ])
                        ->orderBy('uploaded_at')
                        ->orderBy('document_id');
                },
                'activeExternalDocument' => function (HasOne $query): void {
                    $query->select([
                        'document_id',
                        'calibration_id',
                        'original_filename',
                        'mime_type',
                        'byte_size',
                        'is_active',
                        'uploaded_at',
                        'superseded_at',
                    ]);
                },
            ]);
        }

        unset($this->status);
        unset($this->calibratedTypes);
        unset($this->testComponents);
        unset($this->entryGrid);
        $this->fillMissingResultInputs();
        $this->fillMissingUsageDetails();
        $this->fillMissingActivityDetails();
        $this->fillMissingEnvironmentInputs();
    }

    private function syncSelectedActivityIds(): void
    {
        $this->selectedActivityIds = array_map(
            static fn (int $activityId): string => (string) $activityId,
            $this->usedActivityIds(),
        );
    }

    public function hasEligibleActiveTable(): bool
    {
        if (! $this->readSchema()->hasTable('test_profile') || ! $this->readSchema()->hasTable('test_profile_version')) {
            return false;
        }

        $typeId = (int) ($this->currentCalibration()->instrument_type_id ?? 0);

        if ($typeId < 1) {
            return false;
        }

        $profiles = DB::table('test_profile')->where('instrument_type_id', $typeId);

        if ($this->readSchema()->hasColumn('test_profile', 'archived_at')) {
            $profiles->whereNull('archived_at');
        }

        $profileIds = $profiles->pluck('test_profile_id');

        if ($profileIds->isEmpty()) {
            return false;
        }

        return DB::table('test_profile_version')
            ->whereIn('test_profile_id', $profileIds)
            ->where('status', TestProfileVersion::STATUS_ACTIVE)
            ->exists();
    }

    #[Computed]
    public function status(): ?string
    {
        if ($this->currentCalibration()->calibration_due === null) {
            return null;
        }

        if (today()->greaterThan($this->currentCalibration()->calibration_due->copy()->startOfDay())) {
            return 'Overdue';
        }

        return 'On Track';
    }

    public function sessionStatusLabel(): string
    {
        return CalibrationSessionDetailPresenter::sessionStatusLabel((string) $this->currentCalibration()->session_status);
    }

    /**
     * @return array{name: string, type: string, brand: string, model: string, serial_number: string, asset_number: string}
     */
    public function identitySnapshot(): array
    {
        return CalibrationSessionDetailPresenter::identity($this->currentCalibration());
    }

    public function isInternalSession(): bool
    {
        return CalibrationSessionDetailPresenter::isInternal($this->currentCalibration());
    }

    public function isExternalSession(): bool
    {
        return CalibrationSessionDetailPresenter::isExternal($this->currentCalibration());
    }

    public function recordedVendorName(): ?string
    {
        return CalibrationSessionDetailPresenter::recordedVendorName($this->currentCalibration());
    }

    /**
     * @return list<array{key: int, filename: string, uploaded_at: string, status: string, replaced: bool}>
     */
    public function vendorDocuments(): array
    {
        return CalibrationSessionDetailPresenter::vendorDocuments($this->currentCalibration());
    }

    /**
     * @return list<array{key: int, name: string, brand: string, type: string, model: string, serial_number: string, asset_number: string}>
     */
    #[Computed]
    public function calibratedTypes(): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->currentCalibration()->calibrationScopes as $scope) {
            $type = $scope->relationLoaded('instrumentType') ? $scope->getRelation('instrumentType') : null;

            if ($type === null || isset($seen[$type->instrument_type_id])) {
                continue;
            }

            $seen[$type->instrument_type_id] = true;
            $rows[] = CalibrationSessionDetailPresenter::typedIdentity(
                $this->currentCalibration(),
                (int) $type->instrument_type_id,
                $type,
            );
        }

        if ($rows !== []) {
            return $rows;
        }

        $identity = $this->identitySnapshot();
        $hasIdentity = $identity['name'] !== '—'
            || $identity['type'] !== '—'
            || $identity['brand'] !== '—'
            || $identity['model'] !== '—'
            || $identity['serial_number'] !== '—'
            || $identity['asset_number'] !== '—';

        if (! $hasIdentity) {
            return [];
        }

        $typeId = (int) ($this->currentCalibration()->instrument_type_id ?? 0);

        return [[
            'key' => $typeId > 0 ? $typeId : 0,
            ...$identity,
        ]];
    }

    /**
     * @return list<array{category: string, frozen: string, resolved: string, effective: string}>
     */
    public function requirementModes(): array
    {
        return [];
    }

    /**
     * @return list<array{key: int, usage_order: int, name: string, code: string, type: string, serial_number: string, purpose: string, range: string, unit: string, remarks: string}>
     */
    public function recordedStandards(): array
    {
        return CalibrationSessionDetailPresenter::standards($this->currentCalibration());
    }

    /**
     * @return list<array{key: int, activity_id: int|null, activity_order: int, name: string, description: string, result: string}>
     */
    public function recordedActivities(): array
    {
        return CalibrationSessionDetailPresenter::activities($this->currentCalibration());
    }

    /**
     * @return list<array{key: int, parameter_id: int, code: string, name: string, unit: string, data_type: string, requirement_mode: string, required: bool, value: string, input: string, has_value: bool, missing_required: bool}>
     */
    public function recordedEnvironmentParameters(): array
    {
        return CalibrationSessionDetailPresenter::environmentParameters($this->currentCalibration());
    }

    /**
     * @return array{confirmed: bool, confirmed_at: string, reason: string}
     */
    public function recordedEnvironmentConfirmation(): array
    {
        return CalibrationSessionDetailPresenter::environmentConfirmation($this->currentCalibration());
    }

    /**
     * @return array{state: 'hidden'|'unpinned'|'tables', tables: list<array{key: int, title: string, state: 'snapshot'|'unsupported', columns: list<array{key: string, label: string}>, rows: list<array{key: string, point_order: string, cells: list<string>}>}>}
     */
    #[Computed]
    public function testComponents(): array
    {
        return CalibrationSessionDetailPresenter::testComponents($this->currentCalibration(), $this->recordedResultValues());
    }

    /**
     * @return array{state: 'hidden'|'unpinned'|'tables', tables: list<array{key: int, title: string, state: 'snapshot'|'unsupported', columns: list<array{key: string, label: string, unit: string, value_source: string}>, rows: list<array{key: string, test_point_id: int, point_order: string, cells: list<array{id: string, column_key: string, value_source: string, data_type: string, unit: string, editable: bool, display: string, input: string}>}>}>}
     */
    #[Computed]
    public function entryGrid(): array
    {
        return CalibrationSessionDetailPresenter::entryGrid($this->currentCalibration(), $this->recordedResultValues());
    }

    public function showsLegacyScopeDetails(CalibrationScope $scope): bool
    {
        return CalibrationSessionDetailPresenter::showsLegacyScopeDetails($this->currentCalibration(), $scope);
    }

    /**
     * @return Collection<int, object>
     */
    private function recordedResultValues(): Collection
    {
        if (! $this->readSchema()->hasTable('calibration_result_value')) {
            return new Collection;
        }

        $resultIds = $this->currentCalibration()->calibrationScopes
            ->flatMap(function (CalibrationScope $scope): Collection {
                if (! $scope->relationLoaded('calibrationTestResults')) {
                    return new Collection;
                }

                return $scope->calibrationTestResults->pluck('calibration_test_result_id');
            })
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($resultIds === []) {
            return new Collection;
        }

        return DB::table('calibration_result_value')
            ->whereIn('calibration_test_result_id', $resultIds)
            ->orderBy('calibration_test_result_id')
            ->orderBy('column_key')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function scopeColumns(): array
    {
        $columns = [
            'calibration_scope_id',
            'calibration_id',
            'instrument_type_id',
            'test_profile_id',
            'scope_order',
            'remarks',
            'created_at',
        ];

        if ($this->readSchema()->hasColumn('calibration_scope', 'test_profile_version_id')) {
            $columns[] = 'test_profile_version_id';
        }

        if ($this->readSchema()->hasColumn('calibration_scope', 'test_table_snapshot')) {
            $columns[] = 'test_table_snapshot';
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function environmentColumns(): array
    {
        $columns = [
            'calibration_environment_id',
            'calibration_id',
            'created_at',
        ];

        foreach ([
            'temperature_value',
            'temperature_unit',
            'humidity_value',
            'humidity_unit',
            'pressure_value',
            'pressure_unit',
            'remarks',
        ] as $column) {
            if ($this->readSchema()->hasColumn('calibration_environment', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function environmentSnapshotColumns(): array
    {
        return [
            'calibration_id',
            'parameter_id',
            'parameter_code',
            'parameter_name',
            'unit',
            'data_type',
            'requirement_mode',
            'display_order',
            'included_at',
            'inclusion_confirmed',
            'inclusion_reason',
        ];
    }

    /**
     * @return list<string>
     */
    private function activityColumns(): array
    {
        $columns = [
            'calibration_activity_id',
            'calibration_id',
            'activity_type',
            'activity_order',
            'created_at',
        ];

        foreach ([
            'activity_id',
            'activity_name',
            'activity_description',
            'activity_result',
        ] as $column) {
            if ($this->readSchema()->hasColumn('calibration_activity', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function standardUsageColumns(): array
    {
        $columns = [
            'calibration_standard_usage_id',
            'calibration_id',
            'standard_instrument_id',
            'usage_order',
            'usage_purpose',
            'remarks',
            'standard_range',
            'standard_unit',
            'created_at',
        ];

        foreach ([
            'standard_id',
            'snapshot_standard_code',
            'snapshot_instrument_name',
            'snapshot_type_specification',
            'snapshot_serial_number',
        ] as $column) {
            if ($this->readSchema()->hasColumn('calibration_standard_usage', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function display(mixed $value): string
    {
        return CalibrationSessionDetailPresenter::display($value);
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <main class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <a href="{{ route('calibrations.index') }}" class="ui-button-secondary w-fit gap-2 shadow-[0_10px_18px_-14px_rgb(22_49_114/0.9)]">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6 9 12l6 6" />
                </svg>
                Back to calibrations
            </a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-navy">{{ $this->currentCalibration()->calibration_number }}</h1>
            <p class="mt-1 text-sm text-muted">
                <a href="{{ route('instruments.show', $this->currentCalibration()->instrument_id) }}" class="font-medium text-navy hover:text-brand">
                    {{ $this->identitySnapshot()['name'] }}
                </a>
            </p>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <dt class="text-xs font-medium text-muted">Calibration Number</dt>
                    <dd class="mt-1 font-semibold text-navy">{{ $this->currentCalibration()->calibration_number }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-muted">Instrument Type</dt>
                    <dd class="mt-1 font-semibold text-navy">{{ $this->identitySnapshot()['type'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-muted">Calibration Method</dt>
                    <dd class="mt-1 font-semibold text-navy">{{ $this->currentCalibration()->calibration_method ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-muted">Session Status</dt>
                    <dd class="mt-1 font-semibold text-navy"><x-status-badge :status="$this->sessionStatusLabel()" /></dd>
                </div>
            </dl>
        </header>

        @if ($this->currentCalibration()->session_status === Calibration::STATUS_IN_PROGRESS)
            <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="calibration-dates-heading">
                <div class="bg-navy px-5 py-4">
                    <h2 id="calibration-dates-heading" class="text-base font-semibold tracking-tight text-white">Calibration Dates</h2>
                </div>
                @error('saveDates')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror
                @if ($dateStatus !== null)
                    <p class="px-5 pt-5 text-sm text-navy">{{ $dateStatus }}</p>
                @endif
                <form wire:submit="saveDates" class="flex flex-col gap-4 px-5 py-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="session-calibration-date" class="ui-label">Calibration Date</label>
                            <input id="session-calibration-date" type="date" wire:model="completionDate" class="ui-input" autocomplete="off">
                            @error('completionDate') <p class="ui-error mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="session-calibration-due" class="ui-label">Calibration Due</label>
                            <input id="session-calibration-due" type="date" wire:model="completionDue" class="ui-input" autocomplete="off">
                            @error('completionDue') <p class="ui-error mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <button type="submit" class="ui-button-primary w-fit" wire:loading.attr="disabled" wire:target="saveDates">Save Dates</button>
                </form>
            </section>
        @endif

        @if ((string) $this->currentCalibration()->session_status !== Calibration::STATUS_IN_PROGRESS)
            <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="calibration-information-heading">
            <div class="bg-navy px-5 py-4">
                <h2 id="calibration-information-heading" class="text-base font-semibold tracking-tight text-white">Calibration Information</h2>
            </div>

            <dl class="grid gap-3 px-5 py-5 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Calibration Number</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ $this->currentCalibration()->calibration_number }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Session Status</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">
                        <x-status-badge :status="$this->sessionStatusLabel()" />
                    </dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Current Due Status</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">
                        @if ($this->status === null)
                            —
                        @else
                            <x-status-badge :status="$this->status" />
                        @endif
                    </dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Calibration Date</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ $this->currentCalibration()->calibration_date?->format('Y-m-d') ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Calibration Due</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ $this->currentCalibration()->calibration_due?->format('Y-m-d') ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Calibration Method</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ $this->currentCalibration()->calibration_method ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Certificate Number</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ $this->currentCalibration()->certificate_number ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Action Date</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ $this->currentCalibration()->calibration_date?->format('Y-m-d') ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)] sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs font-medium text-muted">Remarks</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ $this->currentCalibration()->remarks ?? '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)] sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs font-medium text-muted">Resume</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ $this->currentCalibration()->resume ?? '—' }}</dd>
                </div>
            </dl>

                @if ((string) $this->currentCalibration()->session_status === Calibration::STATUS_CANCELLED)
                    <section class="mx-5 mb-5 overflow-hidden rounded-xl border border-red-200 bg-red-50/70" aria-labelledby="cancellation-details-heading">
                        <div class="border-b border-red-200 px-4 py-3">
                            <h3 id="cancellation-details-heading" class="text-sm font-semibold text-red-900">Cancellation Details</h3>
                        </div>
                        <dl class="grid gap-3 p-4 sm:grid-cols-2">
                            <div class="rounded-lg border border-red-200 bg-white/70 px-4 py-3 sm:col-span-2">
                                <dt class="text-xs font-medium text-red-700">Cancellation Reason</dt>
                                <dd class="mt-1 break-words text-sm font-semibold text-navy">{{ $this->currentCalibration()->cancelled_reason ?? '—' }}</dd>
                            </div>
                            <div class="rounded-lg border border-red-200 bg-white/70 px-4 py-3">
                                <dt class="text-xs font-medium text-red-700">Cancelled By</dt>
                                <dd class="mt-1 break-words text-sm font-semibold text-navy">
                                    {{ $this->currentCalibration()->cancelled_by_user_id === null ? '—' : 'App User #'.$this->currentCalibration()->cancelled_by_user_id }}
                                </dd>
                            </div>
                            <div class="rounded-lg border border-red-200 bg-white/70 px-4 py-3">
                                <dt class="text-xs font-medium text-red-700">Cancelled At</dt>
                                <dd class="mt-1 whitespace-nowrap text-sm font-semibold text-navy">{{ $this->currentCalibration()->cancelled_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? '—' }}</dd>
                            </div>
                        </dl>
                    </section>
                @endif
            </section>
        @endif

        @if ($this->canShowExternalVendorSection())
            <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="external-vendor-heading">
                <div class="bg-navy px-5 py-4">
                    <h2 id="external-vendor-heading" class="text-base font-semibold tracking-tight text-white">Vendor Information</h2>
                </div>

                @if ($vendorStatus !== null)
                    <p class="px-5 pt-5 text-sm text-navy">{{ $vendorStatus }}</p>
                @endif

                @error('saveExternalVendor')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror

                <div class="flex flex-col gap-4 px-5 py-5">
                    @if ($this->canSaveExternalVendor())
                        <form wire:submit="saveExternalVendor" class="flex flex-col gap-4">
                            <div>
                                <label for="external-vendor-name" class="ui-label">External Calibration Vendor (Optional)</label>
                                <input id="external-vendor-name" type="text" wire:model="externalVendorName" maxlength="255" class="ui-input" autocomplete="organization">
                                @error('externalVendorName')
                                    <p class="ui-error mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <button
                                type="submit"
                                class="ui-button-primary w-fit"
                                wire:loading.attr="disabled"
                                wire:target="saveExternalVendor"
                            >
                                Save Vendor
                            </button>
                        </form>
                    @else
                        <div>
                            <p class="text-xs font-medium text-muted">External Calibration Vendor (Optional)</p>
                            <p class="mt-1 text-sm font-semibold text-navy">
                                {{ $this->recordedVendorName() ?? 'No vendor recorded' }}
                            </p>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if ($this->canShowExternalDocumentsSection())
            <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="external-certificate-heading">
                <div class="bg-navy px-5 py-4">
                    <h2 id="external-certificate-heading" class="text-base font-semibold tracking-tight text-white">External Certificate PDF</h2>
                </div>

                @if ($uploadStatus !== null)
                    <p class="px-5 pt-5 text-sm text-navy">{{ $uploadStatus }}</p>
                @endif

                @error('uploadExternalDocument')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror

                <div class="flex flex-col gap-4 px-5 py-5">
                    @if ($this->vendorDocuments() === [])
                        <p class="text-sm text-navy/70">No vendor PDF recorded.</p>
                    @else
                        <ul class="flex flex-col gap-3">
                            @foreach ($this->vendorDocuments() as $document)
                                <li wire:key="external-document-{{ $document['key'] }}" class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3">
                                    <p class="text-sm font-semibold text-navy">{{ $document['filename'] }}</p>
                                    <p class="mt-1 text-xs text-navy/70">Uploaded {{ $document['uploaded_at'] }}</p>
                                    <p class="mt-1 text-xs font-medium text-navy">{{ $document['status'] }}</p>
                                    @if ($document['replaced'])
                                        <p class="mt-1 text-xs text-navy/70">This file was replaced by a later upload.</p>
                                    @endif
                                    <a
                                        href="{{ route('calibrations.external-documents.download', ['calibration' => $this->currentCalibration()->calibration_id, 'document' => $document['key']]) }}"
                                        class="ui-button-secondary mt-3 w-fit"
                                    >
                                        Download
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($this->canUploadExternalDocument())
                        <form wire:submit="uploadExternalDocument" class="flex flex-col gap-4">
                            <div>
                                <label for="external-certificate-pdf" class="ui-label">
                                    {{ $this->vendorDocuments() === [] ? 'Upload vendor PDF' : 'Replace vendor PDF' }}
                                </label>
                                <input id="external-certificate-pdf" type="file" wire:model="externalCertificatePdf" accept="application/pdf" class="ui-input">
                                @error('externalCertificatePdf')
                                    <p class="ui-error mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <button
                                type="submit"
                                class="ui-button-primary w-fit"
                                wire:loading.attr="disabled"
                                wire:target="uploadExternalDocument"
                            >
                                {{ $this->vendorDocuments() === [] ? 'Upload PDF' : 'Replace PDF' }}
                            </button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        @if (false && $this->requirementModes() !== [])
            <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="requirement-modes-heading">
                <div class="bg-navy px-5 py-4">
                    <h2 id="requirement-modes-heading" class="text-base font-semibold tracking-tight text-white">Requirement Modes</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead class="bg-navy text-xs font-medium text-white">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Category</th>
                                <th scope="col" class="px-5 py-3 font-medium">Frozen</th>
                                <th scope="col" class="px-5 py-3 font-medium">Resolved</th>
                                <th scope="col" class="px-5 py-3 font-medium">Effective</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->requirementModes() as $requirement)
                                <tr wire:key="requirement-mode-{{ $requirement['category'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                    <td class="px-5 py-3.5 font-medium text-navy">{{ $requirement['category'] }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $requirement['frozen'] }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $requirement['resolved'] }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $requirement['effective'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="environment-heading">
            <div class="bg-navy px-5 py-4">
                <h2 id="environment-heading" class="text-base font-semibold tracking-tight text-white">Environment</h2>
            </div>

            @if ($this->hasCanonicalEnvironmentSnapshots())
                @if ($environmentStatus !== null)
                    <p class="px-5 pt-5 text-sm text-navy">{{ $environmentStatus }}</p>
                @endif

                @error('saveEnvironmentMeasurement')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror

                @if (false && $this->canShowEnvironmentResolveControl())
                    <form wire:submit="resolveEnvironmentRequirement" class="flex flex-col gap-4 px-5 py-5">
                        <p class="text-sm text-navy/70">The environment requirement is not configured. Choose required or optional before recording measurements.</p>
                        <div>
                            <label for="environment-resolved-mode" class="ui-label">Resolved Mode</label>
                            <select id="environment-resolved-mode" wire:model="environmentResolvedMode" class="ui-input">
                                <option value="">Select a mode</option>
                                <option value="{{ InstrumentTypeRequirement::MODE_REQUIRED }}">required</option>
                                <option value="{{ InstrumentTypeRequirement::MODE_OPTIONAL }}">optional</option>
                            </select>
                            @error('environmentResolvedMode')
                                <p class="ui-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="environment-resolution-reason" class="ui-label">Reason</label>
                            <textarea id="environment-resolution-reason" wire:model="environmentResolutionReason" rows="3" class="ui-input"></textarea>
                            @error('environmentResolutionReason')
                                <p class="ui-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <button
                            type="submit"
                            class="ui-button-primary w-fit"
                            wire:loading.attr="disabled"
                            wire:target="resolveEnvironmentRequirement"
                        >
                            Apply Environment Mode
                        </button>
                    </form>
                @endif

                @if ($this->recordedEnvironmentParameters() === [])
                    <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        No environment parameters frozen for this session.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                            <thead class="bg-brand-soft text-xs font-medium text-navy">
                                <tr>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3">Parameter</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3">Unit</th>
                                    <th scope="col" class="min-w-48 px-4 py-3">Measurement</th>
                                    @if ($this->canEditEnvironmentMeasurements())
                                        <th scope="col" class="whitespace-nowrap px-4 py-3">Actions</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                                @foreach ($this->recordedEnvironmentParameters() as $parameter)
                                    <tr wire:key="environment-parameter-{{ $parameter['key'] }}">
                                        <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $parameter['name'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $parameter['unit'] }}</td>
                                        @if ($this->canEditEnvironmentMeasurements())
                                            <td class="px-4 py-3">
                                                <input
                                                    id="environment-input-{{ $parameter['key'] }}"
                                                    type="text"
                                                    wire:model="environmentInputs.{{ $parameter['key'] }}"
                                                    class="ui-input"
                                                    autocomplete="off"
                                                >
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex flex-col gap-2">
                                                    <button
                                                        type="button"
                                                        class="ui-button-primary w-fit"
                                                        wire:click="saveEnvironmentMeasurement({{ $parameter['key'] }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="saveEnvironmentMeasurement({{ $parameter['key'] }})"
                                                    >
                                                        Save Measurement
                                                    </button>
                                                    @if ($environmentStatusKey === (string) $parameter['key'] && $environmentStatus !== null)
                                                        <p class="text-xs text-navy">{{ $environmentStatus }}</p>
                                                    @endif
                                                    @error('environmentInputs.'.$parameter['key'])
                                                        <p class="ui-error">{{ $message }}</p>
                                                    @enderror
                                                </div>
                                            </td>
                                        @else
                                            <td class="whitespace-nowrap px-4 py-3">
                                                {{ $parameter['value'] }}
                                                @if ($parameter['unit'] !== '—' && $parameter['has_value'])
                                                    {{ $parameter['unit'] }}
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @elseif ($this->canShowLegacyEnvironment())
                @if ($this->currentCalibration()->environment)
                    <dl class="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Temperature</dt>
                            <dd class="mt-1 text-sm">
                                @if (is_null($this->currentCalibration()->environment->temperature_value))
                                    —
                                @else
                                    {{ $this->currentCalibration()->environment->temperature_value }}{{ filled($this->currentCalibration()->environment->temperature_unit) ? ' '.$this->currentCalibration()->environment->temperature_unit : '' }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Humidity</dt>
                            <dd class="mt-1 text-sm">
                                @if (is_null($this->currentCalibration()->environment->humidity_value))
                                    —
                                @else
                                    {{ $this->currentCalibration()->environment->humidity_value }}{{ filled($this->currentCalibration()->environment->humidity_unit) ? ' '.$this->currentCalibration()->environment->humidity_unit : '' }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pressure</dt>
                            <dd class="mt-1 text-sm">
                                @if (is_null($this->currentCalibration()->environment->pressure_value))
                                    —
                                @else
                                    {{ $this->currentCalibration()->environment->pressure_value }}{{ filled($this->currentCalibration()->environment->pressure_unit) ? ' '.$this->currentCalibration()->environment->pressure_unit : '' }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Remarks</dt>
                            <dd class="mt-1 text-sm">{{ $this->currentCalibration()->environment->remarks ?? '—' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        No environment data found.
                    </p>
                @endif
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="activity-heading">
            <div class="bg-navy px-5 py-4">
                <h2 id="activity-heading" class="text-base font-semibold tracking-tight text-white">Activity</h2>
            </div>

            @error('saveActivities')
                <p class="ui-error px-5 pt-5">{{ $message }}</p>
            @enderror

            @error('addActivity')
                <p class="ui-error px-5 pt-5">{{ $message }}</p>
            @enderror

            @error('removeActivity')
                <p class="ui-error px-5 pt-5">{{ $message }}</p>
            @enderror

            @if ($this->recordedActivities() === [])
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No activities recorded.
                </p>
            @else
                <ul class="flex flex-col gap-2 px-5 py-5 text-sm text-navy">
                    @foreach ($this->recordedActivities() as $recordedActivity)
                        <li wire:key="calibration-activity-{{ $recordedActivity['key'] }}" class="flex items-center justify-between gap-4 rounded-lg border border-white/70 bg-[#edf4fa] px-4 py-3">
                            <span class="font-medium">{{ $recordedActivity['name'] }}</span>
                            @if ($this->canEditActivities())
                                <button
                                    type="button"
                                    class="ui-button-danger w-fit"
                                    wire:click="askToRemoveActivity({{ $recordedActivity['key'] }})"
                                >
                                    Remove
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($this->canEditActivities())
                <div class="flex flex-wrap gap-3 border-t border-brand/15 px-5 py-5">
                    <button
                        type="button"
                        class="ui-button-primary w-fit"
                        wire:click="openAddActivityModal"
                        wire:loading.attr="disabled"
                        wire:target="openAddActivityModal"
                    >
                        Add Activity
                    </button>
                </div>
            @endif

            @if ($addingActivities && $this->canOfferActivityCandidates())
                <div
                    x-transition:enter="transition-opacity ease-out duration-[200ms] motion-reduce:duration-0"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition-opacity ease-in duration-[180ms] motion-reduce:duration-0"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-navy/40 px-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="add-activity-heading"
                >
                    <div
                        x-transition:enter="transition ease-out duration-[200ms] motion-reduce:duration-0"
                        x-transition:enter-start="scale-[0.96] opacity-0"
                        x-transition:enter-end="scale-100 opacity-100"
                        x-transition:leave="transition ease-in duration-[180ms] motion-reduce:duration-0"
                        x-transition:leave-start="scale-100 opacity-100"
                        x-transition:leave-end="scale-[0.96] opacity-0"
                        class="flex max-h-[80vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-brand/15 bg-white shadow-xl"
                    >
                        <div class="flex items-center justify-between gap-4 bg-navy px-5 py-4">
                            <h3 id="add-activity-heading" class="text-base font-semibold text-white">Add Activity</h3>
                            <button
                                type="button"
                                class="inline-flex size-8 items-center justify-center rounded-lg text-white/80 transition hover:bg-white/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                wire:click="closeAddActivityModal"
                                aria-label="Close Add Activity"
                                title="Close"
                            >
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                                </svg>
                            </button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                            <div>
                                <label for="activity-search" class="ui-label">Search</label>
                                <input
                                    id="activity-search"
                                    type="search"
                                    wire:model.live="activitySearch"
                                    class="ui-input"
                                    placeholder="Search by activity name"
                                    autocomplete="off"
                                >
                            </div>
                            @if ($this->candidateActivities() === [])
                                <p class="text-sm text-navy/70">No additional active activities are available.</p>
                            @else
                                <fieldset class="flex flex-col gap-3">
                                    <legend class="ui-label">Master Activity</legend>
                                    @foreach ($this->candidateActivities() as $activity)
                                        <label wire:key="activity-option-{{ $activity['activity_id'] }}" class="flex items-start gap-3 text-sm text-navy">
                                            <input
                                                type="checkbox"
                                                wire:model="modalActivityIds"
                                                value="{{ $activity['activity_id'] }}"
                                                class="mt-1"
                                            >
                                            <span>{{ $activity['activity_name'] }}</span>
                                        </label>
                                    @endforeach
                                </fieldset>
                            @endif
                            @error('modalActivityIds')
                                <p class="ui-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-3 border-t border-brand/15 bg-white px-5 py-4">
                                <button
                                    type="button"
                                    class="ui-button-primary w-fit"
                                    wire:click="addSelectedActivities"
                                    wire:loading.attr="disabled"
                                    wire:target="addSelectedActivities"
                                >
                                    Add Selected Activities
                                </button>
                                <button type="button" class="ui-button-secondary w-fit" wire:click="closeAddActivityModal">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="standard-used-heading">
            <div class="bg-navy px-5 py-4">
                <h2 id="standard-used-heading" class="text-base font-semibold tracking-tight text-white">Standard Used</h2>
            </div>

            @error('saveStandards')
                <p class="ui-error px-5 pt-5">{{ $message }}</p>
            @enderror

            @error('addSelectedStandards')
                <p class="ui-error px-5 pt-5">{{ $message }}</p>
            @enderror

            @error('addStandardUsage')
                <p class="ui-error px-5 pt-5">{{ $message }}</p>
            @enderror

            @error('removeStandardUsage')
                <p class="ui-error px-5 pt-5">{{ $message }}</p>
            @enderror

            @error('removeDraftStandard')
                <p class="ui-error px-5 pt-5">{{ $message }}</p>
            @enderror

            @php
                $standardRows = $this->canPreviewAssignedStandards()
                    ? $this->displayedStandards()
                    : $this->recordedStandards();
            @endphp

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-brand-soft text-xs font-medium text-navy">
                        <tr>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Standard Instrument</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Standard Code</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Type / Specification</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Serial Number</th>
                            @if ($this->canEditStandardUsages())
                                <th scope="col" class="whitespace-nowrap px-4 py-3">Remove</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($standardRows as $standardUsage)
                            <tr wire:key="standard-usage-{{ $standardUsage['key'] }}">
                                <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $standardUsage['name'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $standardUsage['code'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $standardUsage['type'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $standardUsage['serial_number'] }}</td>
                                @if ($this->canEditStandardUsages())
                                    <td class="px-4 py-3">
                                        <button
                                            type="button"
                                            class="ui-button-danger w-fit"
                                            wire:click="askToRemoveDraftStandard({{ $standardUsage['standard_id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="askToRemoveDraftStandard({{ $standardUsage['standard_id'] }})"
                                        >
                                            Remove
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $this->canEditStandardUsages() ? 5 : 4 }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No standards used.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($this->canEditStandardUsages())
                <div class="flex flex-wrap gap-3 border-t border-brand/15 px-5 py-5">
                    <button
                        type="button"
                        class="ui-button-primary w-fit"
                        wire:click="openAddStandardModal"
                        wire:loading.attr="disabled"
                        wire:target="openAddStandardModal"
                    >
                        Add Standard
                    </button>
                </div>
            @endif

            @if ($addingStandards && $this->canOfferStandardCandidates())
                <div
                    x-transition:enter="transition-opacity ease-out duration-[200ms] motion-reduce:duration-0"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition-opacity ease-in duration-[180ms] motion-reduce:duration-0"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-navy/40 px-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="add-standard-heading"
                >
                    <div
                        x-transition:enter="transition ease-out duration-[200ms] motion-reduce:duration-0"
                        x-transition:enter-start="scale-[0.96] opacity-0"
                        x-transition:enter-end="scale-100 opacity-100"
                        x-transition:leave="transition ease-in duration-[180ms] motion-reduce:duration-0"
                        x-transition:leave-start="scale-100 opacity-100"
                        x-transition:leave-end="scale-[0.96] opacity-0"
                        class="flex max-h-[80vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-brand/15 bg-white shadow-xl"
                    >
                        <div class="flex items-center justify-between gap-4 bg-navy px-5 py-4">
                            <h3 id="add-standard-heading" class="text-base font-semibold text-white">Add Standard</h3>
                            <button
                                type="button"
                                class="inline-flex size-8 items-center justify-center rounded-lg text-white/80 transition hover:bg-white/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                wire:click="closeAddStandardModal"
                                aria-label="Close Add Standard"
                                title="Close"
                            >
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                                </svg>
                            </button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                            <div>
                                <label for="standard-search" class="ui-label">Search</label>
                                <input
                                    id="standard-search"
                                    type="search"
                                    wire:model.live="standardSearch"
                                    class="ui-input"
                                    placeholder="Search by name, code, or serial number"
                                    autocomplete="off"
                                >
                            </div>
                            @if ($this->searchableStandards() === [])
                                <p class="text-sm text-navy/70">No additional standards are available.</p>
                            @else
                                <fieldset class="flex flex-col gap-3">
                                    <legend class="ui-label">Standard Master</legend>
                                    @foreach ($this->searchableStandards() as $candidate)
                                        <label wire:key="standard-option-{{ $candidate['standard_id'] }}" class="flex items-start gap-3 text-sm text-navy">
                                            <input
                                                type="checkbox"
                                                wire:model="modalStandardIds"
                                                value="{{ $candidate['standard_id'] }}"
                                                class="mt-1"
                                            >
                                            <span>
                                                {{ $candidate['instrument_name'] }}
                                                @if ($candidate['standard_code'] !== '')
                                                    · {{ $candidate['standard_code'] }}
                                                @endif
                                                @if ($candidate['serial_number'] !== '')
                                                    · {{ $candidate['serial_number'] }}
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </fieldset>
                            @endif
                            @error('modalStandardIds')
                                <p class="ui-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-3 border-t border-brand/15 bg-white px-5 py-4">
                                <button
                                    type="button"
                                    class="ui-button-primary w-fit"
                                    wire:click="addSelectedStandards"
                                    wire:loading.attr="disabled"
                                    wire:target="addSelectedStandards"
                                >
                                    Add Selected Standards
                                </button>
                                <button type="button" class="ui-button-secondary w-fit" wire:click="closeAddStandardModal">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        @if ($this->testComponents['state'] !== 'hidden')
            <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="test-components-heading">
                <div class="bg-navy px-5 py-4">
                    <h2 id="test-components-heading" class="text-base font-semibold tracking-tight text-white">Test Components</h2>
                </div>

                @error('pinTestTables')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror

                @error('recoverTemplateValues')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror

                @if (false && $this->canShowResolveControl())
                    <form wire:submit="resolveTestProfileRequirement" class="flex flex-col gap-4 px-5 py-5">
                        <p class="text-sm text-navy/70">The test profile requirement is not configured. Choose required or optional before pinning Test Tables.</p>
                        <div>
                            <label for="resolved-mode" class="ui-label">Resolved Mode</label>
                            <select id="resolved-mode" wire:model="resolvedMode" class="ui-input">
                                <option value="">Select a mode</option>
                                <option value="{{ InstrumentTypeRequirement::MODE_REQUIRED }}">required</option>
                                <option value="{{ InstrumentTypeRequirement::MODE_OPTIONAL }}">optional</option>
                            </select>
                            @error('resolvedMode')
                                <p class="ui-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="resolution-reason" class="ui-label">Reason</label>
                            <textarea id="resolution-reason" wire:model="resolutionReason" rows="3" class="ui-input"></textarea>
                            @error('resolutionReason')
                                <p class="ui-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <button
                            type="submit"
                            class="ui-button-primary w-fit"
                            wire:loading.attr="disabled"
                            wire:target="resolveTestProfileRequirement"
                        >
                            Resolve Requirement
                        </button>
                    </form>
                @endif

                @if ($this->testComponents['state'] === 'unpinned')
                    <div class="flex flex-col gap-4 px-5 py-8">
                        <p class="text-sm text-navy/70">Test Components have not been pinned to this session yet.</p>
                        @if ($this->canShowPinControl() && ! $this->hasEligibleActiveTable())
                            <p class="text-sm text-navy/70">An Active Test Table is required before this session can pin Test Components.</p>
                        @endif
                        @if ($this->canShowPinControl())
                            <button
                                type="button"
                                class="ui-button-primary w-fit"
                                wire:click="pinTestTables"
                                wire:loading.attr="disabled"
                                wire:target="pinTestTables"
                            >
                                Pin Test Tables
                            </button>
                        @endif
                    </div>
                @else
                    <div class="flex flex-col gap-6 px-5 py-5">
                        @if ($this->canEditTestResults())
                            @foreach ($this->entryGrid['tables'] as $table)
                                <div wire:key="test-component-scope-{{ $table['key'] }}">
                                    <h3 class="text-sm font-semibold text-navy">{{ $table['title'] }}</h3>
                                    @if ($this->scopeTemplateMaterialization($table['key']) !== null)
                                        <p class="mt-2 text-sm text-navy/70">{{ $this->scopeTemplateMaterialization($table['key'])['label'] }}</p>
                                    @endif
                                    @if ($this->canRecoverTemplateValues($table['key']))
                                        <button
                                            type="button"
                                            class="ui-button-primary mt-3 w-fit"
                                            wire:click="requestRecoverTemplateValues({{ $table['key'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="requestRecoverTemplateValues({{ $table['key'] }})"
                                        >
                                            Recover Template Values
                                        </button>
                                    @endif
                                    @if ($table['state'] === 'unsupported')
                                        <p class="mt-3 text-sm text-navy/70">This Test Table snapshot is unavailable.</p>
                                    @else
                                        <div class="mt-3 overflow-x-auto rounded-lg border border-brand/15">
                                            <table class="ui-table">
                                                <thead class="bg-navy text-xs font-medium text-white">
                                                    <tr>
                                                        <th scope="col" class="px-5 py-3 font-medium">Point</th>
                                                        @foreach ($table['columns'] as $column)
                                                            <th scope="col" class="px-5 py-3 font-medium" wire:key="test-component-column-{{ $table['key'] }}-{{ $column['key'] }}">
                                                                {{ $column['label'] }}
                                                                @if ($column['unit'] !== '—')
                                                                    <span class="font-normal text-white/70">({{ $column['unit'] }})</span>
                                                                @endif
                                                            </th>
                                                        @endforeach
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-white/70">
                                                    @forelse ($table['rows'] as $row)
                                                        <tr wire:key="test-component-row-{{ $table['key'] }}-{{ $row['key'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                                            <td class="px-5 py-3.5 font-mono text-xs text-navy">{{ $row['point_order'] }}</td>
                                                            @foreach ($row['cells'] as $cell)
                                                                <td class="px-5 py-3.5 text-navy" wire:key="test-component-cell-{{ $cell['id'] }}">
                                                                    @if ($cell['editable'])
                                                                        <div class="flex flex-col gap-2">
                                                                            <input
                                                                                id="result-{{ $cell['id'] }}"
                                                                                type="text"
                                                                                wire:model="resultInputs.{{ $cell['id'] }}"
                                                                                value="{{ $resultInputs[$cell['id']] ?? $cell['input'] }}"
                                                                                class="ui-input"
                                                                                autocomplete="off"
                                                                            >
                                                                            <button
                                                                                type="button"
                                                                                class="ui-button-primary w-fit"
                                                                                wire:click="requestSaveTestResult({{ $table['key'] }}, {{ $row['test_point_id'] }}, '{{ $cell['column_key'] }}')"
                                                                                wire:loading.attr="disabled"
                                                                                wire:target="requestSaveTestResult({{ $table['key'] }}, {{ $row['test_point_id'] }}, '{{ $cell['column_key'] }}')"
                                                                            >
                                                                                Save
                                                                            </button>
                                                                            @error('resultInputs.'.$cell['id'])
                                                                                <p class="ui-error">{{ $message }}</p>
                                                                            @enderror
                                                                        </div>
                                                                    @else
                                                                        {{ $cell['display'] }}
                                                                    @endif
                                                                </td>
                                                            @endforeach
                                                        </tr>
                                                    @empty
                                                        <tr>
                                                            <td colspan="{{ max(1, count($table['columns']) + 1) }}" class="px-5 py-8 text-center text-sm text-navy/70">
                                                                No frozen test points found.
                                                            </td>
                                                        </tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                        @if (filled($table['remarks'] ?? null))
                                            <p class="mt-3 whitespace-pre-line text-sm text-navy/70"><span class="font-medium">Remarks:</span> {{ $table['remarks'] }}</p>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        @else
                            @foreach ($this->testComponents['tables'] as $table)
                                <div wire:key="test-component-scope-{{ $table['key'] }}">
                                    <h3 class="text-sm font-semibold text-navy">{{ $table['title'] }}</h3>
                                    @if ($this->scopeTemplateMaterialization($table['key']) !== null)
                                        <p class="mt-2 text-sm text-navy/70">{{ $this->scopeTemplateMaterialization($table['key'])['label'] }}</p>
                                    @endif
                                    @if ($table['state'] === 'unsupported')
                                        <p class="mt-3 text-sm text-navy/70">This Test Table snapshot is unavailable.</p>
                                    @else
                                        <div class="mt-3 overflow-x-auto rounded-lg border border-brand/15">
                                            <table class="ui-table">
                                                <thead class="bg-navy text-xs font-medium text-white">
                                                    <tr>
                                                        <th scope="col" class="px-5 py-3 font-medium">Point</th>
                                                        @foreach ($table['columns'] as $column)
                                                            <th scope="col" class="px-5 py-3 font-medium" wire:key="test-component-column-{{ $table['key'] }}-{{ $column['key'] }}">{{ $column['label'] }}</th>
                                                        @endforeach
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-white/70">
                                                    @forelse ($table['rows'] as $row)
                                                        <tr wire:key="test-component-row-{{ $table['key'] }}-{{ $row['key'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                                            <td class="px-5 py-3.5 font-mono text-xs text-navy">{{ $row['point_order'] }}</td>
                                                            @foreach ($row['cells'] as $cell)
                                                                <td class="px-5 py-3.5 text-navy">{{ $cell }}</td>
                                                            @endforeach
                                                        </tr>
                                                    @empty
                                                        <tr>
                                                            <td colspan="{{ max(1, count($table['columns']) + 1) }}" class="px-5 py-8 text-center text-sm text-navy/70">
                                                                No frozen test points found.
                                                            </td>
                                                        </tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endif
            </section>
        @endif

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="resume-heading">
            <div class="bg-navy px-5 py-4">
                <h2 id="resume-heading" class="text-base font-semibold tracking-tight text-white">Resume</h2>
            </div>
            @if ($this->currentCalibration()->session_status === Calibration::STATUS_IN_PROGRESS && Gate::allows(ApplicationCapability::SessionRecord->value))
                @error('saveResume')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror
                @if ($resumeStatus !== null)
                    <p class="px-5 pt-5 text-sm text-navy">{{ $resumeStatus }}</p>
                @endif
                <form wire:submit="saveResume" class="flex flex-col gap-4 px-5 py-5">
                    <textarea id="calibration-resume" wire:model="resume" rows="4" maxlength="5000" class="ui-input" placeholder="Enter the instrument condition or conclusion."></textarea>
                    <button type="submit" class="ui-button-primary w-fit" wire:loading.attr="disabled" wire:target="saveResume">Save Resume</button>
                </form>
            @else
                <p class="whitespace-pre-line px-5 py-5 text-sm text-navy">{{ filled($this->currentCalibration()->resume) ? $this->currentCalibration()->resume : '—' }}</p>
            @endif
        </section>

        @if ($this->canShowCompleteSection())
            <div class="flex flex-col gap-3">
                @error('completeSession')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror

                @error('cancelSession')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror

                <div class="flex flex-col gap-4 px-5 py-5">
                    @if ($this->canCompleteSession())
                        <form wire:submit="requestCompleteSession" class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <button
                                    type="submit"
                                    class="ui-button-primary w-fit"
                                    wire:loading.attr="disabled"
                                    wire:target="requestCompleteSession"
                                >
                                    Complete Calibration
                                </button>
                                @if ($this->canShowCancelSection())
                                    <button
                                        type="button"
                                        class="ui-button-danger w-fit"
                                        wire:click="openCancellationModal"
                                        wire:loading.attr="disabled"
                                        wire:target="openCancellationModal"
                                    >
                                        Cancel Calibration
                                    </button>
                                @endif
                            </div>
                        </form>
                    @elseif ($this->canShowCancelSection())
                        <button
                            type="button"
                            class="ui-button-danger w-fit"
                            wire:click="openCancellationModal"
                            wire:loading.attr="disabled"
                            wire:target="openCancellationModal"
                        >
                            Cancel Calibration
                        </button>
                    @endif
                </div>
            </div>
        @endif

        @if ($this->canShowExternalCompleteSection())
            <div class="flex flex-col gap-3">
                @error('completeExternalSession')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror

                @error('cancelSession')
                    <p class="ui-error px-5 pt-5">{{ $message }}</p>
                @enderror

                <div class="flex flex-col gap-4 px-5 py-5">
                    @if ($this->canCompleteExternalSession())
                        <form wire:submit="requestCompleteExternalSession" class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <button
                                    type="submit"
                                    class="ui-button-primary w-fit"
                                    wire:loading.attr="disabled"
                                    wire:target="requestCompleteExternalSession"
                                >
                                    Complete External Calibration
                                </button>
                                @if ($this->canShowCancelSection())
                                    <button
                                        type="button"
                                        class="ui-button-danger w-fit"
                                        wire:click="openCancellationModal"
                                        wire:loading.attr="disabled"
                                        wire:target="openCancellationModal"
                                    >
                                        Cancel Calibration
                                    </button>
                                @endif
                            </div>
                        </form>
                    @elseif ($this->canShowCancelSection())
                        <button
                            type="button"
                            class="ui-button-danger w-fit"
                            wire:click="openCancellationModal"
                            wire:loading.attr="disabled"
                            wire:target="openCancellationModal"
                        >
                            Cancel Calibration
                        </button>
                    @endif
                </div>
            </div>
        @endif

        @if (false)
            {{-- Calibration scope data remains available in the backend; it is not part of the session detail UI. --}}
        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]">
            <div class="bg-navy px-5 py-4">
                <h2 class="text-base font-semibold tracking-tight text-white">Calibration Scope</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-brand-soft text-xs font-medium text-navy">
                        <tr>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Scope Order</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Instrument Type</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Test Profile ID</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($this->currentCalibration()->calibrationScopes as $calibrationScope)
                            @php
                                $scopeType = $calibrationScope->relationLoaded('instrumentType')
                                    ? $calibrationScope->getRelation('instrumentType')
                                    : null;
                                $scopeProfile = $calibrationScope->relationLoaded('testProfile')
                                    ? $calibrationScope->getRelation('testProfile')
                                    : null;
                            @endphp
                            <tr wire:key="calibration-scope-{{ $calibrationScope->calibration_scope_id }}" class="align-top">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ $calibrationScope->scope_order }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="font-medium">{{ $scopeType?->type_name ?? '—' }}</span>
                                    @if ($scopeType)
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            ID: {{ $scopeType->instrument_type_id }}
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $calibrationScope->test_profile_id ?? '—' }}</td>
                                <td class="min-w-64 px-4 py-3">{{ $calibrationScope->remarks ?? '—' }}</td>
                            </tr>
                            @if ($this->showsLegacyScopeDetails($calibrationScope))
                            <tr wire:key="calibration-scope-details-{{ $calibrationScope->calibration_scope_id }}">
                                <td colspan="4" class="bg-gray-50 px-4 py-4 dark:bg-gray-800">
                                    <div class="mb-4">
                                        <h3 class="text-sm font-semibold text-gray-950 dark:text-gray-100">
                                            Test Profile:
                                            {{ $scopeProfile?->profile_name ?? '—' }}
                                        </h3>
                                        @if ($scopeProfile)
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                ID: {{ $scopeProfile->test_profile_id }}
                                                &middot;
                                                {{ $scopeProfile->description ?? '—' }}
                                            </p>
                                        @endif
                                    </div>

                                    @if ($scopeProfile)
                                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                                            <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                                                <thead class="bg-brand-soft text-xs font-medium text-navy">
                                                    <tr>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Point</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Nominal</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Unit</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">+Tol</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">-Tol</th>
                                                        <th scope="col" class="min-w-64 px-3 py-2">Description</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                                    @forelse ($scopeProfile->relationLoaded('testPoints') ? $scopeProfile->testPoints : [] as $testPoint)
                                                        <tr wire:key="test-point-{{ $testPoint->test_point_id }}">
                                                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">
                                                                {{ $testPoint->point_order }}
                                                            </td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $testPoint->nominal_value ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $testPoint->unit ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $testPoint->tolerance_plus ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $testPoint->tolerance_minus ?? '—' }}</td>
                                                            <td class="px-3 py-2">{{ $testPoint->description ?? '—' }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr>
                                                            <td colspan="6" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                                                No test points found.
                                                            </td>
                                                        </tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif

                                    <div class="mt-6">
                                        <h4 class="mb-2 text-sm font-semibold text-gray-950 dark:text-gray-100">Test Results</h4>

                                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                                            <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                                                <thead class="bg-brand-soft text-xs font-medium text-navy">
                                                    <tr>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Point</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Nominal</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Unit</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Result</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Standard</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Average</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Correction</th>
                                                        <th scope="col" class="min-w-64 px-3 py-2">Remarks</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                                    @forelse ($calibrationScope->calibrationTestResults as $calibrationTestResult)
                                                        <tr wire:key="test-result-{{ $calibrationTestResult->calibration_test_result_id }}">
                                                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">
                                                                {{ $calibrationTestResult->testPoint?->point_order ?? '—' }}
                                                            </td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->testPoint?->nominal_value ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->testPoint?->unit ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">
                                                                @if (is_null($calibrationTestResult->result_status))
                                                                    —
                                                                @elseif ($calibrationTestResult->result_status === 'PASS')
                                                                    <span class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                                                                        {{ $calibrationTestResult->result_status }}
                                                                    </span>
                                                                @elseif ($calibrationTestResult->result_status === 'FAIL')
                                                                    <span class="inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
                                                                        {{ $calibrationTestResult->result_status }}
                                                                    </span>
                                                                @else
                                                                    {{ $calibrationTestResult->result_status }}
                                                                @endif
                                                            </td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->standard_value ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->average_value ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->correction_value ?? '—' }}</td>
                                                            <td class="px-3 py-2">{{ $calibrationTestResult->remarks ?? '—' }}</td>
                                                        </tr>
                                                        <tr wire:key="test-result-readings-{{ $calibrationTestResult->calibration_test_result_id }}">
                                                            <td colspan="8" class="bg-gray-50 px-3 py-3 dark:bg-gray-950">
                                                                <h5 class="mb-2 text-xs font-medium text-muted">
                                                                    Readings
                                                                </h5>
                                                                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                                                                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                                                                        <thead class="bg-brand-soft text-xs font-medium text-navy">
                                                                            <tr>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Reading</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Reference</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Instrument</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Error</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Uncertainty</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Unit</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                                                            @forelse ($calibrationTestResult->calibrationReadings as $calibrationReading)
                                                                                <tr wire:key="calibration-reading-{{ $calibrationReading->calibration_reading_id }}">
                                                                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">
                                                                                        {{ $calibrationReading->reading_order }}
                                                                                    </td>
                                                                                    <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->reference_value ?? '—' }}</td>
                                                                                    <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->instrument_value ?? '—' }}</td>
                                                                                    <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->error_value ?? '—' }}</td>
                                                                                    <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->uncertainty_value ?? '—' }}</td>
                                                                                    <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->unit ?? '—' }}</td>
                                                                                </tr>
                                                                            @empty
                                                                                <tr>
                                                                                    <td colspan="6" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400">
                                                                                        No readings found.
                                                                                    </td>
                                                                                </tr>
                                                                            @endforelse
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @empty
                                                        <tr>
                                                            <td colspan="8" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                                                No test results found.
                                                            </td>
                                                        </tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No calibration scopes found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endif
    </main>
</div>
