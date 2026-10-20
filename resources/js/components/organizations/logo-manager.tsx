import { router } from '@inertiajs/react';
import { ImageUp, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import OrganizationLogoController from '@/actions/App/Http/Controllers/Organizations/OrganizationLogoController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';

/**
 * Upload or remove an organization's logo (Phase 11D; organizations.manage).
 * The server checks the file by content (PNG, JPEG or WebP; never SVG),
 * scans it, and stores a re-encoded copy without metadata.
 */
export default function LogoManager({
    organizationUlid,
    hasLogo,
}: {
    organizationUlid: string;
    hasLogo: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const [removing, setRemoving] = useState(false);

    const upload = (file: File) => {
        router.post(
            OrganizationLogoController.store(organizationUlid).url,
            { logo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => {
                    setUploading(true);
                    setError(undefined);
                },
                onFinish: () => {
                    setUploading(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
                onError: (errors) => setError(errors.logo),
            },
        );
    };

    return (
        <div className="space-y-1">
            <div className="flex flex-wrap gap-2">
                <input
                    ref={input}
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    className="sr-only"
                    aria-label="Logo file"
                    data-test="logo-input"
                    onChange={(e) => {
                        const file = e.target.files?.[0];

                        if (file) {
                            upload(file);
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={uploading}
                    onClick={() => input.current?.click()}
                >
                    {uploading ? <Spinner /> : <ImageUp />}
                    {hasLogo ? 'Replace logo' : 'Add logo'}
                </Button>
                {hasLogo && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={uploading}
                        onClick={() => setRemoving(true)}
                    >
                        <Trash2 /> Remove logo
                    </Button>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                PNG, JPEG or WebP, up to 2 MB. Logos are visible to every state:
                use only the organization’s public logo.
            </p>
            <InputError message={error} />
            {removing && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setRemoving}
                    title="Remove the logo?"
                    description="The logo is no longer shown. It stays in the organization's history."
                    confirmLabel="Remove"
                    destructive
                    method="delete"
                    url={
                        OrganizationLogoController.destroy(organizationUlid).url
                    }
                />
            )}
        </div>
    );
}
