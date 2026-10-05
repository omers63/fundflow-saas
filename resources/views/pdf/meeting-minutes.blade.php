<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Meeting minutes') }} — {{ $meeting->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin-top: 18px; }
        .meta { color: #555; margin-bottom: 16px; }
        .motion { border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; }
        .minutes { white-space: pre-wrap; line-height: 1.45; }
    </style>
</head>
<body>
    <h1>{{ $fundName }}</h1>
    <div class="meta">
        {{ __('Meeting minutes') }} · {{ $meeting->title }}<br>
        {{ __('Status') }}: {{ $meeting->status }}
        @if ($meeting->minutes_published_at)
            · {{ __('Published') }}: {{ $meeting->minutes_published_at->toDayDateTimeString() }}
        @endif
        <br>{{ __('Generated') }}: {{ $generatedAt->toDayDateTimeString() }}
    </div>

    <h2>{{ __('Motions') }}</h2>
    @forelse ($meeting->motions as $motion)
        <div class="motion">
            <strong>#{{ $motion->id }} — {{ $motion->title }}</strong><br>
            {{ __('Type') }}: {{ $motion->type }} · {{ __('Status') }}: {{ $motion->status }}<br>
            {{ __('Yes') }}: {{ $motion->yes_count }} · {{ __('No') }}: {{ $motion->no_count }} · {{ __('Abstain') }}: {{ $motion->abstain_count }}
            · {{ __('Quorum') }}: {{ $motion->quorum_met ? __('Met') : __('Not met') }}
            @if ($motion->body)
                <p>{{ $motion->body }}</p>
            @endif
        </div>
    @empty
        <p>{{ __('No motions.') }}</p>
    @endforelse

    <h2>{{ __('Minutes') }}</h2>
    <div class="minutes">{{ $meeting->minutes ?: __('No minutes text recorded.') }}</div>
</body>
</html>
