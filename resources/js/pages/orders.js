import { initOrderWorkspace } from '../order-workspace';
import { showAppToast } from '../core/toast';

document.addEventListener('DOMContentLoaded', () => {
    initOrderWorkspace({ showToast: showAppToast });
});
