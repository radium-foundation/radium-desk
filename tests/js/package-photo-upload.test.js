import { afterEach, describe, expect, it, vi } from 'vitest';
import { bindPackagePhotoUploads, compressPackagePhotoFile } from '../../resources/js/package-photo-upload';

describe('package-photo-upload', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('returns the original file when compression is unavailable', async () => {
        const file = new File(['abc'], 'notes.txt', { type: 'text/plain' });
        const result = await compressPackagePhotoFile(file);
        expect(result).toBe(file);
    });

    it('binds show-page forms for client-side compression before submit', async () => {
        const submit = vi.fn();
        HTMLFormElement.prototype.submit = submit;

        document.body.innerHTML = `
            <form data-package-photo-upload data-package-photo-target-kb="140">
                <input type="file" name="photo">
                <button type="submit">Upload</button>
            </form>
        `;

        bindPackagePhotoUploads(document);
        const form = document.querySelector('form');
        const input = form.querySelector('input[name="photo"]');
        const file = new File(['abc'], 'package.jpg', { type: 'image/jpeg' });
        Object.defineProperty(input, 'files', { value: [file] });

        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await new Promise((resolve) => {
            setTimeout(resolve, 0);
        });

        expect(submit).toHaveBeenCalledTimes(1);
    });
});
