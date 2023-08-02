<?php

namespace App\Console\Commands;

use App\Ladder;
use App\User;
use Exception;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class UpdatePlayerCache extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'update_player_cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates the player_caches table';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $updates = \App\PlayerCacheUpdate::get();

        foreach ($updates as $update)
        {
            $pc = \App\PlayerCache::find($update->player_cache_id);
            $update->delete();

            $player = $pc->player;
            $history = $pc->history;

            $user = User::find($player->user_id);
            $pc->ladder_history_id = $history->id;
            $pc->player_id = $player->id;
            $pc->player_name = $player->username;

            # PlayerHistory will never be null
            $ladder = Ladder::find($history->ladder_id);
            $tier = $user->getUserLadderTier($ladder)->tier;
            $pc->tier = $tier;

            $pc->card = $player->card_id;
            $pc->points = $player->points($history);
            $pc->wins = $player->wins($history);
            $pc->games = $player->totalGames($history);

            $v = $player->sideUsage($history)->first();
            $pc->side = $v !== null ? $v->sid : null;

            $v = $player->countryUsage($history)->first();
            $pc->country = $v !== null ? $v->cty : null;
            $pc->fps = $player->averageFPS($history);
            $pc->save();
        }
    }
}
