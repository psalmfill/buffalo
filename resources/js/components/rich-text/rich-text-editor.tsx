import type { Editor } from '@tiptap/react';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import {
    Bold,
    Columns3,
    Heading3,
    Heading4,
    Italic,
    Link2,
    List,
    ListOrdered,
    Pilcrow,
    Quote,
    Redo2,
    RemoveFormatting,
    Rows3,
    Table as TableIcon,
    Trash2,
    Underline as UnderlineIcon,
    Undo2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useId, useMemo, useState } from 'react';
import { richTextExtensions } from '@/components/rich-text/extensions';
import { isEmptyRichText, plainText } from '@/lib/rich-text';
import { cn } from '@/lib/utils';

/** The CSP nonce for the editor's injected base styles (Phase 8B.1). */
function cspNonce(): string | undefined {
    return (
        document
            .querySelector('meta[name="csp-nonce"]')
            ?.getAttribute('content') ?? undefined
    );
}

function ToolButton({
    label,
    onClick,
    active = false,
    disabled = false,
    children,
}: {
    label: string;
    onClick: () => void;
    active?: boolean;
    disabled?: boolean;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            aria-pressed={active}
            disabled={disabled}
            // Keep the editor's selection when using the toolbar.
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
            className={cn(
                'inline-flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-40',
                active && 'bg-accent text-foreground',
            )}
        >
            {children}
        </button>
    );
}

function Separator() {
    return <span aria-hidden className="mx-0.5 h-5 w-px bg-border" />;
}

/** Link add, edit and remove, in the toolbar (no browser dialogs). */
function LinkControl({ editor }: { editor: Editor }) {
    const [open, setOpen] = useState(false);
    const [url, setUrl] = useState('');
    const active = editor.isActive('link');

    const start = () => {
        setUrl((editor.getAttributes('link').href as string | undefined) ?? '');
        setOpen(true);
    };
    const apply = () => {
        const value = url.trim();

        if (value === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
        } else {
            editor
                .chain()
                .focus()
                .extendMarkRange('link')
                .setLink({ href: value })
                .run();
        }

        setOpen(false);
    };

    return (
        <>
            <ToolButton
                label={active ? 'Edit link' : 'Add link'}
                onClick={start}
                active={active}
            >
                <Link2 className="size-4" />
            </ToolButton>
            {open && (
                <span className="inline-flex items-center gap-1">
                    <input
                        autoFocus
                        aria-label="Link address"
                        placeholder="https://…"
                        value={url}
                        onChange={(e) => setUrl(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                apply();
                            } else if (e.key === 'Escape') {
                                setOpen(false);
                                editor.commands.focus();
                            }
                        }}
                        className="h-7 w-48 rounded border bg-background px-2 text-xs"
                    />
                    <button
                        type="button"
                        className="h-7 rounded border px-2 text-xs hover:bg-accent"
                        onClick={apply}
                    >
                        Apply
                    </button>
                    {active && (
                        <button
                            type="button"
                            className="h-7 rounded border px-2 text-xs hover:bg-accent"
                            onClick={() => {
                                editor
                                    .chain()
                                    .focus()
                                    .extendMarkRange('link')
                                    .unsetLink()
                                    .run();
                                setOpen(false);
                            }}
                        >
                            Remove
                        </button>
                    )}
                </span>
            )}
        </>
    );
}

