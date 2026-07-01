export type DebtItem = { period: string | null; amount: number; document_type: string | null; status: string | null };

export type QueryResult = {
    query_token: string;
    status: 'debt_free' | 'has_debt' | 'not_found' | 'error';
    client_number: string;
    holder_name: string | null;
    address: string | null;
    city: string | null;
    rate: string | null;
    balances: { total_balance: number; expired_balance: number; non_expired_balance: number };
    debts: DebtItem[];
};

export type GeneratedDocument = { id: number; folio: string; pdf_url: string; download_url: string };

export type PageProps = {
    flash: { result: QueryResult | null; document: GeneratedDocument | null; message: string | null };
    errors: Record<string, string>;
};
