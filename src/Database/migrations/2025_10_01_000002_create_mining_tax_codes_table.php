<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMiningTaxCodesTable extends Migration
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
            $table->bigInteger('tax_id')->unsigned();
            $table->string('code', 16)->unique();
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->bigInteger('used_by_character_id')->unsigned()->nullable();
            $table->timestamps();

            // Foreign key
            $table->foreign('tax_id')
                  ->references('id')
                  ->on('mining_taxes')
                  ->onDelete('cascade');
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
}
