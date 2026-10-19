<?php

namespace App\Livewire\Concerns;

use App\Models\InstrumentType;
use App\Models\TestColumn;
use App\Models\TestPoint;
use App\Models\TestPointValue;
use App\Models\TestProfile;
use App\Models\TestProfileVersion;
use App\TestColumns\ColumnDefinition;
use App\TestColumns\ColumnFormula;
use App\TestColumns\ColumnKey;
use App\TestColumns\DurationValue;
use App\TestColumns\TestColumnWriteException;
use App\TestColumns\TestColumnWriter;
use App\TestColumns\TestPointValueConstraintMessage;
use App\TestColumns\TestPointValueWriteException;
use App\TestColumns\TestPointValueWriter;
use App\TestColumns\TestProfileLifecycle;
use App\TestColumns\TestProfileLifecycleException;
use App\TestColumns\TestTableVersioning;
use App\TestColumns\TestTableVersionWriteException;
use App\TestColumns\TestTableVersionWriter;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Shared Test Components management for the dedicated editor page.
 *
 * @property InstrumentType|null $instrumentType
 */
trait ManagesTestComponents
{
    use DisplaysTestComponents;

    public bool $addingTestTable = false;

    public string $profileName = '';

    public string $displayTitle = '';

    public string $existingDisplayTitle = '';

    public ?string $testTableStatus = null;

    public ?int $managingColumnsProfileId = null;

    public ?int $editingColumnId = null;

    public ?int $deletingColumnId = null;

    public string $columnLabel = '';

    public string $dataType = 'numeric';

    public string $valueSource = 'template';

    public string $unit = '';

    public string $displayScale = '';

    public string $formulaCode = 'average';

    /** @var list<string> */
    public array $formulaInputs = [];

    public string $deviationMinuend = '';

    public string $deviationSubtrahend = '';

    public ?string $columnStatus = null;

    public ?int $editingRowProfileId = null;

    public ?int $editingPointId = null;

    public ?int $deletingPointId = null;

    /** @var array<string, string> */
    public array $templateValues = [];

    public ?string $rowStatus = null;

    public ?int $rowStatusProfileId = null;

    public ?int $selectedProfileId = null;

    public ?int $publishingProfileId = null;

    public ?int $editingProfileId = null;

    public ?int $deletingProfileId = null;

    public ?int $archivingProfileId = null;

    public ?int $restoringProfileId = null;

    public function selectProfile(int $profileId): void
    {
        $this->profileForColumns($profileId);
        $this->resetErrorBag();
        $this->addingTestTable = false;
        $this->reset('profileName', 'displayTitle', 'existingDisplayTitle');
        $this->testTableStatus = null;
        $this->managingColumnsProfileId = null;
        $this->resetColumnForm();
        $this->columnStatus = null;
        $this->resetRowForm();
        $this->rowStatus = null;
        $this->rowStatusProfileId = null;
        $this->publishingProfileId = null;
        $this->editingProfileId = null;
        $this->deletingProfileId = null;
        $this->archivingProfileId = null;
        $this->restoringProfileId = null;
        $this->selectedProfileId = $profileId;
    }

    public function startAddingTestTable(): void
    {
        if ($this->instrumentType === null) {
            return;
        }

        $this->resetErrorBag();
        $this->testTableStatus = null;
        $this->managingColumnsProfileId = null;
        $this->resetColumnForm();
        $this->columnStatus = null;
        $this->resetRowForm();
        $this->rowStatus = null;
        $this->rowStatusProfileId = null;
        $this->publishingProfileId = null;
        $this->editingProfileId = null;
        $this->deletingProfileId = null;
        $this->archivingProfileId = null;
        $this->restoringProfileId = null;
        $this->reset('profileName', 'displayTitle', 'existingDisplayTitle');
        $this->reloadManagedProfiles();

        $activeProfiles = $this->activeTestProfiles();
        $onlyProfile = $activeProfiles->count() === 1
            ? $activeProfiles->first()
            : null;
        $this->existingDisplayTitle = trim((string) ($onlyProfile?->display_title ?? ''));
        $this->addingTestTable = true;
    }

    public function cancelAddingTestTable(): void
    {
        $this->addingTestTable = false;
        $this->resetErrorBag();
        $this->reset('profileName', 'displayTitle', 'existingDisplayTitle');
    }

    public function saveTestTable(): void
    {
        if ($this->instrumentType === null) {
            abort(404);
        }

        $typeId = $this->instrumentType->instrument_type_id;

        try {
            DB::transaction(function () use ($typeId): void {
                $existing = TestProfile::query()
                    ->where('instrument_type_id', $typeId)
                    ->orderBy('test_profile_id')
                    ->lockForUpdate()
                    ->get();
                $existing->load('versions');
                $activeProfiles = $existing
                    ->reject(fn (TestProfile $profile): bool => $profile->isArchived())
                    ->values();

                $validated = validator([
                    'profileName' => $this->profileName,
                    'displayTitle' => $this->displayTitle,
                    'existingDisplayTitle' => $this->existingDisplayTitle,
                ], [
                    'profileName' => ['required', 'string', 'max:150'],
                    'displayTitle' => [$activeProfiles->isNotEmpty() ? 'required' : 'nullable', 'string', 'max:255'],
                    'existingDisplayTitle' => [$activeProfiles->count() === 1 ? 'required' : 'nullable', 'string', 'max:255'],
                ], [
                    'profileName.required' => 'Enter a profile name.',
                    'profileName.max' => 'The profile name must be 150 characters or fewer.',
                    'displayTitle.required' => 'Enter a table title.',
                    'displayTitle.max' => 'The table title must be 255 characters or fewer.',
                    'existingDisplayTitle.required' => 'Enter a title for the existing test table.',
                    'existingDisplayTitle.max' => 'The existing table title must be 255 characters or fewer.',
                ])->validate();

                $name = trim($validated['profileName']);

                if ($name === '') {
                    throw ValidationException::withMessages([
                        'profileName' => 'Enter a profile name.',
                    ]);
                }

                if ($existing->contains(fn (TestProfile $profile): bool => $profile->profile_name === $name)) {
                    throw ValidationException::withMessages([
                        'profileName' => 'A test table with this profile name already exists for this type.',
                    ]);
                }

                $newTitle = $this->blankToNull($validated['displayTitle'] ?? null);

                if ($activeProfiles->count() === 1) {
                    $firstTitle = $this->blankToNull($validated['existingDisplayTitle'] ?? null);

                    if ($firstTitle === null) {
                        throw ValidationException::withMessages([
                            'existingDisplayTitle' => 'Enter a title for the existing test table.',
                        ]);
                    }

                    if ($newTitle === null) {
                        throw ValidationException::withMessages([
                            'displayTitle' => 'Enter a table title.',
                        ]);
                    }

                    if ($this->titlesMatch($firstTitle, $newTitle)) {
                        throw ValidationException::withMessages([
                            'displayTitle' => 'Table titles must be different.',
                        ]);
                    }

                    $activeProfiles->first()->update(['display_title' => $firstTitle]);
                } elseif ($activeProfiles->isNotEmpty() && $newTitle !== null) {
                    $duplicate = $activeProfiles->contains(function (TestProfile $profile) use ($newTitle): bool {
                        return filled($profile->display_title) && $this->titlesMatch((string) $profile->display_title, $newTitle);
                    });

                    if ($duplicate) {
                        throw ValidationException::withMessages([
                            'displayTitle' => 'Table titles must be different.',
                        ]);
                    }
                }

                $created = TestProfile::query()->create([
                    'instrument_type_id' => $typeId,
                    'profile_name' => $name,
                    'display_title' => $newTitle,
                ]);

                app(TestTableVersioning::class)->ensureInitialDraft($created);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'profileName' => 'A test table with this profile name already exists for this type.',
            ]);
        }

        $this->reloadManagedProfiles();
        $this->reset('profileName', 'displayTitle', 'existingDisplayTitle');
        $this->addingTestTable = false;
        $this->testTableStatus = 'Test table added.';

        $created = $this->instrumentType?->testProfiles->last();
        if ($created !== null) {
            $this->selectProfile((int) $created->test_profile_id);
            $this->testTableStatus = 'Test table added.';
        }
    }

