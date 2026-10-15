@extends('reports.layout')

@section('content')
    <p class="muted">{{ $facts['state']['name'] }} State @if ($facts['district']) · {{ $facts['district'] }} @endif @if ($facts['code']) · Code {{ $facts['code'] }} @endif</p>

    <h2>Profile</h2>
    <table><tbody>
        <tr><th>Headquarters</th><td>{{ $facts['headquarters'] ?? '—' }}</td></tr>
        <tr><th>Area</th><td>{{ $facts['area_km2'] !== null ? number_format((float) $facts['area_km2']).' km²' : '—' }}</td></tr>
        <tr><th>Population estimate</th><td>{{ $facts['population_estimate'] !== null ? number_format((int) $facts['population_estimate']) : '—' }}</td></tr>
        <tr><th>Communities</th><td>{{ number_format($communities['count']) }} in {{ $communities['wards'] }} wards</td></tr>
    </tbody></table>
    @if ($profile['exists'])
        @foreach (['history' => 'History', 'economic_activities' => 'Economic activities', 'major_roads' => 'Major roads', 'waterways' => 'Waterways', 'borders' => 'Borders', 'general_notes' => 'General notes'] as $key => $label)
            @if ($profile[$key])<h3>{{ $label }}</h3><div class="rich">{!! \App\Support\RichText\RichText::display($profile[$key]) !!}</div>@endif
        @endforeach
        @if ($profile['last_reviewed_at'])<p class="small muted">Last reviewed {{ \Carbon\CarbonImmutable::parse($profile['last_reviewed_at'])->setTimezone(config('app.timezone'))->format('j M Y') }}</p>@endif
    @else
        <p class="empty">No profile recorded.</p>
    @endif

    @isset($religious)
        <h2>Religious institutions</h2>
        @if (count($religious) === 0)<p class="empty">No records.</p>@else
            <table><thead><tr><th>Religion</th><th class="num">Institutions</th><th class="num">Estimated congregation</th></tr></thead>
                <tbody>@foreach ($religious as $r)<tr><td>{{ $r['religion'] }}</td><td class="num">{{ $r['institutions'] }}</td><td class="num">{{ $r['congregation'] !== null ? number_format($r['congregation']) : '—' }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset

    @isset($vulnerable)
        <h2>Vulnerable population (latest LGA-wide figures)</h2>
        @if (count($vulnerable) === 0)<p class="empty">No records.</p>@else
            <table><thead><tr><th>Category</th><th class="num">Men</th><th class="num">Women</th><th class="num">Boys</th><th class="num">Girls</th><th class="num">Total</th><th>As of</th></tr></thead>
                <tbody>@foreach ($vulnerable as $v)<tr><td>{{ $v['category'] }}</td><td class="num">{{ number_format($v['adult_male']) }}</td><td class="num">{{ number_format($v['adult_female']) }}</td><td class="num">{{ number_format($v['child_male']) }}</td><td class="num">{{ number_format($v['child_female']) }}</td><td class="num"><strong>{{ number_format($v['total']) }}</strong></td><td>{{ $v['as_of'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset

    @isset($formations)
        <h2>Security presence</h2>
        @if (count($formations) === 0)<p class="empty">No records.</p>@else
            <table><thead><tr><th>Agency</th><th>Formation</th><th>Type</th><th>Status</th></tr></thead>
                <tbody>@foreach ($formations as $f)<tr><td>{{ $f['agency'] }}</td><td>{{ $f['name'] }}</td><td>{{ $f['type'] }}</td><td>{{ $f['status'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset

    @isset($offices)
        <h2>Current public office holders</h2>
        @if (count($offices) === 0)<p class="empty">No records.</p>@else
            <table><thead><tr><th>Office</th><th>Ward</th><th>Name</th><th>Party</th><th>Since</th></tr></thead>
                <tbody>@foreach ($offices as $o)<tr><td>{{ $o['office'] }}</td><td>{{ $o['ward'] ?? '—' }}</td><td>{{ $o['name'] }}</td><td>{{ $o['party'] ?? '—' }}</td><td>{{ $o['since'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset

    @isset($flashpoints)
        <h2>Flashpoints</h2>
        @if (count($flashpoints) === 0)<p class="empty">No records.</p>@else
            <table><thead><tr><th>Flashpoint</th><th>Kind</th><th>Concern</th><th>Status</th></tr></thead>
                <tbody>@foreach ($flashpoints as $f)<tr><td>{{ $f['name'] }}</td><td>{{ $f['kind'] }}</td><td>{{ $f['concern'] }}</td><td>{{ $f['status'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset

    @isset($incidents)
        <h2>Incidents in the last 90 days</h2>
        @if (count($incidents) === 0)<p class="empty">No records in this period.</p>@else
            <table><thead><tr><th>Type</th><th class="num">Incidents</th></tr></thead>
                <tbody>@foreach ($incidents as $i)<tr><td>{{ $i['type'] }}</td><td class="num">{{ $i['count'] }}</td></tr>@endforeach</tbody></table>
        @endif
    @endisset
@endsection
