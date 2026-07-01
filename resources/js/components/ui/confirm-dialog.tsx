import { AlertTriangle } from 'lucide-react';
import type { ReactNode } from 'react';
import { Modal } from './modal';

type ConfirmDialogProps = {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    title: string;
    message: string | ReactNode;
    confirmLabel?: string;
    processing?: boolean;
};

export function ConfirmDialog({ open, onClose, onConfirm, title, message, confirmLabel = 'Confirmar', processing }: ConfirmDialogProps) {
    return (
        <Modal open={open} onClose={onClose} title={title}>
            <div className="confirm-body">
                <div className="confirm-icon"><AlertTriangle size={28} /></div>
                <div className="confirm-message">{message}</div>
            </div>
            <div className="modal-footer">
                <button className="btn-secondary" onClick={onClose} disabled={processing}>Cancelar</button>
                <button className="danger-button" onClick={onConfirm} disabled={processing}>
                    {processing ? 'Procesando…' : confirmLabel}
                </button>
            </div>
        </Modal>
    );
}
