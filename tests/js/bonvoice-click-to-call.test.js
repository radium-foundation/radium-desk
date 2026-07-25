import { describe, expect, it } from 'vitest';
import { appendSupportReference } from '../../resources/js/bonvoice-click-to-call';

describe('bonvoice click-to-call support reference', () => {
    it('appends reference id to the existing failure message', () => {
        expect(appendSupportReference('Automatic calling failed.', 'BV-81AF93D2')).toBe(
            'Automatic calling failed.\n\nRef: BV-81AF93D2',
        );
    });

    it('uses default failure message when message is empty', () => {
        expect(appendSupportReference(null, 'BV-81AF93D2')).toBe(
            'Automatic calling failed.\n\nRef: BV-81AF93D2',
        );
    });

    it('does not append provider details when reference id is missing', () => {
        expect(appendSupportReference('Automatic calling failed.', null)).toBe('Automatic calling failed.');
    });
});
