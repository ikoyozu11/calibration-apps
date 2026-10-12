<?php

use App\Models\Instrument;
use App\Models\InstrumentType;
use Livewire\Component;

new class extends Component
{
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
        $this->instrumentType = $instrumentType;
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
            ['label' => 'Serial Number', 'value' => $this->display($type?->serial_number)],
            ['label' => 'Asset Number', 'value' => $this->display($type?->asset_number)],
            ['label' => 'Brand', 'value' => $this->display($type?->brand)],
            ['label' => 'Model', 'value' => $this->display($type?->model)],
            ['label' => 'Max Capacity', 'value' => $this->measurement($type?->max_capacity, $type?->max_capacity_unit)],
            ['label' => 'Resolution', 'value' => $this->measurement($type?->resolution, $type?->resolution_unit)],
        ];
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
    </div>
</div>
