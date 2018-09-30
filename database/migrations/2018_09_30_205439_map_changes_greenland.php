<?php

use App\Territory;
use App\Occupation;
use Illuminate\Database\Migrations\Migration;

class MapChangesGreenland extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Territory::where('id', 607)->update(['x' => 2116, 'y' => 157]);
        Territory::where('id', 606)->update(['x' => 2244, 'y' => 16]);
        Territory::where('id', 604)->update(['x' => 2124, 'y' => 17]);

        $t1 = new Territory(['x' => 2042, 'y' => 21, 'size' => 4034]); $t1->save();
        $t1->borders()->sync([604, 605]);
        $t2 = new Territory(['x' => 2155, 'y' => 64, 'size' => 4304]); $t2->save();
        $t2->borders()->sync([604, 605, 606, 607]);
        $t3 = new Territory(['x' => 2056, 'y' => 141, 'size' => 3066]); $t3->save();
        $t3->borders()->sync([43, 605, 607]);

        Territory::find(604)->borders()->sync([$t1->id, $t2->id, $t3->id, 605, 606]);
        Territory::find(605)->borders()->sync([$t1->id, $t2->id, $t3->id, 604, 607]);
        Territory::find(606)->borders()->sync([608, 604, $t2->id]);
        Territory::find(607)->borders()->sync([605, $t2->id, $t3->id]);

        Occupation::where('territory_id', 607)->update(['territory_id' => $t3->id]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
