<?php

use App\Activities\CalibrationActivityMasterException;
use App\Activities\CalibrationActivityMasterWriter;
use App\Authorization\AuthorizesMasterDataWrites;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Models\CalibrationActivityMaster;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use AuthorizesMasterDataWrites;
    use WithPagination;

    public string $search = '';

    public bool $adding = false;

    public ?int $editingActivityId = null;

    public ?int $removingActivityId = null;

    public string $activityName = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startAdding(): void
    {
        $this->authorizeMasterDataManage();

        $this->adding = true;
        $this->editingActivityId = null;
        $this->removingActivityId = null;
        $this->activityName = '';
        $this->resetValidation();
    }

    public function cancelForm(): void
    {
        $this->adding = false;
        $this->editingActivityId = null;
        $this->removingActivityId = null;
        $this->activityName = '';
        $this->resetValidation();
    }

    public function prepareActivitySave(): void
    {
        $this->authorizeMasterDataManage();

        $this->validateActivityForm();

        $activityCode = $this->editingActivityId === null
            ? null
            : CalibrationActivityMaster::on($this->readConnectionName())
                ->where('activity_id', $this->editingActivityId)
                ->value('activity_code');

        $this->dispatch(
            'instruments-desk-confirm-activity-master-save',
            componentId: $this->getId(),
            mode: $this->editingActivityId === null ? 'create' : 'update',
            activityId: $this->editingActivityId,
            activityCode: $activityCode,
            activityName: $this->activityName,
        );
    }

    /**
     * @return array{created?: bool, updated?: bool, validation?: bool, message?: string}
     */
    public function saveActivity(): array
    {
        $this->authorizeMasterDataManage();

        try {
            $this->validateActivityForm();
        } catch (\Illuminate\Validation\ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return ['validation' => true];
        }

        $writer = app(CalibrationActivityMasterWriter::class);

        try {
            if ($this->editingActivityId !== null) {
                $writer->update($this->editingActivityId, $this->activityName);
                $result = ['updated' => true];
            } else {
                $writer->create($this->activityName);
                $result = ['created' => true];
            }
        } catch (CalibrationActivityMasterException $exception) {
            $this->addError($exception->field === 'activity' ? 'activityName' : $exception->field, $exception->getMessage());

            return ['validation' => true, 'message' => $exception->getMessage()];
        }

        $this->cancelForm();
        $this->resetPage();

        return $result;
    }

    public function startEditing(int $activityId): void
    {
        $this->authorizeMasterDataManage();

        $activity = CalibrationActivityMaster::on($this->readConnectionName())->find($activityId);

        if ($activity === null) {
            $this->addError('activity', 'This activity could not be found.');

            return;
        }

        $this->editingActivityId = $activityId;
        $this->adding = false;
        $this->removingActivityId = null;
        $this->activityName = $activity->activity_name;
        $this->resetValidation();
    }

    public function prepareActivityStatusChange(int $activityId, bool $activate): void
    {
        $this->authorizeMasterDataManage();

        $activity = CalibrationActivityMaster::on($this->readConnectionName())->find($activityId);

        if ($activity === null) {
            $this->dispatch('instruments-desk-notification', type: 'error', message: 'This activity could not be found.');

            return;
        }

        $this->dispatch(
            $activate ? 'instruments-desk-confirm-activity-reactivate' : 'instruments-desk-confirm-activity-deactivate',
            componentId: $this->getId(),
            activityId: $activityId,
            activityCode: $activity->activity_code,
            activityName: $activity->activity_name,
        );
    }

    /**
     * @return array{deactivated?: bool, message?: string}
     */
    public function deactivateActivity(int $activityId): array
    {
        $this->authorizeMasterDataManage();

        try {
            app(CalibrationActivityMasterWriter::class)->deactivate($activityId);
        } catch (CalibrationActivityMasterException $exception) {
            return ['deactivated' => false, 'message' => $exception->getMessage()];
        }

        $this->cancelForm();

        return ['deactivated' => true];
    }

    /**
     * @return array{reactivated?: bool, message?: string}
     */
    public function activateActivity(int $activityId): array
    {
        $this->authorizeMasterDataManage();

        try {
            app(CalibrationActivityMasterWriter::class)->activate($activityId);
        } catch (CalibrationActivityMasterException $exception) {
            return ['reactivated' => false, 'message' => $exception->getMessage()];
        }

        $this->cancelForm();

        return ['reactivated' => true];
    }

    public function askToRemove(int $activityId): void
    {
        $this->authorizeMasterDataManage();

        $this->removingActivityId = $activityId;
        $this->adding = false;
        $this->editingActivityId = null;
        $this->resetValidation();
    }

    public function prepareActivityDelete(int $activityId): void
    {
        $this->authorizeMasterDataManage();

        $activity = CalibrationActivityMaster::on($this->readConnectionName())->find($activityId);

        if ($activity === null) {
            $this->dispatch('instruments-desk-notification', type: 'error', message: 'This activity could not be found.');

            return;
        }

        $this->removingActivityId = $activityId;
        $this->adding = false;
        $this->editingActivityId = null;
        $this->dispatch(
            'instruments-desk-confirm-activity-delete',
            componentId: $this->getId(),
            activityId: $activityId,
            activityCode: $activity->activity_code,
            activityName: $activity->activity_name,
        );
    }

    /**
     * @return array{deleted?: bool, message?: string}
     */
    public function removeActivity(): array
    {
        $this->authorizeMasterDataManage();

        if ($this->removingActivityId === null) {
            return ['deleted' => false, 'message' => 'This activity could not be found.'];
        }

        try {
            app(CalibrationActivityMasterWriter::class)->delete($this->removingActivityId);
        } catch (CalibrationActivityMasterException $exception) {
            $message = $exception->getMessage();
            $this->removingActivityId = null;

            return ['deleted' => false, 'message' => $message];
        }

        $this->removingActivityId = null;
        $this->resetPage();

        return ['deleted' => true];
    }

    /**
     * @return LengthAwarePaginator<int, CalibrationActivityMaster>
     */
    public function activities(): LengthAwarePaginator
    {
        return CalibrationActivityMaster::on($this->readConnectionName())
            ->whereActivityMasterMatches($this->search)
            ->orderBy('activity_code')
            ->paginate(10);
    }

    private function readConnectionName(): string
    {
        return app(CalibrationSessionHttpRead::class)->connectionName();
    }

    private function validateActivityForm(): void
    {
        $this->validate([
            'activityName' => ['required', 'string', 'max:'.CalibrationActivityMasterWriter::NAME_MAX],
        ], [
            'activityName.required' => 'Activity Name is required.',
        ]);
    }
};
?>

