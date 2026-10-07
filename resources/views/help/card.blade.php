<!DOCTYPE html>
{{-- Quick reference card (Phase 9B.4): one page, printed through Gotenberg
     with the same local assets as reports. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="report.css">
    <style>
        main { font-size: 9.5pt; }
        main h1 { font-size: 15pt; margin-bottom: 4pt; }
        main h2 { font-size: 11pt; margin: 9pt 0 3pt; }
        main ul, main ol { margin: 2pt 0 4pt 14pt; padding: 0; }
        main li { margin: 1pt 0; }
        main p { margin: 3pt 0; }
        main table { border-collapse: collapse; width: 100%; margin: 4pt 0; }
        main th, main td { border: 0.5pt solid #cbd5e1; padding: 2pt 4pt; text-align: left; vertical-align: top; }
    </style>
</head>
<body>
    <div class="watermark">{{ $generatedFor }}</div>
    <main>
        <h1>{{ $title }}</h1>
        {!! $html !!}
    </main>
    <script>window.reportReady = true;</script>
</body>
</html>
