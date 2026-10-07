import { Link, useForm, usePage } from '@inertiajs/react';
import { MessageSquarePlus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useAuth } from '@/hooks/use-auth';
import { UAT_FEEDBACK } from '@/lib/uat';

/**
 * UAT feedback (Phase 9B.5): only in the UAT environment. Records a short
 * note with the current page (path only) and the tester's role.
 */
export default function UatFeedbackButton() {
    const { uat } = usePage().props.app;
    const { can } = useAuth();
    const [open, setOpen] = useState(false);
    const form = useForm({ text: '', page_url: '' });

    if (!uat) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            page_url: window.location.pathname,
        }));
        form.post(UAT_FEEDBACK.store, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Button
                className="fixed right-4 bottom-4 z-40 shadow-lg"
                onClick={() => setOpen(true)}
                data-test="uat-feedback"
            >
                <MessageSquarePlus /> Feedback
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>UAT feedback</DialogTitle>
                            <DialogDescription>
                                What did you expect, and what happened? The page
                                and your role are recorded with it. Test data
                                only: never enter real information.
                            </DialogDescription>
                        </DialogHeader>
                        <Textarea
                            rows={5}
                            maxLength={1000}
                            value={form.data.text}
                            onChange={(e) =>
                                form.setData('text', e.target.value)
                            }
                            aria-label="Feedback"
                        />
                        <InputError message={form.errors.text} />
                        <DialogFooter className="sm:justify-between">
                            {can('system.settings') ? (
                                <Link
                                    href={UAT_FEEDBACK.index}
                                    className="self-center text-sm underline"
                                >
                                    All feedback
                                </Link>
                            ) : (
                                <span />
                            )}
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    form.data.text.trim().length < 3
                                }
                            >
                                Send
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
