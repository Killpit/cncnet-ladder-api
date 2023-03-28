<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use \App\Http\Services\LadderService;
use \App\Http\Services\GameService;
use \App\Http\Services\PlayerService;
use \App\Http\Services\PointService;
use \App\Http\Services\AdminService;
use \Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use \App;
use Illuminate\Support\Facades\Log;
use DB;
use Exception;

class ApiLadderController extends Controller
{
    private $ladderService;
    private $gameService;
    private $playerService;
    private $adminService;

    public function __construct()
    {
        $this->ladderService = new LadderService();
        $this->gameService = new GameService();
        $this->playerService = new PlayerService();
        $this->adminService = new AdminService();
    }

    public function pingLadder(Request $request)
    {
        return "pong";
    }

    public function getLadder(Request $request, $game = null)
    {
        return $this->ladderService->getLadderByGameAbbreviation($game);
    }

    public function newPostLadder(Request $request, $ladderId, $gameId, $playerId, $pingSent, $pingReceived)
    {
        $ladder = App\Ladder::find($ladderId);
        $player = App\Player::find($playerId);

        // Player checks
        $check = $this->ladderService->checkPlayer($request, $player->username, $ladder);
        if ($check !== null)
        {
            return $check;
        }

        $filePath = config('filesystems')['dmp'];
        $fileName = $gameId . '.' . $ladderId . '.' . $playerId . '.dmp';
        $file = $request->file('file')->move($filePath, $fileName);

        $this->dispatch(new App\Commands\SaveLadderResult($filePath . '/' . $fileName, $ladderId, $gameId, $playerId, $pingSent, $pingReceived));

        return response()->json(['success' => 'Queued for processing.'], 200);
    }

    public function saveLadderResult($file, $ladderId, $gameId, $playerId, $pingSent, $pingReceived)
    {
        $ladder = App\Ladder::find($ladderId);
        $player = App\Player::find($playerId);
        $game = App\Game::find($gameId);

        # Game stats result
        $result = $this->gameService->processStatsDmp($file, $ladder->game, $ladder);

        if (count($result) == 0 || $result == null)
        {
            return response()->json(['No data'], 400);
        }

        $history = $game->ladderHistory;

        # Keep a record of the raw stats sent in
        $this->gameService->saveRawStats($result, $game->id, $history->id);
        $this->gameService->fillGameCols($game, $result);

        # Now save the processed stats
        $result = $this->gameService->saveGameStats($result, $game->id, $player->id, $ladder, $ladder->game);
        $gameReport = $result['gameReport'];

        if ($gameReport === null)
        {
            return response()->json(['Error' => $result['error']], 400);
        }

        $gameReport->pings_sent = $pingSent;
        $gameReport->pings_received = $pingReceived;
        $gameReport->save();

        # Award points
        $status = $this->awardPoints($gameReport, $history);

        # Dispute handling
        $this->handleGameDispute($gameReport);

        # Achievements
        $stats = $gameReport->playerGameReports()->where('player_id', $playerId)->first()->stats;

        if ($ladderId == 8 || $ladderId == 1) //toggle achievements on for Blitz and YR
            $this->updateAchievements($playerId, $history->ladder, $stats);

        if ($gameReport->best_report == true && $gameReport->duration == 3 && $gameReport->fps == 33)
            $this->adminService->doWashGame($gameReport->game_id, "ladder-auto-wash");

        return response()->json(['success' => $status], 200);
    }

