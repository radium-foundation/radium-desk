(function () {
    const OEM = 'morpho';

    function showBanner(message, tone) {
        const bannerId = 'radium-oem-handoff-banner';
        if (document.getElementById(bannerId)) {
            return;
        }
        const el = document.createElement('p');
        el.id = bannerId;
        el.setAttribute('role', 'status');
        el.textContent = message;
        const bg = tone === 'warn' ? '#fff3cd' : '#e7f3ff';
        const border = tone === 'warn' ? '#ffeeba' : '#b8daff';
        el.style.cssText = `margin:8px 0;padding:8px;background:${bg};border:1px solid ${border};font-size:13px;`;
        const anchor = document.getElementById('device-serial-number1');
        anchor?.parentElement?.prepend(el);
    }

    /**
     * DOM-only — does not call Selectize or dispatch blur (avoids rdonline.rdms.co.in AJAX).
     */
    function populateDomOnly(serial) {
        const base = document.getElementById('device-serial-number1');
        const visible = document.getElementById('device-serial-number1-selectized');
        if (!base || !(base instanceof HTMLInputElement)) {
            return false;
        }
        const normalized = String(serial).trim().toUpperCase();
        base.value = normalized;
        base.dispatchEvent(new Event('input', { bubbles: true }));
        if (visible instanceof HTMLInputElement) {
            visible.value = normalized;
        }
        return true;
    }

    chrome.runtime.sendMessage({ type: 'OEM_HANDOFF_CLAIM', oem: OEM }, (response) => {
        if (chrome.runtime.lastError || !response?.ok) {
            showBanner('Serial handoff was not available. Enter the serial manually.', 'warn');
            return;
        }
        const success = populateDomOnly(response.serial);
        if (!success) {
            showBanner('Serial could not be filled automatically. Enter it manually.', 'warn');
            return;
        }
        showBanner(
            'Serial prefilled (visual only). Focus the field if needed, then use Submit after review.',
            'info',
        );
    });
})();
