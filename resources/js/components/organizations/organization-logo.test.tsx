import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import OrganizationLogo, {
    initials,
} from '@/components/organizations/organization-logo';

describe('OrganizationLogo', () => {
    it('takes up to two initials, ignoring punctuation and filler words', () => {
        expect(initials('Gulf of Guinea Petroleum Ltd')).toBe('GG');
        expect(initials('The Of')).toBe('TO');
        expect(initials('NNPC')).toBe('N');
        expect(initials('  (Ọbá) & Sons ')).toBe('ỌS');
        expect(initials('')).toBe('');
    });

    it('shows the logo thumbnail from its authorised URL', () => {
        const html = renderToStaticMarkup(
            <OrganizationLogo
                name="Gulf of Guinea Petroleum Ltd"
                logo={{
                    thumb: '/organizations/01J/logo/01K/thumb',
                    full: '/organizations/01J/logo/01K',
                }}
            />,
        );

        expect(html).toContain('src="/organizations/01J/logo/01K/thumb"');
        expect(html).toContain('alt="Gulf of Guinea Petroleum Ltd logo"');
    });

    it('shows a neutral placeholder with initials when there is no logo', () => {
        const html = renderToStaticMarkup(
            <OrganizationLogo name="Gulf Haulage" logo={null} />,
        );

        expect(html).not.toContain('<img');
        expect(html).toContain('data-test="logo-placeholder"');
        expect(html).toContain('>GH<');
    });
});
