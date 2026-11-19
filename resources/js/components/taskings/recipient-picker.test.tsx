// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { describe, expect, it } from 'vitest';
import RecipientPicker, {
    recipientSentence,
} from '@/components/taskings/recipient-picker';
import type { PickerLga } from '@/types/taskings';

// React's act() needs this flag outside a test renderer.
(
    globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
).IS_REACT_ACT_ENVIRONMENT = true;

const LGAS: PickerLga[] = [
    { ulid: 'u1', name: 'Eket', district: 'd1', chief: 'A. Chief' },
    { ulid: 'u2', name: 'Ikot Ekpene', district: 'd2', chief: null },
    { ulid: 'u3', name: 'Uyo', district: 'd1', chief: 'B. Chief' },
];

function render(selected: string[]) {
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    let latest = selected;
    const draw = () =>
        root.render(
            <RecipientPicker
                lgas={LGAS}
                districts={[
                    { ulid: 'd1', name: 'South' },
                    { ulid: 'd2', name: 'North' },
                ]}
                selected={latest}
                onChange={(next) => {
                    latest = next;
                    draw();
                }}
            />,
        );

    act(draw);

    return {
        container,
        selected: () => latest,
        cleanup: () => act(() => root.unmount()),
    };
}

function type(input: HTMLInputElement, value: string) {
    const setter = Object.getOwnPropertyDescriptor(
        HTMLInputElement.prototype,
        'value',
    )?.set;
    setter?.call(input, value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

describe('recipient picker (Phase 11H)', () => {
    it('Select all ticks the whole state even when a search hides rows', () => {
        const view = render([]);
        const search = view.container.querySelector<HTMLInputElement>(
            '[data-test="recipient-search"]',
        );
        act(() => type(search!, 'Uyo'));
        expect(
            view.container.querySelectorAll('ul[aria-label="LGAs"] li'),
        ).toHaveLength(1);

        const all = view.container.querySelector<HTMLButtonElement>(
            '[data-test="select-all"]',
        );
        act(() => all!.click());

        expect(view.selected()).toEqual(['u1', 'u2', 'u3']);
        expect(
            view.container.querySelector('[data-test="recipient-count"]')
                ?.textContent,
        ).toBe('3 of 3 LGAs selected');
        expect(view.container.textContent).toContain('All 3 LGAs');
        view.cleanup();
    });

    it('shows the indeterminate state for some, and the district quick pick adds a district', () => {
        const view = render(['u2']);
        const all = view.container.querySelector('[data-test="select-all"]');
        expect(all?.getAttribute('data-state')).toBe('indeterminate');

        const district = view.container.querySelector<HTMLSelectElement>(
            '[data-test="district-pick"]',
        );
        act(() => {
            district!.value = 'd1';
            district!.dispatchEvent(new Event('change', { bubbles: true }));
        });

        expect(view.selected()).toEqual(['u1', 'u2', 'u3']);
        view.cleanup();
    });

    it('names a vacant LGA', () => {
        const view = render([]);
        expect(view.container.textContent).toContain('No chief assigned');
        view.cleanup();
    });
});

describe('recipientSentence', () => {
    it('reads naturally for one, some and all LGAs', () => {
        expect(recipientSentence([LGAS[2]], 31, 'Akwa Ibom').text).toBe(
            'Send to the Uyo LGA chief',
        );
        expect(
            recipientSentence([LGAS[2], LGAS[1], LGAS[0]], 31, 'Akwa Ibom')
                .text,
        ).toBe('Send to 3 LGA chiefs: Uyo, Ikot Ekpene, Eket');
        expect(recipientSentence(LGAS, 3, 'Akwa Ibom')).toEqual({
            text: 'Send to all 3 LGA chiefs in Akwa Ibom',
            all: true,
        });
    });
});
