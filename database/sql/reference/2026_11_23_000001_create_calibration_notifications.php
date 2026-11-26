<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference-only schema definition.
 *
 * The schema is managed by an explicitly approved SQL-only staging release.
 * It remains outside database/migrations so Laravel cannot execute it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_notifications', function (Blueprint $table): void {
            $table->id('calibration_notification_id');
            $table->bigInteger('recipient_user_id');
            $table->bigInteger('instrument_id')->nullable();
            $table->bigInteger('instrument_type_id')->nullable();
            $table->bigInteger('source_calibration_id')->nullable();
            $table->string('event_type', 32);
            $table->string('event_key', 512);
            $table->unsignedSmallInteger('occurrence_revision')->default(1);
            $table->text('instrument_name_snapshot');
            $table->text('instrument_type_snapshot');
            $table->text('serial_number_snapshot')->nullable();
            $table->date('due_date_snapshot');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->bigInteger('resolution_calibration_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            /*
             * PostgreSQL-only CHECK constraints are declared in the guarded
             * SQL release artifact. Keep these expressions beside the
             * Blueprint reference so schema review cannot drift:
             *
             * event_type IN ('due_soon', 'overdue')
             * length(trim(event_key)) > 0
             * occurrence_revision > 0
             * length(trim(instrument_name_snapshot)) > 0
             * length(trim(instrument_type_snapshot)) > 0
             */

            $table->foreign('recipient_user_id', 'fk_calibration_notifications_recipient')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('instrument_id', 'fk_calibration_notifications_instrument')
                ->references('instrument_id')
                ->on('instrument')
                ->nullOnDelete();
            $table->foreign('instrument_type_id', 'fk_calibration_notifications_instrument_type')
                ->references('instrument_type_id')
                ->on('instrument_type')
                ->nullOnDelete();
            $table->foreign('source_calibration_id', 'fk_calibration_notifications_source_calibration')
                ->references('calibration_id')
                ->on('calibration')
                ->nullOnDelete();
            $table->foreign('resolution_calibration_id', 'fk_calibration_notifications_resolution_calibration')
                ->references('calibration_id')
                ->on('calibration')
                ->nullOnDelete();

            $table->unique(['recipient_user_id', 'event_key', 'occurrence_revision'], 'uq_calibration_notifications_recipient_event');
            $table->index(['recipient_user_id', 'resolved_at', 'read_at', 'created_at'], 'ix_calibration_notifications_recipient_active_read');
            $table->index(['recipient_user_id', 'created_at'], 'ix_calibration_notifications_recipient_created');
            $table->index(['instrument_type_id', 'event_type', 'due_date_snapshot'], 'ix_calibration_notifications_pair_event');
            $table->index('source_calibration_id', 'ix_calibration_notifications_source_calibration');
            $table->index(['recipient_user_id', 'event_key', 'occurrence_revision'], 'ix_calibration_notifications_recipient_event_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_notifications');
    }
};
