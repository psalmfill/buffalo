<!DOCTYPE html>
{{-- Report page layout (Phase 7D). Everything it loads is sent with the
     request (report.css, the Inter font, Chart.js): nothing from the network. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="report.css">
    <script src="chart.umd.min.js"></script>
</head>
<body>
    <div class="watermark">{{ $markings->watermark }} · {{ $markings->reference }}</div>
    <main>
        <h1>{{ $title }}</h1>
        @isset($subtitle)<p class="muted">{{ $subtitle }}</p>@endisset
        @yield('content')
    </main>
    <script>
        // Draw every chart (animation off), then tell Gotenberg the page is ready.
        (function () {
            // The configured chart palette (Phase 11A.3; printed reports use the light mode).
            var palette = {!! json_encode(\App\Support\Theme\Theme::chartPalette('light')) !!};
            document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
                var spec = JSON.parse(canvas.getAttribute('data-chart'));
                var line = spec.kind === 'line', stacked = spec.kind === 'stacked', c = 0;
                if (typeof Chart === 'undefined') { return; }
                new Chart(canvas, {
                    type: line ? 'line' : 'bar',
                    data: {
                        labels: spec.labels,
                        datasets: spec.series.map(function (s) {
                            var colour = s.comparison ? '#94a3b8' : palette[c++ % palette.length];
                            return line
                                ? { type: 'line', label: s.label, data: s.data, borderColor: colour, backgroundColor: colour, borderDash: s.comparison ? [6, 4] : [], pointRadius: spec.labels.length > 40 ? 0 : 2 }
                                : { type: 'bar', label: s.label, data: s.data, backgroundColor: colour };
                        })
                    },
                    options: {
                        animation: false, responsive: true, maintainAspectRatio: false, devicePixelRatio: 2,
                        plugins: { legend: { display: spec.series.length > 1 } },
                        scales: { x: { stacked: stacked, grid: { display: false } }, y: { stacked: stacked, beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });
            });
            window.reportReady = true;
        })();
    </script>
</body>
</html>
