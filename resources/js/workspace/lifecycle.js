const LIFECYCLE_HOOKS = [
    'beforeOpen',
    'afterOpen',
    'beforeSubmit',
    'afterSubmit',
    'afterSuccess',
    'afterClose',
];

export const createLifecycleRunner = (hooks = {}) => {
    const run = async (name, ...args) => {
        if (!LIFECYCLE_HOOKS.includes(name)) {
            return true;
        }

        const hook = hooks[name];

        if (typeof hook !== 'function') {
            return true;
        }

        try {
            const result = await hook(...args);

            return result !== false;
        } catch (error) {
            console.error(`Workspace lifecycle hook "${name}" failed:`, error);

            return false;
        }
    };

    return {
        run,
    };
};
