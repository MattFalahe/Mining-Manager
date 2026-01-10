<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTriggeredByToMiningTaxes extends Migration
{
    public function up()
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->string('triggered_by', 255)->nullable()->after('notes');
        });
    }

    public function down()
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->dropColumn('triggered_by');
        });
    }
}
