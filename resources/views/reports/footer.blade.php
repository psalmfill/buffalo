{{-- Repeated by Chromium at the bottom of every page (Phase 7D). Inline styles only. --}}
<html><head><style>
    body { margin: 0 0.5in; font-family: sans-serif; font-size: 7.5pt; color: #475569; width: 100%; }
    .row { display: flex; justify-content: space-between; }
    .banner { text-align: center; font-weight: 700; color: #b91c1c; letter-spacing: 0.08em; font-size: 9pt; }
</style></head><body>
    <div class="row"><span>{{ $title }} · {{ $markings->reference }}</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>
    <div class="banner">{{ $markings->classification }}</div>
</body></html>
