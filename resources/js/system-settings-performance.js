export const initSystemSettingsPerformance = () => {
    const root = document.querySelector('[data-performance-settings]');

    if (! root) {
        return;
    }

    let presets = {};

    try {
        presets = JSON.parse(root.dataset.profilePresets ?? '{}');
    } catch {
        presets = {};
    }

    const profileInputs = root.querySelectorAll('[data-performance-profile-option]');
    const pollingInputs = root.querySelectorAll('[data-performance-polling-input]');

    const selectedProfile = () => {
        const checked = root.querySelector('[data-performance-profile-option]:checked');

        return checked?.value ?? 'balanced';
    };

    const applyPreset = (profileKey) => {
        const values = presets[profileKey] ?? {};

        pollingInputs.forEach((input) => {
            const key = input.dataset.settingKey;

            if (! key || input.disabled && profileKey !== 'manual') {
                return;
            }

            if (Object.prototype.hasOwnProperty.call(values, key)) {
                input.value = String(values[key]);
            }
        });
    };

    const setPollingEditable = (editable) => {
        pollingInputs.forEach((input) => {
            if (input.type === 'hidden') {
                return;
            }

            const hiddenSibling = input.closest('.border')?.querySelector('input[type="hidden"][name="' + input.name + '"]');

            if (hiddenSibling) {
                return;
            }

            input.readOnly = ! editable;
            input.classList.toggle('bg-light', ! editable);
        });
    };

    const syncFromProfile = () => {
        const profile = selectedProfile();
        const isManual = profile === 'manual';

        setPollingEditable(isManual);

        if (! isManual) {
            applyPreset(profile);
        }
    };

    profileInputs.forEach((input) => {
        input.addEventListener('change', syncFromProfile);
    });

    pollingInputs.forEach((input) => {
        input.addEventListener('input', () => {
            if (selectedProfile() === 'manual') {
                return;
            }

            const manualInput = root.querySelector('[data-performance-profile-option][value="manual"]');

            if (manualInput) {
                manualInput.checked = true;
                setPollingEditable(true);
            }
        });
    });

    syncFromProfile();
};
