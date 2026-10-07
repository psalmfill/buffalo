<?php

namespace App\Support\Help;

use App\Enums\Role;
use RuntimeException;

/**
 * One manual file (Phase 9B.4): its front matter and Markdown body.
 */
final readonly class Manual
{
    public function __construct(
        public string $kind,
        public string $slug,
        public string $title,
        public string $summary,
        public string $audience,
        public ?Role $role,
        public int $order,
        public string $body,
    ) {}

    public static function fromFile(string $kind, string $path): self
    {
        $contents = (string) file_get_contents($path);

        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $contents, $matches) !== 1) {
            throw new RuntimeException("Manual {$path} has no front matter.");
        }

        $meta = [];
        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $pair) === 1) {
                $meta[$pair[1]] = trim($pair[2]);
            }
        }

        $audience = $meta['audience'] ?? '';
        if (! in_array($audience, ['all', 'intelligence', 'super_admin', 'role'], true)) {
            throw new RuntimeException("Manual {$path}: unknown audience \"{$audience}\".");
        }

        $role = isset($meta['role']) ? Role::tryFrom($meta['role']) : null;
        if ($audience === 'role' && $role === null) {
            throw new RuntimeException("Manual {$path}: a role manual needs a valid role.");
        }

        return new self(
            kind: $kind,
            slug: basename($path, '.md'),
            title: $meta['title'] ?? basename($path, '.md'),
            summary: $meta['summary'] ?? '',
            audience: $audience,
            role: $role,
            order: (int) ($meta['order'] ?? 100),
            body: $matches[2],
        );
    }
}
