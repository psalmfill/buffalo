@extends('reports.layout')
@php($at = fn ($iso) => \App\Services\Reports\Builders\IncidentReportBuilder::at($iso))

@section('content')
    <p>{{ $report['lga']['name'] }} LGA, {{ $report['state']['name'] }} State · Report date {{ \Carbon\CarbonImmutable::parse($report['report_date'])->format('j M Y') }} · {{ $report['period'] }} · {{ $report['status']['label'] }} · Overall rating: {{ $report['overall_rating']['label'] ?? 'Not rated' }}</p>

    <h2>Report</h2>
    @if ($report['summary'])
        <div class="rich">{!! \App\Support\RichText\RichText::display($report['summary']) !!}</div>
    @else
        <p class="empty">No report text yet.</p>
    @endif

    @if (count($photos) > 0)
        <h2>Photos</h2>
        <div class="photos">
            @foreach ($photos as $photo)
                <figure>
                    <img src="{{ $photo['file'] }}" alt="">
                    <figcaption>{{ $photo['caption'] ?? 'No caption' }}@if ($photo['captured']) · {{ $photo['captured'] }}@endif</figcaption>
                </figure>
            @endforeach
        </div>
    @endif

    @if (count($videos) > 0)
        <h2>Videos</h2>
        <ul>
            @foreach ($videos as $video)
                <li>{{ $video['caption'] ?? 'No caption' }}@if ($video['captured']) · captured {{ $video['captured'] }}@endif</li>
            @endforeach
        </ul>
    @endif

    @foreach (['gaps_identified' => 'Gaps identified', 'recommendations' => 'Recommendations'] as $key => $label)
        @if ($report[$key])<h2>{{ $label }}</h2><div class="rich">{!! \App\Support\RichText\RichText::display($report[$key]) !!}</div>@endif
    @endforeach

    @if (count($deployments) > 0)
    <h2>Deployments</h2>
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

    <h2>Uncovered communities</h2>
    @if (count($uncovered) === 0)
        <p class="empty">Every community is covered.</p>
    @else
        <p>{{ count($uncovered) }}: {{ implode(', ', $uncovered) }}</p>
    @endif
    @endif

    <h2>Workflow history</h2>
    <ol>
        @foreach ($timeline as $entry)
            <li><strong>{{ $at($entry['at']) }}</strong> · {{ $entry['label'] }}@if ($entry['by']) · {{ $entry['by'] }}@endif
                @if ($entry['note'])<div class="pre">{{ $entry['note'] }}</div>@endif</li>
        @endforeach
    </ol>
@endsection
