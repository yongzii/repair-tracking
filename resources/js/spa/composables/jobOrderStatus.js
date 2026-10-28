const jobStatusClasses = {
    RECEIVED: 'bg-blue-50 text-blue-700 ring-blue-200',
    DIAGNOSING: 'bg-violet-50 text-violet-700 ring-violet-200',
    WAITING_PARTS: 'bg-amber-50 text-amber-700 ring-amber-200',
    IN_PROGRESS: 'bg-cyan-50 text-cyan-700 ring-cyan-200',
    READY_FOR_PICKUP: 'bg-sky-50 text-sky-700 ring-sky-200',
    PICKED_UP: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    RTO: 'bg-orange-50 text-orange-700 ring-orange-200',
    RTO_RELEASED: 'bg-slate-100 text-slate-700 ring-slate-300',
};

export const jobOrderStatusClass = (status) => {
    const normalizedStatus = String(status || '').toUpperCase();
    return jobStatusClasses[normalizedStatus] || 'bg-slate-50 text-slate-700 ring-slate-200';
};

export const paymentStatusClass = (status) => {
    const normalizedStatus = String(status || 'PENDING').toUpperCase();

    return {
        PENDING: 'bg-rose-50 text-rose-700 ring-rose-200',
        PARTIAL: 'bg-amber-50 text-amber-700 ring-amber-200',
        PAID: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    }[normalizedStatus] || 'bg-slate-50 text-slate-700 ring-slate-200';
};
