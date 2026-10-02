@extends('reports.layout', ['subtitle' => $place.' · '.$since->format('j M Y H:i').' – '.$until->format('j M Y H:i T')])

@section('content')
@isset($incidents)
    <h2>New incidents ({{ count($incidents) }})</h2>
    @if (count($incidents) === 0)
        <p class="empty">No records in this period.</p>
    @else
        <table>
            <thead><tr><th>Reference</th><th>Type</th><th>LGA</th><th>Time</th><th>Severity</th><th>Status</th><th>Casualties</th></tr></thead>
            <tbody>@foreach ($incidents as $i)<tr><td>{{ $i['reference'] }}</td><td>{{ $i['type'] }}</td><td>{{ $i['lga'] }}</td><td>{{ $i['time'] }}</td><td>{{ $i['severity'] }}</td><td>{{ $i['status'] }}</td><td>{{ $i['casualties'] }}</td></tr>@endforeach</tbody>
        </table>
    @endif

    <h2>Casualties</h2>
    <table><thead><tr>@foreach ($casualtyTotals as $label => $n)<th class="num">{{ $label }}</th>@endforeach</tr></thead>
        <tbody><tr>@foreach ($casualtyTotals as $n)<td class="num">{{ $n }}</td>@endforeach</tr></tbody></table>

    <h2>Escalations</h2>
    <p>Escalated to national: {{ count($escalations['escalated']) ? implode(', ', $escalations['escalated']) : 'none' }}.</p>
    <p>Acknowledged: {{ count($escalations['acknowledged']) ? implode(', ', $escalations['acknowledged']) : 'none' }}.</p>
@endisset

@isset($threats)
    <h2>Immediate and priority threats, new or changed ({{ count($threats) }})</h2>
    @if (count($threats) === 0)
        <p class="empty">No records in this period.</p>
    @else
        <table>
            <thead><tr><th>Reference</th><th>Title</th><th>Type</th><th>Urgency</th><th>Status</th><th>Place</th></tr></thead>
            <tbody>@foreach ($threats as $t)<tr><td>{{ $t['reference'] }}</td><td>{{ $t['title'] }} @if ($t['new'])<span class="badge">new</span>@endif</td><td>{{ $t['type'] }}</td><td>{{ $t['urgency'] }}</td><td>{{ $t['status'] }}</td><td>{{ $t['place'] }}</td></tr>@endforeach</tbody>
        </table>
    @endif
@endisset

@isset($flashpoints)
    <h2>Flashpoints assessed high or severe ({{ count($flashpoints) }})</h2>
    @if (count($flashpoints) === 0)
        <p class="empty">No records in this period.</p>
    @else
        <table><thead><tr><th>Name</th><th>Kind</th><th>Concern</th><th>LGA</th></tr></thead>
            <tbody>@foreach ($flashpoints as $f)<tr><td>{{ $f['name'] }}</td><td>{{ $f['kind'] }}</td><td>{{ $f['concern'] }}</td><td>{{ $f['lga'] }}</td></tr>@endforeach</tbody></table>
    @endif
@endisset

@isset($disseminations)
    <h2>Shared with us ({{ count($disseminations) }})</h2>
    @if (count($disseminations) === 0)
        <p class="empty">No records in this period.</p>
    @else
        <table><thead><tr><th>Record</th><th>From</th><th>Shared</th><th>Acknowledged</th></tr></thead>
            <tbody>@foreach ($disseminations as $d)<tr><td>{{ $d['kind'] }} {{ $d['label'] }}</td><td>{{ $d['from'] }}</td><td>{{ $d['at'] }}</td><td>{{ $d['acknowledged'] ? 'Yes' : 'No' }}</td></tr>@endforeach</tbody></table>
    @endif
@endisset

@isset($overdue)
    <h2>Overdue verifications ({{ $overdue['count'] }})</h2>
    @if ($overdue['count'] === 0)
        <p class="empty">No records in this period.</p>
    @else
        <table><thead><tr><th>Reference</th><th>LGA</th><th>Severity</th><th>Submitted</th></tr></thead>
            <tbody>@foreach ($overdue['rows'] as $o)<tr><td>{{ $o['reference'] }}</td><td>{{ $o['lga'] }}</td><td>{{ $o['severity'] }}</td><td>{{ $o['submitted'] }}</td></tr>@endforeach</tbody></table>
    @endif
@endisset

@isset($coverage)
    <h2>Coverage reports awaiting review ({{ count($coverage) }})</h2>
    @if (count($coverage) === 0)
        <p class="empty">No records in this period.</p>
    @else
        <table><thead><tr><th>LGA</th><th>Period</th><th>Rating</th></tr></thead>
            <tbody>@foreach ($coverage as $c)<tr><td>{{ $c['lga'] }}</td><td>{{ $c['period'] }}</td><td>{{ $c['rating'] ?? '—' }}</td></tr>@endforeach</tbody></table>
    @endif
@endisset
@endsection