    public function handleGameDispute($gameReport)
    {
        $game = $gameReport->game()->first();

        if ($game->game_report_id == $gameReport->id)
        {
            $this->ladderService->updatePlayerCache($gameReport);
            return;
        }

        $allReports = $game->allReports()->get();

        $bestReport = $game->report()->first();

        // If we're not the best report and the best report is disconnected
        // I'm disconnected then we wash the game
        if (($bestReport->disconnected() && $gameReport->disconnected())
            ||
            ($bestReport->oos && $gameReport->oos)
        )
        {
            $wash = new \App\GameReport();
            $wash->game_id = $gameReport->game_id;
            $wash->player_id = $gameReport->player_id;
            $wash->best_report = false;
            $wash->manual_report = true;
            $wash->duration = $gameReport->duration;
            $wash->valid = true;
            $wash->finished = false;
            $wash->fps = $gameReport->fps;
            $wash->oos = false;
            $wash->save();

            foreach ($gameReport->playerGameReports()->get() as $pgr)
            {
                $playerGR = new \App\PlayerGameReport;
                $playerGR->game_report_id = $wash->id;
                $playerGR->game_id = $pgr->game_id;
                $playerGR->player_id = $pgr->player_id;
                $playerGR->local_id = $pgr->local_team_id;
                $playerGR->local_team_id = $pgr->local_team_id;
                $playerGR->points = 0;
                $playerGR->disconnected = true;
                $playerGR->no_completion = false;
                $playerGR->quit = false;
                $playerGR->won = false;
                $playerGR->defeated = false;
                $playerGR->draw = true;
                $playerGR->spectator = $pgr->spectator;
                $playerGR->save();
            }

            if (($gameReport->pings_sent - $gameReport->pings_received + 5)
                <
                $bestReport->pings_sent - $bestReport->pings_received
            )
            {
                $bestReport->best_report = false;
                $gameReport->best_report = true;
                $game->game_report_id = $gameReport->id;
                $game->save();
                $gameReport->save();
                $bestReport->save();
                $this->ladderService->undoPlayerCache($bestReport);
                $this->ladderService->updatePlayerCache($gameReport);
                return;
            }
            else if ($gameReport->pings_sent - $gameReport->pings_received < 7)
            {
                $bestReport->best_report = false;
                $wash->best_report = true;
                $game->game_report_id = $wash->id;
                $game->save();
                $wash->save();
                $bestReport->save();
                $this->ladderService->undoPlayerCache($bestReport);
                $this->ladderService->updatePlayerCache($wash);
                return;
            }

            return;
        }

        // Prefer the report who saw the end of the game
        if ($gameReport->finished && (!$bestReport->finished || $bestReport->quit))
        {
            $bestReport->best_report = false;
            $gameReport->best_report = true;
            $game->game_report_id = $gameReport->id;
            $game->save();
            $gameReport->save();
            $bestReport->save();
            $this->ladderService->undoPlayerCache($bestReport);
            $this->ladderService->updatePlayerCache($gameReport);
            return;
        }

        // Prefer the longer game
        if ($bestReport->duration + 5 < $gameReport->duration)
        {
            $bestReport->best_report = false;
            $gameReport->best_report = true;
            $game->game_report_id = $gameReport->id;
            $game->save();
            $gameReport->save();
            $bestReport->save();
            $this->ladderService->undoPlayerCache($bestReport);
            $this->ladderService->updatePlayerCache($gameReport);
            return;
        }
    }