    public function tableTitleRequired(): bool
    {
        return $this->activeTestProfiles()->isNotEmpty();
    }

    public function needsExistingTableTitle(): bool
    {
        return $this->activeTestProfiles()->count() === 1;
    }

    public function canPublishTestTable(TestProfile $profile): bool
    {
        $versioning = app(TestTableVersioning::class);

        return $versioning->enabled()
            && ! $profile->isArchived()
            && $versioning->draft($profile) !== null;
    }

    public function canForkTestTable(TestProfile $profile): bool
    {
        $versioning = app(TestTableVersioning::class);

        return $versioning->enabled()
            && ! $profile->isArchived()
            && $versioning->active($profile) !== null
            && $versioning->draft($profile) === null;
    }

    public function canRenameTestTable(TestProfile $profile): bool
    {
        return $this->canEditTestTable($profile);
    }

    public function canPermanentlyDeleteTestTable(TestProfile $profile): bool
    {
        if ($profile->isArchived()) {
            return false;
        }

        if (app(TestTableVersioning::class)->hasRetired($profile)) {
            return false;
        }

        return ! app(TestProfileLifecycle::class)->isUsedInCalibration((int) $profile->test_profile_id);
    }

    public function canArchiveTestTable(TestProfile $profile): bool
    {
        if ($profile->isArchived()) {
            return false;
        }

        if (app(TestTableVersioning::class)->hasRetired($profile)) {
            return true;
        }

        return app(TestProfileLifecycle::class)->isUsedInCalibration((int) $profile->test_profile_id);
    }

    public function canRestoreTestTable(TestProfile $profile): bool
    {
        return $profile->isArchived();
    }

    public function startEditingTestTable(int $profileId): void
    {
        $profile = $this->profileForColumns($profileId);

        if ($profile->isArchived()) {
            throw ValidationException::withMessages([
                'testTable' => 'Archived test tables cannot be edited.',
            ]);
        }

        if (! $this->canRenameTestTable($profile)) {
            throw ValidationException::withMessages([
                'testTable' => 'Fork a draft before renaming this published test table.',
            ]);
        }

        $this->resetErrorBag();
        $this->addingTestTable = false;
        $this->publishingProfileId = null;
        $this->deletingProfileId = null;
        $this->archivingProfileId = null;
        $this->restoringProfileId = null;
        $this->managingColumnsProfileId = null;
        $this->resetColumnForm();
        $this->resetRowForm();
        $this->profileName = $this->testTableProfileName($profile);
        $this->displayTitle = (string) ($this->testTableDisplayTitle($profile) ?? '');
        $this->editingProfileId = $profileId;
        $this->selectedProfileId = $profileId;
        $this->testTableStatus = null;
    }

    public function cancelEditingTestTable(): void
    {
        $this->editingProfileId = null;
        $this->resetErrorBag();
        $this->reset('profileName', 'displayTitle');
    }

