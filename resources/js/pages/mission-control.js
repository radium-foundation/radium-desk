import { initOperationsDashboard } from '../operations-dashboard';
import { initAutomationHealth } from '../automation-health';

document.addEventListener('DOMContentLoaded', () => {
    void initOperationsDashboard();
    initAutomationHealth();
});
