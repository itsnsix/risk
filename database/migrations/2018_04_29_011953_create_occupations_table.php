<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOccupationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('occupations', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('active');
            $table->unsignedInteger('api_data_id')->nullable();
            $table->unsignedInteger('territory_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('previous_occupation')->nullable();
            $table->timestamp('api_created_at')->nullable();
            $table->timestamps();

            $table->foreign('previous_occupation')->references('id')->on('occupations');
            $table->foreign('territory_id')->references('id')->on('territories');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('occupations');
    }
}
