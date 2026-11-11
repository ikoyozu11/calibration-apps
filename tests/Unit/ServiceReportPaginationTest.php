<?php

use App\Certificates\ServiceReport;
use App\Models\Certificate;

function serviceReportLayoutGroup(string $label): array
{
    return [
        'label' => $label,
        'columns' => [
            ['label' => 'Standard Value', 'unit' => ''],
            ['label' => 'Reading Avg', 'unit' => ''],
            ['label' => 'Correction', 'unit' => ''],
        ],
        'rows' => [['cells' => ['10.0', '10.1', '0.1']]],
        'remarks' => '',
        'tolerance' => null,
    ];
}

it('uses half-width rows for one to five simple test result tables', function () {
    foreach ([1, 2, 3, 4, 5] as $count) {
        $layouts = ServiceReport::testResultLayoutsFor(
            array_map(
                static fn (int $index): array => serviceReportLayoutGroup('Table '.($index + 1)),
                range(0, $count - 1),
            ),
        );

        expect($layouts)->toHaveCount((int) ceil($count / 2));
        expect(array_column($layouts, 'mode'))->toBe(
            $count === 1 ? ['half'] : ($count === 2 ? ['pair'] : ($count === 3 ? ['pair', 'half'] : ($count === 4 ? ['pair', 'pair'] : ['pair', 'pair', 'half']))),
        );
    }
});

it('keeps complex test result tables full width without changing order', function () {
    $wide = serviceReportLayoutGroup('Wide');
    $wide['columns'][] = ['label' => 'Additional', 'unit' => ''];
    $wide['rows'][0]['cells'][] = 'A deliberately long value that needs its own row';

    $layouts = ServiceReport::testResultLayoutsFor([
        serviceReportLayoutGroup('First'),
        $wide,
        serviceReportLayoutGroup('Third'),
    ]);

    expect(array_column($layouts, 'mode'))->toBe(['half', 'full', 'half'])
        ->and(array_map(
            static fn (array $layout): string => $layout['groups'][0]['label'],
            $layouts,
        ))->toBe(['First', 'Wide', 'Third']);
});

it('keeps ordinary long headers in the half-width layout', function () {
    $groups = [
        serviceReportLayoutGroup('Size'),
        serviceReportLayoutGroup('Length'),
        serviceReportLayoutGroup('Diameter'),
    ];

    foreach ($groups as &$group) {
        $group['columns'][0]['label'] .= ' ('.strtolower($group['label']).')';
        $group['columns'][1]['label'] .= ' ('.strtolower($group['label']).')';
        $group['columns'][2]['label'] .= ' ('.strtolower($group['label']).')';
        $group['columns'][0]['unit'] = 'mm';
        $group['columns'][1]['unit'] = 'mm';
        $group['columns'][2]['unit'] = 'mm';
    }
    unset($group);

    expect(array_column(ServiceReport::testResultLayoutsFor($groups), 'mode'))->toBe(['pair', 'half']);
});

it('pairs content-aware four-column tables when their values remain readable', function () {
    $fourColumns = serviceReportLayoutGroup('Four columns');
    $fourColumns['columns'][] = ['label' => 'Unit', 'unit' => ''];
    $fourColumns['rows'][0]['cells'][] = 'mm';

    $layouts = ServiceReport::testResultLayoutsFor([$fourColumns, $fourColumns]);

    expect($layouts)->toHaveCount(1)
        ->and($layouts[0]['mode'])->toBe('pair')
        ->and($layouts[0]['widths'])->toBe([50, 50]);
});

it('gives a standalone four-column table a wider left-aligned width', function () {
    $fourColumns = serviceReportLayoutGroup('Standard PD');
    $fourColumns['columns'][] = ['label' => 'Correction', 'unit' => 'mmWG'];
    $fourColumns['rows'][0]['cells'][] = '0.00';

    $layouts = ServiceReport::testResultLayoutsFor([$fourColumns]);

    expect($layouts[0]['mode'])->toBe('half')
        ->and($layouts[0]['widths'])->toBe([70]);
});

it('uses combined content demands for mixed paired tables', function () {
    $fourColumns = serviceReportLayoutGroup('Four');
    $fourColumns['columns'][] = ['label' => 'Unit', 'unit' => ''];
    $fourColumns['rows'][0]['cells'][] = 'mm';
    $twoColumns = [
        'label' => 'Two',
        'columns' => [
            ['label' => 'Value', 'unit' => ''],
            ['label' => 'Unit', 'unit' => ''],
        ],
        'rows' => [['cells' => ['10.0', 'mm']]],
        'remarks' => '',
        'tolerance' => null,
    ];

    expect(ServiceReport::testResultLayoutsFor([$fourColumns, $twoColumns])[0]['widths'])
        ->toBe([69, 31]);
});

it('inherits the preceding pair left width for a final unpaired table', function () {
    $groups = [
        serviceReportLayoutGroup('Size'),
        serviceReportLayoutGroup('Length'),
        serviceReportLayoutGroup('Diameter'),
    ];

    $layouts = ServiceReport::testResultLayoutsFor($groups);

    expect(array_column($layouts, 'mode'))->toBe(['pair', 'half'])
        ->and($layouts[0]['widths'])->toBe([50, 50])
        ->and($layouts[1]['widths'])->toBe([50]);
});

