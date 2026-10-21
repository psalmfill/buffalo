@extends('reports.layout')

{{-- One organization site (Phase 11E). Photos are files sent with the
     request (referenced by name); contacts by name and position only. --}}
@section('content')
    <p>{{ $site['organization']['name'] }} · {{ $site['site_type']['label'] }} · {{ $site['status']['label'] }}
        · Security relevance: <strong>{{ $site['security_relevance']['label'] }}</strong> · {{ $site['classification']['label'] }}</p>
    <table>
        <tbody>
            <tr><th>Place</th><td>{{ $site['community']['name'] ?? '' }}{{ $site['community'] ? ', ' : '' }}{{ $site['lga']['name'] }} LGA, {{ $site['state']['name'] }} State</td></tr>
            @if ($site['address'])<tr><th>Address</th><td>{{ $site['address'] }}</td></tr>@endif
            @if ($site['latitude'] !== null)<tr><th>Coordinates</th><td>{{ number_format((float) $site['latitude'], 6) }}, {{ number_format((float) $site['longitude'], 6) }}</td></tr>@endif
            @if ($site['employees_estimate'])<tr><th>Employees (estimate)</th><td>{{ number_format($site['employees_estimate']) }}</td></tr>@endif
            <tr><th>Private security</th><td>{{ $site['has_private_security'] ? 'Yes' : 'No' }}@if ($site['security_provider']) · {{ $site['security_provider']['name'] }}@endif</td></tr>
        </tbody>
    </table>

    @if (count($photos) > 0)
        <h2>Photos</h2>
        <div class="photos">
            @foreach ($photos as $photo)
                <figure>
                    <img src="{{ $photo['file'] }}" alt="">
                    <figcaption>@if ($photo['cover'])<strong>Cover</strong> · @endif{{ $photo['caption'] ?? 'No caption' }}@if ($photo['taken_on']) · {{ $photo['taken_on'] }}@endif</figcaption>
                </figure>
            @endforeach
        </div>
    @endif

    @if ($site['security_notes'])
        <h2>Security notes</h2>
        <div class="rich">{!! \App\Support\RichText\RichText::display($site['security_notes']) !!}</div>
    @endif

    <h2>Government security</h2>
    @if (count($formations) === 0)<p class="empty">No formation recorded as protecting this site.</p>@else
        <table><thead><tr><th>Formation</th><th>Agency</th><th>Arrangement</th><th>Notes</th></tr></thead><tbody>
        @foreach ($formations as $f)<tr><td>{{ $f['formation']['name'] }}</td><td>{{ $f['formation']['agency']['short_name'] ?: $f['formation']['agency']['name'] }}</td><td>{{ $f['arrangement']['label'] }}</td><td>{{ $f['notes'] ?? '' }}</td></tr>@endforeach
        </tbody></table>
    @endif

    <h2>Contacts</h2>
    @if (count($contacts) === 0)<p class="empty">No contacts recorded.</p>@else
        <table><thead><tr><th>Name</th><th>Position</th><th></th></tr></thead><tbody>
        @foreach ($contacts as $c)<tr><td>{{ $c['name'] }}</td><td>{{ $c['position'] }}</td><td>{{ implode(', ', $c['flags']) }}</td></tr>@endforeach
        </tbody></table>
        <p class="small muted">Phone numbers and email addresses are not printed.</p>
    @endif
@endsection
