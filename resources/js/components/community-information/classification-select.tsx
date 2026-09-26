import NativeSelect from '@/components/native-select';
import { useCurrentUser } from '@/hooks/use-auth';
import type { ClassificationLevel } from '@/types/auth';

export const CLASSIFICATION_LEVELS: ClassificationLevel[] = [
    'restricted',
    'confidential',
    'secret',
];

const LABELS: Record<ClassificationLevel, string> = {
    restricted: 'Restricted — all officers in jurisdiction',
    confidential: 'Confidential — deputy state directors and above',
    secret: 'Secret — state directors and above',
};

/** Levels up to the user's maximum (ClassificationGate::canAssign). */
export function assignableLevels(
    max: ClassificationLevel | null,
): ClassificationLevel[] {
    return max === null
        ? []
        : CLASSIFICATION_LEVELS.slice(
              0,
              CLASSIFICATION_LEVELS.indexOf(max) + 1,
          );
}

/**
 * Offers only the levels the signed-in user may assign. The server checks
 * again (SecurityNoteRequest).
 */
export default function ClassificationSelect({
    id = 'classification',
    value,
    onChange,
}: {
    id?: string;
    value: ClassificationLevel;
    onChange: (level: ClassificationLevel) => void;
}) {
    const user = useCurrentUser();
    const levels = assignableLevels(user.maxClassification);
    // A record above the user's level (they created it) keeps showing it.
    const options = levels.includes(value) ? levels : [...levels, value];

    return (
        <NativeSelect
            id={id}
            value={value}
            onChange={(event) =>
                onChange(event.target.value as ClassificationLevel)
            }
        >
            {options.map((level) => (
                <option key={level} value={level}>
                    {LABELS[level]}
                </option>
            ))}
        </NativeSelect>
    );
}