it('inherits an asymmetric left width through five tables', function () {
    $fourColumns = serviceReportLayoutGroup('Four');
    $fourColumns['columns'][] = ['label' => 'Unit', 'unit' => ''];
    $fourColumns['rows'][0]['cells'][] = 'mm';
    $twoColumns = [
        'label' => 'Two',
        'columns' => [
            ['label' => 'Value', 'unit' => ''],
            ['label' => 'Unit', 'unit' => ''],
        ],
        'rows' => [['cells' => ['10.0', 'mm']]],
        'remarks' => '',
        'tolerance' => null,
    ];

    $layouts = ServiceReport::testResultLayoutsFor([
        $fourColumns,
        $twoColumns,
        $fourColumns,
        $twoColumns,
        serviceReportLayoutGroup('Final'),
    ]);

    expect(array_column($layouts, 'mode'))->toBe(['pair', 'pair', 'half'])
        ->and($layouts[0]['widths'])->toBe([69, 31])
        ->and($layouts[2]['widths'])->toBe([69]);
});

it('keeps genuinely wide four-column content on separate rows', function () {
    $wide = serviceReportLayoutGroup('Wide');
    $wide['columns'][] = ['label' => 'Unit', 'unit' => ''];
    $wide['rows'][0]['cells'][] = 'A value that cannot fit legibly in a compact table';

    $layouts = ServiceReport::testResultLayoutsFor([$wide, serviceReportLayoutGroup('Simple')]);

    expect(array_column($layouts, 'mode'))->toBe(['full', 'half']);
});

it('formats standards as a compact numbered list and omits empty records', function () {
    $report = new ServiceReport(new Certificate);
    $property = new ReflectionProperty(ServiceReport::class, 'standards');
    $property->setValue($report, [
        ['name' => 'Weight Standard', 'type' => 'E2 Class', 'serial_number' => '40488'],
        ['name' => '', 'type' => 'Temperature', 'serial_number' => ''],
        ['name' => '', 'type' => '', 'serial_number' => ''],
    ]);

    expect($report->standardList())->toBe([
        'Weight Standard; E2 Class; 40488',
        '-; Temperature; -',
    ]);
});

it('preserves order and long content for five standards', function () {
    $report = new ServiceReport(new Certificate);
    $property = new ReflectionProperty(ServiceReport::class, 'standards');
    $property->setValue($report, array_map(
        static fn (int $index): array => [
            'name' => 'Standard '.$index,
            'type' => $index === 3 ? '' : 'Specification with a long readable description',
            'serial_number' => $index === 5 ? '' : 'SN-'.$index,
        ],
        range(1, 5),
    ));

    expect($report->standardList())->toHaveCount(5)
        ->and($report->standardList()[0])->toStartWith('Standard 1;')
        ->and($report->standardList()[4])->toBe('Standard 5; Specification with a long readable description; -');
});

it('keeps a short test result group eligible for one-page placement', function () {
    $report = new ServiceReport(new Certificate);

    $group = [
        'columns' => [
            ['label' => 'Standard Value', 'unit' => ''],
            ['label' => 'Average', 'unit' => ''],
            ['label' => 'Correction', 'unit' => ''],
        ],
        'rows' => array_fill(0, 5, ['cells' => ['10.0', '10.1', '0.1']]),
        'remarks' => '',
        'tolerance' => null,
    ];

    expect($report->testResultGroupIsCompact($group))->toBeTrue();
});

it('allows a long test result group to continue across pages', function () {
    $report = new ServiceReport(new Certificate);

    $group = [
        'columns' => [
            ['label' => 'Standard Value', 'unit' => ''],
            ['label' => 'Average', 'unit' => ''],
            ['label' => 'Correction', 'unit' => ''],
        ],
        'rows' => array_fill(0, 35, ['cells' => ['10.0', '10.1', '0.1']]),
        'remarks' => '',
        'tolerance' => null,
    ];

    expect($report->testResultGroupIsCompact($group))->toBeFalse();
});

it('filters empty identity values before column-major distribution', function () {
    $columns = ServiceReport::identityFieldColumnsFor([
        'name' => 'Quantum Neo',
        'location' => 'FMD',
        'detail_location' => 'FM 01/07',
        'type' => 'X-ray',
        'serial_number' => 'QN-001',
        'asset_number' => '—',
        'brand' => 'Quantum',
        'model' => 'Neo',
        'max_capacity' => null,
        'resolution' => '   ',
    ]);

    expect($columns)->toHaveCount(3)
        ->and(array_map('array_keys', $columns))->toBe([
            ['Instrument Name', 'Location', 'Detail Location'],
            ['Instrument Type', 'Serial Number', 'Brand'],
            ['Model'],
        ]);
});

it('preserves five and ten populated identity fields in three-field columns', function () {
    $five = ServiceReport::identityFieldColumnsFor([
        'name' => 'Instrument',
        'location' => 'Location',
        'detail_location' => 'Detail',
        'type' => 'Type',
        'serial_number' => 'Serial',
    ]);
    $ten = ServiceReport::identityFieldColumnsFor([
        'name' => 'Instrument',
        'location' => 'Location',
        'detail_location' => 'Detail',
        'type' => 'Type',
        'serial_number' => 'Serial',
        'asset_number' => 'Asset',
        'brand' => 'Brand',
        'model' => 'Model',
        'max_capacity' => '64 g',
        'resolution' => '0.00001 g',
    ]);

    expect(array_map('count', $five))->toBe([3, 2])
        ->and(array_map('count', $ten))->toBe([3, 3, 3, 1]);
});
