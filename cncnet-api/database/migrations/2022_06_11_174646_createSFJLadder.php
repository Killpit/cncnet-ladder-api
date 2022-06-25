<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSFJLadder extends Migration {

/**
	 * Create a SFJ Ladder.
	 *
	 * @return void
	 */
	public function up()
	{
		$yrLadder = \App\Ladder::where('abbreviation', 'yr')->first();

		#create test ladder
		$sfjLadder = $yrLadder->replicate()->fill([
			'name' => 'SFJ',
			'abbreviation' => 'sfj',
		]);
		$sfjLadder->save();

		$lc = new \App\Http\Controllers\LadderController;
		$lc->addLadder($sfjLadder->id); #create ladder histories

		#add sides
		$sides = \App\Side::where('ladder_id', $yrLadder->id)->get();
		for ($i = 0; $i < count($sides); ++$i)
		{
			$side = new \App\Side();
			$side->ladder_id = $sfjLadder->id;
			$side->local_id = $i;
			$side->name = $sides[$i]->name;
			$side->save();
		}

		#create ladder rules			
		$yrLadderRules = \App\QmLadderRules::where('ladder_id', $yrLadder->id)->first();
		$newLadderRules = $yrLadderRules->replicate()->fill([
			'ladder_id' => $sfjLadder->id
		]);
		$newLadderRules->save();

		#Copy over the YR spawn options
		$options = \App\SpawnOptionValue::where('ladder_id', $yrLadder->id)->get();

		foreach ($options as $option)
		{
			$o = new \App\SpawnOptionValue;
			$o->ladder_id = $sfjLadder->id;
			$o->spawn_option_id = $option->spawn_option_id;
			$o->value_id = $option->value_id;
			$o->save();
		}

		#Copy over existing YR ladder Map Pool
		$newPool = new \App\MapPool;
		$newPool->name = 'SFJ Map Pool';
		$newPool->ladder_id = $sfjLadder->id;
		$newPool->save();

		#copy over maps
		$yrMaps = \App\Map::where('ladder_id', $yrLadder->id)->get();
		foreach ($yrMaps as $yrMap)
		{
			$newMap = $yrMap->replicate()->fill([
				'ladder_id' => $sfjLadder->id,
			]);
			$newMap->save();
		}

		$yrQmMaps = \App\QmMap::where('map_pool_id', $yrLadder->map_pool_id)->get();
		#copy yr qm maps
		foreach ($yrQmMaps as $yrQmMap)
		{
			$map_id = \App\Map::where('ladder_id', $yrLadder->id)
				->where('hash', $yrQmMap->map->hash)
				->first()->id;

			$newQmMap = $yrQmMap->replicate()->fill([
				'ladder_id' => $sfjLadder->id,
				'map_pool_id' => $newPool->id,
				'map_id' => $map_id
			]);
			$newQmMap->valid = 1;
			$newQmMap->save();
		}
	}

	/**
	 * Reverse the migrations, delete SFJ ladder data.
	 *
	 * @return void
	 */
	public function down()
	{
		$testLadder = \App\Ladder::where('abbreviation', 'sfj')->first();
		\App\QmMap::where('map_pool_id', $testLadder->map_pool_id)->delete();
		\App\Map::where('ladder_id', $testLadder->id)->delete();
		\App\SpawnOptionValue::where('ladder_id', $testLadder->id)->delete();
		\App\QmLadderRules::where('ladder_id', $testLadder->id)->delete();
		\App\Side::where('ladder_id', $testLadder->id)->delete();

		$testLadder->delete();
	}

}