    public function awardPoints($gameReport, $history)
    {
        $players = [];
        $playerGameReports = $gameReport->playerGameReports()->get();

        // Oops we don't have any players
        if ($playerGameReports->count() < 1)
        {
            return 604;
        }

        if ($gameReport->fps < $history->ladder->qmLadderRules->bail_fps)
        {
            // FPS too low, no points awarded
            return 630;
        }

        if ($gameReport->duration < $history->ladder->qmLadderRules->bail_time)
        {
            // Duration too low, no points awarded
            return 660;
        }

        $disconnected = 0;

        foreach ($playerGameReports as $playerGR)
        {
            $ally_average = 0;
            $ally_points = 0;
            $ally_count = 0;
            $enemy_average = 0;
            $enemy_points = 0;
            $enemy_count = 0;
            $enemy_games = 0;

            // dd($playerGameReports);
            foreach ($playerGameReports as $pgr)
            {
                $other = $this->playerService->findUserRatingByPlayerId($pgr->player_id);
                $players[] = $other;

                if ($pgr->local_team_id == $playerGR->local_team_id)
                {
                    $ally_average += $other->rating;
                    $ally_points += $pgr->player->pointsBefore($history, $pgr->game_id);
                    $ally_count++;
                }
                else
                {
                    $enemy_average += $other->rating;
                    $enemy_points += $pgr->player->pointsBefore($history, $pgr->game_id);
                    $enemy_count++;
                    $enemy_games += $pgr->player->totalGames($history);
                }
            }

            $ally_average /= $ally_count;
            $enemy_average /= $enemy_count;
            $elo_k = $this->playerService->getEloKvalue($players);
            $points = null;
            $base_rating = $enemy_average > $ally_average ? $enemy_average : $ally_average;

            $gvc = 8;
            if ($history->ladder->qmLadderRules->use_elo_points)
            {
                $gvc = ceil(($base_rating * $enemy_average) / 230000);
            }

            $wol_k = $history->ladder->qmLadderRules->wol_k;
            $diff = $enemy_points - $ally_points;
            $we = 1 / (pow(10, abs($diff) / 600) + 1);
            $we = $diff > 0 && $playerGR->wonOrDisco() ? 1 - $we : ($diff < 0 && !$playerGR->wonOrDisco() ? 1 - $we : $we);
            $wol = (int)($wol_k * $we);

            $eloAdjust = 0;

            if ($playerGR->draw)
            {
                $playerGR->points = 0;
            }
            else if ($playerGR->wonOrDisco())
            {
                $points = (new PointService(16, $ally_average, $enemy_average, 1, 0))->getNewRatings()["a"];
                $diff = (int)($points - $ally_average);
                if (!$history->ladder->qmLadderRules->use_elo_points)
                {
                    $diff = 0;
                }

                $playerGR->points = $gvc + $diff + $wol;

                $eloAdjust = new PointService($elo_k, $ally_average, $enemy_average, 1, 0);

                if ($gameReport->best_report)
                {
                    $this->playerService->updateUserRating($playerGR->player_id, $eloAdjust->getNewRatings()["a"]);
                }
            }
            else
            {
                if ($enemy_games < 10)
                {
                    $wol = (int)($wol * ($enemy_games / 10));
                }
                if ($ally_points  < ($wol + $gvc) * 10)
                {
                    $playerGR->points = -1 * (int)($ally_points / 10);
                }
                else
                {
                    $playerGR->points = -1 * ($wol + $gvc);
                }

                $eloAdjust = new PointService($elo_k, $ally_average, $enemy_average, 0, 1);
                if ($gameReport->best_report)
                {
                    $this->playerService->updateUserRating(
                        $playerGR->player_id,
                        $eloAdjust->getNewRatings()["a"]
                    );
                }
            }

            $playerGR->save();

            $pc = $playerGR->player->playerCache($history->id);

            if ($playerGR->points < 0 && ($pc === null || $pc->points < 0))
            {
                $playerGR->points = 0;
                $playerGR->save();
            }
        }

        return 200;
    }

    public function getAllLadders(Request $request)
    {
        return $this->ladderService->getAllLadders();
    }

    public function getCurrentLadders(Request $request)
    {
        return $this->ladderService->getLadders(false);
    }

    public function getLadderGame(Request $request, $game = null, $gameId = null)
    {
        return $this->ladderService->getLadderGameById($game, $gameId);
    }

    public function getLadderPlayer(Request $request, $game = null, $player = null)
    {
        $date = Carbon::now()->format('m-Y');
        $ladderService = $this->ladderService;
        return Cache::remember("$date/$game/$player", 5, function () use ($ladderService, $date, $game, $player)
        {
            $history = $ladderService->getActiveLadderByDate($date, $game);
            return $ladderService->getLadderPlayer($history, $player);
        });
    }

    public function viewRawGame(Request $request, $gameId)
    {
        $rawGame = \App\GameRaw::where("game_id", "=", $gameId)->get();

        return response($rawGame, 200)->header('Content-Type', 'application/json');
    }

