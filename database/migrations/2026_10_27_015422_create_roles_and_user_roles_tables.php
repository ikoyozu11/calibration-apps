<?php

use App\Authorization\ApplicationRoleName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Laravel auth-database role storage. Domain calibration tables are unchanged.
     * Staging PostgreSQL applies the companion SQL under database/sql/ when ready;
     * do not run this migration against calibration_db or calibration_staging_db
     * from automated tests or ad-hoc artisan migrate on those connections.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('laravel_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->timestamps();

            $table->unique('laravel_user_id');
            $table->unique(['laravel_user_id', 'role_id']);
        });

        $now = now();

        foreach (ApplicationRoleName::cases() as $role) {
            DB::table('roles')->insert([
                'name' => $role->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
    }
};
