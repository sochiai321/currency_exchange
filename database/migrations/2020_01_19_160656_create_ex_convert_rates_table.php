<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateExConvertRatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ex_convert_rates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('from_currency');
            $table->string('to_currency');
            $table->decimal('rate', 20, 7);
            $table->decimal('inverse_rate', 20, 7);
            $table->dateTime('effective_date');
            $table->timestamps();
            $table->index(['from_currency', 'to_currency', 'effective_date']);
            $table->index(['effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ex_convert_rates');
    }
}
