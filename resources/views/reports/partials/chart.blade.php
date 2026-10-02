{{-- One analytics chart (ChartData) in a PDF: the chart (drawn by the layout) and its table. --}}
<div class="section">
    <h3>{{ $chart['title'] }}</h3>
    @if (in_array($chart['kind'], ['line', 'bar', 'stacked'], true) && count($chart['rows']) > 0)
        <div class="chart"><canvas data-chart="{{ json_encode(['kind' => $chart['kind'], 'labels' => $chart['labels'], 'series' => $chart['series']]) }}"></canvas></div>
    @endif
    @if (count($chart['rows']) === 0)
        <p class="empty">No records in this period.</p>
    @else
        <table>
            <thead><tr>@foreach ($chart['columns'] as $column)<th class="{{ ($column['numeric'] ?? false) ? 'num' : '' }}">{{ $column['label'] }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach (array_slice($chart['rows'], 0, $limit ?? 60) as $row)
                <tr>@foreach ($chart['columns'] as $column)<td class="{{ ($column['numeric'] ?? false) ? 'num' : '' }}">{{ is_numeric($row[$column['key']] ?? null) ? number_format((float) $row[$column['key']], is_float($row[$column['key']]) ? 1 : 0) : ($row[$column['key']] ?? '—') }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table>
        @if (count($chart['rows']) > ($limit ?? 60))<p class="small muted">{{ count($chart['rows']) - ($limit ?? 60) }} more rows not shown.</p>@endif
    @endif
    @if ($chart['note'])<p class="small muted">{{ $chart['note'] }}</p>@endif
</div>
