<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Pocket Mechanic's tables. PostgreSQL doesn't index foreign keys by itself, so
 * the ones used for lookups and cascades are indexed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->jsonb('value')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('lubelogger');
            $table->string('filename');
            $table->string('sha256', 64)->index();
            $table->string('status')->index();
            $table->string('work_path')->nullable();
            $table->jsonb('options')->nullable();
            $table->jsonb('preview')->nullable();
            $table->jsonb('summary')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('nickname')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('make');
            $table->string('model');
            $table->string('license_plate')->nullable();
            $table->string('fuel_type')->default('gasoline');
            $table->string('distance_unit')->default('km');
            $table->string('fuel_unit')->default('l');
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 14, 3)->nullable();
            $table->date('sold_date')->nullable();
            $table->decimal('sold_price', 14, 3)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('tags')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('import_id')->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_user', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['vehicle_id', 'user_id']);
        });

        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('category')->index();
            $table->date('date');
            $table->unsignedInteger('mileage')->nullable();
            $table->string('description');
            $table->decimal('cost', 14, 3)->default(0);
            $table->text('notes')->nullable();
            $table->jsonb('links')->nullable();
            $this->commonColumns($table);
            $table->index(['vehicle_id', 'date']);
        });

        Schema::create('fuel_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('mileage')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('cost', 14, 3)->default(0);
            $table->boolean('is_full')->default(true);
            $table->boolean('missed_previous')->default(false);
            $table->unsignedTinyInteger('soc_start')->nullable();
            $table->unsignedTinyInteger('soc_end')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('links')->nullable();
            $this->commonColumns($table);
            $table->index(['vehicle_id', 'date']);
        });

        Schema::create('odometer_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('mileage');
            $table->text('notes')->nullable();
            $this->commonColumns($table);
            $table->index(['vehicle_id', 'date']);
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->index()->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->text('notes')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('due_mileage')->nullable();
            $table->unsignedSmallInteger('repeat_every')->nullable();
            $table->string('repeat_unit')->nullable();
            $table->unsignedInteger('repeat_distance')->nullable();
            $table->boolean('fixed_schedule')->default(false);
            $table->date('snoozed_until')->nullable();
            $table->date('last_completed_on')->nullable();
            $table->unsignedInteger('last_completed_mileage')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('tags')->nullable();
            $table->unsignedBigInteger('import_id')->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
        });

        Schema::create('record_reminder', function (Blueprint $table) {
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_id')->index()->constrained()->cascadeOnDelete();
            $table->primary(['record_id', 'reminder_id']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->index()->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->text('notes')->nullable();
            $table->string('category')->default('service');
            $table->string('priority')->default('normal');
            $table->string('status')->default('planned')->index();
            $table->decimal('cost', 14, 3)->nullable();
            $table->foreignId('completed_record_id')->nullable()->index()->constrained('records')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $this->commonColumns($table);
        });

        Schema::create('plan_reminder', function (Blueprint $table) {
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_id')->index()->constrained()->cascadeOnDelete();
            $table->primary(['plan_id', 'reminder_id']);
        });

        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            // Who added it: deleting that account keeps the supply (and its stock history) for the vehicle.
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('part_number')->nullable();
            $table->string('supplier')->nullable();
            $table->text('notes')->nullable();
            $this->commonColumns($table);
        });

        Schema::create('supply_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->index()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('type');
            // Signed: purchases are positive, usage negative.
            $table->decimal('quantity', 14, 3);
            $table->decimal('cost', 14, 3)->default(0);
            $table->nullableMorphs('consumer');
            $table->string('note')->nullable();
            $table->unsignedBigInteger('import_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->index()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('pinned')->default(false);
            $this->commonColumns($table);
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $this->commonColumns($table);
        });

        Schema::create('equipment_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->index()->constrained('equipment')->cascadeOnDelete();
            $table->date('installed_on');
            $table->unsignedInteger('installed_mileage')->nullable();
            $table->date('removed_on')->nullable();
            $table->unsignedInteger('removed_mileage')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        foreach ([
            'equipment_periods', 'equipment', 'notes',
            'supply_movements', 'supplies', 'plan_reminder', 'plans', 'record_reminder',
            'reminders', 'odometer_readings', 'fuel_records', 'records', 'vehicle_user', 'vehicles',
            'backup_imports', 'app_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function commonColumns(Blueprint $table): void
    {
        $table->jsonb('tags')->nullable();
        $table->unsignedBigInteger('import_id')->nullable()->index();
        $table->unsignedBigInteger('source_id')->nullable();
        $table->timestamps();
    }
};
