<?php

namespace App\Http\Controllers;

use App\ClanCache;
use \Carbon\Carbon;
use App\LadderHistory;
use Illuminate\Http\Request;
use \App\Http\Services\LadderService;
use App\Ladder;

class LeagueChampionsController extends Controller
{
    private $ladderService;

    public function __construct()
    {
        $this->ladderService = new LadderService();
    }

    public function getLeagueChampions(Request $request, $game)
    {
        $prevWinners = [];
        $prevLadders = [];

        $ladder = Ladder::where("abbreviation", $game)->first();
        if ($ladder == null)
        {
            abort(404);
        }
        $prevLadders[] = $this->ladderService->getPreviousLaddersByGame($game, 10)->splice(0, 9);

        foreach ($prevLadders as $h)
        {
            foreach ($h as $history)
            {
                # Default
                $clans = null;
                $players = null;
                $tier = $request->tier ?? 1;

                if ($history->ladder->clans_allowed)
                {
                    $clans = ClanCache::where("ladder_history_id", "=", $history->id)
                        ->where("clan_name", "like", "%" . $request->search . "%")
                        ->orderBy("points", "desc")
                        ->get()
                        ->splice(0, 5);
                }
                else
                {
                    $players = \App\PlayerCache::where("ladder_history_id", "=", $history->id)
                        ->where("tier", "=", $tier)
                        ->where("player_name", "like", "%" . $request->search . "%")
                        ->orderBy("points", "desc")
                        ->get()
                        ->splice(0, 5);
                }

                $sides = \App\Side::where('ladder_id', '=', $history->ladder_id)
                    ->where('local_id', '>=', 0)
                    ->orderBy('local_id', 'asc')
                    ->lists('name');

                $prevWinners[] = [
                    "history" => $history,
                    "players" => $players,
                    "clans" => $clans,
                    "sides" => $sides
                ];
            }
        }

        return view(
            "champions.index",
            [
                "ladder" => $ladder,
                "isTierLeague" => $history->ladder->qmLadderRules->tier2_rating > 0,
                "isClanLadder" => $history->ladder->clans_allowed,
                "abbreviation" => $game,
                "ladders_winners" => $prevWinners,
                "ladders" => $this->ladderService->getLatestLadders(),
                "clan_ladders" => $this->ladderService->getLatestClanLadders()
            ]
        );
    }
}
