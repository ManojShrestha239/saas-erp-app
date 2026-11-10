import { useState } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { useCan } from '@/features/auth/hooks/useCan';
import {
  Scale,
  TrendingUp,
  FileSpreadsheet,
  Clock,
  Package,
  History,
} from 'lucide-react';
import { TrialBalanceViewer } from '../components/TrialBalanceViewer';
import { ProfitAndLossViewer } from '../components/ProfitAndLossViewer';
import { BalanceSheetViewer } from '../components/BalanceSheetViewer';
import { ArApAgingViewer } from '../components/ArApAgingViewer';
import { StockValuationViewer } from '../components/StockValuationViewer';
import { StockMovementViewer } from '../components/StockMovementViewer';

type ReportTab =
  | 'trial-balance'
  | 'pnl'
  | 'balance-sheet'
  | 'ar-ap-aging'
  | 'stock-valuation'
  | 'stock-movements';

export default function ReportsIndex() {
  const canFinancial = useCan('reports.financial');
  const canStock = useCan('reports.stock');

  const defaultTab: ReportTab = canFinancial ? 'trial-balance' : canStock ? 'stock-valuation' : 'trial-balance';
  const [activeTab, setActiveTab] = useState<ReportTab>(defaultTab);

  const tabs: Array<{
    id: ReportTab;
    label: string;
    icon: React.ComponentType<{ className?: string }>;
    visible: boolean;
    category: 'financial' | 'stock';
  }> = [
    {
      id: 'trial-balance',
      label: 'Trial Balance',
      icon: Scale,
      visible: canFinancial,
      category: 'financial',
    },
    {
      id: 'pnl',
      label: 'Profit & Loss',
      icon: TrendingUp,
      visible: canFinancial,
      category: 'financial',
    },
    {
      id: 'balance-sheet',
      label: 'Balance Sheet',
      icon: FileSpreadsheet,
      visible: canFinancial,
      category: 'financial',
    },
    {
      id: 'ar-ap-aging',
      label: 'AR & AP Aging',
      icon: Clock,
      visible: canFinancial,
      category: 'financial',
    },
    {
      id: 'stock-valuation',
      label: 'Stock Valuation',
      icon: Package,
      visible: canStock,
      category: 'stock',
    },
    {
      id: 'stock-movements',
      label: 'Stock Movements',
      icon: History,
      visible: canStock,
      category: 'stock',
    },
  ];

  const visibleTabs = tabs.filter((t) => t.visible);

  return (
    <AppLayout
      title="Financial & Inventory Reports"
      breadcrumbs={[{ label: 'Reports', href: '/reports' }]}
      activeNav="reports"
    >
      <div className="space-y-6">
        {/* Navigation Tabs Bar */}
        <div className="border-b border-border bg-card/60 backdrop-blur-sm -mt-6 -mx-6 px-6 pt-3">
          <nav className="flex space-x-2 overflow-x-auto pb-2" aria-label="Reports Navigation Tabs">
            {visibleTabs.map((tab) => {
              const Icon = tab.icon;
              const isActive = activeTab === tab.id;
              return (
                <button
                  key={tab.id}
                  onClick={() => setActiveTab(tab.id)}
                  className={`flex items-center gap-2 px-3.5 py-2 text-xs font-semibold rounded-md transition-all whitespace-nowrap ${
                    isActive
                      ? 'bg-primary text-primary-foreground shadow-sm'
                      : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                  }`}
                >
                  <Icon className="h-4 w-4" />
                  <span>{tab.label}</span>
                </button>
              );
            })}
          </nav>
        </div>

        {/* Tab Content Rendering */}
        <div className="mt-4">
          {activeTab === 'trial-balance' && canFinancial && <TrialBalanceViewer />}
          {activeTab === 'pnl' && canFinancial && <ProfitAndLossViewer />}
          {activeTab === 'balance-sheet' && canFinancial && <BalanceSheetViewer />}
          {activeTab === 'ar-ap-aging' && canFinancial && <ArApAgingViewer />}
          {activeTab === 'stock-valuation' && canStock && <StockValuationViewer />}
          {activeTab === 'stock-movements' && canStock && <StockMovementViewer />}

          {visibleTabs.length === 0 && (
            <div className="text-center py-16 bg-card rounded-lg border border-border">
              <p className="text-base font-semibold text-foreground">No reports available</p>
              <p className="text-sm text-muted-foreground mt-1">
                You do not have permission to view financial or stock reports. Please contact your administrator.
              </p>
            </div>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
