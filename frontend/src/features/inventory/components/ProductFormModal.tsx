import React, { useState, useEffect } from 'react';
import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { inventoryApi } from '../api/inventoryApi';
import type {
  CategoryItem,
  ProductFormData,
  ProductItem,
  TaxCategory,
  UnitOfMeasureItem,
} from '../types/inventory.types';
import { AlertCircle, Loader2 } from 'lucide-react';

interface ProductFormModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  product: ProductItem | null;
  categories: CategoryItem[];
  unitsOfMeasure: UnitOfMeasureItem[];
  onSuccess: (message: string) => void;
}

export function ProductFormModal({
  open,
  onOpenChange,
  product,
  categories,
  unitsOfMeasure,
  onSuccess,
}: ProductFormModalProps) {
  const isEditing = Boolean(product);

  const [name, setName] = useState('');
  const [sku, setSku] = useState('');
  const [barcode, setBarcode] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [unitOfMeasureId, setUnitOfMeasureId] = useState('');
  const [costPrice, setCostPrice] = useState('0.00');
  const [sellingPrice, setSellingPrice] = useState('0.00');
  const [taxCategory, setTaxCategory] = useState<TaxCategory>('standard');
  const [lowStockThreshold, setLowStockThreshold] = useState('0');
  const [description, setDescription] = useState('');
  const [isActive, setIsActive] = useState(true);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setError(null);
      if (product) {
        setName(product.name);
        setSku(product.sku);
        setBarcode(product.barcode || '');
        setCategoryId(product.category_id || '');
        setUnitOfMeasureId(product.unit_of_measure_id || '');
        setCostPrice((product.cost_price_cents / 100).toFixed(2));
        setSellingPrice((product.selling_price_cents / 100).toFixed(2));
        const tc: TaxCategory =
          product.tax_category === 'zero_rated' || product.tax_category === 'exempt'
            ? product.tax_category
            : 'standard';
        setTaxCategory(tc);
        setLowStockThreshold(String(product.low_stock_threshold ?? 0));
        setDescription(product.description || '');
        setIsActive(product.is_active);
      } else {
        setName('');
        setSku('');
        setBarcode('');
        setCategoryId('');
        setUnitOfMeasureId('');
        setCostPrice('0.00');
        setSellingPrice('0.00');
        setTaxCategory('standard');
        setLowStockThreshold('0');
        setDescription('');
        setIsActive(true);
      }
    }
  }, [open, product]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!name.trim()) {
      setError('Product name is required.');
      return;
    }
    if (!sku.trim()) {
      setError('SKU is required.');
      return;
    }

    const parsedCost = parseFloat(costPrice);
    if (isNaN(parsedCost) || parsedCost < 0) {
      setError('Cost price must be a non-negative number.');
      return;
    }

    const parsedSelling = parseFloat(sellingPrice);
    if (isNaN(parsedSelling) || parsedSelling < 0) {
      setError('Selling price must be a non-negative number.');
      return;
    }

    const parsedThreshold = parseInt(lowStockThreshold, 10);
    if (isNaN(parsedThreshold) || parsedThreshold < 0) {
      setError('Low stock threshold must be a non-negative integer.');
      return;
    }

    setSubmitting(true);
    setError(null);

    const payload: ProductFormData = {
      name: name.trim(),
      sku: sku.trim(),
      barcode: barcode.trim() || null,
      category_id: categoryId || null,
      unit_of_measure_id: unitOfMeasureId || null,
      cost_price_cents: Math.round(parsedCost * 100),
      selling_price_cents: Math.round(parsedSelling * 100),
      tax_category: taxCategory,
      low_stock_threshold: parsedThreshold,
      description: description.trim() || null,
      is_active: isActive,
    };

    try {
      if (product) {
        await inventoryApi.updateProduct(product.id, payload);
        onSuccess(`Product "${payload.name}" updated successfully.`);
      } else {
        await inventoryApi.createProduct(payload);
        onSuccess(`Product "${payload.name}" created successfully.`);
      }
      onOpenChange(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to save product.';
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div className="bg-background rounded-lg border shadow-lg w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
          <DialogHeader className="p-6 pb-4 border-b">
            <DialogTitle>{isEditing ? 'Edit Product' : 'Add New Product'}</DialogTitle>
            <DialogDescription>
              {isEditing
                ? 'Update catalog and pricing details for this product.'
                : 'Enter catalog specifications, unit of measure, and default pricing.'}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="flex flex-col flex-1 overflow-hidden">
            <div className="p-6 overflow-y-auto space-y-4">
              {error && (
                <Alert variant="destructive" className="py-2.5">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription className="text-xs">{error}</AlertDescription>
                </Alert>
              )}

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-1.5 md:col-span-2">
                  <Label htmlFor="product-name" className="text-xs font-semibold">
                    Product Name <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="product-name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="e.g. Standard Steel Rod 12mm"
                    required
                    className="h-9 text-xs"
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="product-sku" className="text-xs font-semibold">
                    SKU Code <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="product-sku"
                    value={sku}
                    onChange={(e) => setSku(e.target.value)}
                    placeholder="e.g. ROD-STL-12"
                    required
                    className="h-9 text-xs uppercase"
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="product-barcode" className="text-xs font-semibold">
                    Barcode / EAN
                  </Label>
                  <Input
                    id="product-barcode"
                    value={barcode}
                    onChange={(e) => setBarcode(e.target.value)}
                    placeholder="e.g. 8901234567890"
                    className="h-9 text-xs font-mono"
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="product-category" className="text-xs font-semibold">
                    Category
                  </Label>
                  <select
                    id="product-category"
                    value={categoryId}
                    onChange={(e) => setCategoryId(e.target.value)}
                    className="w-full h-9 px-3 rounded-md border text-xs bg-background text-foreground"
                  >
                    <option value="">No Category</option>
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="product-uom" className="text-xs font-semibold">
                    Unit of Measure
                  </Label>
                  <select
                    id="product-uom"
                    value={unitOfMeasureId}
                    onChange={(e) => setUnitOfMeasureId(e.target.value)}
                    className="w-full h-9 px-3 rounded-md border text-xs bg-background text-foreground"
                  >
                    <option value="">Select UoM</option>
                    {unitsOfMeasure.map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name} ({u.symbol})
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="product-cost-price" className="text-xs font-semibold">
                    Cost Price (NPR) <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="product-cost-price"
                    type="number"
                    step="0.01"
                    min="0"
                    value={costPrice}
                    onChange={(e) => setCostPrice(e.target.value)}
                    className="h-9 text-xs"
                    required
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="product-selling-price" className="text-xs font-semibold">
                    Selling Price (NPR) <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="product-selling-price"
                    type="number"
                    step="0.01"
                    min="0"
                    value={sellingPrice}
                    onChange={(e) => setSellingPrice(e.target.value)}
                    className="h-9 text-xs"
                    required
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="product-tax-category" className="text-xs font-semibold">
                    Tax Category <span className="text-destructive">*</span>
                  </Label>
                  <select
                    id="product-tax-category"
                    value={taxCategory}
                    onChange={(e) => setTaxCategory(e.target.value as TaxCategory)}
                    className="w-full h-9 px-3 rounded-md border text-xs bg-background text-foreground"
                  >
                    <option value="standard">Standard Rate (13% VAT)</option>
                    <option value="zero_rated">Zero Rated (0%)</option>
                    <option value="exempt">Exempt</option>
                  </select>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="product-low-stock" className="text-xs font-semibold">
                    Low Stock Threshold
                  </Label>
                  <Input
                    id="product-low-stock"
                    type="number"
                    min="0"
                    step="1"
                    value={lowStockThreshold}
                    onChange={(e) => setLowStockThreshold(e.target.value)}
                    className="h-9 text-xs"
                  />
                </div>

                <div className="space-y-1.5 md:col-span-2">
                  <Label htmlFor="product-description" className="text-xs font-semibold">
                    Description / Notes
                  </Label>
                  <textarea
                    id="product-description"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder="Enter any specifications, grade, manufacturer notes..."
                    rows={3}
                    className="w-full p-2.5 rounded-md border text-xs bg-background text-foreground resize-none focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                  />
                </div>

                <div className="flex items-center gap-2 pt-2 md:col-span-2">
                  <input
                    type="checkbox"
                    id="product-is-active"
                    checked={isActive}
                    onChange={(e) => setIsActive(e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                  />
                  <Label htmlFor="product-is-active" className="text-xs font-medium cursor-pointer">
                    Product is Active and available for sales/purchases
                  </Label>
                </div>
              </div>
            </div>

            <DialogFooter className="p-4 border-t bg-muted/20 flex items-center justify-end gap-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => onOpenChange(false)}
                disabled={submitting}
              >
                Cancel
              </Button>
              <Button type="submit" size="sm" disabled={submitting}>
                {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {isEditing ? 'Save Changes' : 'Create Product'}
              </Button>
            </DialogFooter>
          </form>
        </div>
      </div>
    </Dialog>
  );
}
