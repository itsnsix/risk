<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('dad_user_id')->nullable();
            $table->string('name');
            $table->string('color')->unique();
            $table->string('image')->nullable();
            $table->unsignedInteger('house_id')->nullable();
            $table->timestamps();

            $table->foreign('house_id')->references('id')->on('houses');
        });

        Schema::table('houses', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
