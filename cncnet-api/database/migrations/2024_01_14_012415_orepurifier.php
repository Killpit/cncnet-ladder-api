<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class Orepurifier extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \App\SpawnOption::makeOne(\App\SpawnOptionType::SPAWNMAP_INI, "GAOREP.TechLevel", "GAOREP", "TechLevel")->save();
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
