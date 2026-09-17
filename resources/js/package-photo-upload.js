const DEFAULT_MAX_DIMENSION = 1600;
const DEFAULT_TARGET_KB = 140;
const DEFAULT_MIN_QUALITY = 0.55;
const DEFAULT_START_QUALITY = 0.82;

const readOptions = (input) => {
    const form = input?.closest('form');

    return {
        maxDimension: Number(form?.dataset.packagePhotoMaxDimension || DEFAULT_MAX_DIMENSION),
        targetKb: Number(form?.dataset.packagePhotoTargetKb || DEFAULT_TARGET_KB),
    };
};

const loadImage = (file) => new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
        URL.revokeObjectURL(url);
        resolve(image);
    };
    image.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('invalid image'));
    };
    image.src = url;
});

const canvasToBlob = (canvas, quality) => new Promise((resolve) => {
    canvas.toBlob((blob) => resolve(blob), 'image/jpeg', quality);
});

export const compressPackagePhotoFile = async (file, options = {}) => {
    if (!(file instanceof File) || !file.type.startsWith('image/')) {
        return file;
    }

    if (typeof document === 'undefined' || typeof HTMLCanvasElement === 'undefined') {
        return file;
    }

    const maxDimension = Number(options.maxDimension || DEFAULT_MAX_DIMENSION);
    const targetBytes = Number(options.targetKb || DEFAULT_TARGET_KB) * 1024;

    if (file.size > 0 && file.size <= targetBytes && file.type === 'image/jpeg') {
        return file;
    }

    try {
        const image = await loadImage(file);
        const scale = Math.min(1, maxDimension / Math.max(image.width, image.height));
        const width = Math.max(1, Math.round(image.width * scale));
        const height = Math.max(1, Math.round(image.height * scale));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        if (!context) {
            return file;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(image, 0, 0, width, height);

        let quality = DEFAULT_START_QUALITY;
        let blob = await canvasToBlob(canvas, quality);
        while (blob && blob.size > targetBytes && quality > DEFAULT_MIN_QUALITY) {
            quality -= 0.05;
            blob = await canvasToBlob(canvas, quality);
        }

        if (!blob) {
            return file;
        }

        const baseName = file.name.replace(/\.[^.]+$/, '') || 'package-photo';

        return new File([blob], `${baseName}.jpg`, {
            type: 'image/jpeg',
            lastModified: Date.now(),
        });
    } catch {
        return file;
    }
};

export const applyCompressedPhotoInput = async (input) => {
    const file = input?.files?.[0];
    if (!file) {
        return false;
    }

    const compressed = await compressPackagePhotoFile(file, readOptions(input));
    if (!(compressed instanceof File) || compressed === file) {
        return false;
    }

    const transfer = new DataTransfer();
    transfer.items.add(compressed);
    input.files = transfer.files;

    return true;
};

export const bindPackagePhotoUploads = (root = document) => {
    root.querySelectorAll('[data-package-photo-upload]').forEach((form) => {
        if (form.dataset.packagePhotoBound === '1') {
            return;
        }

        form.dataset.packagePhotoBound = '1';
        form.addEventListener('submit', (event) => {
            if (form.dataset.packagePhotoSubmitting === '1') {
                return;
            }

            const input = form.querySelector('input[type="file"][name="photo"]');
            const file = input?.files?.[0];
            if (!file) {
                return;
            }

            event.preventDefault();
            form.dataset.packagePhotoSubmitting = '1';
            const submit = form.querySelector('[type="submit"]');
            submit?.setAttribute('disabled', 'disabled');

            void applyCompressedPhotoInput(input)
                .catch(() => false)
                .finally(() => {
                    form.dataset.packagePhotoSubmitting = '';
                    submit?.removeAttribute('disabled');
                    form.submit();
                });
        });
    });
};