    public function saveEditedTestTable(int $profileId): void
    {
        if ($this->instrumentType === null) {
            abort(404);
        }

        $typeId = $this->instrumentType->instrument_type_id;

        try {
            DB::transaction(function () use ($typeId, $profileId): void {
                $existing = TestProfile::query()
                    ->where('instrument_type_id', $typeId)
                    ->orderBy('test_profile_id')
                    ->lockForUpdate()
                    ->get();
                $existing->load('versions');

                $profile = $existing->first(
                    fn (TestProfile $item): bool => (int) $item->test_profile_id === $profileId,
                );

                if ($profile === null) {
                    abort(404);
                }

                if ($profile->isArchived()) {
                    throw ValidationException::withMessages([
                        'profileName' => 'Archived test tables cannot be edited.',
                    ]);
                }

                $versioning = app(TestTableVersioning::class);

                if (! $versioning->canMutate($profile)) {
                    throw ValidationException::withMessages([
                        'profileName' => 'Fork a draft before renaming this published test table.',
                    ]);
                }

                $activeProfiles = $existing
                    ->reject(fn (TestProfile $item): bool => $item->isArchived())
                    ->values();
                $titleRequired = $activeProfiles->count() > 1;

                $validated = validator([
                    'profileName' => $this->profileName,
                    'displayTitle' => $this->displayTitle,
                ], [
                    'profileName' => ['required', 'string', 'max:150'],
                    'displayTitle' => [$titleRequired ? 'required' : 'nullable', 'string', 'max:255'],
                ], [
                    'profileName.required' => 'Enter a profile name.',
                    'profileName.max' => 'The profile name must be 150 characters or fewer.',
                    'displayTitle.required' => 'Enter a table title.',
                    'displayTitle.max' => 'The table title must be 255 characters or fewer.',
                ])->validate();

                $name = trim($validated['profileName']);

                if ($name === '') {
                    throw ValidationException::withMessages([
                        'profileName' => 'Enter a profile name.',
                    ]);
                }

                $newTitle = $this->blankToNull($validated['displayTitle'] ?? null);

                if ($titleRequired && $newTitle === null) {
                    throw ValidationException::withMessages([
                        'displayTitle' => 'Enter a table title.',
                    ]);
                }

                foreach ($existing as $other) {
                    if ((int) $other->test_profile_id === $profileId) {
                        continue;
                    }

                    if ($other->profile_name === $name) {
                        throw ValidationException::withMessages([
                            'profileName' => 'A test table with this profile name already exists for this type.',
                        ]);
                    }

                    $otherDraft = $versioning->draft($other);

                    if ($otherDraft !== null && $otherDraft->profile_name === $name) {
                        throw ValidationException::withMessages([
                            'profileName' => 'A test table with this profile name already exists for this type.',
                        ]);
                    }
                }

                if ($newTitle !== null) {
                    foreach ($activeProfiles as $other) {
                        if ((int) $other->test_profile_id === $profileId) {
                            continue;
                        }

                        $otherTitle = $this->editableTitle($other, $versioning);

                        if (filled($otherTitle) && $this->titlesMatch((string) $otherTitle, $newTitle)) {
                            throw ValidationException::withMessages([
                                'displayTitle' => 'Table titles must be different.',
                            ]);
                        }
                    }
                }

                $draft = $versioning->draft($profile);
                $active = $versioning->active($profile);

                if ($draft !== null) {
                    $lockedDraft = TestProfileVersion::query()
                        ->where('test_profile_version_id', $draft->test_profile_version_id)
                        ->where('status', TestProfileVersion::STATUS_DRAFT)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedDraft === null) {
                        throw ValidationException::withMessages([
                            'profileName' => 'Fork a draft before renaming this published test table.',
                        ]);
                    }

                    $lockedDraft->update([
                        'profile_name' => $name,
                        'display_title' => $newTitle,
                    ]);
                }

                if ($active === null) {
                    $profile->update([
                        'profile_name' => $name,
                        'display_title' => $newTitle,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'profileName' => 'A test table with this profile name already exists for this type.',
            ]);
        }

        $this->editingProfileId = null;
        $this->reset('profileName', 'displayTitle');
        $this->reloadManagedProfiles();
        $this->selectProfile($profileId);
        $this->testTableStatus = 'Test table updated.';
    }

    public function askToDeleteTestTable(int $profileId): void
    {
        $profile = $this->profileForColumns($profileId);

        if (! $this->canPermanentlyDeleteTestTable($profile)) {
            throw ValidationException::withMessages([
                'testTable' => 'This test table cannot be deleted. Archive it instead.',
            ]);
        }

        $this->resetErrorBag();
        $this->addingTestTable = false;
        $this->publishingProfileId = null;
        $this->editingProfileId = null;
        $this->archivingProfileId = null;
        $this->restoringProfileId = null;
        $this->deletingProfileId = $profileId;
        $this->selectedProfileId = $profileId;
        $this->testTableStatus = null;
    }

    public function cancelDeletingTestTable(): void
    {
        $this->deletingProfileId = null;
    }

    public function deleteTestTable(int $profileId, TestProfileLifecycle $lifecycle): void
    {
        if ($this->instrumentType === null) {
            abort(404);
        }

        $typeId = $this->instrumentType->instrument_type_id;

        try {
            DB::transaction(function () use ($typeId, $profileId, $lifecycle): void {
                $existing = TestProfile::query()
                    ->where('instrument_type_id', $typeId)
                    ->orderBy('test_profile_id')
                    ->lockForUpdate()
                    ->get();
                $existing->load('versions');

                $profile = $existing->first(
                    fn (TestProfile $item): bool => (int) $item->test_profile_id === $profileId,
                );

                if ($profile === null) {
                    abort(404);
                }

                if (! $this->canPermanentlyDeleteTestTable($profile)) {
                    throw ValidationException::withMessages([
                        'testTable' => 'This test table cannot be deleted. Archive it instead.',
                    ]);
                }

                $lifecycle->deleteUnused((int) $profile->test_profile_id);
            });
        } catch (TestProfileLifecycleException $exception) {
            throw ValidationException::withMessages([
                $exception->field => $exception->getMessage(),
            ]);
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'testTable' => 'This test table cannot be deleted. Archive it instead.',
            ]);
        }

        $this->deletingProfileId = null;
        $this->reloadManagedProfiles();
        $this->testTableStatus = 'Test table deleted.';
    }

    public function askToArchiveTestTable(int $profileId): void
    {
        $profile = $this->profileForColumns($profileId);

        if (! $this->canArchiveTestTable($profile)) {
            throw ValidationException::withMessages([
                'testTable' => 'This test table cannot be archived.',
            ]);
        }

        $this->resetErrorBag();
        $this->addingTestTable = false;
        $this->publishingProfileId = null;
        $this->editingProfileId = null;
        $this->deletingProfileId = null;
        $this->restoringProfileId = null;
        $this->archivingProfileId = $profileId;
        $this->selectedProfileId = $profileId;
        $this->testTableStatus = null;
    }

    public function cancelArchivingTestTable(): void
    {
        $this->archivingProfileId = null;
    }

