<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPrefixToMiningTaxCodes extends Migration
{
    public function up()
    {
        Schema::table('mining_tax_codes', function (Blueprint $table) {
            $table->string('prefix', 20)->nullable()->after('code');
        });

        // Backfill existing codes with current prefix setting
        try {
            $settings = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
            $taxRates = $settings->getTaxRates();
            $prefix = $taxRates['tax_code_prefix'] ?? 'TAX-';
        } catch (\Exception $e) {
            $prefix = config('mining-manager.wallet.tax_code_prefix', 'TAX-');
        }

        \Illuminate\Support\Facades\DB::table('mining_tax_codes')
            ->whereNull('prefix')
            ->update(['prefix' => $prefix]);
    }

    public function down()
    {
        Schema::table('mining_tax_codes', function (Blueprint $table) {
            $table->dropColumn('prefix');
        });
    }
}
