import { reactive, readonly } from 'vue';

const state = reactive({
    path: window.location.pathname,
    search: window.location.search,
    fullPath: `${window.location.pathname}${window.location.search}`,
});

const syncFromLocation = () => {
    state.path = window.location.pathname;
    state.search = window.location.search;
    state.fullPath = `${window.location.pathname}${window.location.search}`;
};

window.addEventListener('popstate', syncFromLocation);

export const navigate = (to, options = {}) => {
    const { replace = false } = options;
    const url = new URL(to, window.location.origin);

    if (url.origin !== window.location.origin) {
        window.location.href = to;
        return;
    }

    const nextPath = `${url.pathname}${url.search}`;

    if (replace) {
        window.history.replaceState({}, '', nextPath);
    } else {
        window.history.pushState({}, '', nextPath);
    }

    syncFromLocation();
    window.scrollTo({ top: 0, behavior: 'auto' });
};

export const useNavigation = () => {
    return {
        location: readonly(state),
        navigate,
    };
};
