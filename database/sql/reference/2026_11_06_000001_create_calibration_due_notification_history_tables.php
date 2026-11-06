<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference-only schema definition.
 *
 * This schema is managed by the approved SQL-only staging release. It is kept
 * outside database/migrations so Laravel cannot execute it a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_due_notification_batches', function (Blueprint $table): void {
            $table->id();
            $table->date('notification_date');
            $table->string('timezone', 64);
            $table->string('recipient', 320);
            $table->string('status', 32);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampsTz();

            $table->unique(['notification_date', 'recipient']);
            $table->index(['status', 'notification_date']);
        });

        Schema::create('calibration_due_notification_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')
                ->constrained('calibration_due_notification_batches')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('instrument_id');
            $table->unsignedBigInteger('instrument_type_id');
            $table->unsignedBigInteger('latest_calibration_id');
            $table->string('calibration_method', 32);
            $table->text('instrument_name_snapshot');
            $table->text('instrument_type_snapshot');
            $table->text('serial_number_snapshot')->nullable();
            $table->text('location_snapshot')->nullable();
            $table->date('due_date_snapshot');
            $table->string('milestone_code', 32);
            $table->date('milestone_date');
            $table->string('recipient', 320);
            $table->string('status', 32);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampsTz();

            $table->unique([
                'instrument_type_id',
                'due_date_snapshot',
                'milestone_code',
                'recipient',
            ], 'calibration_due_notification_item_dedupe');
            $table->foreign('instrument_id')
                ->references('instrument_id')
                ->on('instrument')
                ->restrictOnDelete();
            $table->foreign('instrument_type_id')
                ->references('instrument_type_id')
                ->on('instrument_type')
                ->restrictOnDelete();
            $table->foreign('latest_calibration_id')
                ->references('calibration_id')
                ->on('calibration')
                ->restrictOnDelete();
            $table->index(['batch_id', 'status']);
            $table->index(['milestone_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_due_notification_items');
        Schema::dropIfExists('calibration_due_notification_batches');
    }
};
