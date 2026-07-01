export function LoadingOverlay({ show, label }: { show: boolean; label: string }) {
    if (!show) return null;
    return <div className="loading-overlay" role="status"><span className="spinner" /><p>{label}</p></div>;
}
