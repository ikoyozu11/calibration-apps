<?php

use App\Activities\CalibrationActivityMasterException;
use App\Activities\CalibrationActivityMasterWriter;
use App\Models\CalibrationActivityMaster;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $adding = false;

    public ?int $editingActivityId = null;

    public ?int $removingActivityId = null;

    public string $activityName = '';

    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startAdding(): void
    {
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

    public function saveActivity(): void
    {
        $this->validate([
            'activityName' => ['required', 'string', 'max:'.CalibrationActivityMasterWriter::NAME_MAX],
        ], [
            'activityName.required' => 'Activity Name is required.',
        ]);

        $writer = app(CalibrationActivityMasterWriter::class);

        try {
            if ($this->editingActivityId !== null) {
                $writer->update($this->editingActivityId, $this->activityName);
                $this->status = 'Activity updated.';
            } else {
                $writer->create($this->activityName);
                $this->status = 'Activity saved.';
            }
        } catch (CalibrationActivityMasterException $exception) {
            $this->addError($exception->field === 'activity' ? 'activityName' : $exception->field, $exception->getMessage());

            return;
        }

        $this->cancelForm();
        $this->resetPage();
    }

    public function startEditing(int $activityId): void
    {
        $activity = CalibrationActivityMaster::query()->find($activityId);

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

    public function deactivateActivity(int $activityId): void
    {
        try {
            app(CalibrationActivityMasterWriter::class)->deactivate($activityId);
        } catch (CalibrationActivityMasterException $exception) {
            $this->addError('activity', $exception->getMessage());

            return;
        }

        $this->status = 'Activity deactivated.';
        $this->cancelForm();
    }

    public function activateActivity(int $activityId): void
    {
        try {
            app(CalibrationActivityMasterWriter::class)->activate($activityId);
        } catch (CalibrationActivityMasterException $exception) {
            $this->addError('activity', $exception->getMessage());

            return;
        }

        $this->status = 'Activity reactivated.';
        $this->cancelForm();
    }

    public function askToRemove(int $activityId): void
    {
        $this->removingActivityId = $activityId;
        $this->adding = false;
        $this->editingActivityId = null;
        $this->resetValidation();
    }

    public function removeActivity(): void
    {
        if ($this->removingActivityId === null) {
            return;
        }

        try {
            app(CalibrationActivityMasterWriter::class)->delete($this->removingActivityId);
        } catch (CalibrationActivityMasterException $exception) {
            $this->addError('activity', $exception->getMessage());
            $this->removingActivityId = null;

            return;
        }

        $this->status = 'Activity deleted.';
        $this->removingActivityId = null;
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, CalibrationActivityMaster>
     */
    public function activities(): LengthAwarePaginator
    {
        return CalibrationActivityMaster::query()
            ->whereActivityMasterMatches($this->search)
            ->orderBy('activity_code')
            ->paginate(10);
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight text-navy">Calibration Activities</h1>
            <p class="mt-1 text-sm text-muted">Register master activities independently of calibration sessions.</p>
        </header>

        @if ($status)
            <p class="ui-alert" role="status">{{ $status }}</p>
        @endif
        @error('activity')
            <p class="ui-error">{{ $message }}</p>
        @enderror

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="activity-master-heading">
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="activity-master-heading" class="text-base font-semibold tracking-tight text-navy">Activity Master</h2>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label for="activity-master-search" class="sr-only">Search by name or activity code</label>
                    <input id="activity-master-search" type="search" wire:model.live.debounce.300ms="search" maxlength="100" class="ui-input" placeholder="Name or activity code">
                    <button type="button" wire:click="startAdding" class="ui-button-outline">Add New Activity</button>
                </div>
            </div>

            @if ($adding || $editingActivityId !== null)
                <form wire:submit="saveActivity" class="flex flex-col gap-3 border-t border-white/70 px-5 py-4">
                    <div>
                        <label for="master-activity-name" class="ui-label">Activity Name</label>
                        <input id="master-activity-name" type="text" wire:model="activityName" maxlength="255" required class="ui-input">
                        @error('activityName')
                            <p class="ui-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="saveActivity">{{ $editingActivityId !== null ? 'Save changes' : 'Save Activity' }}</button>
                        <button type="button" wire:click="cancelForm" class="ui-button-secondary">Cancel</button>
                    </div>
                </form>
            @endif

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
                                    <td class="px-5 py-3.5 text-navy">{{ $activity->is_active ? 'Active' : 'Inactive' }}</td>
                                    <td class="px-5 py-3.5">
                                        @if ((int) $removingActivityId === (int) $activity->activity_id)
                                            <div class="flex flex-col gap-2">
                                                <p class="text-sm text-navy">Delete this activity from the master list?</p>
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" wire:click="removeActivity" class="ui-button-danger" wire:loading.attr="disabled" wire:target="removeActivity">Delete</button>
                                                    <button type="button" wire:click="cancelForm" class="ui-button-secondary">Cancel</button>
                                                </div>
                                            </div>
                                        @else
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="startEditing({{ $activity->activity_id }})" class="ui-button-outline">Edit</button>
                                                @if ($activity->is_active)
                                                    <button type="button" wire:click="deactivateActivity({{ $activity->activity_id }})" class="ui-button-outline">Deactivate</button>
                                                @else
                                                    <button type="button" wire:click="activateActivity({{ $activity->activity_id }})" class="ui-button-outline">Reactivate</button>
                                                @endif
                                                <button type="button" wire:click="askToRemove({{ $activity->activity_id }})" class="ui-button-danger">Delete</button>
                                            </div>
                                        @endif
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
</div>
