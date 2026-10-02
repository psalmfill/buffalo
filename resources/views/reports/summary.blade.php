@extends('reports.layout')

@section('content')
    @if (count($kpis) > 0)
        <div class="kpis">
            @foreach ($kpis as $kpi)
                <div class="kpi">
                    <div class="small muted">{{ $kpi['label'] }}</div>
                    <div class="v">{{ $kpi['value'] === null ? '—' : number_format((float) $kpi['value'], is_float($kpi['value']) ? 1 : 0) }}{{ $kpi['value'] !== null ? ($kpi['unit'] ?? '') : '' }}</div>
                    <div class="small muted">
                        @if ($kpi['change_pct'] !== null) {{ $kpi['change_pct'] > 0 ? '+' : '' }}{{ $kpi['change_pct'] }}% vs previous
                        @elseif ($kpi['previous'] !== null) Previously {{ $kpi['previous'] }}{{ $kpi['unit'] ?? '' }}
                        @else Now @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        @foreach ($section['charts'] as $chart)
            @include('reports.partials.chart', ['chart' => $chart])
        @endforeach

        @if ($section['title'] === 'Incidents' && isset($notable))
            <h3>Notable incidents (critical and high)</h3>
            @if (count($notable) === 0)
                <p class="empty">No records in this period.</p>
            @else
                <table><thead><tr><th>Reference</th><th>Title</th><th>Type</th><th>LGA</th><th>Severity</th><th>Status</th><th>Occurred</th></tr></thead>
                    <tbody>@foreach ($notable as $n)<tr><td>{{ $n['reference'] }}</td><td>{{ $n['title'] }}</td><td>{{ $n['type'] }}</td><td>{{ $n['lga'] }}</td><td>{{ $n['severity'] }}</td><td>{{ $n['status'] }}</td><td>{{ $n['occurred'] }}</td></tr>@endforeach</tbody></table>
            @endif
        @endif
    @endforeach

    @isset($flashpointChanges)
        <h2>Flashpoint changes</h2>
        @if (count($flashpointChanges) === 0)
            <p class="empty">No records in this period.</p>
        @else
            <table><thead><tr><th>Flashpoint</th><th>LGA</th><th>From</th><th>To</th></tr></thead>
                <tbody>@foreach ($flashpointChanges as $c)<tr><td>{{ $c['name'] }}</td><td>{{ $c['lga'] }}</td><td>{{ $c['from'] }}</td><td>{{ $c['to'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset
@endsection
