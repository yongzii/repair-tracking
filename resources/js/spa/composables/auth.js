import { reactive } from 'vue';

const state = reactive({
    user: null,
    initialized: false,
    loading: false,
});

export const authState = state;

export const platformRoles = ['platform_admin'];
export const adminRoles = ['shop_admin', 'admin'];

const roleAliases = {
    admin: adminRoles,
    shop_admin: adminRoles,
    platform_admin: platformRoles,
};

const expandRoles = (roles) => {
    return [...new Set(roles.flatMap((role) => roleAliases[role] || [role]))];
};

const applyCsrfToken = (token) => {
    if (!token) return;

    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;

    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
        meta.setAttribute('content', token);
    }
};

export const setAuthenticatedUser = (user, token = null) => {
    applyCsrfToken(token);
    state.user = user ?? null;
    state.initialized = true;
};

export const isCsrfMismatch = (error) => {
    const status = error?.response?.status;
    const message = String(error?.response?.data?.message || '').toLowerCase();

    return status === 419 || message.includes('csrf token mismatch');
};

export const refreshCsrfToken = async () => {
    try {
        const { data } = await window.axios.get('/api/csrf-token');
        applyCsrfToken(data?.csrf_token);
    } catch {
        // no-op; login flow already returns csrf token in normal path
    }
};

export const hydrateAuth = async () => {
    if (state.loading) {
        return;
    }

    state.loading = true;
    try {
        const { data } = await window.axios.get('/api/auth/user');
        state.user = data.user ?? null;
    } catch {
        state.user = null;
    } finally {
        state.initialized = true;
        state.loading = false;
    }
};

export const login = async (payload) => {
    await refreshCsrfToken();

    try {
        const { data } = await window.axios.post('/api/auth/login', payload);
        applyCsrfToken(data?.csrf_token);
        await refreshCsrfToken();
        state.user = data.user ?? null;
        state.initialized = true;
        return data;
    } catch (error) {
        if (!isCsrfMismatch(error)) {
            throw error;
        }

        await refreshCsrfToken();

        const { data } = await window.axios.post('/api/auth/login', payload);
        applyCsrfToken(data?.csrf_token);
        await refreshCsrfToken();
        state.user = data.user ?? null;
        state.initialized = true;
        return data;
    }
};

export const logout = async () => {
    await refreshCsrfToken();
    await window.axios.post('/api/auth/logout');
    state.user = null;
    state.initialized = true;
};

export const hasAnyRole = (roles) => {
    if (!state.user) {
        return false;
    }

    return expandRoles(roles).includes(state.user.role);
};

export const ensureRoles = async (roles) => {
    if (!state.initialized) {
        await hydrateAuth();
    }

    if (!state.user) {
        return { ok: false, reason: 'unauthenticated' };
    }

    if (!expandRoles(roles).includes(state.user.role)) {
        return { ok: false, reason: 'forbidden' };
    }

    return { ok: true };
};

export const ensureAdmin = async () => ensureRoles(['admin']);
export const ensurePlatformAdmin = async () => ensureRoles(['platform_admin']);
