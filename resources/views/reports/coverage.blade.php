@extends('reports.layout')
@php($at = fn ($iso) => \App\Services\Reports\Builders\IncidentReportBuilder::at($iso))

@section('content')
    <p>{{ $report['period_type']['label'] }} · {{ $report['status']['label'] }} · Overall rating: {{ $report['overall_rating']['label'] ?? 'Not rated' }}</p>

    @foreach (['summary' => 'Summary', 'gaps_identified' => 'Gaps identified', 'recommendations' => 'Recommendations'] as $key => $label)
        @if ($report[$key])<h2>{{ $label }}</h2><p class="pre">{{ $report[$key] }}</p>@endif
    @endforeach

    <h2>Deployments</h2>
    @if (count($deployments) === 0)
        <p class="empty">No deployments recorded.</p>
    @else
        <table>
            <thead><tr><th>Agency</th><th>Formation</th><th class="num">Personnel</th><th class="num">Vehicles</th><th>Patrols</th><th>Communities</th></tr></thead>
            <tbody>
            @foreach ($deployments as $d)
                <tr>
                    <td>{{ $d['agency']['short_name'] ?? $d['agency']['name'] }}</td>
                    <td>{{ $d['formation']['name'] ?? ($d['formation_hidden'] ? 'Not visible to you' : '—') }}</td>
                    <td class="num">{{ $d['personnel_count'] }}</td>
                    <td class="num">{{ $d['vehicles_count'] ?? '—' }}</td>
                    <td>{{ $d['patrol_frequency']['label'] }}</td>
                    <td>{{ collect($d['communities'])->pluck('name')->implode(', ') ?: ($d['areas_covered'] ?? '—') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <h3>Totals by agency</h3>
        <table>
            <thead><tr><th>Agency</th><th class="num">Deployments</th><th class="num">Personnel</th><th class="num">Vehicles</th></tr></thead>
            <tbody>
            @foreach ($totals['by_agency'] as $t)
                <tr><td>{{ $t['agency']['short_name'] ?: $t['agency']['name'] }}</td><td class="num">{{ $t['deployments'] }}</td><td class="num">{{ $t['personnel'] }}</td><td class="num">{{ $t['vehicles'] }}</td></tr>
            @endforeach
            <tr><th>Total</th><th></th><th class="num">{{ $totals['personnel'] }}</th><th class="num">{{ $totals['vehicles'] }}</th></tr>
            </tbody>
        </table>
    @endif

    <h2>Uncovered communities</h2>
    @if (count($uncovered) === 0)
        <p class="empty">Every community is covered.</p>
    @else
        <p>{{ count($uncovered) }}: {{ implode(', ', $uncovered) }}</p>
    @endif

    <h2>Workflow history</h2>
    <ol>
        @foreach ($timeline as $entry)
            <li><strong>{{ $at($entry['at']) }}</strong> · {{ $entry['label'] }}@if ($entry['by']) · {{ $entry['by'] }}@endif
                @if ($entry['note'])<div class="pre">{{ $entry['note'] }}</div>@endif</li>
        @endforeach
    </ol>
@endsection
