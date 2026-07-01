import { useEffect, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';

type ModalProps = {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: ReactNode;
};

export function Modal({ open, onClose, title, description, children }: ModalProps) {
    useEffect(() => {
        if (!open) return;
        const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', handler);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', handler);
            document.body.style.overflow = '';
        };
    }, [open, onClose]);

    if (!open) return null;

    return createPortal(
        <div className="modal-backdrop" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="inner-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
                <div className="modal-header">
                    <div>
                        <h2 id="modal-title">{title}</h2>
                        {description && <p className="modal-desc">{description}</p>}
                    </div>
                    <button className="close-button" onClick={onClose} aria-label="Cerrar"><X size={20} /></button>
                </div>
                {children}
            </div>
        </div>,
        document.body
    );
}
