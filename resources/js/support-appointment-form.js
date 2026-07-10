const parseTime = (timeValue) => {
    const [hours, minutes] = timeValue.split(':').map(Number);

    return { hours, minutes };
};

const isSunday = (dateString, timezone) => {
    if (!dateString) {
        return false;
    }

    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        weekday: 'short',
    });

    return formatter.format(new Date(`${dateString}T12:00:00Z`)) === 'Sun';
};

const nextAvailableDate = (dateString, timezone) => {
    let candidate = dateString;

    for (let attempts = 0; attempts < 7 && isSunday(candidate, timezone); attempts += 1) {
        const date = new Date(`${candidate}T12:00:00Z`);
        date.setUTCDate(date.getUTCDate() + 1);
        candidate = date.toISOString().slice(0, 10);
    }

    return candidate;
};

const currentMinutes = (config) => {
    const now = new Date();
    const formatter = new Intl.DateTimeFormat('en-GB', {
        timeZone: config.timezone,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    const parts = formatter.formatToParts(now);
    const hour = Number(parts.find((part) => part.type === 'hour')?.value ?? 0);
    const minute = Number(parts.find((part) => part.type === 'minute')?.value ?? 0);

    return hour * 60 + minute;
};

const isSlotAvailable = (config, dateString, slotValue) => {
    if (!dateString || !slotValue) {
        return true;
    }

    if (isSunday(dateString, config.timezone)) {
        return false;
    }

    if (dateString !== config.today) {
        return true;
    }

    const cutoff = config.cutoffs[slotValue];

    if (!cutoff) {
        return true;
    }

    const { hours, minutes } = parseTime(cutoff);

    return currentMinutes(config) < hours * 60 + minutes;
};

const availableSlotsForDate = (config, dateString) => {
    return Object.keys(config.slots).filter((slotValue) => isSlotAvailable(config, dateString, slotValue));
};

const setDateFieldMessage = (dateInput, message) => {
    let feedback = dateInput.parentElement?.querySelector('.support-appointment-date-feedback');

    if (!feedback) {
        feedback = document.createElement('div');
        feedback.className = 'form-text text-danger support-appointment-date-feedback';
        dateInput.closest('.support-appointment-date-field')?.appendChild(feedback);
    }

    feedback.textContent = message ?? '';
    feedback.hidden = !message;
};

const syncTimeSlotOptions = (config, dateInput, slotSelect) => {
    const selectedDate = dateInput.value;
    const previouslySelected = slotSelect.value;
    const availableSlots = availableSlotsForDate(config, selectedDate);

    Array.from(slotSelect.options).forEach((option) => {
        if (option.value === '') {
            option.disabled = false;
            option.hidden = false;

            return;
        }

        const isAvailable = availableSlots.includes(option.value);
        option.disabled = !isAvailable;
        option.hidden = !isAvailable;
    });

    if (previouslySelected && !availableSlots.includes(previouslySelected)) {
        slotSelect.value = '';
    }
};

export const initSupportAppointmentForm = (root = document) => {
    const form = root.querySelector('[data-support-appointment-form]');

    if (!form || form.dataset.supportAppointmentBound === 'true') {
        return;
    }

    form.dataset.supportAppointmentBound = 'true';

    const configElement = form.querySelector('[data-support-appointment-availability]');

    if (!configElement) {
        return;
    }

    const config = JSON.parse(configElement.textContent || '{}');
    const dateInput = form.querySelector('#preferred_date');
    const slotSelect = form.querySelector('#preferred_time_slot');

    if (!dateInput || !slotSelect) {
        return;
    }

    const refreshAvailability = () => {
        let selectedDate = dateInput.value;

        if (selectedDate && isSunday(selectedDate, config.timezone)) {
            const adjustedDate = nextAvailableDate(selectedDate, config.timezone);
            dateInput.value = adjustedDate;
            selectedDate = adjustedDate;
            setDateFieldMessage(dateInput, config.sundayUnavailableMessage);
            dateInput.setCustomValidity('');
        } else if (
            selectedDate === config.today
            && availableSlotsForDate(config, selectedDate).length === 0
        ) {
            setDateFieldMessage(dateInput, config.sameDayUnavailableMessage);
            dateInput.setCustomValidity(config.sameDayUnavailableMessage);
        } else {
            setDateFieldMessage(dateInput, null);
            dateInput.setCustomValidity('');
        }

        syncTimeSlotOptions(config, dateInput, slotSelect);
    };

    dateInput.addEventListener('change', refreshAvailability);
    dateInput.addEventListener('input', refreshAvailability);

    refreshAvailability();
};
