export type ProductType = 'stockable' | 'service' | 'consumable';

export type ProductRow = {
    id: string;
    sku: string;
    name: string;
    type: ProductType;
    sale_price: number;
    unit: string;
    category: string | null;
    barcode: string | null;
    archived: boolean;
    image_url: string | null;
};

export type BarcodeRow = {
    id: string;
    code: string;
    symbology: 'ean13' | 'upca' | 'code128' | 'qr' | 'internal';
    is_primary: boolean;
};

export type ProductDetail = {
    id: string;
    sku: string;
    name: string;
    description: string | null;
    type: ProductType;
    category_id: string | null;
    unit_id: string;
    tax_rate_id: string;
    sale_price: number;
    standard_cost: number;
    version: number;
    archived: boolean;
    image_url: string | null;
    barcodes: BarcodeRow[];
};

export type Option = { id: string; name: string };

export type UnitOption = { id: string; code: string; name: string };

export type TaxRateOption = {
    id: string;
    name: string;
    rate_basis_points: number;
    is_default: boolean;
};

export type Paginated<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    total?: number;
};

/** Article choisi dans un sélecteur (vente, achat, stock), en ligne ou hors ligne. */
export type PickedProduct = {
    id: string;
    sku: string;
    name: string;
    unit: string;
    decimals: boolean;
    standard_cost: number | null;
    type?: string;
    sale_price?: number;
    tax_rate_basis_points?: number;
    available?: string | null;
};
