<?php

use App\Certificates\ServiceReport;
use App\Models\Certificate;

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
