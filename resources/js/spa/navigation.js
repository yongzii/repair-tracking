import { reactive, readonly } from 'vue';

const state = reactive({
    path: window.location.pathname,
    search: window.location.search,
    fullPath: `${window.location.pathname}${window.location.search}`,
});
let adminSidebarScrollTop = 0;

export const captureAdminSidebarScroll = () => {
    const sidebarNav = document.querySelector('[data-admin-sidebar-nav]');
    if (sidebarNav) {
        adminSidebarScrollTop = sidebarNav.scrollTop;
    }
};

export const consumeAdminSidebarScroll = () => {
    const scrollTop = adminSidebarScrollTop;
    adminSidebarScrollTop = 0;
    return scrollTop;
};

const syncFromLocation = () => {
    state.path = window.location.pathname;
    state.search = window.location.search;
    state.fullPath = `${window.location.pathname}${window.location.search}`;
};

const handlePopstate = () => {
    captureAdminSidebarScroll();
    syncFromLocation();
};

window.addEventListener('popstate', handlePopstate);

export const navigate = (to, options = {}) => {
    const { replace = false } = options;
    const url = new URL(to, window.location.origin);

    if (url.origin !== window.location.origin) {
        window.location.href = to;
        return;
    }

    const nextPath = `${url.pathname}${url.search}`;
    if (!url.pathname.startsWith('/admin')) {
        adminSidebarScrollTop = 0;
    }

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
