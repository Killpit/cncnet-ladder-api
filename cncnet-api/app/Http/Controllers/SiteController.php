<?php

namespace App\Http\Controllers;

use App\Game;
use App\Http\Services\LadderService;
use App\Ladder;
use App\LadderHistory;
use App\News;
use App\QmMatch;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function getIndex(Request $request)
    {
        $ladderService = new LadderService();

        $news = News::orderBy("created_at", "desc")->limit(4)->get();

        return view("index", [
            "news" => $news,
            "ladders" => $ladderService->getLatestLadders(),
            "clan_ladders" => $ladderService->getLatestClanLadders(),
        ]);
    }

    public function getStats()
    {
        $date = Carbon::now();

        $end = $date->endOfMonth()->toDateTimeString();
        $start = $date->subMonths(12)->startOfMonth()->toDateTimeString();

        $ladderIds = Ladder::where("game", "yr")->pluck("id");
        $ladderHistoryIds = LadderHistory::where("starts", ">=", $start)
            ->where("ends", "<=", $end)
            ->whereIn("ladder_id", $ladderIds)
            ->pluck("id");

        $matchCount = Game::whereIn("ladder_history_id", $ladderHistoryIds)->groupBy("ladder_history_id")->count();
        return view("stats", ["start" => $start, "end" => $end, "matchCount" => $matchCount]);
    }

    public function getOBSHelp(Request $request)
    {
        return view("help.obs");
    }

    public function getClanLadderNews(Request $request)
    {
        return view("news.clans-coming-soon");
    }

    public function getDonate(Request $request)
    {
        return view("donate");
    }

    public function getStyleguide(Request $request)
    {
        return view("styleguide", ["ladders" => []]);
    }
}