function Toolbar({ editor }: { editor: Editor }) {
    const state = useEditorState({
        editor,
        selector: ({ editor: e }) => ({
            paragraph: e.isActive('paragraph'),
            h3: e.isActive('heading', { level: 3 }),
            h4: e.isActive('heading', { level: 4 }),
            bold: e.isActive('bold'),
            italic: e.isActive('italic'),
            underline: e.isActive('underline'),
            bulletList: e.isActive('bulletList'),
            orderedList: e.isActive('orderedList'),
            blockquote: e.isActive('blockquote'),
            table: e.isActive('table'),
            canUndo: e.can().undo(),
            canRedo: e.can().redo(),
        }),
    });
    const chain = () => editor.chain().focus();

    return (
        <div
            role="toolbar"
            aria-label="Formatting"
            className="flex flex-wrap items-center gap-0.5 border-b px-1 py-1"
        >
            <ToolButton
                label="Paragraph"
                active={state.paragraph}
                onClick={() => chain().setParagraph().run()}
            >
                <Pilcrow className="size-4" />
            </ToolButton>
            <ToolButton
                label="Heading"
                active={state.h3}
                onClick={() => chain().toggleHeading({ level: 3 }).run()}
            >
                <Heading3 className="size-4" />
            </ToolButton>
            <ToolButton
                label="Subheading"
                active={state.h4}
                onClick={() => chain().toggleHeading({ level: 4 }).run()}
            >
                <Heading4 className="size-4" />
            </ToolButton>
            <Separator />
            <ToolButton
                label="Bold"
                active={state.bold}
                onClick={() => chain().toggleBold().run()}
            >
                <Bold className="size-4" />
            </ToolButton>
            <ToolButton
                label="Italic"
                active={state.italic}
                onClick={() => chain().toggleItalic().run()}
            >
                <Italic className="size-4" />
            </ToolButton>
            <ToolButton
                label="Underline"
                active={state.underline}
                onClick={() => chain().toggleUnderline().run()}
            >
                <UnderlineIcon className="size-4" />
            </ToolButton>
            <Separator />
            <ToolButton
                label="Bulleted list"
                active={state.bulletList}
                onClick={() => chain().toggleBulletList().run()}
            >
                <List className="size-4" />
            </ToolButton>
            <ToolButton
                label="Numbered list"
                active={state.orderedList}
                onClick={() => chain().toggleOrderedList().run()}
            >
                <ListOrdered className="size-4" />
            </ToolButton>
            <ToolButton
                label="Quote"
                active={state.blockquote}
                onClick={() => chain().toggleBlockquote().run()}
            >
                <Quote className="size-4" />
            </ToolButton>
            <Separator />
            <LinkControl editor={editor} />
            <Separator />
            {state.table ? (
                <>
                    <ToolButton
                        label="Add row below"
                        onClick={() => chain().addRowAfter().run()}
                    >
                        <Rows3 className="size-4" />
                    </ToolButton>
                    <ToolButton
                        label="Remove row"
                        onClick={() => chain().deleteRow().run()}
                    >
                        <Rows3 className="size-4 text-destructive" />
                    </ToolButton>
                    <ToolButton
                        label="Add column to the right"
                        onClick={() => chain().addColumnAfter().run()}
                    >
                        <Columns3 className="size-4" />
                    </ToolButton>
                    <ToolButton
                        label="Remove column"
                        onClick={() => chain().deleteColumn().run()}
                    >
                        <Columns3 className="size-4 text-destructive" />
                    </ToolButton>
                    <ToolButton
                        label="Remove table"
                        onClick={() => chain().deleteTable().run()}
                    >
                        <Trash2 className="size-4" />
                    </ToolButton>
                </>
            ) : (
                <ToolButton
                    label="Insert table"
                    onClick={() =>
                        chain()
                            .insertTable({
                                rows: 3,
                                cols: 3,
                                withHeaderRow: true,
                            })
                            .run()
                    }
                >
                    <TableIcon className="size-4" />
                </ToolButton>
            )}
            <Separator />
            <ToolButton
                label="Undo"
                disabled={!state.canUndo}
                onClick={() => chain().undo().run()}
            >
                <Undo2 className="size-4" />
            </ToolButton>
            <ToolButton
                label="Redo"
                disabled={!state.canRedo}
                onClick={() => chain().redo().run()}
            >
                <Redo2 className="size-4" />
            </ToolButton>
            <ToolButton
                label="Clear formatting"
                onClick={() => chain().unsetAllMarks().clearNodes().run()}
            >
                <RemoveFormatting className="size-4" />
            </ToolButton>
        </div>
    );
}