    public function getLadderTopList(Request $request, $cncnetGame = null, $count = 10)
    {
        if ($count > 100) $count = 100;

        return Cache::remember(
            "$cncnetGame/top/$count",
            5,
            function () use (&$cncnetGame, &$count)
            {
                $date = Carbon::now()->format('m-Y');
                $history = $this->ladderService->getActiveLadderByDate($date, $cncnetGame);
                $players = \App\PlayerCache::where('ladder_history_id', '=', $history->id)->orderBy('points', 'DESC')->limit($count)->get();
                $top = [];
                foreach ($players as $player)
                {
                    $top[] = ["name" => $player->player_name, "points" => $player->points];
                }
                return $top;
            }
        );
    }

    public function getLadderRecentGamesList(Request $request, $cncnetGame = null, $count = 10)
    {
        if ($count > 100) $count = 100;

        return Cache::remember(
            "$cncnetGame/games/recent/$count",
            5,
            function () use (&$request, &$cncnetGame, &$count)
            {
                $date = Carbon::now()->format('m-Y');
                $recentGames = $this->ladderService->getRecentValidLadderGames($date, $request->game, $count);

                foreach ($recentGames as $rg)
                {
                    $rg["url"] = "/ladder/" . $date . "/" . $request->game . "/games/" . $rg->id;
                    $rg["map_url"] = "/images/maps/" . $request->game . "/" . $rg->hash . ".png";
                    $rg["players"] = $rg->playerGameReports()
                        ->select("won", "player_id", "points", "no_completion", "quit", "defeated", "draw")
                        ->get();

                    foreach ($rg["players"] as $p)
                    {
                        $p["username"] = $p->player()->first()->username;
                        $p["url"] = "/ladder/" . $date . "/" . $request->game . "/player/" . $p->username;
                    }
                }
                return $recentGames;
            }
        );
    }

    public function getLadderWinners(Request $request, $cncnetGame)
    {
        $prevWinners = [];
        $prevLadders = [];

        $prevLadders[] = $this->ladderService->getPreviousLaddersByGame($cncnetGame, 5)->splice(0, 1);

        foreach ($prevLadders as $h)
        {
            foreach ($h as $history)
            {
                $prevWinners[] = [
                    "game" => $history->ladder->game,
                    "short" => $history->short,
                    "full" => $history->ladder->name,
                    "abbreviation" => $history->ladder->abbreviation,
                    "ends" => $history->ends,
                    "players" => \App\PlayerCache::where('ladder_history_id', '=', $history->id)->orderBy('points', 'desc')->get()->splice(0, 2)
                ];
            }
        }

        return $prevWinners;
    }

    public function reRunDisconnectionPoints()
    {
        $grs = \App\GameReport::where('game_reports.created_at', '>', '2018-03-01 00:00:00')
            ->where('disconnected', '=', true)->where('points', '>', 0)
            ->join('player_game_reports', 'player_game_reports.game_report_id', '=', 'game_reports.id')
            ->orderBy('game_reports.id', 'ASC')->select('game_reports.*')->get();

        foreach ($grs as $gr)
        {
            error_log("{$gr->game_id}, {$gr->player_id}");
            $this->awardPoints($gr, $gr->game->ladderHistory);
        }
    }

    public function countMapVetos($ladderId)
    {
        $ladder = \App\Ladder::find($ladderId);
        $qmMapSides = \App\QmMatchPlayer::select('map_sides')
            ->where('ladder_id', '=', $ladderId)
            ->whereNotNull('qm_match_id')->where('qm_match_id', '>', 90932)
            ->get();

        $map_vetos_raw = [];
        foreach ($qmMapSides as $ms)
        {
            $map_sides = explode(',', $ms->map_sides);
            $index = 0;
            foreach ($map_sides as $side)
            {
                if ($side == -2)
                {
                    if (!array_key_exists($index, $map_vetos_raw))
                        $map_vetos_raw[$index] = 1;
                    else
                        $map_vetos_raw[$index]++;
                }
                $index++;
            }
        }
        $map_vetos = [];
        foreach ($map_vetos_raw as $index => $count)
        {
            $map = \App\QmMap::where('ladder_id', '=', $ladderId)->where('bit_idx', '=', $index)->where('valid', '=', true)->first();
            if ($map !== null)
                $map_vetos[$map->admin_description] = $count;
            else
                $map_vetos[$index] = $count;
        }
        return $map_vetos;
    }

