@extends('layouts.app')
@section('title', 'Ladder')

@section('cover')
/images/feature/feature-{{ $history->ladder->abbreviation }}.jpg
@endsection

@section('feature')
<div class="game">
    <div class="feature-background sub-feature-background">
        <div class="container">
            <div class="row">
                <div class="col-md-12 text-center">
                    <h1>
                        {{ $history->ladder->name }}
                    </h1>
                    <p>
                        <a href="/ladder" class="previous-link">
                            <i class="fa fa-caret-left" aria-hidden="true"></i>
                            <i class="fa fa-caret-left" aria-hidden="true"></i>
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('content')
<div class="game">

    <section class="game-statistics">
        <div class="game-details">
            <div class="container" style="position:relative;padding: 60px 0;">
                @foreach($playerGameReports as $k => $pgr)
                <?php $gameStats = $pgr->stats()->first(); ?>
                    @if ($gameStats != null)
                        <div class="hidden-xs faction faction-{{ $gameStats->faction($history->ladder->abbreviation, $gameStats->cty) }} @if($k&1) faction-right @else faction-left @endif"></div>
                    @endif
                @endforeach

                <div class="row">
                    <div class="col-md-12">

                        <h3 class="game-intro text-center">
                        @foreach($playerGameReports as $pgr)
                            <?php $player = $pgr->player()->first(); ?>
                                <span class="player">
                                    {{ $player->username or "Unknown" }} <strong>+{{ $pgr->points or "" }}</strong>
                                    @if($pgr->won)
                                        <i class="fa fa-trophy fa-fw" style="color: #E91E63;"></i>
                                    @else
                                        <i class="fa fa-sun-o fa-fw" style="color: #00BCD4;"></i>
                                    @endif
                                </span>

                                @if ($playerGameReports->count() == 1)
                                    <span class="player">
                                        {{ $player->username or "Unknown" }} <strong>+{{ $pgr->points or "" }}</strong>
                                        @if ($pgr->won)
                                            <i class="fa fa-trophy fa-fw" style="color: #E91E63;"></i>
                                        @else
                                            <i class="fa fa-sun-o fa-fw" style="color: #00BCD4;"></i>
                                        @endif
                                    </span>
                                @endif
                        @endforeach
                        </h3>

                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="dark-texture">
        <div class="container">
            <div class="row">
                @foreach($playerGameReports as $pgr)
                    <?php $gameStats = $pgr->stats()->first(); ?>
                    <?php $player = $pgr->player()->first() ?>
                    <?php $rank = $player->rank($history, $player->username); ?>
                    <?php $points = $player->playerPoints($history, $player->username); ?>

                    <div class="col-md-6">
                        <a href="/ladder/{{ $history->short . "/" . $history->ladder->abbreviation }}/player/{{ $player->username }}" class="profile-link">
                            <div class="profile-detail">
                                <div class="rank">
                                    <i class="rank {{ $player->badge($points) }}"></i>
                                </div>
                                <h3>Rank  #{{ $rank }}</h3>
                                <p class="username"><i class="fa fa-user fa-fw"></i> {{ $player->username }}</p>
                                <p class="points"><i class="fa fa-bolt fa-fw"></i> {{ $points  }}</p>
                                @if($gameStats !== null)
                                    <?php $credits = json_decode($gameStats->crd); ?>
                                    <p class="points"><strong>Funds Left: </strong> {{ $credits->value or "" }}</p>
                                    <p class="colour player-panel-{{ $gameStats->colour($gameStats->col) }}" style="width:25px;height:25px;"></p>
                                    <div class="country">
                                        <span class="flag-icon flag-icon-{{ $gameStats->country($gameStats->cty) }}"></span>
                                   </div>
                                @endif
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="dark-texture">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h3>Game details</h3>
                    <ul class="list-unstyled">
                        <li><strong>Short Game:</strong> {{ $game->shrt ? "On" : "Off" }}</li>
                        <li><strong>Superweapons:</strong> {{ $game->supr ? "On" : "Off" }}</li>
                        <li><strong>Crates:</strong> {{ $game->crat ? "On" : "Off" }}</li>
                        <li><strong>Credits:</strong> {{ $game->cred }}</li>
                        <li><strong>MCV Redeploy:</strong> {{ $game->bamr & 1 ? "On" : "Off" }}</li>
                        <li><strong>Unit Count Start:</strong> {{ $game->unit ? $game->unit : 0 }}</li>
                        <li><strong>Players in Game:</strong> {{ $game->plrs ? $game->plrs : 0 }}</li>
                        <li><strong>Build off Ally Conyard:</strong> {{ $game->bamr & 2 ? "On" : "Off" }}</li>

                        @if($gameReport !== null)
                        <li><strong>Duration:</strong> {{ gmdate("H:i:s", $gameReport->duration) }}</li>
                        <li><strong>Average FPS:</strong> {{ $gameReport->fps }}</li>
                        <li><strong>Reconnection Error (OOS):</strong> {{ $gameReport->oos ? "Yes" : "No" }}</li>
                        <li><strong>Disconnect:</strong> {{ $gameReport->disconnected() ? "Yes" : "No" }}</li>
                        @endif
                    </ul>
                </div>

                <div class="col-md-6">
                    <h3>Map - {{ $game->scen }} </h3>
                    <?php $map = \App\Map::where("hash", "=", $game->hash)->first(); ?>
                    @if ($map)
                    <div class="feature-map">
                        <img src="/images/maps/{{ $history->ladder->abbreviation}}/{{ $map->hash . ".png" }}">
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>
@endsection