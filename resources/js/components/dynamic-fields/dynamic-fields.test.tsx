import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import DynamicFields from '@/components/dynamic-fields/dynamic-fields';
import DynamicFieldsView from '@/components/dynamic-fields/dynamic-fields-view';
import type { FieldDefinition } from '@/types/dynamic-fields';
import { FIELD_TYPES } from '@/types/dynamic-fields';

const options = [
    { value: 'home', label: 'Home' },
    { value: 'farm', label: 'Farm' },
    { value: 'old', label: 'Old place', deprecated: true },
];

const fields: FieldDefinition[] = [
    { key: 'a_text', label: 'Text', type: 'text', max_length: 20 },
    {
        key: 'a_textarea',
        label: 'Textarea',
        type: 'textarea',
        help: 'Say more',
    },
    { key: 'an_integer', label: 'Integer', type: 'integer', min: 1, max: 9 },
    { key: 'a_number', label: 'Number', type: 'number', required: true },
    { key: 'a_boolean', label: 'Boolean', type: 'boolean' },
    { key: 'a_date', label: 'Date', type: 'date' },
    { key: 'a_datetime', label: 'Datetime', type: 'datetime' },
    { key: 'a_select', label: 'Select', type: 'select', options },
    {
        key: 'a_multiselect',
        label: 'Multiselect',
        type: 'multiselect',
        options,
    },
];

function render(
    list: FieldDefinition[],
    values: Record<string, unknown> = {},
    extra: Partial<Parameters<typeof DynamicFields>[0]> = {},
): string {
    return renderToStaticMarkup(
        <DynamicFields
            fields={list}
            values={values as never}
            onChange={() => {}}
            {...extra}
        />,
    );
}

describe('DynamicFields', () => {
    it('covers every field type', () => {
        expect(fields.map((field) => field.type).sort()).toEqual(
            [...FIELD_TYPES].sort(),
        );
    });

    it('renders an input for every field type', () => {
        const html = render(fields);

        for (const type of FIELD_TYPES) {
            expect(html).toContain(`data-field-type="${type}"`);
        }

        expect(html).toMatch(
            /<input[^>]*id="details-a_text"[^>]*maxLength="20"/,
        );
        expect(html).toMatch(/<textarea[^>]*id="details-a_textarea"/);
        expect(html).toMatch(
            /<input[^>]*type="number"[^>]*id="details-an_integer"|<input[^>]*id="details-an_integer"[^>]*type="number"/,
        );
        expect(html).toMatch(/id="details-an_integer"[^>]*min="1"[^>]*max="9"/);
        expect(html).toMatch(/<select[^>]*id="details-a_boolean"/);
        expect(html).toContain('Not recorded');
        expect(html).toMatch(
            /type="date"[^>]*id="details-a_date"|id="details-a_date"[^>]*type="date"/,
        );
        expect(html).toMatch(
            /type="datetime-local"[^>]*id="details-a_datetime"|id="details-a_datetime"[^>]*type="datetime-local"/,
        );
        expect(html).toMatch(/<select[^>]*id="details-a_select"/);
        expect(html).toMatch(/role="group"[^>]*aria-label="Multiselect"/);
        expect(html).toContain('Say more');
        // Required marker on the required field only.
        expect(html.match(/text-destructive/g)).toHaveLength(1);
    });

    it('hides deprecated options for new entries', () => {
        const html = render(fields);

        expect(html).toContain('Farm');
        expect(html).not.toContain('Old place');
    });

    it('shows a deprecated option the record already has', () => {
        const html = render(fields, { a_select: 'old' }, { mode: 'edit' });

        expect(html).toContain('Old place');
    });

    it('hides deprecated fields for new entries, shows them with a value when editing', () => {
        const list: FieldDefinition[] = [
            {
                key: 'legacy',
                label: 'Legacy field',
                type: 'text',
                deprecated: true,
            },
            { key: 'current', label: 'Current field', type: 'text' },
        ];

        expect(render(list)).not.toContain('Legacy field');
        expect(render(list, {}, { mode: 'edit' })).not.toContain(
            'Legacy field',
        );

        const editing = render(list, { legacy: 'kept' }, { mode: 'edit' });
        expect(editing).toContain('Legacy field');
        expect(editing).toContain('(no longer used)');
        expect(editing).toMatch(/id="details-legacy"[^>]*disabled/);
    });

    it('shows server errors next to the field, including item errors', () => {
        const html = render(
            fields,
            {},
            {
                errors: {
                    'details.a_number': 'The Number field is required.',
                    'details.a_multiselect.1':
                        'A selected option is not available.',
                },
            },
        );

        expect(html).toContain('The Number field is required.');
        expect(html).toContain('A selected option is not available.');
    });

    it('says so when there are no fields', () => {
        expect(render([])).toContain('no extra detail fields');
    });
});

describe('DynamicFieldsView', () => {
    it('shows labels and option labels, including deprecated fields', () => {
        const html = renderToStaticMarkup(
            <DynamicFieldsView
                fields={[
                    ...fields,
                    {
                        key: 'legacy',
                        label: 'Legacy',
                        type: 'text',
                        deprecated: true,
                    },
                ]}
                values={{
                    a_boolean: false,
                    a_number: 1500.5,
                    a_select: 'old',
                    a_multiselect: ['home', 'farm'],
                    legacy: 'kept',
                }}
            />,
        );

        expect(html).toContain('No');
        expect(html).toContain('1,500.5');
        expect(html).toContain('Old place');
        expect(html).toContain('Home, Farm');
        expect(html).toContain('Legacy (no longer used)');
        expect(html).not.toContain('Text</dt>');
    });
});
