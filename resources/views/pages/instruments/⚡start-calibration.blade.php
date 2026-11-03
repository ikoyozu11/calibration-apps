<?php

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\CalibrationSessions\ResolveDomainActorException;
use App\CalibrationSessions\StartCalibrationException;
use App\CalibrationSessions\StartCalibrationHttpConnection;
use App\CalibrationSessions\StartCalibrationHttpException;
use App\CalibrationSessions\StartCalibrationSession;
use App\Models\Calibration;
use App\Models\Instrument;
use App\Models\InstrumentType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $instrumentId = 0;

    #[Locked]
    public int $instrumentTypeId = 0;

    public string $calibrationMethod = '';

    public ?int $blockingCalibrationId = null;

    public function mount(int $instrument, int $instrumentType): void
    {
        Gate::authorize(ApplicationCapability::SessionStart->value);

        $this->instrumentId = $instrument;
        $this->instrumentTypeId = $instrumentType;
        $this->instrument();
        $this->instrumentType();
        $this->blockingCalibrationId = $this->activeSessionId();
    }

    #[Computed]
    public function instrument(): Instrument
    {
        return app(CalibrationSessionHttpRead::class)->instrument($this->instrumentId);
    }

    #[Computed]
    public function instrumentType(): InstrumentType
    {
        return app(CalibrationSessionHttpRead::class)->instrumentType($this->instrumentTypeId, $this->instrumentId);
    }

    public function startCalibration(
        StartCalibrationSession $starter,
        StartCalibrationHttpConnection $writes,
    ): void {
        Gate::authorize(ApplicationCapability::SessionStart->value);

        if ((int) $this->instrumentType->instrument_id !== $this->instrumentId) {
            abort(404);
        }

        $this->validate([
            'calibrationMethod' => ['required', Rule::in([Calibration::METHOD_INTERNAL, Calibration::METHOD_EXTERNAL])],
        ]);

        $activeId = $this->activeSessionId();

        if ($activeId !== null) {
            $this->blockingCalibrationId = $activeId;
            $this->addError('calibrationMethod', StartCalibrationException::inProgressExists()->getMessage());

            return;
        }

        $laravelUserId = (int) Auth::id();

        if ($laravelUserId < 1) {
            abort(403);
        }

        try {
            $calibration = $starter->start(
                $this->instrumentTypeId,
                $this->calibrationMethod,
                $laravelUserId,
                $writes->resolve(),
            );
        } catch (StartCalibrationHttpException $exception) {
            $this->addError('calibrationMethod', $exception->getMessage());

            return;
        } catch (CalibrationSessionConnectionException $exception) {
            $this->addError('calibrationMethod', StartCalibrationHttpException::writesUnavailable()->getMessage());

            return;
        } catch (ResolveDomainActorException $exception) {
            $this->addError('calibrationMethod', $exception->getMessage());

            return;
        } catch (StartCalibrationException $exception) {
            if ($exception->getMessage() === StartCalibrationException::inProgressExists()->getMessage()) {
                $this->blockingCalibrationId = $this->activeSessionId();
            }

            $this->addError('calibrationMethod', $exception->getMessage());

            return;
        }

        $this->redirect(route('calibrations.show', $calibration->calibration_id));
    }

    private function activeSessionId(): ?int
    {
        $calibrationId = Calibration::on(app(CalibrationSessionHttpRead::class)->connectionName())
            ->inProgress()
            ->where('instrument_type_id', $this->instrumentTypeId)
            ->value('calibration_id');

        return $calibrationId === null ? null : (int) $calibrationId;
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <a href="{{ route('calibrations.index') }}" class="ui-button-secondary w-fit gap-2 shadow-[0_10px_18px_-14px_rgb(22_49_114/0.9)]">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6 9 12l6 6" />
                </svg>
                Back to calibrations
            </a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-navy">Start Calibration</h1>
            <p class="mt-1 text-sm text-muted">Confirm the instrument type and calibration method. Internal Temperature and Relative Humidity are entered later, while recording the session.</p>
        </header>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="start-identity-heading">
            <div class="px-5 py-4">
                <h2 id="start-identity-heading" class="text-base font-semibold tracking-tight text-navy">Instrument Type</h2>
            </div>
            <dl class="grid gap-3 px-5 pb-5 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Instrument Name</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ filled($this->instrument->instrument_name) ? $this->instrument->instrument_name : '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Instrument Type</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ filled($this->instrumentType->type_name) ? $this->instrumentType->type_name : '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Brand</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ filled($this->instrumentType->brand) ? $this->instrumentType->brand : '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Model</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ filled($this->instrumentType->model) ? $this->instrumentType->model : '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Serial Number</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ filled($this->instrument->serial_number) ? $this->instrument->serial_number : '—' }}</dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                    <dt class="text-xs font-medium text-muted">Asset Number</dt>
                    <dd class="mt-1 text-sm font-semibold text-navy">{{ filled($this->instrumentType->asset_number) ? $this->instrumentType->asset_number : '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="start-method-heading">
            <div class="px-5 py-4">
                <h2 id="start-method-heading" class="text-base font-semibold tracking-tight text-navy">Calibration Method</h2>
            </div>
            <div class="px-5 pb-5">
                @if ($blockingCalibrationId !== null)
                    <p class="ui-error">{{ StartCalibrationException::inProgressExists()->getMessage() }}</p>
                    <a href="{{ route('calibrations.show', $blockingCalibrationId) }}" class="ui-button-primary mt-4 inline-flex">
                        Open Session
                    </a>
                @else
                    <form wire:submit="startCalibration" class="flex flex-col gap-4">
                        <div>
                            <label for="calibration-method" class="ui-label">Calibration Method</label>
                            <select id="calibration-method" wire:model="calibrationMethod" class="ui-input">
                                <option value="">Select a method</option>
                                <option value="{{ Calibration::METHOD_INTERNAL }}">INTERNAL</option>
                                <option value="{{ Calibration::METHOD_EXTERNAL }}">EXTERNAL</option>
                            </select>
                            @error('calibrationMethod')
                                <p class="ui-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <button
                            type="submit"
                            class="ui-button-primary w-fit"
                            wire:loading.attr="disabled"
                            wire:target="startCalibration"
                        >
                            Start Calibration
                        </button>
                    </form>
                @endif
            </div>
        </section>
    </div>
</div>
