/**
 * Lightweight shared flag so global HTTP pollers (notifications) can slow
 * down while Ably/Reverb is delivering events on the dashboard.
 */
let realtimeTransportConnected = false;

export const setRealtimeTransportConnected = (connected) => {
    realtimeTransportConnected = Boolean(connected);
};

export const isRealtimeTransportConnected = () => realtimeTransportConnected;

export const resetRealtimeTransportStatusForTests = () => {
    realtimeTransportConnected = false;
};
