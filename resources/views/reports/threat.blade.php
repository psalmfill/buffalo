@extends('reports.layout')
@php($at = fn ($iso) => \App\Services\Reports\Builders\IncidentReportBuilder::at($iso))
@php($rich = fn ($html) => \App\Support\RichText\RichText::display($html))

{{-- One threat report (Phase 11E), as on its page. --}}
@section('content')
    <p>{{ $threat['threat_type']['name'] }} · Urgency: <strong>{{ $threat['urgency']['label'] }}</strong> · Credibility: {{ $threat['credibility']['label'] }}
        · {{ $threat['status']['label'] }} · {{ $threat['classification']['label'] }}</p>
    <table>
        <tbody>
            <tr><th>Place</th><td>{{ $threat['community']['name'] ?? '' }}{{ $threat['community'] ? ', ' : '' }}{{ $threat['lga']['name'] }} LGA, {{ $threat['state']['name'] }} State</td></tr>
            @if ($threat['latitude'] !== null)<tr><th>Coordinates</th><td>{{ number_format((float) $threat['latitude'], 6) }}, {{ number_format((float) $threat['longitude'], 6) }}@if ($threat['radius_meters']) · radius {{ number_format($threat['radius_meters']) }} m @endif</td></tr>@endif
            <tr><th>Expected</th><td>{{ $threat['expected_from'] ? $at($threat['expected_from']) : '—' }} to {{ $threat['expected_until'] ? $at($threat['expected_until']) : '—' }}</td></tr>
            <tr><th>Expires</th><td>{{ $at($threat['expires_at']) }}</td></tr>
            <tr><th>Source</th><td>{{ $threat['source_type']['label'] }}@if ($threat['source_notes']) · {{ $threat['source_notes'] }}@endif</td></tr>
            <tr><th>Reported</th><td>{{ $at($threat['created_at']) }}@if ($threat['created_by']) by {{ $threat['created_by'] }}@endif</td></tr>
            @if ($threat['status_note'])<tr><th>Status note</th><td>{{ $threat['status_note'] }}</td></tr>@endif
        </tbody>
    </table>

    <h2>Description</h2>
    <div class="rich">{!! $rich($threat['description']) !!}</div>
    @if ($threat['target_description'])<h2>Target</h2><div class="rich">{!! $rich($threat['target_description']) !!}</div>@endif
    @if ($threat['recommended_action'])<h2>Recommended action</h2><div class="rich">{!! $rich($threat['recommended_action']) !!}</div>@endif

    @if ($flashpoints !== null)
        <h2>Linked flashpoints ({{ count($flashpoints) }})</h2>
        @if (count($flashpoints) === 0)<p class="empty">None you can see.</p>@else
            <table><thead><tr><th>Name</th><th>Kind</th><th>Concern</th><th>LGA</th></tr></thead><tbody>
            @foreach ($flashpoints as $f)<tr><td>{{ $f['name'] }}</td><td>{{ $f['kind']['label'] }}</td><td>{{ $f['concern_level']['label'] }}</td><td>{{ $f['lga']['name'] }}</td></tr>@endforeach
            </tbody></table>
        @endif
    @endif
    @if ($groups !== null)
        <h2>Linked criminal groups ({{ count($groups) }})</h2>
        @if (count($groups) === 0)<p class="empty">None you can see.</p>@else
            <table><thead><tr><th>Group</th><th>Role</th><th>LGA</th></tr></thead><tbody>
            @foreach ($groups as $g)<tr><td>{{ $g['name'] }}</td><td>{{ $g['role']['label'] }}</td><td>{{ $g['lga']['name'] ?? '' }}</td></tr>@endforeach
            </tbody></table>
        @endif
    @endif
    @if ($incidents !== null)
        <h2>Linked incidents ({{ count($incidents) }})</h2>
        @if (count($incidents) === 0)<p class="empty">None you can see.</p>@else
            <table><thead><tr><th>Reference</th><th>Type</th><th>Occurred</th><th>Status</th><th>Relation</th></tr></thead><tbody>
            @foreach ($incidents as $i)<tr><td>{{ $i['reference_no'] ?? 'Draft' }}</td><td>{{ $i['type']['name'] }}</td><td>{{ $at($i['occurred_at']) }}</td><td>{{ $i['status']['label'] }}</td><td>{{ $i['relation']['label'] }}</td></tr>@endforeach
            </tbody></table>
        @endif
    @endif

    <h2>Timeline</h2>
    <ol>
        @foreach ($timeline as $entry)
            <li><strong>{{ $at($entry['at']) }}</strong> · {{ $entry['description'] }}@if ($entry['by']) · {{ $entry['by'] }}@endif
                @if ($entry['comment'])<div class="pre">{{ $entry['comment'] }}</div>@endif</li>
        @endforeach
    </ol>

    @if ($disseminations !== null)
        <h2>Disseminations ({{ count($disseminations) }})</h2>
        @if (count($disseminations) === 0)<p class="empty">Not shared with any other state or LGA.</p>@else
            <table><thead><tr><th>Shared with</th><th>Shared</th><th>Expires</th><th>Status</th></tr></thead><tbody>
            @foreach ($disseminations as $d)<tr><td>{{ $d['target'] }}</td><td>{{ $at($d['disseminated_at']) }}</td><td>{{ $at($d['expires_at']) }}</td><td>{{ ucfirst($d['status']) }}</td></tr>@endforeach
            </tbody></table>
        @endif
    @endif
@endsection