    public function countUniqueMapVetos($ladderId)
    {
        $ladder = \App\Ladder::find($ladderId);
        $qmMapSides = \App\QmMatchPlayer::select('map_sides')
            ->where('ladder_id', '=', $ladderId)
            ->whereNotNull('qm_match_id')->where('qm_match_id', '>', 90932)
            ->groupBy('player_id')
            ->orderBy('id', 'desc')
            ->get();

        $map_vetos_raw = [];
        foreach ($qmMapSides as $ms)
        {
            $map_sides = explode(',', $ms->map_sides);
            $index = 0;
            foreach ($map_sides as $side)
            {
                if ($side == -2)
                {
                    if (!array_key_exists($index, $map_vetos_raw))
                        $map_vetos_raw[$index] = 1;
                    else
                        $map_vetos_raw[$index]++;
                }
                $index++;
            }
        }
        $map_vetos = [];
        foreach ($map_vetos_raw as $index => $count)
        {
            $map = \App\QmMap::where('ladder_id', '=', $ladderId)->where('bit_idx', '=', $index)->where('valid', '=', true)->first();
            if ($map !== null)
                $map_vetos[$map->admin_description] = $count;
            else
                $map_vetos[$index] = $count;
        }
        return $map_vetos;
    }

    private function updateAchievements($playerId, $ladder, $stats)
    {
        if ($stats === null)
            return;

        $user = \App\Player::where('id', $playerId)->first()->user;

        //fetch achievements that have not been unlocked for this ladder
        $achievements = \App\Achievement::where('ladder_id', $ladder->id)->get();

        //fetch the game object counts from the game stats for this game
        $gocs = \App\GameObjectCounts::where('stats_id', $stats->id)->get();

        foreach ($gocs as $goc)
        {
            $count = $goc->count;
            $objectName = $goc->countableGameObject->name;
            $heapName = $goc->countableGameObject->heap_name;

            //fetch the achievement that uses this heap_name and object_name
            $unitCareerAchievements = $achievements->filter(function ($value) use (&$objectName, &$heapName)
            {
                return $value->object_name === $objectName && $value->heap_name === $heapName && $value->achievement_type === "CAREER";
            })->sortBy("unlock_count");

            $this->achievementCheck($user, $unitCareerAchievements, $count, "CAREER");

            $unitImmediateAchievements = $achievements->filter(function ($value) use (&$objectName, &$heapName)
            {
                return $value->object_name === $objectName && $value->heap_name === $heapName && $value->achievement_type === "IMMEDIATE";
            })->sortBy("unlock_count");
            $this->achievementCheck($user, $unitImmediateAchievements, $count, "IMMEDIATE");
        }

        $pgr = $stats->playerGameReport;
        $won = $pgr->wonOrDisco();

        //update player won achievement
        if ($won)
        {
            $winAchievements = $achievements->filter(function ($value) use (&$ladder)
            {
                return $value->tag == "Win " . $ladder->name . " QM Games";
            })->sortBy("unlock_count");

            $this->achievementCheck($user, $winAchievements, 1);

            $country = $stats->cty;

            if ($country < 5) //allied
            {
                $winAllied = $achievements->filter(function ($value)
                {
                    return $value->tag === 'Allied: Win QM Games';
                })->sortBy('unlock_count');

                $this->achievementCheck($user, $winAllied, 1);
            }
            else if ($country > 4 && $country < 9) //soviet
            {
                $winSoviet = $achievements->filter(function ($value)
                {
                    return $value->tag === 'Soviet: Win QM Games';
                })->sortBy('unlock_count');

                $this->achievementCheck($user, $winSoviet, 1);
            }
            else if ($country == 9) //yuri
            {
                $winYuri = $achievements->filter(function ($value)
                {
                    return $value->tag === 'Yuri: Win QM Games';
                })->sortBy('unlock_count');

                $this->achievementCheck($user, $winYuri, 1);
            }

            //win 'x' amount of QMs in one month
            $winGames = $achievements->filter(function ($value)
            {
                return $value->tag === 'Win QM Games in One Month';
            })->sortBy('unlock_count');

            // $this->monthlyAchievement($user, $lockedAchievement, 1);
        }

        //play 'x' amount of QMs in one month
        $lockedAchievement = $achievements->filter(function ($value)
        {
            return $value->tag === 'Play Games in one Month';
        })->sortBy('unlock_count');

        //$this->monthlyAchievement($user, $lockedAchievement, 1);
    }

