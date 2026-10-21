@extends('reports.layout')
@php($at = fn ($iso) => \App\Services\Reports\Builders\IncidentReportBuilder::at($iso))

{{-- One criminal group (Phase 11E): members only as the requester sees them. --}}
@section('content')
    <p>{{ $group['type']['label'] }} · {{ $group['status']['label'] }} · {{ $group['classification']['label'] }}
        · {{ $group['lga']['name'] }} LGA, {{ $group['state']['name'] }} State</p>
    @if (count($group['other_names']) > 0)<p>Also known as: {{ implode(', ', $group['other_names']) }}</p>@endif
    @if ($group['estimated_size'])<p>Estimated size: {{ number_format($group['estimated_size']) }}</p>@endif

    <h2>Description</h2>
    <div class="rich">{!! \App\Support\RichText\RichText::display($group['description']) !!}</div>

    <h2>Areas of operation ({{ count($areas) }})</h2>
    @if (count($areas) === 0)<p class="empty">No areas recorded.</p>@else
        <table><thead><tr><th>LGA</th><th>State</th><th>Notes</th></tr></thead><tbody>
        @foreach ($areas as $a)<tr><td>{{ $a['lga']['name'] }}</td><td>{{ $a['state']['name'] }}</td><td>{{ $a['notes'] ?? '' }}</td></tr>@endforeach
        </tbody></table>
    @endif

    <h2>Members on record ({{ count($members) }})</h2>
    <p class="small muted">Only members whose records you can see are listed.</p>
    @if (count($members) === 0)<p class="empty">No members you can see.</p>@else
        <table><thead><tr><th>Name</th><th>LGA</th><th>Role in group</th><th>Status</th><th>Since</th></tr></thead><tbody>
        @foreach ($members as $m)<tr><td>{{ $m['name'] }}</td><td>{{ $m['lga'] }}</td><td>{{ $m['role'] ?? '—' }}</td><td>{{ $m['status'] }}</td><td>{{ $m['since'] ?? '—' }}</td></tr>@endforeach
        </tbody></table>
    @endif

    @if ($incidents !== null)
        <h2>Linked incidents ({{ count($incidents) }})</h2>
        @if (count($incidents) === 0)<p class="empty">None you can see.</p>@else
            <table><thead><tr><th>Reference</th><th>Type</th><th>Occurred</th><th>Status</th><th>LGA</th></tr></thead><tbody>
            @foreach ($incidents as $i)<tr><td>{{ $i['reference_no'] ?? 'Draft' }}</td><td>{{ $i['type']['name'] }}</td><td>{{ $at($i['occurred_at']) }}</td><td>{{ $i['status']['label'] }}</td><td>{{ $i['lga']['name'] }}</td></tr>@endforeach
            </tbody></table>
        @endif
    @endif
@endsection
