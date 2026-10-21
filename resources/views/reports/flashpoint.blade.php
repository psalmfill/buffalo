@extends('reports.layout')
@php($at = fn ($iso) => \App\Services\Reports\Builders\IncidentReportBuilder::at($iso))
@php($rich = fn ($html) => \App\Support\RichText\RichText::display($html))

{{-- One flashpoint or blackspot (Phase 11E). The sketch is an inline SVG
     drawn on the server: nothing is fetched. --}}
@section('content')
    <p>
        <span class="badge">{{ $flashpoint['kind']['label'] }}</span>
        {{ $flashpoint['threat_type']['name'] }} · Concern: <strong>{{ $flashpoint['concern_level']['label'] }}</strong>
        · {{ $flashpoint['status']['label'] }} · {{ $flashpoint['classification']['label'] }}
    </p>
    <p class="muted">
        {{ $flashpoint['community']['name'] ?? 'No community' }} · {{ $flashpoint['lga']['name'] }} LGA, {{ $flashpoint['state']['name'] }} State
        · Last assessed {{ $at($flashpoint['last_assessed_at']) }}@if ($flashpoint['last_assessed_by']) by {{ $flashpoint['last_assessed_by'] }}@endif
        · Review due {{ \Carbon\CarbonImmutable::parse($flashpoint['review_due_at'])->format('j M Y') }}@if ($flashpoint['review_overdue']) (overdue)@endif
    </p>

    <h2>Location</h2>
    @if ($sketch)
        <div class="sketch-frame">{!! $sketch !!}</div>
        <p class="small muted">
            Centre {{ number_format((float) $flashpoint['latitude'], 6) }}, {{ number_format((float) $flashpoint['longitude'], 6) }}
            · radius {{ number_format($flashpoint['radius_meters']) }} m
            · {{ $markers }} linked {{ $markers === 1 ? 'incident' : 'incidents' }} with a location shown as blue dots.
            Sketch from coordinates only (no map tiles); the outline is the LGA boundary where one has been imported.
        </p>
    @else
        <p class="empty">No location recorded.</p>
    @endif

    <h2>Description</h2>
    <div class="rich">{!! $rich($flashpoint['description']) !!}</div>
    @if ($flashpoint['recommended_action'])
        <h2>Recommended action</h2>
        <div class="rich">{!! $rich($flashpoint['recommended_action']) !!}</div>
    @endif

    <h2>Current assessment</h2>
    @if (count($assessments) === 0)
        <p class="empty">Not assessed yet.</p>
    @else
        @php($latest = $assessments[0])
        <p><strong>{{ $latest['concern_level']['label'] }}</strong> · {{ $latest['status']['label'] }} · {{ $at($latest['assessed_at']) }}@if ($latest['assessed_by']) · {{ $latest['assessed_by'] }}@endif</p>
        @if ($latest['format'] === 'html')<div class="rich">{!! $rich($latest['assessment']) !!}</div>@else<div class="pre">{{ $latest['assessment'] }}</div>@endif

        <h3>Assessment history ({{ count($assessments) }})</h3>
        <table>
            <thead><tr><th>Assessed</th><th>By</th><th>Concern</th><th>Status</th><th>Assessment</th></tr></thead>
            <tbody>
            @foreach ($assessments as $a)
                <tr>
                    <td>{{ $at($a['assessed_at']) }}</td>
                    <td>{{ $a['assessed_by'] ?? '—' }}</td>
                    <td>{{ $a['concern_level']['label'] }}</td>
                    <td>{{ $a['status']['label'] }}</td>
                    <td>@if ($a['format'] === 'html')<div class="rich">{!! $rich($a['assessment']) !!}</div>@else<div class="pre">{{ $a['assessment'] }}</div>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($incidents !== null)
        <h2>Linked incidents ({{ count($incidents) }})</h2>
        @if (count($incidents) === 0)
            <p class="empty">No linked incidents you can see.</p>
        @else
            <table>
                <thead><tr><th>Reference</th><th>Type</th><th>Occurred</th><th>Status</th><th>LGA</th></tr></thead>
                <tbody>
                @foreach ($incidents as $i)
                    <tr><td>{{ $i['reference_no'] ?? 'Draft' }}</td><td>{{ $i['type']['name'] }}</td><td>{{ $at($i['occurred_at']) }}</td><td>{{ $i['status']['label'] }}</td><td>{{ $i['lga']['name'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if ($notes !== null)
        <h2>Linked security notes ({{ count($notes) }})</h2>
        @if (count($notes) === 0)
            <p class="empty">No linked security notes you can see.</p>
        @else
            <table>
                <thead><tr><th>Title</th><th>Community</th><th>Category</th><th>Status</th><th>Observed</th></tr></thead>
                <tbody>
                @foreach ($notes as $n)
                    <tr><td>{{ $n['title'] }}</td><td>{{ $n['community']['name'] }}</td><td>{{ $n['category']['label'] }}</td><td>{{ $n['status']['label'] }}</td><td>{{ $n['observed_at'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if ($threats !== null)
        <h2>Linked threat reports ({{ count($threats) }})</h2>
        @if (count($threats) === 0)
            <p class="empty">No linked threat reports you can see.</p>
        @else
            <table>
                <thead><tr><th>Reference</th><th>Title</th><th>Urgency</th><th>Status</th><th>Expires</th></tr></thead>
                <tbody>
                @foreach ($threats as $t)
                    <tr><td>{{ $t['reference_no'] }}</td><td>{{ $t['title'] }}</td><td>{{ $t['urgency']['label'] }}</td><td>{{ $t['status']['label'] }}</td><td>{{ $at($t['expires_at']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if ($disseminations !== null)
        <h2>Disseminations ({{ count($disseminations) }})</h2>
        @if (count($disseminations) === 0)
            <p class="empty">Not shared with any other state or LGA.</p>
        @else
            <table>
                <thead><tr><th>Shared with</th><th>Shared</th><th>By</th><th>Expires</th><th>Status</th></tr></thead>
                <tbody>
                @foreach ($disseminations as $d)
                    <tr><td>{{ $d['target'] }}@if ($d['onward']) (onward)@endif</td><td>{{ $at($d['disseminated_at']) }}</td><td>{{ $d['disseminated_by'] ?? '—' }}</td><td>{{ $at($d['expires_at']) }}</td><td>{{ ucfirst($d['status']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif
@endsection
