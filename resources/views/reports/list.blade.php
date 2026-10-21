@extends('reports.layout')

{{-- A list export as a PDF table (Phase 11E): the chosen columns of the
     page's rows for the requester's filters. Plain text only. --}}
@section('content')
    <p class="muted small">{{ count($rows) }} {{ count($rows) === 1 ? 'row' : 'rows' }}</p>
    @if (count($rows) === 0)
        <p class="empty">No rows match these filters.</p>
    @else
        <table class="list">
            <thead><tr>@foreach ($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>@foreach ($row as $value)<td @if (is_int($value) || is_float($value)) class="num" @endif>{{ $value ?? '—' }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
