@extends('reports.layout')
@php($at = fn ($iso) => \App\Services\Reports\Builders\IncidentReportBuilder::at($iso))

{{-- One tasking (Phase 11H). Directors: the recipients table; an LGA chief: their own LGA only. --}}
@section('content')
    <p>{{ $tasking['reference'] }} · {{ $tasking['priority']['label'] }} · {{ $tasking['status']['label'] }} · {{ $tasking['classification']['label'] }}</p>
    <table>
        <tbody>
            <tr><th>Title</th><td>{{ $tasking['title'] }}</td></tr>
            <tr><th>From</th><td>{{ $tasking['sent_by'] ?? 'The State Director' }}, {{ $state }} State</td></tr>
            <tr><th>Sent</th><td>{{ $at($tasking['sent_at']) }}</td></tr>
            <tr><th>Response due</th><td>{{ $tasking['response_due_at'] ? $at($tasking['response_due_at']) : 'No date set' }}</td></tr>
            @if ($tasking['target_mode'] && $recipients !== null)<tr><th>Sent to</th><td>{{ $tasking['target_mode']['label'] }} ({{ count($recipients) }})</td></tr>@endif
            @if ($progress)<tr><th>Progress</th><td>{{ $progress }}</td></tr>@endif
        </tbody>
    </table>

    @if ($tasking['recall'])
        <p class="badge">Recalled by {{ $tasking['recall']['by'] ?? 'a director' }} on {{ $at($tasking['recall']['at']) }}: {{ $tasking['recall']['reason'] }}</p>
    @endif

    <h2>Instruction</h2>
    <div class="rich">{!! $tasking['body'] !!}</div>

    @if (count($attachments) > 0)
        <h2>Attachments ({{ count($attachments) }})</h2>
        <ul>
            @foreach ($attachments as $a)
                <li>{{ $a['type']['label'] }}: {{ $a['caption'] ?? 'No caption' }} · {{ $a['classification']['label'] }}</li>
            @endforeach
        </ul>
    @endif

    @if (count($notes) > 0)
        <h2>Follow-up notes</h2>
        @foreach ($notes as $note)
            <p><strong>{{ $at($note['at']) }}</strong>@if ($note['by']) · {{ $note['by'] }}@endif</p>
            <div class="rich">{!! $note['body'] !!}</div>
        @endforeach
    @endif

    @if ($recipients !== null)
        <h2>Recipients ({{ count($recipients) }})</h2>
        <table>
            <thead><tr><th>LGA</th><th>Security Chief</th><th>Status</th><th>Read</th><th>Acknowledged</th><th>Completed</th><th>Overdue</th></tr></thead>
            <tbody>
            @foreach ($recipients as $r)
                <tr>
                    <td>{{ $r['lga']['name'] }}</td>
                    <td>{{ $r['chief'] ?? 'No chief assigned' }}</td>
                    <td>{{ $r['status']['label'] }}</td>
                    <td>{{ $r['read_at'] ? $at($r['read_at']) : '' }}</td>
                    <td>{{ $r['acknowledged_at'] ? $at($r['acknowledged_at']) : '' }}</td>
                    <td>{{ $r['completed_at'] ? $at($r['completed_at']) : '' }}</td>
                    <td>{{ $r['overdue'] ? 'Overdue' : '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($mine !== null)
        <h2>{{ $mine['lga']['name'] }} LGA</h2>
        <p>{{ $mine['status']['label'] }}@if ($mine['overdue']) · Overdue @endif</p>
        @if ($mine['response'] !== '')
            <h3>Report back</h3>
            <div class="rich">{!! $mine['response'] !!}</div>
        @endif
        @foreach ($mine['responses'] as $response)
            <p><strong>Further response, {{ $at($response['at']) }}</strong>@if ($response['by']) · {{ $response['by'] }}@endif</p>
            <div class="rich">{!! $response['body'] !!}</div>
        @endforeach
    @endif
@endsection