<div
    class="px-4 py-8 sm:px-6 lg:px-10"
    data-component-id="{{ $this->getId() }}"
    x-data="{
        adding: $wire.entangle('adding'),
        editingActivityId: $wire.entangle('editingActivityId'),
        get activityModalOpen() { return this.adding || this.editingActivityId !== null; },
        requestCloseActivityModal() {
            window.instrumentsDeskNotifications.activityMasterDiscardConfirmation({ componentId: $root.dataset.componentId });
        },
    }"
    x-on:keydown.escape.window="if (activityModalOpen) requestCloseActivityModal()"
    x-init="$watch('activityModalOpen', (open) => $nextTick(() => open ? $refs.activityName?.focus() : $refs.addActivityButton?.focus()))"
>
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight text-navy">Calibration Activities</h1>
            <p class="mt-1 text-sm text-muted">Register master activities independently of calibration sessions.</p>
        </header>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="activity-master-heading">
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="activity-master-heading" class="text-base font-semibold tracking-tight text-navy">Activity Master</h2>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label for="activity-master-search" class="sr-only">Search by name or activity code</label>
                    <input id="activity-master-search" type="search" wire:model.live.debounce.300ms="search" maxlength="100" class="ui-input" placeholder="Name or activity code">
                    @can('masterdata.manage')
                        <button x-ref="addActivityButton" type="button" wire:click="startAdding" class="ui-button-outline">Add New Activity</button>
                    @endcan
                </div>
            </div>

            @if ($this->activities()->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">No activities found.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead class="bg-navy text-xs font-medium text-white">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Activity Code</th>
                                <th scope="col" class="px-5 py-3 font-medium">Activity Name</th>
                                <th scope="col" class="px-5 py-3 font-medium">Status</th>
                                <th scope="col" class="px-5 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->activities() as $activity)
                                <tr wire:key="activity-master-{{ $activity->activity_id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                    <td class="px-5 py-3.5 font-medium text-navy">{{ $activity->activity_code }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $activity->activity_name }}</td>
                                    <td class="px-5 py-3.5">
                                        @if ($activity->is_active)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-on-track"><span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>Active</span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-not-calibrated"><span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>Inactive</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" wire:click="startEditing({{ $activity->activity_id }})" class="ui-button-outline">Edit</button>
                                            @if ($activity->is_active)
                                                <button type="button" wire:click="prepareActivityStatusChange({{ $activity->activity_id }}, false)" class="ui-button-outline" wire:loading.attr="disabled" wire:target="prepareActivityStatusChange">Deactivate</button>
                                            @else
                                                <button type="button" wire:click="prepareActivityStatusChange({{ $activity->activity_id }}, true)" class="ui-button-outline" wire:loading.attr="disabled" wire:target="prepareActivityStatusChange">Reactivate</button>
                                            @endif
                                            <button type="button" wire:click="prepareActivityDelete({{ $activity->activity_id }})" class="ui-button-danger" wire:loading.attr="disabled" wire:target="prepareActivityDelete">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-4">
                    {{ $this->activities()->links() }}
                </div>
            @endif
        </section>
    </div>

    <div
        x-cloak
        x-show="activityModalOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-180"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-navy/40 px-4 py-8 sm:items-center"
        role="presentation"
        x-on:click.self="requestCloseActivityModal()"
    >
        <div
            x-show="activityModalOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="scale-95 opacity-0"
            x-transition:enter-end="scale-100 opacity-100"
            x-transition:leave="transition ease-in duration-180"
            x-transition:leave-start="scale-100 opacity-100"
            x-transition:leave-end="scale-95 opacity-0"
            class="w-full max-w-xl overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-2xl motion-reduce:transition-none"
            role="dialog"
            aria-modal="true"
            aria-labelledby="activity-master-modal-heading"
            data-activity-master-modal
            x-on:click.stop
        >
            <div class="flex items-start justify-between bg-navy px-5 py-4">
                <div>
                    <h2 id="activity-master-modal-heading" class="text-base font-semibold tracking-tight text-white" x-text="editingActivityId !== null ? 'Edit Activity' : 'Add New Activity'"></h2>
                    <p class="mt-1 text-sm text-white/80" x-text="editingActivityId !== null ? 'Update the selected activity information.' : 'Create a new activity in the Activity Master.'"></p>
                </div>
                <button type="button" class="text-xl leading-none text-white/80 hover:text-white" aria-label="Close" x-on:click="requestCloseActivityModal()">&times;</button>
            </div>

            <form wire:submit="prepareActivitySave" class="max-h-[calc(100vh-9rem)] overflow-y-auto px-5 py-5">
                <div>
                    <label for="master-activity-name" class="ui-label">Activity Name <span class="text-red-700">*</span></label>
                    <input x-ref="activityName" id="master-activity-name" data-activity-form-field data-initial-value="{{ $activityName }}" type="text" wire:model="activityName" maxlength="255" required class="ui-input">
                    @error('activityName') <p class="ui-error">{{ $message }}</p> @enderror
                </div>

                <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-brand/20 pt-4">
                    <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="prepareActivitySave" x-text="editingActivityId !== null ? 'Save Changes' : 'Save Activity'"></button>
                    <button type="button" class="ui-button-secondary" x-on:click="requestCloseActivityModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