    public function archiveTestTable(int $profileId, TestProfileLifecycle $lifecycle): void
    {
        if ($this->instrumentType === null) {
            abort(404);
        }

        $typeId = $this->instrumentType->instrument_type_id;

        try {
            DB::transaction(function () use ($typeId, $profileId, $lifecycle): void {
                $existing = TestProfile::query()
                    ->where('instrument_type_id', $typeId)
                    ->orderBy('test_profile_id')
                    ->lockForUpdate()
                    ->get();
                $existing->load('versions');

                $profile = $existing->first(
                    fn (TestProfile $item): bool => (int) $item->test_profile_id === $profileId,
                );

                if ($profile === null) {
                    abort(404);
                }

                if (! $this->canArchiveTestTable($profile)) {
                    throw ValidationException::withMessages([
                        'testTable' => 'This test table cannot be archived.',
                    ]);
                }

                $lifecycle->archive((int) $profile->test_profile_id);
            });
        } catch (TestProfileLifecycleException $exception) {
            throw ValidationException::withMessages([
                $exception->field => $exception->getMessage(),
            ]);
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'testTable' => 'This test table could not be archived. Refresh the page and try again.',
            ]);
        }

        $this->archivingProfileId = null;
        $this->reloadManagedProfiles();
        $this->selectProfile($profileId);
        $this->testTableStatus = 'Test table archived.';
    }

    public function askToRestoreTestTable(int $profileId): void
    {
        $profile = $this->profileForColumns($profileId);

        if (! $this->canRestoreTestTable($profile)) {
            throw ValidationException::withMessages([
                'testTable' => 'This test table is not archived.',
            ]);
        }

        $this->resetErrorBag();
        $this->addingTestTable = false;
        $this->publishingProfileId = null;
        $this->editingProfileId = null;
        $this->deletingProfileId = null;
        $this->archivingProfileId = null;
        $this->restoringProfileId = $profileId;
        $this->selectedProfileId = $profileId;
        $this->testTableStatus = null;
    }

    public function cancelRestoringTestTable(): void
    {
        $this->restoringProfileId = null;
    }

    public function restoreTestTable(int $profileId, TestProfileLifecycle $lifecycle): void
    {
        if ($this->instrumentType === null) {
            abort(404);
        }

        $typeId = $this->instrumentType->instrument_type_id;

        try {
            DB::transaction(function () use ($typeId, $profileId, $lifecycle): void {
                $existing = TestProfile::query()
                    ->where('instrument_type_id', $typeId)
                    ->orderBy('test_profile_id')
                    ->lockForUpdate()
                    ->get();
                $existing->load('versions');

                $profile = $existing->first(
                    fn (TestProfile $item): bool => (int) $item->test_profile_id === $profileId,
                );

                if ($profile === null) {
                    abort(404);
                }

                if (! $this->canRestoreTestTable($profile)) {
                    throw ValidationException::withMessages([
                        'testTable' => 'This test table is not archived.',
                    ]);
                }

                $this->assertRestoreDoesNotConflict($profile, $existing);

                $lifecycle->restore((int) $profile->test_profile_id);
            });
        } catch (TestProfileLifecycleException $exception) {
            throw ValidationException::withMessages([
                $exception->field => $exception->getMessage(),
            ]);
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'testTable' => 'This test table could not be restored. Refresh the page and try again.',
            ]);
        }

        $this->restoringProfileId = null;
        $this->reloadManagedProfiles();
        $this->selectProfile($profileId);
        $this->testTableStatus = 'Test table restored.';
    }

    /**
     * @param  Collection<int, TestProfile>  $existing
     */
    private function assertRestoreDoesNotConflict(TestProfile $profile, Collection $existing): void
    {
        $versioning = app(TestTableVersioning::class);
        $liveProfiles = $existing
            ->reject(fn (TestProfile $item): bool => $item->isArchived() || (int) $item->test_profile_id === (int) $profile->test_profile_id)
            ->values();

        $restoredName = $this->editableName($profile, $versioning);
        $restoredTitle = $this->editableTitle($profile, $versioning);

        foreach ($liveProfiles as $other) {
            if ($other->profile_name === $restoredName) {
                throw ValidationException::withMessages([
                    'testTable' => 'A test table with this profile name already exists for this type.',
                ]);
            }

            $otherDraft = $versioning->draft($other);

            if ($otherDraft !== null && $otherDraft->profile_name === $restoredName) {
                throw ValidationException::withMessages([
                    'testTable' => 'A test table with this profile name already exists for this type.',
                ]);
            }

            if ($profile->profile_name !== $restoredName && $other->profile_name === $profile->profile_name) {
                throw ValidationException::withMessages([
                    'testTable' => 'A test table with this profile name already exists for this type.',
                ]);
            }
        }

        if ($liveProfiles->isNotEmpty() && $restoredTitle === null) {
            throw ValidationException::withMessages([
                'testTable' => 'Enter a table title before restoring this test table.',
            ]);
        }

        if ($restoredTitle === null) {
            return;
        }

        foreach ($liveProfiles as $other) {
            $otherTitle = $this->editableTitle($other, $versioning);

            if (filled($otherTitle) && $this->titlesMatch((string) $otherTitle, $restoredTitle)) {
                throw ValidationException::withMessages([
                    'testTable' => 'Table titles must be different.',
                ]);
            }
        }
    }

    public function askToPublishVersion(int $profileId): void
    {
        $profile = $this->profileForColumns($profileId);

        if (! $this->canPublishTestTable($profile)) {
            abort(404);
        }

        $this->resetErrorBag();
        $this->publishingProfileId = $profileId;
    }

    public function cancelPublishingVersion(): void
    {
        $this->publishingProfileId = null;
    }

    public function publishVersion(int $profileId, TestTableVersionWriter $writer): void
    {
        $this->resetErrorBag();
        $profile = $this->profileForColumns($profileId);

        if (! app(TestTableVersioning::class)->enabled()) {
            abort(404);
        }

        try {
            $writer->publish((int) $profile->test_profile_id);
        } catch (TestTableVersionWriteException $exception) {
            throw ValidationException::withMessages([
                $exception->field => $exception->getMessage(),
            ]);
        }

        $this->publishingProfileId = null;
        $this->reloadManagedProfiles();
        $this->selectProfile((int) $profile->test_profile_id);
        $this->testTableStatus = 'Version published.';
    }

    public function forkDraft(int $profileId, TestTableVersionWriter $writer): void
    {
        $this->resetErrorBag();
        $profile = $this->profileForColumns($profileId);
        $versioning = app(TestTableVersioning::class);

        if (! $versioning->enabled()) {
            abort(404);
        }

        $active = $versioning->active($profile);

        if ($active === null) {
            throw ValidationException::withMessages([
                'testTable' => 'Fork the active version, not a draft.',
            ]);
        }

        try {
            $writer->fork((int) $active->test_profile_version_id);
        } catch (TestTableVersionWriteException $exception) {
            throw ValidationException::withMessages([
                $exception->field => $exception->getMessage(),
            ]);
        }

        $this->publishingProfileId = null;
        $this->reloadManagedProfiles();
        $this->selectProfile((int) $profile->test_profile_id);
        $this->testTableStatus = 'Draft created.';
    }

    private function reloadManagedProfiles(): void
    {
        $this->loadTestProfiles();
        $this->ensureSelectedProfile();
    }

    private function ensureSelectedProfile(): void
    {
        if ($this->instrumentType === null) {
            $this->selectedProfileId = null;

            return;
        }

        $activeIds = $this->activeTestProfiles()
            ->map(fn (TestProfile $profile): int => (int) $profile->test_profile_id)
            ->all();
        $archivedIds = $this->archivedTestProfiles()
            ->map(fn (TestProfile $profile): int => (int) $profile->test_profile_id)
            ->all();
        $ids = array_merge($activeIds, $archivedIds);

        if ($this->selectedProfileId !== null && in_array((int) $this->selectedProfileId, $ids, true)) {
            return;
        }

        $this->selectedProfileId = $activeIds[0] ?? $archivedIds[0] ?? null;
    }

    public function updatedDataType(string $value): void
    {
        if ($value === 'text' && $this->valueSource === 'calculated') {
            $this->valueSource = 'template';
        }

        if ($value !== 'numeric') {
            $this->displayScale = '';
        }

        if ($value === 'duration' && $this->formulaCode === ColumnFormula::Average) {
            $this->formulaCode = ColumnFormula::StandardMinusReading;
        }
    }

    /**
     * @return list<string>
     */
    public function formulaCodes(): array
    {
        return ColumnFormula::codesFor($this->dataType);
    }

    public function usesPairedFormula(): bool
    {
        return ColumnFormula::usesPairedInputs($this->formulaCode);
    }

    public function formulaPairHint(): string
    {
        return match ($this->formulaCode) {
            ColumnFormula::StandardMinusReading => 'Correction is the standard column minus the reading column.',
            ColumnFormula::ReadingMinusStandard => 'Correction is the reading column minus the standard column.',
            default => 'The result is the first column minus the second column.',
        };
    }

    public function formulaFirstLabel(): string
    {
        return match ($this->formulaCode) {
            ColumnFormula::StandardMinusReading => 'Standard column',
            ColumnFormula::ReadingMinusStandard => 'Reading column',
            default => 'First column',
        };
    }

    public function formulaSecondLabel(): string
    {
        return match ($this->formulaCode) {
            ColumnFormula::StandardMinusReading => 'Reading column',
            ColumnFormula::ReadingMinusStandard => 'Standard column',
            default => 'Second column',
        };
    }

    protected function testTableViewPurpose(): string
    {
        return TestTableVersioning::PURPOSE_MANAGE;
    }

    public function startManagingColumns(int $profileId): void
    {
        $this->assertCanMutateTestTable($this->profileForColumns($profileId));
        $this->addingTestTable = false;
        $this->resetErrorBag();
        $this->testTableStatus = null;
        $this->managingColumnsProfileId = $profileId;
        $this->resetColumnForm();
        $this->columnStatus = null;
        $this->resetRowForm();
        $this->rowStatus = null;
        $this->rowStatusProfileId = null;
    }

    public function cancelManagingColumns(): void
    {
        $this->managingColumnsProfileId = null;
        $this->resetErrorBag();
        $this->resetColumnForm();
        $this->columnStatus = null;
    }

    public function editTestColumn(int $profileId, int $columnId): void
    {
        $this->assertCanMutateTestTable($this->profileForColumns($profileId));
        $column = $this->columnForProfile($profileId, $columnId);
        $inputs = $column->inputKeys();
        $this->managingColumnsProfileId = $profileId;
        $this->editingColumnId = $columnId;
        $this->deletingColumnId = null;
        $this->columnLabel = (string) $column->column_label;
        $this->dataType = (string) $column->data_type;
        $this->valueSource = (string) $column->value_source;
        $this->unit = (string) ($column->unit ?? '');
        $this->displayScale = $column->display_scale === null ? '' : (string) $column->display_scale;
        $this->formulaCode = filled($column->formula_code) ? (string) $column->formula_code : 'average';
        $this->formulaInputs = $this->formulaCode === 'average' ? $inputs : [];
        $this->deviationMinuend = $this->formulaCode === 'deviation' ? (string) ($inputs[0] ?? '') : '';
        $this->deviationSubtrahend = $this->formulaCode === 'deviation' ? (string) ($inputs[1] ?? '') : '';
        $this->resetErrorBag();
        $this->columnStatus = null;
    }

    public function askToDeleteTestColumn(int $profileId, int $columnId): void
    {
        $this->assertCanMutateTestTable($this->profileForColumns($profileId));
        $this->columnForProfile($profileId, $columnId);
        $this->managingColumnsProfileId = $profileId;
        $this->deletingColumnId = $columnId;
        $this->resetErrorBag();
    }

    public function cancelDeletingTestColumn(): void
    {
        $this->deletingColumnId = null;
    }

    public function saveTestColumn(TestColumnWriter $columns): void
    {
        $this->resetErrorBag();
        $profile = $this->profileForColumns((int) $this->managingColumnsProfileId);
        $this->assertCanMutateTestTable($profile);
        $existing = $this->editingColumnId === null
            ? null
            : $this->columnForProfile((int) $profile->test_profile_id, (int) $this->editingColumnId);
        $validated = $this->validatedColumn($profile, $existing);

        $definition = new ColumnDefinition(
            profileId: (int) $profile->test_profile_id,
            columnKey: $existing === null
                ? ColumnKey::fromLabel($validated['columnLabel'], $profile->testColumns->pluck('column_key')->all())
                : (string) $existing->column_key,
            columnLabel: $validated['columnLabel'],
            columnOrder: $existing === null
                ? ((int) $profile->testColumns->max('column_order')) + 1
                : (int) $existing->column_order,
            dataType: $validated['dataType'],
            valueSource: $validated['valueSource'],
            unit: $validated['unit'],
            displayScale: $validated['displayScale'],
            formulaCode: $validated['formulaCode'],
            formulaInputs: $validated['formulaInputs'],
        );

        try {
            DB::transaction(function () use ($columns, $definition, $existing): void {
                if ($existing === null) {
                    $columns->insert($definition);

                    return;
                }

                $columns->update((int) $existing->test_column_id, $definition);
            });
        } catch (TestColumnWriteException $exception) {
            throw ValidationException::withMessages([
                $exception->field => $exception->getMessage(),
            ]);
        }

        $this->reloadManagedProfiles();
        $this->resetColumnForm();
        $this->columnStatus = $existing === null ? 'Column added.' : 'Column updated.';
    }

    public function deleteTestColumn(TestColumnWriter $columns): void
    {
        $this->resetErrorBag();

        if ($this->deletingColumnId === null || $this->managingColumnsProfileId === null) {
            return;
        }

        $profile = $this->profileForColumns((int) $this->managingColumnsProfileId);
        $this->assertCanMutateTestTable($profile);
        $column = $this->columnForProfile((int) $profile->test_profile_id, (int) $this->deletingColumnId);

        if ($this->columnIsFormulaInput($profile, $column)) {
            throw ValidationException::withMessages([
                'columns' => 'This column is used by a calculated column. Change that formula before deleting it.',
            ]);
        }

        $remaining = $profile->testColumns
            ->reject(fn (TestColumn $item): bool => (int) $item->test_column_id === (int) $column->test_column_id)
            ->map(fn (TestColumn $item): int => (int) $item->test_column_id)
            ->values()
            ->all();

        try {
            DB::transaction(function () use ($columns, $profile, $column, $remaining): void {
                $columns->delete((int) $column->test_column_id);

                if ($remaining !== []) {
                    $columns->reorder((int) $profile->test_profile_id, $remaining);
                }
            });
        } catch (TestColumnWriteException $exception) {
            throw ValidationException::withMessages([
                'columns' => $exception->getMessage(),
            ]);
        }

        $this->reloadManagedProfiles();
        $this->resetColumnForm();
        $this->columnStatus = 'Column deleted.';
    }

    public function moveTestColumn(TestColumnWriter $columns, int $profileId, int $columnId, string $direction): void
    {
        $this->resetErrorBag();

        if (! in_array($direction, ['up', 'down'], true)) {
            abort(404);
        }

        $profile = $this->profileForColumns($profileId);
        $this->assertCanMutateTestTable($profile);
        $ids = $profile->testColumns
            ->map(fn (TestColumn $column): int => (int) $column->test_column_id)
            ->values()
            ->all();
        $index = array_search($columnId, $ids, true);

        if ($index === false) {
            abort(404);
        }

        $swap = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swap < 0 || $swap >= count($ids)) {
            return;
        }

        [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];

        try {
            DB::transaction(fn () => $columns->reorder($profileId, $ids));
        } catch (TestColumnWriteException $exception) {
            throw ValidationException::withMessages([
                'columns' => $exception->getMessage(),
            ]);
        }

        $this->managingColumnsProfileId = $profileId;
        $this->reloadManagedProfiles();
        $this->columnStatus = 'Column order updated.';
    }

    /**
     * @return Collection<int, TestColumn>
     */
    public function formulaSources(): Collection
    {
        $profile = $this->managedProfile();

        if ($profile === null) {
            return new Collection;
        }

        $neededType = $this->dataType === 'duration' ? 'duration' : 'numeric';

        return $profile->testColumns
            ->filter(function (TestColumn $column) use ($neededType): bool {
                if ($this->editingColumnId !== null && (int) $column->test_column_id === (int) $this->editingColumnId) {
                    return false;
                }

                return $column->data_type === $neededType
                    && in_array($column->value_source, ['template', 'engineer'], true);
            })
            ->values();
    }

    public function columnSummary(TestColumn $column): string
    {
        $parts = [(string) $column->data_type, (string) $column->value_source];

        if (filled($column->unit)) {
            $parts[] = (string) $column->unit;
        }

        if ($column->data_type === 'numeric' && $column->display_scale !== null && $column->display_scale !== '') {
            $parts[] = 'scale '.$column->display_scale;
        }

        if ($column->data_type === 'duration') {
            $parts[] = 'hh:mm:ss';
        }

        if ($column->value_source === 'calculated' && filled($column->formula_code)) {
            $inputs = $column->inputKeys();
            $parts[] = $column->formula_code.' of '.($inputs === [] ? '—' : implode(', ', $inputs));
        }

        return implode(', ', $parts);
    }

    private function profileForColumns(int $profileId): TestProfile
    {
        $profile = $this->managedTypeProfiles()->first(
            fn (TestProfile $profile): bool => (int) $profile->test_profile_id === $profileId,
        );

        if ($profile === null) {
            abort(404);
        }

        return $profile;
    }

    private function columnForProfile(int $profileId, int $columnId): TestColumn
    {
        $column = $this->profileForColumns($profileId)->testColumns->first(
            fn (TestColumn $column): bool => (int) $column->test_column_id === $columnId,
        );

        if (! $column instanceof TestColumn) {
            abort(404);
        }

        return $column;
    }

    private function managedProfile(): ?TestProfile
    {
        if ($this->managingColumnsProfileId === null) {
            return null;
        }

        return $this->managedTypeProfiles()->first(
            fn (TestProfile $profile): bool => (int) $profile->test_profile_id === (int) $this->managingColumnsProfileId,
        );
    }

    /**
     * @return Collection<int, TestProfile>
     */
    private function managedTypeProfiles(): Collection
    {
        if ($this->instrumentType === null) {
            return new Collection;
        }

        /** @var Collection<int, TestProfile> $profiles */
        $profiles = $this->instrumentType->testProfiles;

        return $profiles;
    }

    private function columnIsFormulaInput(TestProfile $profile, TestColumn $column): bool
    {
        return $profile->testColumns->contains(function (TestColumn $other) use ($column): bool {
            return (int) $other->test_column_id !== (int) $column->test_column_id
                && $other->value_source === 'calculated'
                && in_array((string) $column->column_key, $other->inputKeys(), true);
        });
    }

    private function columnHasTemplateValues(TestProfile $profile, TestColumn $column): bool
    {
        return $profile->testPoints->contains(function (TestPoint $point) use ($column): bool {
            return $point->testPointValues->contains(
                fn (TestPointValue $value): bool => (int) $value->test_column_id === (int) $column->test_column_id,
            );
        });
    }

    /**
     * @return array{columnLabel: string, dataType: string, valueSource: string, unit: ?string, displayScale: ?int, formulaCode: ?string, formulaInputs: list<string>}
     */
    private function validatedColumn(TestProfile $profile, ?TestColumn $existing): array
    {
        $allowed = $this->formulaSources()
            ->map(fn (TestColumn $column): string => (string) $column->column_key)
            ->all();
        $calculated = $this->valueSource === 'calculated';

        if ($calculated && ! in_array($this->dataType, ['numeric', 'duration'], true)) {
            throw ValidationException::withMessages([
                'valueSource' => 'A calculated column must be numeric or duration.',
            ]);
        }

        $paired = $calculated && ColumnFormula::usesPairedInputs($this->formulaCode);
        $formulaCodes = ColumnFormula::codesFor($this->dataType);
        $sourceKind = $this->dataType === 'duration' ? 'duration' : 'numeric';
        $sourceHint = 'Formula columns must be '.$sourceKind.' template or engineer columns on this test table.';

        $validated = validator([
            'columnLabel' => $this->columnLabel,
            'dataType' => $this->dataType,
            'valueSource' => $this->valueSource,
            'unit' => $this->blankToNull($this->unit),
            'displayScale' => $this->blankToNull($this->displayScale),
            'formulaCode' => $calculated ? $this->formulaCode : null,
            'formulaInputs' => $calculated && $this->formulaCode === ColumnFormula::Average ? $this->formulaInputs : [],
            'deviationMinuend' => $paired ? $this->deviationMinuend : null,
            'deviationSubtrahend' => $paired ? $this->deviationSubtrahend : null,
        ], [
            'columnLabel' => ['required', 'string', 'max:255'],
            'dataType' => ['required', Rule::in(['numeric', 'text', 'duration'])],
            'valueSource' => ['required', Rule::in(['template', 'engineer', 'calculated'])],
            'unit' => ['nullable', 'string', 'max:50'],
            'displayScale' => [Rule::excludeIf($this->dataType !== 'numeric'), 'nullable', 'integer', 'between:0,6'],
            'formulaCode' => [Rule::excludeIf(! $calculated), Rule::requiredIf($calculated), Rule::in($formulaCodes)],
            'formulaInputs' => [Rule::excludeIf(! $calculated || $this->formulaCode !== ColumnFormula::Average), 'array', 'min:1'],
            'formulaInputs.*' => [Rule::in($allowed)],
            'deviationMinuend' => [Rule::requiredIf($paired), 'nullable', 'string', Rule::in($allowed)],
            'deviationSubtrahend' => [Rule::requiredIf($paired), 'nullable', 'string', Rule::in($allowed)],
        ], [
            'columnLabel.required' => 'Enter a column label.',
            'columnLabel.max' => 'The column label must be 255 characters or fewer.',
            'dataType.in' => 'Choose numeric, text, or duration.',
            'valueSource.in' => 'Choose template, engineer, or calculated.',
            'unit.max' => 'The unit must be 50 characters or fewer.',
            'displayScale.integer' => 'Decimal precision must be a whole number from 0 to 6.',
            'displayScale.between' => 'Decimal precision must be a whole number from 0 to 6.',
            'formulaCode.required' => 'Choose a formula.',
            'formulaCode.in' => 'Choose a formula that matches this column type.',
            'formulaInputs.required' => 'Choose at least one numeric column for the average.',
            'formulaInputs.min' => 'Choose at least one numeric column for the average.',
            'formulaInputs.*.in' => $sourceHint,
            'deviationMinuend.required' => 'Choose the first formula column.',
            'deviationMinuend.in' => $sourceHint,
            'deviationSubtrahend.required' => 'Choose the second formula column.',
            'deviationSubtrahend.in' => $sourceHint,
        ])->validate();

        $label = trim((string) $validated['columnLabel']);

        if ($label === '') {
            throw ValidationException::withMessages([
                'columnLabel' => 'Enter a column label.',
            ]);
        }

        if ($calculated && $formulaCodes === []) {
            throw ValidationException::withMessages([
                'valueSource' => 'A calculated column must be numeric or duration.',
            ]);
        }

        if ($calculated && $allowed === []) {
            throw ValidationException::withMessages([
                'valueSource' => 'Add a '.$sourceKind.' template or engineer column before a calculated column.',
            ]);
        }

        $inputs = array_values($validated['formulaInputs'] ?? []);

        if ($calculated && $this->formulaCode === ColumnFormula::Average && count($inputs) !== count(array_unique($inputs))) {
            throw ValidationException::withMessages([
                'formulaInputs' => 'Choose each average column only once.',
            ]);
        }

        if ($paired) {
            if ($validated['deviationMinuend'] === $validated['deviationSubtrahend']) {
                throw ValidationException::withMessages([
                    'deviationSubtrahend' => 'The two formula columns must be different.',
                ]);
            }

            $inputs = [$validated['deviationMinuend'], $validated['deviationSubtrahend']];
        }

        if (! $calculated) {
            $inputs = [];
        }

        if ($existing !== null && $this->columnHasTemplateValues($profile, $existing) && ($existing->data_type !== $validated['dataType'] || $existing->value_source !== $validated['valueSource'])) {
            throw ValidationException::withMessages([
                'dataType' => 'This column already has template values, so its type and source stay as they are.',
            ]);
        }

        $keepsFormulaSource = in_array($validated['dataType'], ['numeric', 'duration'], true)
            && in_array($validated['valueSource'], ['template', 'engineer'], true)
            && ($existing === null || $existing->data_type === $validated['dataType']);

        if ($existing !== null && ! $keepsFormulaSource && $this->columnIsFormulaInput($profile, $existing)) {
            throw ValidationException::withMessages([
                'valueSource' => 'This column is used by a calculated column, so it must stay a template or engineer column of the same type.',
            ]);
        }

        return [
            'columnLabel' => $label,
            'dataType' => $validated['dataType'],
            'valueSource' => $validated['valueSource'],
            'unit' => $validated['unit'] ?? null,
            'displayScale' => $validated['dataType'] === 'numeric' && isset($validated['displayScale'])
                ? (int) $validated['displayScale']
                : null,
            'formulaCode' => $calculated ? $validated['formulaCode'] : null,
            'formulaInputs' => $inputs,
        ];
    }

    private function resetColumnForm(): void
    {
        $this->editingColumnId = null;
        $this->deletingColumnId = null;
        $this->reset('columnLabel', 'dataType', 'valueSource', 'unit', 'displayScale', 'formulaCode', 'formulaInputs', 'deviationMinuend', 'deviationSubtrahend');
    }

    public function startAddingRow(int $profileId): void
    {
        $profile = $this->profileForColumns($profileId);
        $this->assertCanMutateTestTable($profile);

        if ($profile->testColumns->isEmpty()) {
            abort(404);
        }

        $this->addingTestTable = false;
        $this->managingColumnsProfileId = null;
        $this->resetColumnForm();
        $this->columnStatus = null;
        $this->resetErrorBag();
        $this->deletingPointId = null;

        try {
            $pointId = DB::transaction(function () use ($profile): int {
                $locked = TestProfile::query()
                    ->whereKey($profile->test_profile_id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    abort(404);
                }

                $versionId = app(TestTableVersioning::class)->draftId((int) $locked->test_profile_id);
                $orderQuery = TestPoint::query()->where('test_profile_id', $locked->test_profile_id);

                if ($versionId !== null) {
                    $orderQuery->where('test_profile_version_id', $versionId);
                } elseif (app(TestTableVersioning::class)->enabled()) {
                    $orderQuery->whereNull('test_profile_version_id');
                }

                $nextOrder = ((int) $orderQuery->max('point_order')) + 1;
                $attributes = [
                    'test_profile_id' => $locked->test_profile_id,
                    'point_order' => $nextOrder,
                ];

                if ($versionId !== null) {
                    $attributes['test_profile_version_id'] = $versionId;
                }

                return (int) TestPoint::query()->create($attributes)->test_point_id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'rows' => 'The row order conflicts with another row. Refresh the page and try again.',
            ]);
        }

        $this->reloadManagedProfiles();
        $this->editTestRow($profileId, $pointId);
        $this->rowStatusProfileId = $profileId;
        $this->rowStatus = 'Row added.';
    }

    public function editTestRow(int $profileId, int $pointId): void
    {
        $profile = $this->profileForColumns($profileId);
        $this->assertCanMutateTestTable($profile);
        $point = $this->pointForProfile($profile, $pointId);

        if ($profile->testColumns->isEmpty()) {
            abort(404);
        }

        $this->addingTestTable = false;
        $this->managingColumnsProfileId = null;
        $this->resetColumnForm();
        $this->columnStatus = null;
        $this->editingRowProfileId = $profileId;
        $this->editingPointId = $pointId;
        $this->deletingPointId = null;
        $this->templateValues = [];

        foreach ($profile->testColumns as $column) {
            if ($column->value_source !== 'template') {
                continue;
            }

            $cell = $point->testPointValues->first(
                fn (TestPointValue $value): bool => (int) $value->test_column_id === (int) $column->test_column_id,
            );
            $this->templateValues[(string) $column->test_column_id] = $this->templateInputValue($column, $cell);
        }

        $this->resetErrorBag();
        $this->rowStatus = null;
    }

    public function cancelEditingRow(): void
    {
        $this->resetRowForm();
        $this->resetErrorBag();
        $this->rowStatus = null;
        $this->rowStatusProfileId = null;
    }

    public function askToDeleteTestRow(int $profileId, int $pointId): void
    {
        $profile = $this->profileForColumns($profileId);
        $this->assertCanMutateTestTable($profile);
        $this->pointForProfile($profile, $pointId);
        $this->editingRowProfileId = $profileId;
        $this->deletingPointId = $pointId;
        $this->rowStatusProfileId = $profileId;
        $this->resetErrorBag();
    }

    public function cancelDeletingTestRow(): void
    {
        $this->deletingPointId = null;
    }

    public function saveTestRow(TestPointValueWriter $values): void
    {
        $this->resetErrorBag();

        if ($this->editingRowProfileId === null || $this->editingPointId === null) {
            return;
        }

        $profile = $this->profileForColumns((int) $this->editingRowProfileId);
        $this->assertCanMutateTestTable($profile);
        $point = $this->pointForProfile($profile, (int) $this->editingPointId);
        $payload = $this->validatedTemplateValues($profile);

        try {
            DB::transaction(function () use ($values, $profile, $point, $payload): void {
                foreach ($payload as $columnId => $cell) {
                    if ($cell['empty']) {
                        $values->delete(
                            (int) $profile->test_profile_id,
                            (int) $point->test_point_id,
                            $columnId,
                        );

                        continue;
                    }

                    $values->upsert(
                        (int) $profile->test_profile_id,
                        (int) $point->test_point_id,
                        $columnId,
                        $cell['numeric'],
                        $cell['text'],
                    );
                }
            });
        } catch (TestPointValueWriteException $exception) {
            throw ValidationException::withMessages([
                $exception->field => $exception->getMessage(),
            ]);
        }

        $this->reloadManagedProfiles();
        $profileId = (int) $profile->test_profile_id;
        $this->resetRowForm();
        $this->rowStatusProfileId = $profileId;
        $this->rowStatus = 'Row saved.';
    }

    public function deleteTestRow(): void
    {
        $this->resetErrorBag();

        if ($this->deletingPointId === null || $this->editingRowProfileId === null) {
            return;
        }

        $profile = $this->profileForColumns((int) $this->editingRowProfileId);
        $this->assertCanMutateTestTable($profile);
        $point = $this->pointForProfile($profile, (int) $this->deletingPointId);
        $profileId = (int) $profile->test_profile_id;
        $versionId = app(TestTableVersioning::class)->draftId($profileId);

        try {
            DB::transaction(function () use ($profile, $point, $versionId): void {
                $valueQuery = TestPointValue::query()
                    ->where('test_profile_id', $profile->test_profile_id)
                    ->where('test_point_id', $point->test_point_id);

                if ($versionId !== null) {
                    $valueQuery->where('test_profile_version_id', $versionId);
                }

                $valueQuery->delete();

                $point->delete();

                $remainingQuery = TestPoint::query()->where('test_profile_id', $profile->test_profile_id);

                if ($versionId !== null) {
                    $remainingQuery->where('test_profile_version_id', $versionId);
                } elseif (app(TestTableVersioning::class)->enabled()) {
                    $remainingQuery->whereNull('test_profile_version_id');
                }

                $remaining = $remainingQuery
                    ->orderBy('point_order')
                    ->orderBy('test_point_id')
                    ->pluck('test_point_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                $this->rewritePointOrder((int) $profile->test_profile_id, $remaining, $versionId);
            });
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'rows' => TestPointValueConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            ]);
        }

        $this->reloadManagedProfiles();
        $this->resetRowForm();
        $this->rowStatusProfileId = $profileId;
        $this->rowStatus = 'Row deleted.';
    }

    public function moveTestRow(int $profileId, int $pointId, string $direction): void
    {
        $this->resetErrorBag();

        if (! in_array($direction, ['up', 'down'], true)) {
            abort(404);
        }

        $profile = $this->profileForColumns($profileId);
        $this->assertCanMutateTestTable($profile);
        $this->pointForProfile($profile, $pointId);
        $ids = $profile->testPoints
            ->map(fn (TestPoint $point): int => (int) $point->test_point_id)
            ->values()
            ->all();
        $index = array_search($pointId, $ids, true);

        if ($index === false) {
            abort(404);
        }

        $swap = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swap < 0 || $swap >= count($ids)) {
            return;
        }

        [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];

        try {
            DB::transaction(fn () => $this->rewritePointOrder(
                $profileId,
                $ids,
                app(TestTableVersioning::class)->draftId($profileId),
            ));
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'rows' => TestPointValueConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            ]);
        }

        $this->editingRowProfileId = $profileId;
        $this->rowStatusProfileId = $profileId;
        $this->reloadManagedProfiles();
        $this->rowStatus = 'Row order updated.';
    }

    private function pointForProfile(TestProfile $profile, int $pointId): TestPoint
    {
        $point = $profile->testPoints->first(
            fn (TestPoint $point): bool => (int) $point->test_point_id === $pointId,
        );

        if (! $point instanceof TestPoint) {
            abort(404);
        }

        return $point;
    }

    /**
     * @return array<int, array{empty: bool, numeric: ?string, text: ?string}>
     */
    private function validatedTemplateValues(TestProfile $profile): array
    {
        $rules = [];
        $messages = [];
        $values = [];

        foreach ($profile->testColumns as $column) {
            if ($column->value_source !== 'template') {
                continue;
            }

            $key = (string) $column->test_column_id;
            $values[$key] = $this->templateValues[$key] ?? '';
            $ruleKey = 'templateValues.'.$key;

            if ($column->data_type === 'numeric') {
                $rules[$ruleKey] = ['nullable', 'numeric'];
                $messages[$ruleKey.'.numeric'] = 'Enter a number for '.$column->column_label.'.';
            } elseif ($column->data_type === 'duration') {
                $rules[$ruleKey] = ['nullable', 'string'];
            } else {
                $rules[$ruleKey] = ['nullable', 'string', 'max:1000'];
                $messages[$ruleKey.'.max'] = 'The value for '.$column->column_label.' must be 1000 characters or fewer.';
            }
        }

        $validated = validator(['templateValues' => $values], $rules, $messages)->validate();
        $payload = [];

        foreach ($profile->testColumns as $column) {
            if ($column->value_source !== 'template') {
                continue;
            }

            $key = (string) $column->test_column_id;
            $raw = $validated['templateValues'][$key] ?? null;

            if (! is_string($raw) && ! is_numeric($raw)) {
                $payload[(int) $column->test_column_id] = [
                    'empty' => true,
                    'numeric' => null,
                    'text' => null,
                ];

                continue;
            }

            $trimmed = trim((string) $raw);

            if ($trimmed === '') {
                $payload[(int) $column->test_column_id] = [
                    'empty' => true,
                    'numeric' => null,
                    'text' => null,
                ];

                continue;
            }

            if ($column->data_type === 'duration') {
                try {
                    $seconds = DurationValue::parse($trimmed);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages([
                        'templateValues.'.$key => 'Enter a duration as hh:mm:ss for '.$column->column_label.'.',
                    ]);
                }

                $payload[(int) $column->test_column_id] = [
                    'empty' => false,
                    'numeric' => (string) $seconds,
                    'text' => null,
                ];

                continue;
            }

            $payload[(int) $column->test_column_id] = [
                'empty' => false,
                'numeric' => $column->data_type === 'numeric' ? $trimmed : null,
                'text' => $column->data_type === 'text' ? $trimmed : null,
            ];
        }

        return $payload;
    }

    private function templateInputValue(TestColumn $column, ?TestPointValue $cell): string
    {
        if ($column->data_type === 'text') {
            return filled($cell?->text_value) ? (string) $cell->text_value : '';
        }

        if (! filled($cell?->numeric_value)) {
            return '';
        }

        if ($column->data_type === 'duration') {
            return DurationValue::format((int) $cell->numeric_value);
        }

        return (string) $cell->numeric_value;
    }

    /**
     * @param  list<int>  $pointIds
     */
    private function rewritePointOrder(int $profileId, array $pointIds, ?int $versionId = null): void
    {
        if ($pointIds === []) {
            return;
        }

        $case = [];
        $bindings = [];

        foreach ($pointIds as $index => $id) {
            $case[] = 'when test_point_id = ? then ?';
            $bindings[] = $id;
            $bindings[] = $index + 1;
        }

        $sql = 'update test_point set point_order = case '.implode(' ', $case).' end where test_profile_id = ?';
        $bindings[] = $profileId;

        if ($versionId !== null) {
            $sql .= ' and test_profile_version_id = ?';
            $bindings[] = $versionId;
        } elseif (app(TestTableVersioning::class)->enabled()) {
            $sql .= ' and test_profile_version_id is null';
        }

        DB::update($sql, $bindings);
    }

    private function assertCanMutateTestTable(TestProfile $profile): void
    {
        if ($profile->isArchived()) {
            throw ValidationException::withMessages([
                'testTable' => 'Archived test tables are read-only.',
            ]);
        }

        if (app(TestTableVersioning::class)->canMutate($profile)) {
            return;
        }

        throw ValidationException::withMessages([
            'testTable' => 'This published test table is read-only.',
        ]);
    }

    private function editableName(TestProfile $profile, TestTableVersioning $versioning): string
    {
        $draft = $versioning->draft($profile);

        if ($draft !== null) {
            return $draft->profile_name;
        }

        return $profile->profile_name;
    }

    private function editableTitle(TestProfile $profile, TestTableVersioning $versioning): ?string
    {
        $draft = $versioning->draft($profile);

        if ($draft !== null) {
            return $draft->display_title;
        }

        return $profile->display_title;
    }

    private function resetRowForm(): void
    {
        $this->editingRowProfileId = null;
        $this->editingPointId = null;
        $this->deletingPointId = null;
        $this->templateValues = [];
    }

    private function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function titlesMatch(string $left, string $right): bool
    {
        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }
}
