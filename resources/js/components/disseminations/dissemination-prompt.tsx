import { useState } from 'react';
import DisseminateDialog from '@/components/disseminations/disseminate-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type {
    DisseminationPrompt as Prompt,
    Sharing,
} from '@/types/disseminations';

type Props = {
    prompt: Prompt;
    sharing: Sharing;
    disseminateUrl: string;
    requestUrl: string;
};

/**
 * Right after recording an urgent threat report or a high/severe flashpoint
 * whose area crosses into other LGAs or states (Phase 7A): "Disseminate
 * now?" (SD/DSD) or "Request dissemination?" (LGA chief).
 */
export default function DisseminationPrompt({
    prompt,
    sharing,
    disseminateUrl,
    requestUrl,
}: Props) {
    const [step, setStep] = useState<'ask' | 'form' | null>('ask');
    const disseminate = prompt.mode === 'disseminate';

    return (
        <>
            <Dialog
                open={step === 'ask'}
                onOpenChange={(open) => !open && setStep(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {disseminate
                                ? 'Disseminate now?'
                                : 'Request dissemination?'}
                        </DialogTitle>
                        <DialogDescription>
                            This area extends into {prompt.places.join(', ')}.{' '}
                            {disseminate
                                ? 'Their officers cannot see this record unless you share it.'
                                : 'Ask the State Director or Deputy to share it with them.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setStep(null)}>
                            Not now
                        </Button>
                        <Button onClick={() => setStep('form')}>
                            {disseminate
                                ? 'Choose places'
                                : 'Write the request'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {step === 'form' && (
                <DisseminateDialog
                    open
                    onOpenChange={(open) => !open && setStep(null)}
                    mode={prompt.mode}
                    url={disseminate ? disseminateUrl : requestUrl}
                    suggestions={sharing.suggestions}
                    preselect
                    places={sharing.places}
                    audience={sharing.audience}
                />
            )}
        </>
    );
}
