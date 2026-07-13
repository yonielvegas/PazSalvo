export type DebtItem = { period: string | null; amount: number; document_type: string | null; status: string | null };

export type QueryResult = {
    query_token: string;
    status: 'debt_free' | 'has_debt' | 'not_found' | 'error' | 'debt_free_aseo_with_energy_debt' | 'has_aseo_debt' | 'not_san_miguelito';
    client_number: string;
    holder_name: string | null;
    address: string | null;
    city: string | null;
    rate: string | null;
    balances: { total_balance: number; expired_balance: number; non_expired_balance: number; aseo_balance: number; energy_balance: number; other_balance: number };
    debts: DebtItem[];
    can_generate_paz_salvo: boolean;
    requires_energy_warning: boolean;
    san_miguelito_validation?: { is_san_miguelito: false; received_city: string | null; message: string };
};

export type GeneratedDocument = { id: number; folio: string; pdf_url: string; download_url: string };

export type PageProps = {
    flash: { result: QueryResult | null; document: GeneratedDocument | null; message: string | null; error: string | null };
    errors: Record<string, string>;
};
