@extends('reports.layout')

@section('content')
    <p class="muted">{{ $facts['zone']['name'] }} @if ($facts['code']) · Code {{ $facts['code'] }} @endif</p>

    <h2>Profile</h2>
    <table><tbody>
        <tr><th>Capital</th><td>{{ $facts['capital'] ?? '—' }}</td></tr>
        @if ($operational)
            <tr><th>Area</th><td>{{ $facts['area_km2'] !== null ? number_format((float) $facts['area_km2']).' km²' : '—' }}@if ($facts['area_missing'] > 0) <span class="small muted">({{ $facts['area_missing'] }} {{ $facts['area_missing'] === 1 ? 'LGA' : 'LGAs' }} without a figure)</span>@endif</td></tr>
            <tr><th>Population estimate</th><td>{{ $facts['population'] !== null ? number_format((int) $facts['population']) : '—' }}@if ($facts['population_missing'] > 0) <span class="small muted">({{ $facts['population_missing'] }} {{ $facts['population_missing'] === 1 ? 'LGA' : 'LGAs' }} without a figure)</span>@endif</td></tr>
        @endif
        <tr><th>LGAs</th><td>{{ $facts['lgas'] }}</td></tr>
        <tr><th>Senatorial districts</th><td>{{ $facts['districts'] }}</td></tr>
    </tbody></table>
    @if ($profile['exists'])
        @foreach (['history' => 'History', 'economic_activities' => 'Economic activities', 'major_roads' => 'Major roads', 'waterways' => 'Waterways', 'borders' => 'Borders (states, countries, crossings)', 'general_notes' => 'General notes'] as $key => $label)
            @if ($profile[$key])<h3>{{ $label }}</h3><div class="rich">{!! \App\Support\RichText\RichText::display($profile[$key]) !!}</div>@endif
        @endforeach
        @if ($profile['last_reviewed_at'])<p class="small muted">Last reviewed {{ \Carbon\CarbonImmutable::parse($profile['last_reviewed_at'])->setTimezone(config('app.timezone'))->format('j M Y') }}</p>@endif
    @else
        <p class="empty">No profile recorded.</p>
    @endif

    <h2>LGAs</h2>
    @if (count($lgas) === 0)<p class="empty">No records.</p>@else
        <table><thead><tr><th>LGA</th><th>Senatorial district</th><th>Headquarters</th><th class="num">Profile</th></tr></thead>
            <tbody>@foreach ($lgas as $l)<tr><td>{{ $l['name'] }}</td><td>{{ $l['district'] ?? '—' }}</td><td>{{ $l['headquarters'] ?? '—' }}</td><td class="num">{{ $l['completeness'] }}%</td></tr>@endforeach</tbody></table>
    @endif

    <h2>Senatorial districts</h2>
    @if (count($districts) === 0)<p class="empty">No records.</p>@else
        <table><thead><tr><th>District</th><th>LGAs</th><th>Current senator</th></tr></thead>
            <tbody>@foreach ($districts as $d)<tr><td>{{ $d['name'] }}</td><td>{{ implode(', ', array_column($d['lgas'], 'name')) ?: '—' }}</td><td>{{ implode(', ', array_column($d['senators'], 'name')) ?: '—' }}</td></tr>@endforeach</tbody></table>
    @endif

    @isset($offices)
        <h2>Current public office holders</h2>
        @if (count($offices) === 0)<p class="empty">No records.</p>@else
            <table><thead><tr><th>Office</th><th>Seat</th><th>Name</th><th>Party</th><th>Since</th></tr></thead>
                <tbody>@foreach ($offices as $o)<tr><td>{{ $o['office'] }}</td><td>{{ $o['place'] ?? '—' }}</td><td>{{ $o['name'] }}</td><td>{{ $o['party'] ?? '—' }}</td><td>{{ $o['since'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset

    @isset($formations)
        <h2>Security presence</h2>
        @if (count($formations) === 0)<p class="empty">No records.</p>@else
            <table><thead><tr><th>Agency</th><th class="num">Formations</th></tr></thead>
                <tbody>@foreach ($formations as $f)<tr><td>{{ $f['agency'] }}</td><td class="num">{{ $f['count'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset

    @isset($flashpoints)
        <h2>Flashpoints and blackspots by concern level</h2>
        @if (count($flashpoints) === 0)<p class="empty">No records.</p>@else
            <table><thead><tr><th>Concern</th><th>Name</th><th>LGA</th><th>Kind</th><th>Status</th></tr></thead>
                <tbody>@foreach ($flashpoints as $f)<tr><td>{{ $f['concern'] }}</td><td>{{ $f['name'] }}</td><td>{{ $f['lga'] }}</td><td>{{ $f['kind'] }}</td><td>{{ $f['status'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset

    @isset($incidents)
        <h2>Incidents in the last 90 days</h2>
        @if ($incidents['total'] === 0)<p class="empty">No records in this period.</p>@else
            <h3>By type</h3>
            <table><thead><tr><th>Type</th><th class="num">Incidents</th></tr></thead>
                <tbody>@foreach ($incidents['byType'] as $i)<tr><td>{{ $i['type'] }}</td><td class="num">{{ $i['count'] }}</td></tr>@endforeach</tbody></table>
            <h3>By LGA</h3>
            <table><thead><tr><th>LGA</th><th class="num">Incidents</th></tr></thead>
                <tbody>@foreach ($incidents['byLga'] as $i)<tr><td>{{ $i['lga']['name'] }}</td><td class="num">{{ $i['count'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset
@endsection
