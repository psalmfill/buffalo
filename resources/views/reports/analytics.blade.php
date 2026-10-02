@extends('reports.layout')

@section('content')
    @php
        $values = $filters['values'];
        $place = $filters['lga']['name'] ?? null ? $filters['lga']['name'].' LGA' : ($filters['state']['name'] ?? null ? $filters['state']['name'].' State' : ($filters['zone']['name'] ?? null ? $filters['zone']['name'].' zone' : 'All within your jurisdiction'));
        $types = isset($values['types']) ? \App\Models\IncidentType::query()->whereIn('ulid', (array) $values['types'])->orderBy('name')->pluck('name')->implode(', ') : null;
        $category = isset($values['category']) ? \App\Enums\IncidentCategory::tryFrom((string) $values['category'])?->label() : null;
        $severities = isset($values['severities']) ? collect((array) $values['severities'])->map(fn ($s) => \App\Enums\Severity::tryFrom((string) $s)?->label())->filter()->implode(', ') : null;
    @endphp
    <table><tbody>
        <tr><th>Place</th><td>{{ $place }}</td></tr>
        <tr><th>Grouped by</th><td>{{ ucfirst($filters['granularity']) }}</td></tr>
        @if ($types)<tr><th>Incident types</th><td>{{ $types }}</td></tr>@endif
        @if ($category)<tr><th>Category</th><td>{{ $category }}</td></tr>@endif
        @if ($severities)<tr><th>Severities</th><td>{{ $severities }}</td></tr>@endif
    </tbody></table>

    @foreach ($charts as $chart)
        @include('reports.partials.chart', ['chart' => $chart])
    @endforeach
@endsection
