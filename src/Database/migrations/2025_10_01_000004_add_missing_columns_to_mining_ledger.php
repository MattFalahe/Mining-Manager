<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add missing columns to mining_ledger table
        Schema::table('mining_ledger', function (Blueprint $table) {
            // Add ore value calculation columns
            $table->decimal('unit_price', 20, 2)->default(0)->after('quantity');
            $table->decimal('ore_value', 20, 2)->default(0)->after('unit_price');
            $table->decimal('mineral_value', 20, 2)->default(0)->after('ore_value');
            $table->decimal('total_value', 20, 2)->default(0)->after('mineral_value');
            
            // Add tax calculation columns
            $table->decimal('tax_rate', 5, 2)->default(0)->after('total_value');
            $table->decimal('tax_amount', 20, 2)->default(0)->after('tax_rate');
            
            // Add metadata columns
            $table->string('ore_type')->nullable()->after('type_id');
            $table->integer('corporation_id')->unsigned()->nullable()->after('character_id');
            $table->boolean('is_taxable')->default(true)->after('tax_amount');
            $table->text('notes')->nullable()->after('is_taxable');
            
            // Add indexes for performance
            $table->index('corporation_id');
            $table->index('ore_type');
            $table->index('is_taxable');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropColumn([
                'unit_price',
                'ore_value',
                'mineral_value',
                'total_value',
                'tax_rate',
                'tax_amount',
                'ore_type',
                'corporation_id',
                'is_taxable',
                'notes'
            ]);
        });
    }
};
