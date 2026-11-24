import axios from 'axios';
import { handleHttpAuthStatus } from './core/session-lifecycle';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        const response = error?.response;

        if (response) {
            const authResult = await handleHttpAuthStatus(response, { retryAfterCsrf: true });

            if (authResult.action === 'redirect' || authResult.action === 'reload') {
                return Promise.reject(error);
            }
        }

        return Promise.reject(error);
    },
);
