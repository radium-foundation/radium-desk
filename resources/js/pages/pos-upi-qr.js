// Browser QR for persisted UPI URIs. Regenerate the committed bundle with:
// npx esbuild resources/js/pages/pos-upi-qr.js --bundle --outfile=public/js/pos-upi-qr.js --format=iife --legal-comments=none
import QRCode from 'qrcode';

document.querySelectorAll('[data-upi-qr]').forEach((element) => {
    const uri = element.getAttribute('data-upi-qr');
    if (!uri || element.tagName !== 'CANVAS') {
        return;
    }

    QRCode.toCanvas(element, uri, {
        width: 240,
        margin: 1,
        errorCorrectionLevel: 'M',
    }).catch(() => {
        // The persisted URI remains on the page; the canvas is display-only.
    });
});
