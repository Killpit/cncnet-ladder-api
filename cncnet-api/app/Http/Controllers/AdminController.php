<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use \App\Http\Services\LadderService;
use \App\Http\Services\AdminService;
use \Carbon\Carbon;
use \App\User;
use \App\MapPool;
use \App\Ladder;
use \App\SpawnOptionString;
use App\GameObjectSchema;
use App\Helpers\GameHelper;
use App\Http\Services\LeaguePlayerService;
use App\Http\Services\UserRatingService;
use App\Player;
use App\PlayerRating;
use App\UserRating;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    private $ladderService;
    private $adminService;

    public function __construct()
    {
        $this->ladderService = new LadderService();
        $this->adminService = new AdminService();
    }

    public function getAdminIndex(Request $request)
    {
        return view("admin.index", [
            "ladders" => $this->ladderService->getLatestLadders(),
            "clan_ladders" => $this->ladderService->getLatestLadders(),
            "all_ladders" => \App\Ladder::all(),
            "schemas" => \App\GameObjectSchema::managedBy($request->user()),
            "user" => $request->user(),
        ]);
    }

    public function getCanceledMatches($ladderAbbreviation = null)
    {
        $ladder = \App\Ladder::where('abbreviation', $ladderAbbreviation)->first();

        if ($ladder == null)
            abort(404);

        $matches = \App\QmCanceledMatch::where('qm_canceled_matches.ladder_id', $ladder->id)
            ->join('players as p', 'qm_canceled_matches.player_id', '=', 'p.id')
            ->orderBy('qm_canceled_matches.created_at', 'DESC')
            ->select("qm_canceled_matches.*", "p.username")
            ->paginate(50);

        return view("admin.canceled-matches", [
            "canceled_matches" => $matches,
            "ladder" => $ladder
        ]);
    }

    public function getWashedGames($ladderAbbreviation = null)
    {
        $ladder = \App\Ladder::where('abbreviation', $ladderAbbreviation)->first();

        if ($ladder == null)
            abort(404);

        $ladderHistory = $ladder->currentHistory();

        $washedGames = \App\GameAudit::where('game_audit.ladder_history_id', $ladderHistory->id)
            ->orderBy('game_audit.created_at', 'DESC')
            ->paginate(10);

        return view("admin.washed-games", [
            "washed_games" => $washedGames,
            "ladderHistory" => $ladderHistory
        ]);
    }

    public function getLadderSetupIndex(Request $request, $ladderId = null)
    {
        $ladder = \App\Ladder::find($ladderId);

        if ($ladder === null)
            return null;

        $ladders = $this->ladderService->getLatestLadders();
        $clan_ladders = $this->ladderService->getLatestClanLadders();
        $objectSchemas = GameObjectSchema::all();
        $rule = $ladder->qmLadderRules;
        $mapPools = $ladder->mapPools;
        $maps = $ladder->maps;
        $user = $request->user();
        $spawnOptions = \App\SpawnOption::all();

        return view("admin.ladder-setup", compact(
            'ladders',
            'ladder',
            'clan_ladders',
            'objectSchemas',
            'rule',
            'mapPools',
            'maps',
            'user',
            'spawnOptions'
        ));
    }

    public function getGameSchemaSetup(Request $request, $gameSchemaId)
    {
        $gameSchema = \App\GameObjectSchema::find($gameSchemaId);
        if ($gameSchema === null)
            abort(404);

        $countableGameObjects = $gameSchema->countableGameObjects;

        $heapNames = \App\CountableObjectHeap::all();
        $managers = $gameSchema->managers;

        return view("admin.schema-setup", compact('gameSchema', 'countableGameObjects', 'heapNames', 'managers'));
    }

    public function saveSchemaManager(Request $request, $gameSchemaId)
    {
        $gameSchema = \App\GameObjectSchema::find($gameSchemaId);
        if ($gameSchema === null)
            abort(404);

        $user = \App\User::where('email', '=', $request->email)->first();

        if ($user === null)
        {
            $request->session()->flash('error', "No user with email: {$request->email}");
            return redirect()->back();
        }

        $manager = \App\ObjectSchemaManager::firstOrCreate(['game_object_schema_id' => $gameSchema->id, 'user_id' => $user->id]);

        $request->session()->flash('success', "Manager added");
        return redirect()->back();
    }

    public function saveGameSchema(Request $request, $gameSchemaId)
    {
        $gameSchema = \App\GameObjectSchema::find($gameSchemaId);
        if ($gameSchema === null)
            abort(404);

        $gameSchema->name = $request->name;
        $gameSchema->save();

        $request->session()->flash('success', "Schema has been updated");
        return redirect()->back();
    }


    public function saveGameObject(Request $request, $gameSchemaId, $objectId)
    {
        $gameSchema = \App\GameObjectSchema::find($gameSchemaId);
        if ($gameSchema === null)
            abort(404);

        $object = \App\CountableGameObject::find($objectId);

        if ($request->id == "new")
        {
            $object = new \App\CountableObjectHeap;
        }
        else if ($object === null)
        {
            abort(404);
        }

        $request->session()->flash('success', "Object has been updated");
        $object->fill($request->all());
        $object->save();
        return redirect()->back();
    }

    public function postLadderSetupRules(Request $request, $ladderId)
    {
        return $this->adminService->saveQMLadderRulesRequest($request, $ladderId);
    }

    public function editSpawnOptionValue(Request $request)
    {
        if ($request->id == "new")
            $sov = new \App\SpawnOptionValue;
        else
            $sov = \App\SpawnOptionValue::find($request->id);

        if ($request->update !== null && $request->update == 2)
        {
            $sov->delete();
            $request->session()->flash('success', "Option has been deleted.");
        }
        else
        {
            if ($sov == null)
            {
                $sov = new \App\SpawnOptionValue;
            }

            $sov->ladder_id = $request->ladder_id;
            $sov->qm_map_id = $request->qm_map_id;
            $sov->spawn_option_id = $request->spawn_option_id;
            $sov->value_id = SpawnOptionString::findOrCreate($request->value)->id;
            $sov->save();
            $request->session()->flash('success', "Option has been updated.");
        }

        return redirect()->back();
    }

    public function getManageUsersIndex(Request $request)
    {
        $hostname = $request->hostname;
        $userId = $request->userId;
        $search = $request->search;
        $players = null;
        $users = null;

        if ($request->user() == null || !$request->user()->isAdmin())
            return response('Unauthorized.', 401);

        if ($search)
        {
            $players = Cache::remember("admin/users/{$search}", 20, function () use ($search)
            {
                return \App\Player::where('username', '=', $search)->get();
            });
        }
        else if ($userId)
        {
            $users = Cache::remember("admin/users/users/{$userId}", 20, function () use ($userId)
            {
                return \App\User::where("id", $userId)->get();
            });
        }

        return view("admin.manage-users", [
            "users" => $users != null ? $users : [],
            "players" => $players != null ? $players : [],
            "search" => $search,
            "userId" => $userId,
            "hostname" => $hostname
        ]);
    }

    public function getEditUser(Request $request)
    {
        $user = User::where("id", $request->userId)->first();

        return view("admin.edit-user", [
            "user" => $user
        ]);
    }

    public function updateUser(Request $request)
    {
        $user = User::where("id", $request->userId)->first();

        if ($request->removeUserAvatar == "on")
        {
            $user->removeAvatar();
        }

        if ($request->restrictAvatarUpload == "on")
        {
            $user->restrictAvatarUpload(true);
        }
        else
        {
            $user->restrictAvatarUpload(false);
        }

        $user->updateAlias($request->alias);

        return view("admin.edit-user", [
            "user" => $user
        ]);
    }

    public function getManageGameIndex(Request $request, $cncnetGame = null)
    {
        $ladder = \App\Ladder::where("abbreviation", "=", $cncnetGame)->first();

        if ($ladder == null)
            return "No ladder";

        $date = Carbon::now();
        $start = $date->startOfMonth()->toDateTimeString();
        $end = $date->endOfMonth()->toDateTimeString();

        $history = \App\LadderHistory::where("starts", "=", $start)
            ->where("ends", "=", $end)
            ->where("ladder_id", "=", $ladder->id)
            ->first();

        $games = \App\Game::where("ladder_history_id", "=", $history->id)->orderBy("id", "DESC")->limit(100);
        return view("admin.manage-games", ["games" => $games, "ladder" => $ladder, "history" => $history]);
    }

    public function deleteGame(Request $request)
    {
        $game = \App\Game::find($request->game_id);
        if ($game == null) return "Game not found";

        // Just remove the game_report_id linkage rather than actually delete anything
        $game->game_report_id = null;
        $game->save;

        return redirect()->back();
    }

    public function switchGameReport(Request $request)
    {
        $game = \App\Game::find($request->game_id);
        if ($game === null) return "Game not found";

        $gameReport = $game->allReports()->find($request->game_report_id);
        if ($gameReport === null) return "Game Report not found";

        $currentReport = $game->report()->first();
        if ($currentReport !== null)
        {
            $currentReport->best_report = false;
            $currentReport->save();
        }

        $game->game_report_id = $request->game_report_id;
        $game->save();

        $gameReport->best_report = true;
        $gameReport->save();

        $this->ladderService->undoPlayerCache($currentReport);
        $this->ladderService->updatePlayerCache($gameReport);
        return redirect()->back();
    }

    public function washGame(Request $request)
    {
        $this->adminService->doWashGame($request->game_id, $request->user()->name);
        return redirect()->back();
    }

    public function remSide(Request $request, $ladderId = null)
    {
        $ladder = \App\Ladder::find($ladderId);

        if ($ladder === null || $ladderId === null)
        {
            $request->session()->flash('error', "Ladder not found");
            return redirect()->back();
        }

        $side = $ladder->sides()->where('local_id', '=', $request->local_id)->first();
        if ($side === null)
        {
            $request->session()->flash('error', 'Side not found');
            return redirect()->back();
        }
        $side->delete();

        $request->session()->flash('success', 'Side deleted');
        return redirect()->back();
    }

    public function addSide(Request $request, $ladderId = null)
    {
        $ladder = \App\Ladder::find($ladderId);

        if ($ladder === null || $ladderId === null)
        {
            $request->session()->flash('error', "Ladder not found");
            return redirect()->back();
        }

        $side = $ladder->sides()->where('local_id', '=', $request->local_id)->first();
        if ($side === null)
        {
            $side = new \App\Side;
            $side->ladder_id = $ladder->id;
            $side->local_id = $request->local_id;
        }
        $side->name = $request->name;
        $side->save();
        $request->session()->flash('success', "Side has been added or updated");

        return redirect()->back();
    }


    public function addAdmin(Request $request, $ladderId = null)
    {
        if ($ladderId === null)
        {
            $request->session()->flash('error', "No ladder specified");
        }

        $user = \App\User::where('email', '=', $request->email)->first();
        if ($request->email === null || $user == null)
        {
            $request->session()->flash('error', "Unable to add the user as admin");
            return redirect()->back();
        }

        $ladderAdmin = \App\LadderAdmin::findOrCreate($user->id, $ladderId);

        $ladderAdmin->admin = true;
        $ladderAdmin->moderator = true;
        $ladderAdmin->tester = true;
        $ladderAdmin->save();

        $request->session()->flash('success', "The user {$user->email} has been made promoted to admin");
        return redirect()->back();
    }

    public function removeAdmin(Request $request, $ladderId = null)
    {
        if ($ladderId === null)
        {
            $request->session()->flash('error', "No ladder specified");
        }

        if ($request->ladder_admin_id === null)
        {
            $request->session()->flash('error', "Unable to remove the user");
            return redirect()->back();
        }

        $ladderAdmin = \App\LadderAdmin::find($request->ladder_admin_id);
        if ($ladderAdmin === null)
        {
            $request->session()->flash('error', "Unable to find the requested admin");
            return redirect()->back();
        }

        $ladderAdmin->admin = false;
        $ladderAdmin->moderator = false;
        $ladderAdmin->tester = false;
        $ladderAdmin->save();

        $request->session()->flash('success', "The admin {$ladderAdmin->user->email} has been removed");
        return redirect()->back();
    }

    public function addModerator(Request $request, $ladderId = null)
    {
        if ($ladderId === null)
        {
            $request->session()->flash('error', "No ladder specified");
        }

        $user = \App\User::where('email', '=', $request->email)->first();
        if ($request->email === null || $user == null)
        {
            $request->session()->flash('error', "Unable to add the user as moderator");
            return redirect()->back();
        }

        $ladderAdmin = \App\LadderAdmin::findOrCreate($user->id, $ladderId);

        $ladderAdmin->admin = false;
        $ladderAdmin->moderator = true;
        $ladderAdmin->tester = true;
        $ladderAdmin->save();

        $request->session()->flash('success', "The user {$user->email} has been made promoted to moderator");
        return redirect()->back();
    }

    public function removeModerator(Request $request, $ladderId = null)
    {
        if ($ladderId === null)
        {
            $request->session()->flash('error', "No ladder specified");
        }

        if ($request->ladder_admin_id === null)
        {
            $request->session()->flash('error', "Unable to remove the user");
            return redirect()->back();
        }

        $ladderAdmin = \App\LadderAdmin::find($request->ladder_admin_id);
        if ($ladderAdmin === null)
        {
            $request->session()->flash('error', "Unable to find the requested moderator");
            return redirect()->back();
        }

        $ladderAdmin->admin = false;
        $ladderAdmin->moderator = false;
        $ladderAdmin->tester = false;
        $ladderAdmin->save();

        $request->session()->flash('success', "The moderator {$ladderAdmin->user->email} has been removed");
        return redirect()->back();
    }

    public function addTester(Request $request, $ladderId = null)
    {
        if ($ladderId === null)
        {
            $request->session()->flash('error', "No ladder specified");
        }

        $user = \App\User::where('email', '=', $request->email)->first();
        if ($request->email === null || $user == null)
        {
            $request->session()->flash('error', "Unable to add the user as tester");
            return redirect()->back();
        }

        $ladderAdmin = \App\LadderAdmin::findOrCreate($user->id, $ladderId);

        $ladderAdmin->admin = false;
        $ladderAdmin->moderator = false;
        $ladderAdmin->tester = true;
        $ladderAdmin->save();

        $request->session()->flash('success', "The user {$user->email} has been made promoted to tester");
        return redirect()->back();
    }

    public function removeTester(Request $request, $ladderId = null)
    {
        if ($ladderId === null)
        {
            $request->session()->flash('error', "No ladder specified");
        }

        if ($request->ladder_admin_id === null)
        {
            $request->session()->flash('error', "Unable to remove the user");
            return redirect()->back();
        }

        $ladderAdmin = \App\LadderAdmin::find($request->ladder_admin_id);
        if ($ladderAdmin === null)
        {
            $request->session()->flash('error', "Unable to find the requested tester");
            return redirect()->back();
        }

        $ladderAdmin->admin = false;
        $ladderAdmin->moderator = false;
        $ladderAdmin->tester = false;
        $ladderAdmin->save();

        $request->session()->flash('success', "The tester {$ladderAdmin->user->email} has been removed");
        return redirect()->back();
    }

    public function getLadderPlayer(Request $request, $ladderId = null, $playerId = null)
    {
        $mod = $request->user();
        if ($playerId === null)
            return;

        $player = \App\Player::find($playerId);

        if ($player === null || !$mod->isLadderMod($player->ladder))
            return;

        $user = $player->user;

        $ladderService = new LadderService;
        $history = $ladderService->getActiveLadderByDate(Carbon::now()->format('m-Y'), $player->ladder->abbreviation);

        return view(
            "admin.moderate-player",
            [
                "mod"    => $mod,
                "player" => $player,
                "user"   => $user,
                "bans"   => $user->bans()->orderBy('created_at', 'DESC')->get(),
                "ladder" => $player->ladder,
                "history" => $history
            ]
        );
    }

    public function getUserBan(Request $request, $ladderId = null, $playerId = null, $banType = 0)
    {
        $mod = $request->user();
        if ($playerId === null)
            return;

        $player = \App\Player::find($playerId);

        if ($player === null || !$mod->isLadderMod($player->ladder))
            return;

        $user = $player->user;

        return view(
            "admin.edit-ban",
            [
                "mod"    => $mod,
                "player" => $player,
                "user"   => $user,
                "ladder" => $player->ladder,
                "id" => null,
                "expires" => null,
                "admin_id" => $mod->id,
                "user_id" => $user->id,
                "ban_type" => $banType,
                "internal_note" => "",
                "plubic_reason" => "",
                "ip_address_id" => $user->ip->id,
                "start_or_end" => false,
                "banDesc" => \App\Ban::typeToDescription($banType) . " - " . \App\Ban::banStyle($banType)
            ]
        );
    }

    public function editUserBan(Request $request, $ladderId = null, $playerId = null, $banId = null)
    {
        $mod = $request->user();
        if ($playerId === null)
            return;

        $player = \App\Player::find($playerId);

        if ($player === null || !$mod->isLadderMod($player->ladder))
            return;

        $ban = \App\Ban::find($banId);
        if ($player === null)
            return;

        $user = $player->user;

        return view(
            "admin.edit-ban",
            [
                "mod"    => $mod,
                "player" => $player,
                "user"   => $user,
                "ladder" => $player->ladder,
                "id" => $ban->id,
                "expires" => $ban->expires->eq(\App\Ban::unstartedBanTime()) ? null : $ban->expires,
                "admin_id" => $mod->id,
                "user_id" => $user->id,
                "ban_type" => $ban->ban_type,
                "internal_note" => $ban->internal_note,
                "plubic_reason" => $ban->plubic_reason,
                "ip_address_id" => $ban->ip_address_id,
                "start_or_end" => false,
                "banDesc" => \App\Ban::typeToDescription($ban->ban_type) . " - " . \App\Ban::banStyle($ban->ban_type)
            ]
        );
    }

    public function saveUserBan(Request $request, $ladderId = null, $playerId = null, $banId = null)
    {
        $mod = $request->user();
        if ($playerId === null)
            return;
        $player = \App\Player::find($playerId);

        if ($player === null || !$mod->isLadderMod($player->ladder))
            return;

        if ($player === null)
            return;

        $user = $player->user;

        $ban = \App\Ban::find($banId);
        if ($ban === null)
        {
            $ban = new \App\Ban;
        }

        foreach ($ban->fillable as $col)
        {
            if ($request->has($col))
            {
                $ban[$col] = $request[$col];
            }
        }
        $ban->save();

        $banFlash = \App\Ban::banStyle($request->ban_type);

        if ($request->start_or_end)
        {
            if ($ban->expires !== null && $ban->expires->gt(Carbon::now()))
            {
                $ban->expires = Carbon::now();
                $banFlash = "has ended.";
            }
            else if ($ban->expires === null || $ban->expires->eq(\App\Ban::unstartedBanTime()))
            {
                $ban->checkStartBan(true);
                $banFlash = "has started.";
            }
            else
            {
                $ban->checkStartBan(false);
            }
        }
        else
            $ban->checkStartBan(false);

        $ban->save();

        $request->session()->flash('success', "Ban " . $banFlash);
        return redirect()->action('AdminController@getLadderPlayer', ['ladderId' => $ladderId, 'playerId' => $playerId]);
    }

    public function editLadderAlert(Request $request, $ladderId)
    {
        $this->validate($request, [
            'message'   => 'string|required',
            'expires_at' => 'required'
        ]);

        $ladder = \App\Ladder::find($ladderId);

        $alert = \App\LadderAlert::find($request->id);

        if ($request->submit == "delete")
        {
            $alert->delete();
            $request->session()->flash('success', "Alert has been deleted");
            return redirect()->back();
        }

        if ($request->id == 'new' || $request->id === null)
        {
            $alert = new \App\LadderAlert;
            $alert->ladder_id = $ladder->id;
        }

        if ($alert === null)
        {
            $request->session()->flash('error', "No alert found with id " . $request->id);
            return redirect()->back();
        }

        $alert->message = $request->message;
        $alert->expires_at = $request->expires_at;
        $alert->save();

        $request->session()->flash('success', "Alert has been updated");
        return redirect()->back();
    }

    public function editPlayerAlert(Request $request, $ladderId, $playerId)
    {
        $player = \App\Player::find($playerId);

        $alert = \App\PlayerAlert::find($request->id);
        if ($request->submit == "delete")
        {
            $alert->delete();
            $request->session()->flash('success', "Alert has been deleted");
            return redirect()->back();
        }

        if ($request->id == 'new' || $request->id === null)
        {
            $alert = new \App\PlayerAlert;
            $alert->player_id = $player->id;
        }

        $alert->message = $request->message;
        $alert->expires_at = $request->expires_at;
        $alert->save();

        $request->session()->flash('success', "Alert has been updated");
        return redirect()->back();
    }

    /**
     * Set all player's points for their played games to 0 of a given ladder history.
     */
    public function laundryService(Request $request)
    {
        $ladderHistoryId = $request->ladderHistory_id;

        $playerId = $request->player_id;

        $ladderHistory = \App\LadderHistory::find($ladderHistoryId);

        if ($ladderHistory == null)
            $request->session()->flash('error', 'Unabled to find ladder history');

        $playerCache = \App\PlayerCache::where('player_id', '=', $playerId)
            ->where('ladder_history_id', '=', $ladderHistoryId)
            ->first();
        if ($playerCache == null)
            $request->session()->flash('error', 'Unabled to find player cache');

        //Query for the player's game reports from the ladder history month
        $playerGameReports = \App\PlayerGameReport::where('player_id', '=', $playerId)->where('created_at', '<', $ladderHistory->ends)->where('created_at', '>', $ladderHistory->starts)->get();

        //set the players points to 0
        foreach ($playerGameReports as $playerGameReport)
        {
            if ($playerGameReport->points != 0)
            {
                $playerGameReport->backupPts = $playerGameReport->points;
                $playerGameReport->points = 0;
                $playerGameReport->save();
            }
        }

        $playerCache->points = 0;
        $playerCache->save();

        $request->session()->flash('success', "Player games have been laundered");
        return redirect()->back();
    }

    /**
     * Reverse Launder and restore a player's points from their games.
     */
    public function undoLaundryService(Request $request)
    {
        $ladderHistoryId = $request->ladderHistory_id;

        $playerId = $request->player_id;

        $ladderHistory = \App\LadderHistory::find($ladderHistoryId);

        if ($ladderHistory == null)
            $request->session()->flash('error', 'Unabled to find ladder history');

        $playerCache = \App\PlayerCache::where('player_id', '=', $playerId)
            ->where('ladder_history_id', '=', $ladderHistoryId)
            ->first();

        if ($playerCache == null)
            $request->session()->flash('error', 'Unabled to find player cache');

        $player = \App\Player::find($playerId);
        if ($player == null)
            $request->session()->flash('error', 'Unabled to find player');

        //Query for the player's game reports from the ladder history month
        $playerGameReports = \App\PlayerGameReport::where('player_id', '=', $playerId)
            ->where('created_at', '<', $ladderHistory->ends)
            ->where('created_at', '>', $ladderHistory->starts)->get();

        //reset player's points from games played using backupPts
        foreach ($playerGameReports as $playerGameReport)
        {
            if ($playerGameReport->backupPts != 0)
            {
                $playerGameReport->points = $playerGameReport->backupPts;
            }
            $playerGameReport->backupPts = 0;
            $playerGameReport->save();
        }

        $playerCache->points = $player->points($ladderHistory);
        $playerCache->save();

        $request->session()->flash('success', "Player games have been reset");
        return redirect()->back();
    }

    public function getPlayerRatings(Request $request, $ladderAbbreviation = null)
    {
        if ($ladderAbbreviation == null)
        {
            $ladderAbbreviation = GameHelper::$GAME_BLITZ;
        }

        $ladder = Ladder::where("abbreviation", $ladderAbbreviation)->first();

        if ($request->search)
        {
            $byPlayer = Player::where("username", "like", "%" . $request->search . "%")
                ->where("ladder_id", $ladder->id)
                ->get();

            if ($byPlayer == null)
            {
                $request->session()->flash('error', "Player " . $request->search . " not found");
                return redirect()->back();
            }

            $users = User::join("user_ratings as ur", "ur.user_id", "=", "users.id")
                ->orderBy("ur.rating", "DESC")
                ->whereIn("users.id", $byPlayer->pluck("user_id"))
                ->select(["users.*", "ur.rating", "ur.rated_games", "ur.peak_rating"])
                ->paginate(50);
        }
        else
        {
            $users = User::join("user_ratings as ur", "ur.user_id", "=", "users.id")
                ->orderBy("ur.rating", "DESC")
                ->select(["users.*", "ur.rating", "ur.rated_games", "ur.peak_rating"])
                ->paginate(50);
        }

        $history = $ladder->currentHistory();
        $ladders = Ladder::all();

        return view("admin.players.ratings", [
            "ladder" => $ladder,
            "users" => $users,
            "ladders" => $ladders,
            "history" => $history,
            "abbreviation" => $ladderAbbreviation,
            "search" => $request->search
        ]);
    }

    public function updatePlayerRatings(Request $request, $ladderAbbreviation)
    {
        $ladder = Ladder::where("abbreviation", $ladderAbbreviation)->first();
        if ($ladder == null)
        {
            abort(404, "Ladder not found");
        }

        $history = $ladder->currentHistory();
        $userRatingService = new UserRatingService();
        $userRatingService->calculateUserTiers($history);

        $request->session()->flash('success', "User ratings have been updated");
        return redirect()->back();
    }

    public function changeUserRating(Request $request, $abbreviation)
    {
        $ladder = Ladder::where("abbreviation", $abbreviation)->first();
        $history = $ladder->currentHistory();

        $user = User::find($request->user_id);
        if ($user == null)
        {
            $request->session()->flash('error', "User not found");
            return redirect()->back();
        }

        $userRatingService = new UserRatingService();
        $userRatingService->changeUserRating($user, $request->new_rating, $history);

        $request->session()->flash('success', "User rating updated");
        return redirect()->back();
    }

    public function changeLeaguePlayerStatus(Request $request, $abbreviation)
    {
        $ladder = Ladder::where("abbreviation", $abbreviation)->first();

        $user = User::find($request->user_id);
        if ($user == null)
        {
            $request->session()->flash('error', "User not found");
            return redirect()->back();
        }

        $leaguePlayerService = new LeaguePlayerService();
        $userCanPlayBothTiers = $request->canPlayBothTiers;

        if ($userCanPlayBothTiers == "on")
        {
            $leaguePlayerService->updateLeaguePlayer($ladder->id, $user->id, true);
        }
        else
        {
            $leaguePlayerService->deleteLeaguePlayer($ladder->id, $user->id);
        }

        $request->session()->flash('success', "Match making preference updated");
        return redirect()->back();
    }

    public function editPlayerName(Request $request)
    {
        $this->validate($request, [
            'player_name' => 'required|string|regex:/^[a-zA-Z0-9_\[\]\{\}\^\`\-\\x7c]+$/|max:11', //\x7c = | aka pipe
        ]);

        $history = \App\LadderHistory::where('id', $request->history_id)->first();

        $player = \App\Player::where('id', $request->player_id)
            ->where('ladder_id', $history->ladder->id)
            ->first();

        if ($player == null) 
        {
            $request->session()->flash('error', "No existing player found with this player id");
            return redirect()->back();
        }

        $playerCaches = \App\PlayerCache::where('player_id', $player->id)->get();

        if ($playerCaches == null || $playerCaches->count() == 0)
        {
            $request->session()->flash('error', "No player caches found with this player id");
            return redirect()->back();
        }

        $newName = $request->player_name;
        $newName = trim($newName);

        //check if this name already belongs to another player in this ladder
        $existing_players = \App\Player::where('username', $newName)
            ->where('ladder_id', $history->ladder->id)
            ->get();
        if ($existing_players->count() > 0)
        {
            $request->session()->flash('error', "This username is already taken for this ladder.");
            return redirect()->back();
        }

        //update player name to the new name
        $player->username = $newName;
        $player->save();

        //update player name in player caches
        foreach ($playerCaches as $playerCache)
        {
            $playerCache->player_name = $player->username;
            $playerCache->save();
        }

        $url = \App\URLHelper::getPlayerProfileUrl($history, $player->username);
        $request->session()->flash('success', "Player name has been updated to " . $player->username);
        return redirect()->to($url);
    }
}

function ini_to_b($string)
{
    if ($string == "Null") return null;
    if ($string == "Random") return -1;
    return $string == "Yes" ? true : false;
}
