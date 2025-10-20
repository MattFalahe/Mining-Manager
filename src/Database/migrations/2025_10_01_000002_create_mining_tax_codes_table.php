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
        Schema::create('mining_tax_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('mining_tax_id')->unsigned();
            $table->bigInteger('character_id')->unsigned();
            $table->string('code', 20)->unique();
            $table->enum('status', ['active', 'used', 'expired', 'cancelled'])->default('active');
            $table->timestamp('generated_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->bigInteger('transaction_id')->unsigned()->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('mining_tax_id')->references('id')->on('mining_taxes')->onDelete('cascade');
            
            $table->index('mining_tax_id');
            $table->index('character_id');
            $table->index('code');
            $table->index('status');
            $table->index(['character_id', 'status']);
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mining_tax_codes');
    }
};
