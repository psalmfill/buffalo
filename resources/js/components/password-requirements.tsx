type Props = {
    requirements: string[];
};

/**
 * The password policy, shown next to every form that sets a password.
 * The list comes from the server (App\Support\Auth\PasswordRules).
 */
export default function PasswordRequirements({ requirements }: Props) {
    return (
        <div className="rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            <p className="mb-1 font-medium text-foreground">
                Your password must have:
            </p>
            <ul className="list-disc space-y-0.5 pl-4">
                {requirements.map((requirement) => (
                    <li key={requirement}>{requirement}</li>
                ))}
            </ul>
        </div>
    );
}
