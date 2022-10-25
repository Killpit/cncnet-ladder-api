<?php $size = isset($size) ? $size : 150; ?>
<div class="avatar-container" style="width: {{ $size }}px; height: {{ $size }}px;">
    <div class="avatar" style="background-image: url({{ $avatar }});">
        @if (!$avatar)
            @include('icons.user')
        @endif
    </div>
</div>
