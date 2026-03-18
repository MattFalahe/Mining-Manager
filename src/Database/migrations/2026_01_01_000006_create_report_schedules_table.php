<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReportSchedulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the report_schedules table for automated report generation
     * and adds schedule_id foreign key to mining_reports.
     */
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('report_type', 50);
            $table->string('format', 10)->default('json');
            $table->string('frequency', 20)->default('daily');
            $table->time('run_time')->default('00:00');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run')->nullable();
            $table->timestamp('next_run')->nullable();
            $table->unsignedInteger('reports_generated')->default(0);
            $table->boolean('send_to_discord')->default(false);
            $table->unsignedBigInteger('webhook_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index('next_run');
            $table->index(['is_active', 'next_run']);
        });

        Schema::table('mining_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_id')->nullable()->after('generated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mining_reports', function (Blueprint $table) {
            $table->dropColumn('schedule_id');
        });

        Schema::dropIfExists('report_schedules');
    }
}
