export const initSystemSettingsSliders = () => {
    document.querySelectorAll('[data-setting-slider-for]').forEach((slider) => {
        const inputId = slider.dataset.settingSliderFor;
        const input = document.getElementById(inputId);

        if (! input) {
            return;
        }

        const syncToInput = () => {
            input.value = slider.value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        };

        const syncToSlider = () => {
            slider.value = input.value;
        };

        slider.addEventListener('input', syncToInput);
        input.addEventListener('input', syncToSlider);
    });
};

export const initSystemSettingsTooltips = () => {
    if (typeof bootstrap === 'undefined') {
        return;
    }

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        bootstrap.Tooltip.getOrCreateInstance(el);
    });
};
