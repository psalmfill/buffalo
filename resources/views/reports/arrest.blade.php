@extends('reports.layout')
@php($at = fn ($iso) => \App\Services\Reports\Builders\IncidentReportBuilder::at($iso))

{{-- One arrest (Phase 11E), as on its page. --}}
@section('content')
    <p>{{ $arrest['status']['label'] }} · {{ $arrest['classification']['label'] }}</p>
    <table>
        <tbody>
            <tr><th>Arrested</th><td>{{ $at($arrest['arrested_at']) }} ({{ $arrest['arrested_at_precision']['label'] }})</td></tr>
            <tr><th>Agency</th><td>{{ $arrest['agency']['name'] }}@if ($arrest['formation']) · {{ $arrest['formation']['name'] }}@elseif ($arrest['formation_hidden']) · a formation not visible to you @endif</td></tr>
            <tr><th>Place</th><td>{{ $arrest['community']['name'] ?? '' }}{{ $arrest['community'] ? ', ' : '' }}{{ $arrest['lga']['name'] }} LGA, {{ $arrest['state']['name'] }} State</td></tr>
            @if ($arrest['location_description'])<tr><th>Location</th><td>{{ $arrest['location_description'] }}</td></tr>@endif
            @if ($arrest['latitude'] !== null)<tr><th>Coordinates</th><td>{{ number_format((float) $arrest['latitude'], 6) }}, {{ number_format((float) $arrest['longitude'], 6) }}</td></tr>@endif
            <tr><th>Incident</th><td>@if ($arrest['incident']){{ $arrest['incident']['reference_no'] ?? 'Draft' }} — {{ $arrest['incident']['title'] }} ({{ $arrest['incident']['status']['label'] }})@elseif ($arrest['incident_hidden'])An incident not visible to you @else Not linked @endif</td></tr>
        </tbody>
    </table>

    @if ($comparison && $comparison['differs'])
        <p class="badge">The incident reports {{ $comparison['reported'] }} arrested; its arrest records list {{ $comparison['recorded'] }}.</p>
    @endif

    <h2>Arrested persons ({{ count($persons) }})</h2>
    <table>
        <thead><tr><th>Person</th><th>Status</th><th>Offences</th></tr></thead>
        <tbody>
        @foreach ($persons as $p)
            <tr>
                <td>@if ($p['person']){{ $p['person']['name'] }}@elseif ($p['person_hidden'])A person on record not visible to you @else Unidentified: {{ $p['unidentified_description'] }}@endif</td>
                <td>{{ $p['status']['label'] }}</td>
                <td>@foreach ($p['offences'] as $o){{ $o['offence']['label'] }}@if ($o['details']) ({{ $o['details'] }})@endif{{ $loop->last ? '' : '; ' }}@endforeach</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if ($arrest['notes'])
        <h2>Notes</h2>
        <div class="rich">{!! \App\Support\RichText\RichText::display($arrest['notes']) !!}</div>
    @endif

    <h2>Status timeline</h2>
    <ol>
        @foreach ($timeline as $entry)
            <li><strong>{{ $at($entry['at']) }}</strong> · {{ $entry['scope'] }}: {{ $entry['from']['label'] ?? 'Recorded' }} → {{ $entry['to']['label'] }}@if ($entry['who']) · {{ $entry['who'] }}@endif
                <div class="pre">{{ $entry['comment'] }}</div></li>
        @endforeach
    </ol>
@endsection
