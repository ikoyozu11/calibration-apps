<?php

it('guards the global Livewire notification listeners against duplicate registration', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/app.js');

    expect($source)->not->toBeFalse()
        ->and(substr_count($source, 'window.__instrumentsDeskNotificationsRegistered = true'))->toBe(1)
        ->and(substr_count($source, "document.addEventListener('livewire:init'"))->toBe(1)
        ->and($source)->toContain("window.Livewire.on('instruments-desk-notification'")
        ->and($source)->toContain("window.Livewire.on('instruments-desk-terminal-success'")
        ->and($source)->toContain('window.location.reload()')
        ->and($source)->toContain('recentTerminalSuccesses')
        ->and($source)->toContain("window.Livewire.on('instruments-desk-confirm-cancellation'")
        ->and($source)->toContain("window.Livewire.on('instruments-desk-confirm-activity-removal'")
        ->and($source)->toContain("window.Livewire.on('instruments-desk-confirm-standard-removal'")
        ->and($source)->toContain("window.Livewire.on('instruments-desk-confirm-template-recovery'")
        ->and($source)->toContain("window.Livewire.on('instruments-desk-confirm-test-result-clear'")
        ->and($source)->toContain("window.Livewire.on('instruments-desk-confirm-completion'");
});
