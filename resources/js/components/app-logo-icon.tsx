import type { SVGAttributes } from 'react';

/**
 * BUFFALO emblem: a shield. Drawn inline (no external assets).
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 42" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M20 1 4 7v12c0 10.2 6.8 18.9 16 22 9.2-3.1 16-11.8 16-22V7L20 1Zm0 5.3L9 10.4v8.6c0 7.4 4.6 13.9 11 16.6 6.4-2.7 11-9.2 11-16.6v-8.6L20 6.3Zm-5.5 8.7h7.3c3 0 4.7 1.4 4.7 3.6 0 1.5-.8 2.5-2.1 3 1.7.4 2.7 1.6 2.7 3.3 0 2.5-1.9 4.1-5.1 4.1h-7.5V15Zm3.9 3.1v3.4h2.9c1.2 0 1.8-.6 1.8-1.7s-.6-1.7-1.8-1.7h-2.9Zm0 6.1v3.7h3.2c1.3 0 2-.6 2-1.8s-.7-1.9-2-1.9h-3.2Z"
            />
        </svg>
    );
}
