@extends('reports.layout')
@php($at = fn ($iso) => \App\Services\Reports\Builders\IncidentReportBuilder::at($iso))

@section('content')
    <p><strong>{{ $incident['reference_no'] ?? 'Draft — no reference yet' }}</strong> · {{ $incident['title'] }}</p>
    <p>{{ $incident['type']['name'] }} · {{ $incident['severity']['label'] }} · {{ $incident['status']['label'] }} · {{ $incident['lga']['name'] }} LGA, {{ $incident['state']['name'] }} State</p>
    <p>Occurred {{ $at($incident['occurred_at']) }} ({{ strtolower($incident['occurred_at_precision']['label']) }})@if ($incident['community']) · {{ $incident['community']['name'] }}@endif @if ($incident['location_description']) · {{ $incident['location_description'] }}@endif</p>
    @if ($incident['workflow']['escalation'])
        <p>Escalated to national level @if ($incident['workflow']['escalation']['acknowledged']); acknowledged by {{ $incident['workflow']['escalation']['acknowledged']['by'] }}@endif.</p>
    @endif

    <h2>What happened</h2>
    <div class="rich">{!! \App\Support\RichText\RichText::display($incident['description']) !!}</div>
    @if (count($details) > 0)
        <table><tbody>@foreach ($details as $label => $value)<tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>@endforeach</tbody></table>
    @endif
    @if ($incident['action_taken'])<h3>Action taken</h3><div class="rich">{!! \App\Support\RichText\RichText::display($incident['action_taken']) !!}</div>@endif
    @if ($incident['workflow']['resolution_summary'])<h3>Resolution</h3><div class="rich">{!! \App\Support\RichText\RichText::display($incident['workflow']['resolution_summary']) !!}</div>@endif

    <h2>People</h2>
    @if (count($casualties) === 0)
        <p class="empty">No casualties recorded.</p>
    @else
        <table>
            <thead><tr><th>Outcome</th><th>Who</th><th class="num">Men</th><th class="num">Women</th><th class="num">Boys</th><th class="num">Girls</th><th class="num">Unknown</th><th class="num">Total</th></tr></thead>
            <tbody>@foreach ($casualties as $c)<tr><td>{{ $c['outcome']['label'] }}</td><td>{{ $c['group']['label'] }}@if ($c['agency']) ({{ $c['agency']['short_name'] }})@endif</td><td class="num">{{ $c['adult_male'] }}</td><td class="num">{{ $c['adult_female'] }}</td><td class="num">{{ $c['child_male'] }}</td><td class="num">{{ $c['child_female'] }}</td><td class="num">{{ $c['unknown'] }}</td><td class="num"><strong>{{ $c['total'] }}</strong></td></tr>@endforeach</tbody>
        </table>
    @endif
    <ul>
        @foreach ($persons as $p)
            <li>{{ $p['involvement']['label'] }}: @if ($p['person']){{ $p['person']['name'] }}@elseif ($p['person_hidden'])Person on record (not visible to you)@else Unidentified: {{ $p['unidentified_description'] }}@endif</li>
        @endforeach
        @foreach ($groups as $g)
            <li>Criminal group ({{ strtolower($g['role']['label']) }}): {{ $g['group'] ? $g['group']['name'] : 'a group not visible to you' }}</li>
        @endforeach
    </ul>

    <h2>Response</h2>
    <ul>
        @if (count($agencies) === 0)<li>No agencies recorded.</li>@endif
        @foreach ($agencies as $a)
            <li>{{ $a['role']['label'] }}: {{ $a['agency']['short_name'] }}@if ($a['formation']), {{ $a['formation']['name'] }}@endif @if ($a['responded_at'])(responded {{ $at($a['responded_at']) }})@endif</li>
        @endforeach
        @foreach ($properties as $p)
            <li>Property: {{ $p['property_type']['name'] }}@if ($p['quantity'] !== null) × {{ $p['quantity'] }}@endif @if ($p['description']), {{ $p['description'] }}@endif</li>
        @endforeach
    </ul>

    <h2>Evidence (titles only)</h2>
    @if (count($evidence) === 0)
        <p class="empty">No evidence.</p>
    @else
        <ul>@foreach ($evidence as $e)<li>{{ $e['title'] }} ({{ strtolower($e['type']) }}, {{ strtolower($e['classification']) }})</li>@endforeach</ul>
    @endif

    <h2>Timeline</h2>
    <ol>
        @foreach ($timeline as $entry)
            <li><strong>{{ $at($entry['at']) }}</strong> · {{ $entry['label'] }}@if ($entry['who']) · {{ $entry['who'] }}@endif
                {{-- Follow-ups are rich text since Phase 11B; everything else (and older follow-ups) is plain text. --}}
                @if ($entry['body'])@if (($entry['format'] ?? 'text') === 'html')<div class="rich">{!! \App\Support\RichText\RichText::display($entry['body']) !!}</div>@else<div class="pre">{{ $entry['body'] }}</div>@endif @endif</li>
        @endforeach
    </ol>
@endsection
