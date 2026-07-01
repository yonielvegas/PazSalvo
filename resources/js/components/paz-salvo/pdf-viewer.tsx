import { Download, Printer, RotateCcw } from 'lucide-react';
import type { GeneratedDocument } from '@/types/paz-salvo';

export function PdfViewer({ document }: { document: GeneratedDocument }) {
    return (
        <section className="pdf-section">
            <div className="pdf-heading"><div><span>Documento generado</span><h2>{document.folio}</h2></div><div className="pdf-actions"><a href={document.pdf_url} target="_blank" rel="noreferrer"><Printer /> Imprimir</a><a href={document.download_url}><Download /> Descargar</a><a href="/"><RotateCcw /> Nueva búsqueda</a></div></div>
            <iframe src={document.pdf_url} title={`Paz y salvo ${document.folio}`} />
        </section>
    );
}
