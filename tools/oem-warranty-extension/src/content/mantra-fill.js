(function () {
    const OEM = 'mantra';

    function showFailure(reason) {
        const bannerId = 'radium-oem-handoff-banner';
        if (document.getElementById(bannerId)) {
            return;
        }
        const el = document.createElement('p');
        el.id = bannerId;
        el.setAttribute('role', 'status');
        el.textContent = 'Serial could not be filled automatically. Enter it manually on this page.';
        el.style.cssText = 'margin:8px 0;padding:8px;background:#fff3cd;border:1px solid #ffeeba;font-size:13px;';
        const anchor = document.getElementById('txtSerialNo');
        anchor?.parentElement?.prepend(el);
        void reason;
    }

    function populate(serial) {
        const field = document.getElementById('txtSerialNo');
        if (!field || !(field instanceof HTMLInputElement)) {
            return false;
        }
        const normalized = String(serial).trim().toUpperCase();
        field.value = normalized;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    chrome.runtime.sendMessage({ type: 'OEM_HANDOFF_CLAIM', oem: OEM }, (response) => {
        if (chrome.runtime.lastError || !response?.ok) {
            showFailure('no-handoff');
            return;
        }
        const success = populate(response.serial);
        if (!success) {
            showFailure('populate-failed');
        }
    });
})();
