@php
    $mapPreview = '';
    try {
        $mapPreview = url('images/maps/' . $history->ladder->abbreviation . '/' . $map->hash . '.png');
        $mapPreviewSize = getimagesize($mapPreview);
    
        $webMapWidth = $mapPreviewSize[0];
        $webMapHeight = $mapPreviewSize[1];
    
        $mapStartX = $map->mapHeaders->startX ?? -1;
        $mapStartY = $map->mapHeaders->startY ?? -1;
        $mapWidth = $map->mapHeaders->width ?? -1;
        $mapHeight = $map->mapHeaders->height ?? -1;
        $ratioX = $webMapWidth / $mapWidth;
        $ratioY = $webMapHeight / $mapHeight;
    
        $hasMapData = true;
    } catch (\Exception $ex) {
        $hasMapData = false;
    }
@endphp

<div class="container">
    <div class="d-flex justify-content-center">
        @if ($hasMapData)
            <div class="map-preview d-flex d-lg-none">
                <img src="{{ $mapPreview }}" style="max-width:100%" />
            </div>

            <div class="map-preview d-none d-lg-flex"
                style="background-image:url('{{ $mapPreview }}'); width: {{ $webMapWidth }}px; height: {{ $webMapHeight }}px">
                @foreach ($playerGameReports as $k => $pgr)
                    @php
                        $hasValidSpawnData = false;
                        $gameStats = $pgr->stats;
                        $player = $pgr->player()->first();
                        
                        # Player positions plotted onto map preview
                        $playerX = 0;
                        $playerY = 0;
                        
                        $playerSpawnPosition = isset($pgr->spawn) ? $pgr->spawn + 1 : -1;
                        
                        if ($playerSpawnPosition !== -1) {
                            if (isset($map->mapHeaders)) {
                                $position = $map->mapHeaders->waypoints->where('bit_idx', $playerSpawnPosition)->first();
                        
                                if ($position) {
                                    $playerX = $ratioX * ($position->x - $mapStartX);
                                    $playerY = $ratioY * ($position->y - $mapStartY);
                        
                                    $hasValidSpawnData = true;
                                }
                            }
                        }
                    @endphp

                    @if ($hasValidSpawnData)
                        <div id="marker-{{ $k }}" class="player-marker" style="left: {{ $playerX }}px; top: {{ $playerY }}px;">
                            <div class="player-start-position {{ $gameStats->colour($gameStats->col) }}">
                                {{ $playerSpawnPosition != -1 ? $playerSpawnPosition : 'No spawn data' }}
                            </div>
                        </div>

                        <div id="playerdetails-{{ $k }}" class="player player-{{ $gameStats->colour($gameStats->col) }} player-details"
                            style="display:none;">

                            <div class="player-avatar">
                                @include('components.avatar', ['avatar' => $player->user->getUserAvatar(), 'size' => 35])
                            </div>

                            <div class="player-details">
                                <div class="username">
                                    {{ $player->username }}
                                </div>

                                <div class="status text-uppercase status-{{ $pgr->won ? 'won' : 'lost' }}">
                                    @if ($pgr->won)
                                        Won
                                    @elseif($pgr->draw)
                                        Draw
                                    @elseif($pgr->disconnected)
                                        Disconnected
                                    @else
                                        Lost
                                    @endif
                                </div>

                                <div class="faction">
                                    @if ($pgr->stats)
                                        @php $playerStats2 = \App\Stats2::where("id", $pgr->stats->id)->first(); @endphp
                                        @php $playerCountry = $playerStats2->faction($history->ladder->game, $pgr->stats->cty); @endphp
                                        <div class="{{ $history->ladder->game }} player-faction player-faction-{{ $playerCountry }}"></div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @else
            <div class="map-preview d-lg-none">
                <img src="{{ $mapPreview }}" style="max-width:100%" />
            </div>
        @endif
    </div>
</div>

@section('js')
    <script src="/js/popper.js"></script>
    <script>
        ((function() {

            let marker1 = document.querySelector('#marker-0');
            let player1 = document.querySelector('#playerdetails-0');

            Popper.createPopper(marker1, player1, {
                modifiers: [{
                    name: 'offset',
                    options: {
                        offset: [5, 5],
                    },
                }, ],
            });

            let marker2 = document.querySelector('#marker-1');
            let player2 = document.querySelector('#playerdetails-1');

            Popper.createPopper(marker2, player2, {
                modifiers: [{
                    name: 'offset',
                    options: {
                        offset: [5, 5],
                    },
                }, ],
            });

            player1.style.display = "";
            player2.style.display = "";
        })());
    </script>
@endsection