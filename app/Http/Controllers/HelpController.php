<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Help\Manual;
use App\Support\Help\ManualLibrary;
use App\Support\Help\MarkdownRenderer;
use App\Support\Reports\GotenbergClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Help (Phase 9B.4): the manuals in docs/manuals, rendered on the server
 * (sanitised, no external links or images), and one-page quick reference
 * cards printed as PDF with the RESTRICTED marking.
 */
class HelpController extends Controller
{
    public function __construct(private readonly ManualLibrary $library) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $this->authorize('viewHelp');

        $visible = $this->library->visibleTo($user);
        $present = fn (Manual $m) => ['kind' => $m->kind, 'slug' => $m->slug, 'title' => $m->title, 'summary' => $m->summary, 'own' => $m->role !== null && $m->role === $user->getRole()];

        return Inertia::render('help/index', [
            'start' => array_values(array_map($present, array_filter($visible, fn (Manual $m) => $m->kind === 'start'))),
            'roles' => array_values(array_map($present, array_filter($visible, fn (Manual $m) => $m->kind === 'roles'))),
            'guides' => array_values(array_map($present, array_filter($visible, fn (Manual $m) => $m->kind === 'guides'))),
            'cards' => array_values(array_map(fn (Manual $m) => [...$present($m), 'role' => $m->role?->value], array_filter($visible, fn (Manual $m) => $m->kind === 'cards'))),
        ]);
    }

    public function show(Request $request, string $kind, string $slug, MarkdownRenderer $renderer): Response
    {
        $user = $this->user($request);
        $this->authorize('viewHelp');

        $manual = $this->library->find($kind, $slug);
        abort_if($manual === null || ! $this->library->canRead($user, $manual), 404);

        $rendered = $renderer->render($manual);

        return Inertia::render('help/show', [
            'manual' => ['kind' => $manual->kind, 'slug' => $manual->slug, 'title' => $manual->title, 'summary' => $manual->summary, 'role' => $manual->role?->value],
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
        ]);
    }

    public function card(Request $request, string $role, MarkdownRenderer $renderer, GotenbergClient $gotenberg): HttpResponse
    {
        $user = $this->user($request);
        $this->authorize('viewHelp');

        $manual = ($cardRole = Role::tryFrom($role)) === null ? null : $this->library->card($cardRole);
        abort_if($manual === null || ! $this->library->canRead($user, $manual), 404);

        $generatedFor = sprintf('Generated for %s, %s on %s', $user->name, $user->getRole()?->label() ?? '', now()->format('j M Y H:i'));
        $shared = ['title' => $manual->title, 'generatedFor' => $generatedFor];

        $pdf = $gotenberg->render(
            view('help.card', [...$shared, 'html' => $renderer->render($manual)['html']])->render(),
            view('help.card-header', $shared)->render(),
            view('help.card-footer', $shared)->render(),
        );

        AuditLog::event('help_card_printed', "Quick reference card printed: {$manual->title}", null, ['card' => $role], $user);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="buffalo-'.$manual->slug.'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
