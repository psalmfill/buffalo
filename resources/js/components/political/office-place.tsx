import type { PoliticalOffice } from '@/types/political';

/** Where an office sits: ward, LGA, district or constituency, and state. */
export function officePlace(office: PoliticalOffice): string {
    return [
        office.ward ? `${office.ward.name} ward` : null,
        office.lga ? `${office.lga.name} LGA` : null,
        office.senatorial_district?.name ?? null,
        office.constituency_name,
        `${office.state.name} State`,
    ]
        .filter(Boolean)
        .join(' · ');
}

export function officeTerm(office: PoliticalOffice): string {
    return office.ended_on
        ? `${office.started_on} – ${office.ended_on}`
        : `Since ${office.started_on}`;
}