/**
 * The shared rich text editor (Phase 11B; Tiptap, MIT core only).
 *
 * Formatting: paragraphs, two heading levels, bold, italic, underline,
 * lists, quotes, links and simple tables. No images, colours, fonts,
 * embeds or raw HTML: the schema has no place for them, so pasted Word or
 * web content keeps only these. The counter counts plain text, as the
 * server validates it. The server sanitises whatever is sent.
 *
 * `value` and `onChange` carry HTML; an editor without text gives ''.
 */
export default function RichTextEditor({
    id,
    value,
    onChange,
    limit,
    placeholder,
    invalid = false,
    readOnly = false,
    describedBy,
    minHeight = 'min-h-32',
}: {
    id?: string;
    value: string | null | undefined;
    onChange?: (html: string) => void;
    limit?: number;
    placeholder?: string;
    invalid?: boolean;
    readOnly?: boolean;
    describedBy?: string;
    minHeight?: string;
}) {
    const fallbackId = useId();
    const counterId = `${id ?? fallbackId}-count`;
    // The value is controlled, so the count follows it.
    const length = useMemo(() => plainText(value ?? '').length, [value]);

    const editor = useEditor({
        immediatelyRender: false,
        editable: !readOnly,
        injectNonce: cspNonce(),
        extensions: richTextExtensions(placeholder),
        content: value ?? '',
        editorProps: {
            attributes: {
                ...(id ? { id } : {}),
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-invalid': invalid ? 'true' : 'false',
                'aria-describedby': [describedBy, limit ? counterId : null]
                    .filter(Boolean)
                    .join(' '),
                class: cn(
                    'rich-content px-3 py-2 text-sm outline-none',
                    minHeight,
                ),
            },
        },
        onUpdate: ({ editor: e }) => {
            const html = e.getHTML();
            onChange?.(isEmptyRichText(html) ? '' : html);
        },
    });

    // Outside changes (a reset, a draft restored) replace the content.
    useEffect(() => {
        if (!editor || editor.isDestroyed) {
            return;
        }

        const current = editor.getHTML();
        const next = value ?? '';

        if (next !== current && !(next === '' && isEmptyRichText(current))) {
            editor.commands.setContent(next, { emitUpdate: false });
        }
    }, [editor, value]);

    useEffect(() => {
        editor?.setEditable(!readOnly);
    }, [editor, readOnly]);

    // A <label for={id}> names the editor and focuses it when clicked (a
    // contenteditable is not a labelable element).
    useEffect(() => {
        if (!editor || !id) {
            return;
        }

        const label = document.querySelector<HTMLLabelElement>(
            `label[for="${CSS.escape(id)}"]`,
        );

        if (!label) {
            return;
        }

        editor.view.dom.setAttribute(
            'aria-label',
            label.textContent?.trim() ?? '',
        );
        const focus = () => editor.commands.focus();
        label.addEventListener('click', focus);

        return () => label.removeEventListener('click', focus);
    }, [editor, id]);

    const over = limit !== undefined && length > limit;

    return (
        <div
            className={cn(
                'rounded-md border bg-background shadow-xs focus-within:ring-[3px] focus-within:ring-ring/50',
                (invalid || over) && 'border-destructive',
                readOnly && 'bg-muted/40',
            )}
            data-rich-text-editor
        >
            {editor && !readOnly && <Toolbar editor={editor} />}
            <EditorContent editor={editor} />
            {limit !== undefined && !readOnly && (
                <p
                    id={counterId}
                    className={cn(
                        'border-t px-3 py-1 text-right text-xs text-muted-foreground tabular-nums',
                        over && 'font-medium text-destructive',
                    )}
                    aria-live="polite"
                >
                    {length.toLocaleString('en-NG')} /{' '}
                    {limit.toLocaleString('en-NG')} characters
                </p>
            )}
        </div>
    );
}
