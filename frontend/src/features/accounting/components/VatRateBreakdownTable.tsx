import React from 'react';
import type { VatRateBreakdown } from '../types/accounting.types';

interface VatRateBreakdownTableProps {
  rates: VatRateBreakdown[];
  currency?: string;
}

export const VatRateBreakdownTable: React.FC<VatRateBreakdownTableProps> = ({
  rates,
  currency = 'USD',
}) => {
  const formatMoney = (cents: number) => {
    return (cents / 100).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  };

  const totalSalesTaxable = rates.reduce((sum, r) => sum + r.sales_taxable_cents, 0);
  const totalOutputVat = rates.reduce((sum, r) => sum + r.output_vat_cents, 0);
  const totalPurchasesTaxable = rates.reduce((sum, r) => sum + r.purchases_taxable_cents, 0);
  const totalInputVat = rates.reduce((sum, r) => sum + r.input_vat_cents, 0);
  const totalNetVat = rates.reduce((sum, r) => sum + r.net_vat_cents, 0);

  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-card">
      <table className="w-full text-left text-sm">
        <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
          <tr>
            <th className="px-4 py-3 font-semibold">Tax Rate</th>
            <th className="px-4 py-3 text-right font-semibold">Sales Taxable ({currency})</th>
            <th className="px-4 py-3 text-right font-semibold">Output VAT ({currency})</th>
            <th className="px-4 py-3 text-right font-semibold">Purchases Taxable ({currency})</th>
            <th className="px-4 py-3 text-right font-semibold">Input VAT ({currency})</th>
            <th className="px-4 py-3 text-right font-semibold">Net VAT ({currency})</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {rates.length === 0 ? (
            <tr>
              <td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">
                No taxable activity found in this period.
              </td>
            </tr>
          ) : (
            rates.map((rate) => (
              <tr key={rate.tax_rate_percent} className="hover:bg-muted/30">
                <td className="px-4 py-3 font-semibold text-foreground">
                  {rate.tax_rate_percent}%
                </td>
                <td className="px-4 py-3 text-right tabular-nums text-foreground">
                  {formatMoney(rate.sales_taxable_cents)}
                </td>
                <td className="px-4 py-3 text-right font-medium tabular-nums text-foreground">
                  {formatMoney(rate.output_vat_cents)}
                </td>
                <td className="px-4 py-3 text-right tabular-nums text-foreground">
                  {formatMoney(rate.purchases_taxable_cents)}
                </td>
                <td className="px-4 py-3 text-right font-medium tabular-nums text-foreground">
                  {formatMoney(rate.input_vat_cents)}
                </td>
                <td
                  className={`px-4 py-3 text-right font-bold tabular-nums ${
                    rate.net_vat_cents >= 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'
                  }`}
                >
                  {formatMoney(rate.net_vat_cents)}
                </td>
              </tr>
            ))
          )}
        </tbody>
        {rates.length > 0 && (
          <tfoot className="border-t-2 border-border bg-muted/60 font-semibold text-foreground">
            <tr>
              <td className="px-4 py-3">Total</td>
              <td className="px-4 py-3 text-right tabular-nums">{formatMoney(totalSalesTaxable)}</td>
              <td className="px-4 py-3 text-right tabular-nums text-foreground">{formatMoney(totalOutputVat)}</td>
              <td className="px-4 py-3 text-right tabular-nums">{formatMoney(totalPurchasesTaxable)}</td>
              <td className="px-4 py-3 text-right tabular-nums text-foreground">{formatMoney(totalInputVat)}</td>
              <td
                className={`px-4 py-3 text-right font-bold tabular-nums ${
                  totalNetVat >= 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'
                }`}
              >
                {formatMoney(totalNetVat)}
              </td>
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  );
};
