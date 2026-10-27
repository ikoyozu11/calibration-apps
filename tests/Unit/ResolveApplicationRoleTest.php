<?php

use App\Authorization\ApplicationCapability;
use App\Authorization\ApplicationCapabilityMap;
use App\Authorization\ApplicationRoleName;
use App\Authorization\AssignApplicationRole;
use App\Authorization\ResolveApplicationRole;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves a trusted role by laravel user id without auth()', function () {
    $user = User::factory()->create();
    app(AssignApplicationRole::class)->assign((int) $user->id, ApplicationRoleName::Engineer);

    $role = app(ResolveApplicationRole::class)->resolve((int) $user->id);

    expect($role)->toBe(ApplicationRoleName::Engineer);
    expect(auth()->user())->toBeNull();
});

it('denies missing and invalid roles', function () {
    $resolver = app(ResolveApplicationRole::class);
    $user = User::factory()->create();

    expect($resolver->tryResolve((int) $user->id))->toBeNull();
    expect($resolver->allows((int) $user->id, ApplicationCapability::HistoryView))->toBeFalse();

    $invalid = Role::query()->create(['name' => 'Contractor']);
    UserRole::query()->create([
        'laravel_user_id' => $user->id,
        'role_id' => $invalid->id,
    ]);
    expect($resolver->tryResolve((int) $user->id))->toBeNull();
});

it('maps capabilities without granting session.cancel', function () {
    $map = new ApplicationCapabilityMap;

    expect($map->allows(ApplicationRoleName::Administrator, ApplicationCapability::MasterdataManage))->toBeTrue();
    expect($map->allows(ApplicationRoleName::Engineer, ApplicationCapability::MasterdataManage))->toBeFalse();
    expect($map->allows(ApplicationRoleName::Viewer, ApplicationCapability::DocumentsDownload))->toBeFalse();
    expect($map->allows(ApplicationRoleName::Administrator, ApplicationCapability::SessionCancel))->toBeFalse();
    expect($map->allows(ApplicationRoleName::Engineer, ApplicationCapability::SessionCancel))->toBeFalse();
});
