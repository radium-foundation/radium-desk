import { afterEach } from 'vitest';
import { resetSessionLifecycleForTests } from '../../resources/js/core/session-lifecycle';

afterEach(() => {
    resetSessionLifecycleForTests();
});