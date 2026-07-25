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
    const manualConfigSection = root.querySelector('[data-performance-manual-config]');
    const profileCards = root.querySelectorAll('.system-settings-profile-card');

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

            const hiddenSibling = input.closest('[data-setting-row]')?.querySelector('input[type="hidden"][name="' + input.name + '"]');

            if (hiddenSibling) {
                return;
            }

            input.readOnly = ! editable;
            input.closest('[data-setting-row]')?.classList.toggle('system-settings-row--readonly', ! editable);
        });
    };

    const setManualConfigVisible = (visible) => {
        if (! manualConfigSection) {
            return;
        }

        manualConfigSection.hidden = ! visible;
    };

    const syncProfileCardSelection = () => {
        const profile = selectedProfile();

        profileCards.forEach((card) => {
            const input = card.querySelector('[data-performance-profile-option]');
            card.classList.toggle('system-settings-profile-card--selected', input?.checked ?? false);
        });
    };

    const syncFromProfile = () => {
        const profile = selectedProfile();
        const isManual = profile === 'manual';

        setManualConfigVisible(isManual);
        setPollingEditable(isManual);
        syncProfileCardSelection();

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
                setManualConfigVisible(true);
                setPollingEditable(true);
                syncProfileCardSelection();
            }
        });
    });

    syncFromProfile();
};