    private function monthlyAchievement($user, $lockedAchievement, $count)
    {
        if ($lockedAchievement == null || $lockedAchievement->achievement_id == null)
            return;

        $lockedAchievementProgress = \App\AchievementProgress::where('achievement_id', $lockedAchievement->achievement_id)
            ->where('user_id', $user->id)
            ->first();

        if ($lockedAchievementProgress == null)
        {
            $lockedAchievementProgress = new \App\AchievementProgress();
            $lockedAchievementProgress->achievement_id = $lockedAchievement->achievement_id;
            $lockedAchievementProgress->user_id = $user->id;
            $lockedAchievementProgress->count = 0;
            $lockedAchievementProgress->save();
        }

        $currentMonth = Carbon::now()->month; //get the month this game was played in

        $lastUpdateMonth = $lockedAchievementProgress->updated_at->month; //get the month that the most recent game was played in

        if ($lastUpdateMonth != $currentMonth) //current game was played in a different month, set count to 0
            $lockedAchievementProgress->count = 0;


        $lockedAchievementProgress->count += $count;

        if ($lockedAchievementProgress->count >= $lockedAchievement->unlock_count)
        {
            $lockedAchievementProgress->count = $lockedAchievement->unlock_count; //since user has hit or exceeded the required unlock count, set their count to the unlock count
            $lockedAchievementProgress->achievement_unlocked_date = Carbon::now();
        }
        $lockedAchievementProgress->save();
    }

    /**
     * Tick achievement tracker then check if achievement is unlocked.
     */
    private function achievementCheck($user, $achievements, $count, $type = "CAREER")
    {
        foreach ($achievements as $achievement)
        {
            $achievementProgress = \App\AchievementProgress::where('achievement_id', $achievement->id)
                ->where('user_id', $user->id)
                ->first();

            if ($achievementProgress == null)  //first time user is making progress towards this achievement
            {
                $achievementProgress = new \App\AchievementProgress();
                $achievementProgress->achievement_id = $achievement->id;
                $achievementProgress->count = 0;
                $achievementProgress->user_id = $user->id;
                $achievementProgress->save();
            }
            else if ($achievementProgress->achievement_unlocked_date != null) //achievement already unlocked
            {
                continue; //go to next achievement
            }

            if ($type == "CAREER") //career achievement logic
            {
                $achievementProgress->count += $count;

                if ($achievementProgress->count >= $achievement->unlock_count)
                {
                    $achievementProgress->count = $achievement->unlock_count; //since user has hit or exceeded the required unlock count, set their count to the unlock count
                    $achievementProgress->achievement_unlocked_date = Carbon::now();
                }
                $achievementProgress->save();
            }
            else if ($type == "IMMEDIATE") //immediate achievement logic
            {
                if ($count >= $achievement->unlock_count)
                {
                    $achievementProgress->count = $achievement->unlock_count;
                    $achievementProgress->achievement_unlocked_date = Carbon::now();
                    $achievementProgress->save();
                }
            }

            break; //only increment one achievement at a time until it's unlocked
        }
    }
}
