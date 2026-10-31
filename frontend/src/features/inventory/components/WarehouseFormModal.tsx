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
import type { WarehouseFormData, WarehouseItem } from '../types/inventory.types';
import { AlertCircle, Loader2 } from 'lucide-react';

interface WarehouseFormModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  warehouse: WarehouseItem | null;
  onSuccess: (message: string) => void;
}

export function WarehouseFormModal({
  open,
  onOpenChange,
  warehouse,
  onSuccess,
}: WarehouseFormModalProps) {
  const isEditing = Boolean(warehouse);

  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [address, setAddress] = useState('');
  const [isDefault, setIsDefault] = useState(false);
  const [isActive, setIsActive] = useState(true);

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setError(null);
      if (warehouse) {
        setName(warehouse.name);
        setCode(warehouse.code);
        setAddress(warehouse.address || '');
        setIsDefault(warehouse.is_default);
        setIsActive(warehouse.is_active);
      } else {
        setName('');
        setCode('');
        setAddress('');
        setIsDefault(false);
        setIsActive(true);
      }
    }
  }, [open, warehouse]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!name.trim()) {
      setError('Warehouse name is required.');
      return;
    }
    if (!code.trim()) {
      setError('Warehouse code is required.');
      return;
    }

    setSubmitting(true);
    setError(null);

    const payload: WarehouseFormData = {
      name: name.trim(),
      code: code.trim().toUpperCase(),
      address: address.trim() || null,
      is_default: isDefault,
      is_active: isActive,
    };

    try {
      if (warehouse) {
        await inventoryApi.updateWarehouse(warehouse.id, payload);
        onSuccess(`Warehouse "${payload.name}" updated successfully.`);
      } else {
        await inventoryApi.createWarehouse(payload);
        onSuccess(`Warehouse "${payload.name}" created successfully.`);
      }
      onOpenChange(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to save warehouse.';
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div className="bg-background rounded-lg border shadow-lg w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden">
          <DialogHeader className="p-6 pb-4 border-b">
            <DialogTitle>{isEditing ? 'Edit Warehouse' : 'Add New Warehouse'}</DialogTitle>
            <DialogDescription>
              Configure storage location, identification code, and primary status.
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

              <div className="space-y-3">
                <div className="space-y-1.5">
                  <Label htmlFor="wh-name" className="text-xs font-semibold">
                    Warehouse Name <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="wh-name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="e.g. Kathmandu Central Depot"
                    required
                    className="h-9 text-xs"
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="wh-code" className="text-xs font-semibold">
                    Warehouse Code <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="wh-code"
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    placeholder="e.g. WH-KTM-01"
                    required
                    className="h-9 text-xs uppercase"
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="wh-address" className="text-xs font-semibold">
                    Physical Address
                  </Label>
                  <textarea
                    id="wh-address"
                    value={address}
                    onChange={(e) => setAddress(e.target.value)}
                    placeholder="Enter street, city, postal location..."
                    rows={3}
                    className="w-full p-2.5 rounded-md border text-xs bg-background text-foreground resize-none focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                  />
                </div>

                <div className="flex items-center gap-2 pt-2">
                  <input
                    type="checkbox"
                    id="wh-is-default"
                    checked={isDefault}
                    onChange={(e) => setIsDefault(e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                  />
                  <Label htmlFor="wh-is-default" className="text-xs font-medium cursor-pointer">
                    Set as default warehouse for orders & purchases
                  </Label>
                </div>

                <div className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    id="wh-is-active"
                    checked={isActive}
                    onChange={(e) => setIsActive(e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                  />
                  <Label htmlFor="wh-is-active" className="text-xs font-medium cursor-pointer">
                    Warehouse is Active and operational
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
                {isEditing ? 'Save Changes' : 'Create Warehouse'}
              </Button>
            </DialogFooter>
          </form>
        </div>
      </div>
    </Dialog>
  );
}
