<?php

namespace App\Livewire\Concerns;

use App\Models\InstrumentType;
use App\Models\TestColumn;
use App\Models\TestPoint;
use App\Models\TestPointValue;
use App\Models\TestProfile;
use App\TestColumns\DurationValue;
use App\TestColumns\NumericDisplay;
use App\TestColumns\TestTableVersioning;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only Test Components loading for instrument pages.
 *
 * @property InstrumentType|null $instrumentType
 */
trait DisplaysTestComponents
{
    private function loadTestProfiles(): void
    {
        if ($this->instrumentType === null) {
            return;
        }

        $versioning = app(TestTableVersioning::class);
        $purpose = $this->testTableViewPurpose();

        $this->instrumentType->load([
            'testProfiles' => function (HasMany $query) use ($versioning, $purpose): void {
                $query
                    ->select($this->testProfileSelect())
                    ->orderBy('test_profile_id');

                if ($purpose !== TestTableVersioning::PURPOSE_MANAGE) {
                    $query->notArchived();
                }

                if ($versioning->enabled()) {
                    $query->with([
                        'versions' => function (HasMany $versions): void {
                            $versions
                                ->select([
                                    'test_profile_version_id',
                                    'test_profile_id',
                                    'version_number',
                                    'status',
                                    'profile_name',
                                    'display_title',
                                    'published_at',
                                ])
                                ->orderBy('version_number')
                                ->orderBy('test_profile_version_id');
                        },
                    ]);
                }
            },
        ]);

        $profiles = $this->instrumentType->testProfiles;

        $profiles->load([
            'testColumns' => function (HasMany $query) use ($versioning, $profiles, $purpose): void {
                $versioning->constrainChildren($query, $profiles, $purpose);
                $query
                    ->select($this->testColumnSelect($versioning))
                    ->orderBy('column_order')
                    ->orderBy('test_column_id');
            },
            'testPoints' => function (HasMany $query) use ($versioning, $profiles, $purpose): void {
                $versioning->constrainChildren($query, $profiles, $purpose);
                $query
                    ->select($this->testPointSelect($versioning))
                    ->orderBy('point_order')
                    ->orderBy('test_point_id');
            },
            'testPoints.testPointValues' => function (HasMany $query) use ($versioning, $profiles, $purpose): void {
                $versioning->constrainChildren($query, $profiles, $purpose);
                $query->select($this->testPointValueSelect($versioning));
            },
        ]);
    }

    protected function testTableViewPurpose(): string
    {
        return TestTableVersioning::PURPOSE_DETAIL;
    }

    public function canEditTestTable(TestProfile $profile): bool
    {
        return app(TestTableVersioning::class)->canMutate($profile);
    }

    /**
     * @return Collection<int, TestProfile>
     */
    public function activeTestProfiles(): Collection
    {
        return $this->typedProfiles()->reject(
            fn (TestProfile $profile): bool => $profile->isArchived(),
        )->values();
    }

    /**
     * @return Collection<int, TestProfile>
     */
    public function archivedTestProfiles(): Collection
    {
        return $this->typedProfiles()->filter(
            fn (TestProfile $profile): bool => $profile->isArchived(),
        )->values();
    }

    public function testTableProfileName(TestProfile $profile): string
    {
        if ($this->testTableViewPurpose() === TestTableVersioning::PURPOSE_MANAGE) {
            $draft = app(TestTableVersioning::class)->draft($profile);

            if ($draft !== null) {
                return $draft->profile_name;
            }
        }

        return $profile->profile_name;
    }

    public function testTableDisplayTitle(TestProfile $profile): ?string
    {
        if ($this->testTableViewPurpose() === TestTableVersioning::PURPOSE_MANAGE) {
            $draft = app(TestTableVersioning::class)->draft($profile);

            if ($draft !== null) {
                return $draft->display_title;
            }
        }

        return $profile->display_title;
    }

    public function testTableVersionCaption(TestProfile $profile): ?string
    {
        return app(TestTableVersioning::class)->caption($profile, $this->testTableViewPurpose());
    }

    public function testTableIsUnpublished(TestProfile $profile): bool
    {
        return app(TestTableVersioning::class)->showsUnpublishedNotice($profile);
    }

    private function templateCell(TestPoint $point, TestColumn $column): string
    {
        if ($column->value_source !== 'template') {
            return '—';
        }

        $cell = $point->testPointValues->first(
            fn (TestPointValue $value): bool => (int) $value->test_column_id === (int) $column->test_column_id,
        );

        if ($cell === null) {
            return '—';
        }

        if ($column->data_type === 'text') {
            return filled($cell->text_value) ? (string) $cell->text_value : '—';
        }

        if (! filled($cell->numeric_value)) {
            return '—';
        }

        $stored = (string) $cell->numeric_value;

        if ($column->data_type === 'duration') {
            return DurationValue::format((int) $stored, alwaysSigned: false);
        }

        $scale = $column->display_scale === null || $column->display_scale === ''
            ? null
            : (int) $column->display_scale;

        return NumericDisplay::format($stored, $scale);
    }

    /**
     * @return array{text: string, missing: bool}|null
     */
    private function profileHeading(TestProfile $profile): ?array
    {
        if ($this->instrumentType === null || $this->activeTestProfiles()->count() < 2) {
            return null;
        }

        $title = $this->testTableDisplayTitle($profile);

        if (! filled($title)) {
            return ['text' => 'Title not set', 'missing' => true];
        }

        return ['text' => (string) $title, 'missing' => false];
    }

    /**
     * @return Collection<int, TestProfile>
     */
    private function typedProfiles(): Collection
    {
        if ($this->instrumentType === null) {
            return new Collection;
        }

        /** @var Collection<int, TestProfile> $profiles */
        $profiles = $this->instrumentType->testProfiles;

        return $profiles;
    }

    /**
     * @return list<string>
     */
    private function testProfileSelect(): array
    {
        $columns = [
            'test_profile_id',
            'instrument_type_id',
            'profile_name',
            'display_title',
        ];

        if (Schema::hasColumn((new TestProfile)->getTable(), 'archived_at')) {
            $columns[] = 'archived_at';
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function testColumnSelect(TestTableVersioning $versioning): array
    {
        return [
            'test_column_id',
            'test_profile_id',
            ...($versioning->enabled() ? ['test_profile_version_id'] : []),
            'column_key',
            'column_label',
            'column_order',
            'data_type',
            'value_source',
            'unit',
            'display_scale',
            'formula_code',
            'formula_inputs',
        ];
    }

    /**
     * @return list<string>
     */
    private function testPointSelect(TestTableVersioning $versioning): array
    {
        return [
            'test_point_id',
            'test_profile_id',
            ...($versioning->enabled() ? ['test_profile_version_id'] : []),
            'point_order',
            'nominal_value',
            'unit',
            'tolerance_plus',
            'tolerance_minus',
            'description',
        ];
    }

    /**
     * @return list<string>
     */
    private function testPointValueSelect(TestTableVersioning $versioning): array
    {
        return [
            'test_point_value_id',
            'test_point_id',
            'test_column_id',
            ...($versioning->enabled() ? ['test_profile_version_id'] : []),
            'numeric_value',
            'text_value',
        ];
    }
}
