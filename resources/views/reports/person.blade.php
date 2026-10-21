@extends('reports.layout')
@php($at = fn ($iso) => \App\Services\Reports\Builders\IncidentReportBuilder::at($iso))
@php($rich = fn ($html) => \App\Support\RichText\RichText::display($html))
@php($v = fn ($key) => data_get($person, $key))

{{-- One person record (Phase 11E), exactly the fields the requester's page
     shows; under a grant, only that grant's fields. No photos. --}}
@section('content')
    @if ($underGrant)
        <p class="badge">Seen under a cross-jurisdiction access grant: only the fields the grant shows are printed.</p>
    @endif
    <p>{{ $v('classification.label') }}@if ($v('is_deceased')) · Deceased @endif</p>

    <h2>Profile</h2>
    <table>
        <tbody>
            <tr><th>Name</th><td>{{ $v('name') }}</td></tr>
            @if ($v('other_names'))<tr><th>Other names</th><td>{{ $v('other_names') }}</td></tr>@endif
            @if (count($aliases) > 0)<tr><th>Aliases</th><td>{{ implode(', ', $aliases) }}</td></tr>@endif
            <tr><th>Gender</th><td>{{ $v('gender.label') }}</td></tr>
            @if (array_key_exists('date_of_birth', $person))<tr><th>Date of birth</th><td>{{ $v('date_of_birth') ?? '—' }}@if ($v('approximate_age')) (about {{ $v('approximate_age') }})@endif</td></tr>@endif
            @if (array_key_exists('nin', $person))<tr><th>NIN</th><td>{{ $v('nin') ?? '—' }}</td></tr>@endif
            @if (array_key_exists('phone', $person))<tr><th>Phone</th><td>{{ $v('phone') ?? '—' }}</td></tr>@endif
            @if (array_key_exists('address', $person))<tr><th>Address</th><td>{{ $v('address') ?? '—' }}</td></tr>@endif
            <tr><th>Occupation</th><td>{{ $v('occupation') ?? '—' }}</td></tr>
            <tr><th>Nationality</th><td>{{ $v('nationality') ?? '—' }}</td></tr>
            @if ($v('lga'))<tr><th>Place</th><td>{{ $v('community.name') ? $v('community.name').', ' : '' }}{{ $v('lga.name') }} LGA, {{ $v('state.name') }} State</td></tr>@endif
            @if ($v('all_roles'))<tr><th>Roles</th><td>@foreach ($v('all_roles') as $role){{ $role['role']['label'] }}@if (! $role['is_active']) (inactive)@endif{{ $loop->last ? '' : ', ' }}@endforeach</td></tr>@endif
        </tbody>
    </table>
    @if ($v('description'))
        <h2>Description</h2>
        <div class="rich">{!! $rich($v('description')) !!}</div>
    @endif

    @if ($poi !== null)
        <h2>Person of interest</h2>
        <p>Risk: <strong>{{ $poi['risk_level']['label'] }}</strong> · {{ $poi['status']['label'] }} · Review due {{ $poi['review_due_at'] }}@if ($poi['last_sighted_at']) · Last sighted {{ $at($poi['last_sighted_at']) }}@endif</p>
        <p>Offence categories: {{ collect($poi['offence_categories'])->pluck('label')->implode(', ') ?: '—' }}</p>
        <h3>Known offences</h3><div class="rich">{!! $rich($poi['known_offences']) !!}</div>
        @if ($poi['modus_operandi'])<h3>Modus operandi</h3><div class="rich">{!! $rich($poi['modus_operandi']) !!}</div>@endif
        @if ($poi['last_known_location_description'])<p>Last known location: {{ $poi['last_known_location_description'] }}</p>@endif
    @endif

    @if ($offices !== null && count($offices) > 0)
        <h2>Political offices</h2>
        <table><thead><tr><th>Office</th><th>Place</th><th>Party</th><th>From</th><th>To</th></tr></thead><tbody>
        @foreach ($offices as $o)<tr><td>{{ $o['title'] }}</td><td>{{ $o['ward']['name'] ?? $o['lga']['name'] ?? $o['senatorial_district']['name'] ?? $o['state']['name'] }}</td><td>{{ $o['party'] ?? '—' }}</td><td>{{ $o['started_on'] }}</td><td>{{ $o['current'] ? 'Current' : ($o['ended_on'] ?? '—') }}</td></tr>@endforeach
        </tbody></table>
    @endif

    @if ($memberships !== null && count($memberships) > 0)
        <h2>Criminal group memberships</h2>
        <table><thead><tr><th>Group</th><th>Role</th><th>Status</th><th>Since</th></tr></thead><tbody>
        @foreach ($memberships as $m)<tr><td>{{ $m['group'] }}</td><td>{{ $m['role'] ?? '—' }}</td><td>{{ $m['status'] }}</td><td>{{ $m['since'] ?? '—' }}</td></tr>@endforeach
        </tbody></table>
    @endif
@endsection
