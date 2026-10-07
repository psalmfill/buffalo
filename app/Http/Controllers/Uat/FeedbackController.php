<?php

namespace App\Http\Controllers\Uat;

use App\Http\Controllers\Controller;
use App\Models\UatFeedback;
use App\Models\User;
use App\Support\Reports\SafeCell;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UAT feedback (Phase 9B.5): any tester leaves a short note about the page
 * they are on; super admins list and export them as CSV.
 */
class FeedbackController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('leaveUatFeedback');
        $user = $this->user($request);
        $data = $request->validate([
            'text' => ['required', 'string', 'min:3', 'max:1000'],
            'page_url' => ['required', 'string', 'max:500', 'starts_with:/'],
        ]);

        $feedback = new UatFeedback([
            'role' => $user->getRole()->value ?? 'none',
            // The path only: never a query string with filters or tokens.
            'page_url' => (string) parse_url($data['page_url'], PHP_URL_PATH),
            'text' => trim($data['text']),
        ]);
        $feedback->forceFill(['user_id' => $user->id])->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'Thank you: your feedback was recorded.']);
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewSystemHealth');

        return Inertia::render('uat/feedback', [
            'feedback' => UatFeedback::query()->with('user')->latest('id')->limit(500)->get()
                ->map(fn (UatFeedback $f) => [
                    'ulid' => $f->ulid, 'role' => $f->role, 'page_url' => $f->page_url, 'text' => $f->text,
                    'user' => $f->user->name ?? '', 'created_at' => $f->created_at->toIso8601String(),
                ])->values(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewSystemHealth');

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['created_at', 'user', 'role', 'page', 'feedback']);
            UatFeedback::query()->with('user')->orderBy('id')->each(function (UatFeedback $f) use ($out): void {
                // Neutralised against formula injection, like every export.
                fputcsv($out, array_map(fn (string $v) => (string) SafeCell::value($v), [
                    $f->created_at->toIso8601String(), $f->user->name ?? '', $f->role, $f->page_url, $f->text,
                ]));
            });
            fclose($out);
        }, 'uat-feedback-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
