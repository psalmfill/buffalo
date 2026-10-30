/**
 * Shown next to every code field (the owner's decision): codes are set on
 * create and never change.
 */
export const CODE_HELP =
    'Codes are set when the entry is created and can never be changed. Besides incident references, codes are how the official CSV import matches entries: buffalo:import-hierarchy updates entries by code, so changing a code here would make the next import create a duplicate instead of updating the entry. The official CSV is the source of truth for codes. To correct a wrong code, fix it in the CSV and re-import (the old entry is deactivated with --deactivate-missing). For an entry created here with a wrong code and nothing linked to it, deactivate it and create a corrected one.';
