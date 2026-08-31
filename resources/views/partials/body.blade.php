{{-- The same shape the polling script builds, so the first paint needs no
     JavaScript and the tests can assert every state without a browser. --}}
@php($current = $pipeline['current'])
@php($live = $pipeline['live'])

<dl>
    @if ($live !== null)
        <dt>now</dt>
        <dd data-live>
            @if ($live['interrupted'])
                interrupted — {{ implode(', ', $live['steps']) }}
            @else
                {{ $live['state'] }} — {{ implode(', ', $live['steps']) }}
            @endif
        </dd>
    @endif

    @if ($current === null)
        <dt>run</dt>
        <dd data-no-run>nothing recorded</dd>
    @else
        <dt>run</dt>
        <dd>{{ $current['run'] }}</dd>
        <dt>state</dt>
        <dd data-state>{{ $current['state'] }}</dd>
        <dt>verified</dt>
        <dd>{{ $current['all_verified'] ? 'yes' : 'no' }}</dd>
        @if ($current['scope'] !== null)
            <dt>scope</dt>
            <dd>{{ $current['scope'] }}</dd>
        @endif
        @if ($current['tree_matches'] !== null)
            <dt>tree</dt>
            <dd>{{ $current['tree_matches'] ? 'matches the code on disk' : 'moved since the run' }}</dd>
        @endif
    @endif
</dl>

@if ($current !== null)
    <h3 class="muted">steps</h3>
    <ol>
        @foreach ($current['positions'] as $position)
            <li>
                @foreach ($position['steps'] as $step)
                    <div class="position">
                        <span>{{ $step['id'] }}</span>
                        <span class="verdict">{{ $step['verdict'] ?? 'not run' }}</span>
                        @if ($position['parallel'])
                            <span class="muted">parallel</span>
                        @endif
                        <span class="muted">{{ $step['phase'] }}</span>
                    </div>
                @endforeach
            </li>
        @endforeach
        @foreach ($current['undeclared'] as $step)
            <li>
                <div class="position">
                    <span>{{ $step['id'] }}</span>
                    <span class="verdict">{{ $step['verdict'] }}</span>
                    <span class="muted">no longer declared</span>
                </div>
            </li>
        @endforeach
    </ol>
@endif

<h3 class="muted">history</h3>
<ol>
    @forelse ($pipeline['history'] as $run)
        <li>
            <div class="position">
                <span>{{ $run['run'] }}</span>
                <span class="muted">{{ $run['state'] }}</span>
                <span class="muted">{{ $run['recorded_at'] }}</span>
            </div>
        </li>
    @empty
        <li class="muted">nothing recorded</li>
    @endforelse
</ol>
