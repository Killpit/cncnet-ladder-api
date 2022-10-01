<?php

namespace App\Commands;

use App\Commands\Command;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldBeQueued;
use DB;
use Carbon\Carbon;
use App\QmMatch;
use App\QmMatchPlayer;
use App\QmQueueEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class FindOpponent extends Command implements ShouldQueue
{

    use InteractsWithQueue, SerializesModels;

    public $qEntryId = null;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct($id)
    {
        //
        $this->qEntryId = $id;
    }

    public function queue($queue, $arguments)
    {
        $queue->pushOn('findmatch', $arguments);
    }

    /**
     * Execute the command.
     *
     * @return void
     */
    public function handle()
    {
        $this->delete();
        $qEntry = QmQueueEntry::find($this->qEntryId);

        if ($qEntry === null)
        {
            Log::info("FindOpponent ** qEntry is null.");
            return;
        }

        $qEntry->touch();

        $qmPlayer = $qEntry->qmPlayer;

        // A player could cancel out of queue before this function runs
        if ($qmPlayer === null)
        {
            Log::info("FindOpponent ** qmPlayer is null.");
            $qEntry->delete();
            return;
        }

        // Skip if the player has already been matched up
        if ($qmPlayer->qm_match_id !== null)
        {
            Log::info("FindOpponent ** qmPlayer->qm_match_id is not null.");
            $qEntry->delete();
            return;
        }

        $history = $qEntry->ladderHistory;

        if ($history === null)
        {
            Log::info("FindOpponent ** history is null.");
            $qEntry->delete();
            return;
        }

        $ladder = $history->ladder;

        if ($ladder === null)
        {
            //error_log("ladder is null");
            Log::info("FindOpponent ** ladder is null.");
            $qEntry->delete();
            return;
        }

        $player = $qmPlayer->player;

        if ($player === null)
        {
            Log::info("FindOpponent ** player is null.");
            $qEntry->delete();
            return;
        }

        $rating = $player->rating->rating;
        // map_bitfield is an old and unused bit of code
        $qmPlayer->map_bitfield = 0xffffffff;

        // This will throw an exception when a not null field is null
        $qmPlayer->save();

        $ladder = $qmPlayer->ladder;
        $ladder_rules = $ladder->qmLadderRules;

        /* Try to find a matchup
         * Matchups are based on the player's rating,
         * The absolute value of the difference of me and every other player is calculated.
         * Any players whose difference is greater 100 is thrown out with some exceptions
         * If a player has been waiting a long time for a matchup he should get some special
         * treatment.  To allow for this, the player rating difference gets wait time, in
         * seconds, subtracted from it.
         * If 2 players rated 1200, and 1400 are the only players a match won't be made
         * until one player has been waiting for 100 seconds 1400-1200-100seconds = 100
         *
         * The ratio of seconds is tunable per ladder
         */

        $user = $qmPlayer->player->user;
        $userSettings = $user->userSettings;

        $opponentEntries = QmQueueEntry::where('qm_match_player_id', '<>', $qEntry->qmPlayer->id)
            ->where('ladder_history_id', '=', $history->id)
            ->get(); //fetch all opponents who are currently in queue for this ladder

        $opponentEntriesFiltered = (new QmQueueEntry())->newCollection(); //a collection of qm opponents who are within point filter but also includes opponents who have mutual point filter disabled

        foreach ($opponentEntries as $opponentEntry)
        {
            $oppPlayer = $opponentEntry->qmPlayer->player;
            $oppUserSettings = $oppPlayer->user->userSettings; //opponent's point filter flag

            if ($userSettings->disabledPointFilter && $oppUserSettings->disabledPointFilter)
            {
                // both players have the point filter disabled, we will ignore the point filter
                Log::info("FindOpponent ** Ignoring point filter for " . $qmPlayer->player->username . ' and ' . $oppPlayer->username);
                $opponentEntriesFiltered->add($opponentEntry);
            }
            else
            {
                //(updated_at - created_at) / 60 = seconds duration player has been waiting in queue
                $points_time = ((strtotime($qEntry->updated_at) - strtotime($qEntry->created_at))) * $ladder_rules->points_per_second;

                //is the opponent within the point filter
                if ($points_time + $ladder_rules->max_points_difference > ABS($qEntry->points - $opponentEntry->points))
                {
                    $opponentEntriesFiltered->add($opponentEntry);
                }
            }
        }

        $qmOpns = $opponentEntriesFiltered->shuffle();

        Log::info("FindOpponent ** Opponents found: " . $qmOpns->count());

        if ($qmOpns->count() >= $ladder_rules->player_count - 1)
        {
            //error_log("checking qmOpns\n");
            Log::info("FindOpponent ** checking qmOpns");

            // Randomly choose the opponents from the best matches. To prevent
            // long runs of identical matchups.
            $qmOpns = $qmOpns->shuffle()->take($ladder_rules->player_count - 1);
            // Randomly select a map
            $common_qm_maps = array();

            $qmMaps = $ladder->mapPool->maps;
            foreach ($qmMaps as $qmMap)
            {
                $match = true;
                if (
                    array_key_exists($qmMap->bit_idx, $qmPlayer->map_side_array())
                    &&
                    $qmPlayer->map_side_array()[$qmMap->bit_idx] > -2
                    &&
                    in_array($qmPlayer->map_side_array()[$qmMap->bit_idx], $qmMap->sides_array())
                )
                {
                    foreach ($qmOpns as $qOpn)
                    {
                        //error_log("qOpn->rating_time = {$qOpn->rating_time}");
                        $opn = $qOpn->qmPlayer;
                        if ($opn === null)
                        {
                            $qOpn->delete();
                            $qEntry->delete();
                            return;
                        }

                        if (
                            array_key_exists($qmMap->bit_idx, $opn->map_side_array())
                            &&
                            ($opn->map_side_array()[$qmMap->bit_idx] < -1
                                ||
                                !in_array($opn->map_side_array()[$qmMap->bit_idx], $qmMap->sides_array()))
                        )
                        {
                            $match = false;
                        }
                    }
                }
                else
                {
                    $match = false;
                }

                if ($match)
                {
                    $common_qm_maps[] = $qmMap;
                }
            }

            $qEntry->delete();

            $reduceMapRepeats = $ladder->qmLadderRules->reduce_map_repeats;

            if ($reduceMapRepeats > 0) //remove the recent maps from common_qm_maps
            {
                $playerGameReports = $player->playerGames()
                    ->where("ladder_history_id", "=", $history->id)
                    ->where("disconnected", "=", 0)
                    ->where("no_completion", "=", 0)
                    ->where("draw", "=", 0)
                    ->orderBy('created_at', 'DESC')
                    ->limit($reduceMapRepeats)
                    ->get();

                $recentMaps = $playerGameReports->map(function ($item)
                {
                    return $item->game->map;
                });
                $recentMaps = $recentMaps->filter(function ($value)
                {
                    return !is_null($value);
                });

                foreach ($recentMaps as $recentMap)
                {
                    $common_qm_maps = removeMap($recentMap, $common_qm_maps);
                }

                foreach ($qmOpns as $qOpn)
                {
                    $oppPlayer = $qOpn->qmPlayer->player;
                    $oppPlayerGames = $oppPlayer->playerGames()
                        ->where("ladder_history_id", "=", $history->id)
                        ->where("disconnected", "=", 0)
                        ->where("no_completion", "=", 0)
                        ->where("draw", "=", 0)
                        ->orderBy('created_at', 'DESC')
                        ->limit($reduceMapRepeats)
                        ->get();

                    $recentMaps = $oppPlayerGames->map(function ($item)
                    {
                        return $item->game->map;
                    });
                    $recentMaps = $recentMaps->filter(function ($value)
                    {
                        return !is_null($value);
                    });

                    foreach ($recentMaps as $recentMap) //remove the recent maps from common_qm_maps
                    {
                        $common_qm_maps = removeMap($recentMap, $common_qm_maps);
                    }
                }
            }

            if (count($common_qm_maps) < 1)
            {
                Log::info("FindOpponent ** No common maps available");

                $qmPlayer->touch();
                return;
            }

            $randomMapIndex = mt_rand(0, count($common_qm_maps) - 1);

            Log::info("FindOpponent ** Create QmMatch");

            // Create the qm_matches db entry
            $qmMatch = new \App\QmMatch();
            $qmMatch->ladder_id = $qmPlayer->ladder_id;
            $qmMatch->qm_map_id = $common_qm_maps[$randomMapIndex]->id;
            $qmMatch->seed = mt_rand(-2147483647, 2147483647);


            Log::info("FindOpponent ** Create Game");

            // Create the Game
            $game = \App\Game::genQmEntry($qmMatch);
            $qmMatch->game_id = $game->id;
            $qmMatch->save();

            $game->qm_match_id = $qmMatch->id;
            $game->save();

            $qmMap = $qmMatch->map;
            $spawn_order = explode(',', $qmMap->spawn_order);

            // Set up player specific information
            // Color will be used for spawn location
            $qmPlayer->color = 0;
            $qmPlayer->location = $spawn_order[$qmPlayer->color] - 1;
            $qmPlayer->qm_match_id = $qmMatch->id;
            $qmPlayer->tunnel_id = $qmMatch->seed + $qmPlayer->color;

            $psides = explode(',', $qmPlayer->mapSides->value);

            if (count($psides) > $qmMap->bit_idx)
                $qmPlayer->actual_side = $psides[$qmMap->bit_idx];


            if ($qmPlayer->actual_side < -1)
            {
                $qmPlayer->actual_side = $qmPlayer->chosen_side;
            }

            $qmPlayer->save();

            $perMS = array_values(array_filter($qmMap->sides_array(), function ($s)
            {
                return $s >= 0;
            }));
            $color = 1;
            foreach ($qmOpns as $qOpn)
            {
                $opn = $qOpn->qmPlayer;
                $qOpn->delete();

                if ($opn === null)
                {
                    $qEntry->delete();
                    return;
                }

                $osides = explode(',', $opn->mapSides->value);

                if (count($osides) > $qmMap->bit_idx)
                    $opn->actual_side = $osides[$qmMap->bit_idx];

                if ($opn->actual_side  < -1)
                {
                    $opn->actual_side = $opn->chosen_side;
                }

                if ($opn->actual_side == -1)
                {
                    $opn->actual_side = $perMS[mt_rand(0, count($perMS) - 1)];
                }
                $opn->color = $color++;
                $opn->location = $spawn_order[$opn->color] - 1;
                $opn->qm_match_id = $qmMatch->id;
                $opn->tunnel_id = $qmMatch->seed + $opn->color;
                $opn->save();
            }

            if ($qmPlayer->actual_side == -1)
            {
                $qmPlayer->actual_side = $perMS[mt_rand(0, count($perMS) - 1)];
            }
            $qmPlayer->save();
        }
    }
}

/**
 * Remove this 'Map' from this array of 'QmMaps'.
 * The function will loop through the array of common_qm_maps and check if equal to the $recentmMap
 */
function removeMap($recentMap, $common_qm_maps)
{
    $new_common_qm_maps = [];

    foreach ($common_qm_maps as $common_qm_map)
    {
        if ($common_qm_map->map->id != $recentMap->id)
        {
            $new_common_qm_maps[] = $common_qm_map;
        }
    }

    return $new_common_qm_maps;
}
