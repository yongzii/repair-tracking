const readMeta = (name, fallback) => {
    if (typeof document === 'undefined') {
        return fallback;
    }

    return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || fallback;
};

export const appConfig = {
    name: readMeta('app-name', 'Repair Service Management System'),
    tagline: readMeta('app-tagline', 'Electronics Repair Service Management'),
    adminLoginHint: readMeta('admin-login-hint', 'admin@example.com'),
};
