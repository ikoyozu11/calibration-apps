<?php

use App\Models\Calibration;
use App\Models\Instrument;
use App\Models\InstrumentType;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public Instrument $instrument;

    public ?InstrumentType $instrumentType = null;

    public function mount(Instrument $instrument, ?InstrumentType $instrumentType = null): void
    {
        if ($instrumentType === null) {
            $firstType = $instrument->instrumentTypes()->orderBy('instrument_type_id')->first();

            if ($firstType !== null) {
                $this->redirect(route('instruments.types.show', [$instrument, $firstType]));

                return;
            }
        }

        $this->instrument = $instrument->load([
            'detailLocation:detail_location_id,location_id,detail_location_name',
            'detailLocation.location:location_id,location_name',
        ]);
        $this->instrumentType = $instrumentType?->load([
            'testProfiles' => function (HasMany $query): void {
                $query
                    ->select([
                        'test_profile_id',
                        'instrument_type_id',
                        'profile_name',
                    ])
                    ->orderBy('test_profile_id');
            },
            'testProfiles.testPoints' => function (HasMany $query): void {
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
                    ])
                    ->orderBy('point_order')
                    ->orderBy('test_point_id');
            },
        ]);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function identity(): array
    {
        $type = $this->instrumentType;

        return [
            ['label' => 'Instrument Name', 'value' => $this->display($this->instrument->instrument_name)],
            ['label' => 'Location', 'value' => $this->display($this->instrument->detailLocation?->location?->location_name)],
            ['label' => 'Detail Location', 'value' => $this->display($this->instrument->detailLocation?->detail_location_name)],
            ['label' => 'Type', 'value' => $this->display($type?->type_name)],
            ['label' => 'Serial Number', 'value' => $this->display($this->instrument->serial_number)],
            ['label' => 'Asset Number', 'value' => $this->display($type?->asset_number)],
            ['label' => 'Brand', 'value' => $this->display($type?->brand)],
            ['label' => 'Model', 'value' => $this->display($type?->model)],
            ['label' => 'Max Capacity', 'value' => $this->measurement($type?->max_capacity, $type?->max_capacity_unit)],
            ['label' => 'Resolution', 'value' => $this->measurement($type?->resolution, $type?->resolution_unit)],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array{id: int, date: string, due: string, status: string|null, remarks: string}>
     */
    #[Computed]
    public function history(): LengthAwarePaginator
    {
        return $this->instrument->calibrations()
            ->select([
                'calibration_id',
                'instrument_id',
                'calibration_date',
                'calibration_due',
                'remarks',
            ])
            ->orderByRaw('case when calibration_date is null then 1 else 0 end')
            ->orderByDesc('calibration_date')
            ->orderByDesc('calibration_id')
            ->paginate(5)
            ->through(fn (Calibration $calibration): array => [
                'id' => $calibration->calibration_id,
                'date' => $calibration->calibration_date?->format('Y-m-d') ?? '—',
                'due' => $calibration->calibration_due?->format('Y-m-d') ?? '—',
                'status' => $this->status($calibration),
                'remarks' => $this->display($calibration->remarks),
            ]);
    }

    private function status(Calibration $calibration): ?string
    {
        if ($calibration->calibration_due === null) {
            return null;
        }

        if (today()->greaterThan($calibration->calibration_due->copy()->startOfDay())) {
            return 'Overdue';
        }

        return 'On Track';
    }

    private function display(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }

    private function measurement(mixed $value, mixed $unit): string
    {
        if (! filled($value)) {
            return '—';
        }

        $amount = (string) $value;

        if (is_numeric($amount) && str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
            $amount = $amount === '' || $amount === '-' ? '0' : $amount;
        }

        return filled($unit) ? $amount.' '.$unit : $amount;
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <a href="{{ route('instruments.index') }}" class="ui-button-secondary w-fit gap-2 shadow-[0_10px_18px_-14px_rgb(22_49_114/0.9)]">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6 9 12l6 6" />
                </svg>
                Back to instruments
            </a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-navy">{{ $instrument->instrument_name }}</h1>
            <p class="mt-1 text-sm text-muted">{{ $instrumentType?->type_name ?? 'Instrument identity' }}</p>
        </header>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="instrument-identity-heading">
            <div class="bg-navy px-5 py-4">
                <h2 id="instrument-identity-heading" class="text-base font-semibold tracking-tight text-white">Instrument Identity</h2>
            </div>

            <dl class="grid gap-3 px-5 py-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->identity() as $field)
                    <div wire:key="identity-{{ $field['label'] }}" class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                        <dt class="text-xs font-medium text-muted">{{ $field['label'] }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-navy">{{ $field['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="test-components-heading">
            <div class="px-5 py-4">
                <h2 id="test-components-heading" class="text-base font-semibold tracking-tight text-navy">Test Components</h2>
            </div>

            @if ($instrumentType === null || $instrumentType->testProfiles->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">No test components found.</p>
            @else
                <div class="flex flex-col gap-6 px-5 pb-5">
                    @foreach ($instrumentType->testProfiles as $profile)
                        <div wire:key="test-profile-{{ $profile->test_profile_id }}">
                            <h3 class="text-sm font-semibold text-navy">{{ $profile->profile_name }}</h3>

                            @if ($profile->testPoints->isEmpty())
                                <p class="py-4 text-sm text-navy/70">No test components found.</p>
                            @else
                                <div class="mt-3 overflow-x-auto">
                                    <table class="ui-table">
                                        <thead class="bg-navy text-xs font-medium text-white">
                                            <tr>
                                                <th scope="col" class="px-5 py-3 font-medium">Point</th>
                                                <th scope="col" class="px-5 py-3 font-medium">Nominal</th>
                                                <th scope="col" class="px-5 py-3 font-medium">Unit</th>
                                                <th scope="col" class="px-5 py-3 font-medium">+Tol</th>
                                                <th scope="col" class="px-5 py-3 font-medium">-Tol</th>
                                                <th scope="col" class="px-5 py-3 font-medium">Description</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/70">
                                            @foreach ($profile->testPoints as $point)
                                                <tr wire:key="test-point-{{ $point->test_point_id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $point->point_order }}</td>
                                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ filled($point->nominal_value) ? $point->nominal_value : '—' }}</td>
                                                    <td class="px-5 py-3.5 text-navy">{{ filled($point->unit) ? $point->unit : '—' }}</td>
                                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ filled($point->tolerance_plus) ? $point->tolerance_plus : '—' }}</td>
                                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ filled($point->tolerance_minus) ? $point->tolerance_minus : '—' }}</td>
                                                    <td class="px-5 py-3.5 text-navy">{{ filled($point->description) ? $point->description : '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="calibration-history-heading">
            <div class="px-5 py-4">
                <h2 id="calibration-history-heading" class="text-base font-semibold tracking-tight text-navy">Calibration History</h2>
            </div>

            @if ($this->history->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">No calibration history found.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead class="bg-navy text-xs font-medium text-white">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Calibration Date</th>
                                <th scope="col" class="px-5 py-3 font-medium">Calibration Due</th>
                                <th scope="col" class="px-5 py-3 font-medium">Status</th>
                                <th scope="col" class="px-5 py-3 font-medium">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->history as $calibration)
                                <tr wire:key="calibration-{{ $calibration['id'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec] hover:bg-[#edf4fa]">
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $calibration['date'] }}</td>
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $calibration['due'] }}</td>
                                    <td class="px-5 py-3.5">
                                        @if ($calibration['status'] === null)
                                            <span class="text-navy/70">—</span>
                                        @else
                                            <x-status-badge :status="$calibration['status']" />
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-navy">{{ $calibration['remarks'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->history->hasPages())
                    <div class="px-5 py-4">
                        {{ $this->history->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</div>
