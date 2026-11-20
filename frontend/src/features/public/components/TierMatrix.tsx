import { Check, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/shared/utils/cn';

interface PricingTier {
  id: string;
  name: string;
  badge?: string;
  highlighted?: boolean;
  priceMonthly: string;
  billedNote: string;
  description: string;
  quotas: {
    warehouses: string;
    users: string;
    documentsPerMonth: string;
    databaseIsolation: string;
    rtoSla: string;
  };
  features: string[];
  ctaLabel: string;
}

const TIERS: PricingTier[] = [
  {
    id: 'operations',
    name: 'Regional Operations',
    highlighted: false,
    priceMonthly: '$490',
    billedNote: 'Billed monthly per tenant organization',
    description: 'Designed for single-warehouse distributors establishing double-entry GL control.',
    quotas: {
      warehouses: '1 Physical Warehouse',
      users: 'Up to 5 Authorized Seats',
      documentsPerMonth: '5,000 Invoices / Month',
      databaseIsolation: 'Dedicated PostgreSQL DB',
      rtoSla: '4-hour RTO Disaster Recovery',
    },
    features: [
      'Multi-currency ISO 4217 integer math',
      'Continuous AVCO inventory valuation',
      'Double-entry General Ledger & VAT return',
      'Real-time SSE low-stock alert stream',
      'Standard CSV catalog import engine',
    ],
    ctaLabel: 'Select Regional Plan',
  },
  {
    id: 'distribution',
    name: 'Multi-Warehouse Scale',
    badge: 'COMMERCIAL STANDARD',
    highlighted: true,
    priceMonthly: '$1,290',
    billedNote: 'Billed monthly per tenant organization',
    description: 'For growing supply-chain operations managing multi-hub logistics and high transaction velocity.',
    quotas: {
      warehouses: 'Up to 5 Physical Warehouses',
      users: 'Up to 25 Authorized Seats',
      documentsPerMonth: '50,000 Invoices / Month',
      databaseIsolation: 'Dedicated PostgreSQL DB + Replica',
      rtoSla: '15-minute RTO Disaster Recovery',
    },
    features: [
      'All Regional Operations capabilities',
      'Inter-warehouse transfer vouchers & transit loss tracking',
      'Automated 3-way matching on supplier bills',
      'Custom subdomain or white-label vanity domain',
      'Spatie custom role & permission assignment',
      'Browsershot vector PDF invoice branding',
      'Priority REST API rate limits (120 req/min)',
    ],
    ctaLabel: 'Deploy Scale Workspace',
  },
  {
    id: 'enterprise',
    name: 'Enterprise Dedicated',
    highlighted: false,
    priceMonthly: 'Custom',
    billedNote: 'Annual contract with custom SLA guarantees',
    description: 'For high-throughput manufacturers requiring custom VPC deployment and dedicated database clusters.',
    quotas: {
      warehouses: 'Unlimited Warehouses & Bins',
      users: 'Unlimited Authorized Seats',
      documentsPerMonth: 'Uncapped Document Throughput',
      databaseIsolation: 'Isolated VPC / Private RDS Cluster',
      rtoSla: 'Sub-minute RTO Hot Standby',
    },
    features: [
      'All Multi-Warehouse Scale capabilities',
      'Single-tenant isolated VPC deployment option',
      'Custom ERP chart of accounts & fiscal calendar',
      'Dedicated point-in-time PostgreSQL restore drill',
      'Direct read-replica BI query access',
      'Named Enterprise Solutions Engineer',
      '24/7/365 emergency operational hotline',
    ],
    ctaLabel: 'Contact Architecture Team',
  },
];

export interface TierMatrixProps {
  onSelectTier?: (tierId: string) => void;
}

export function TierMatrix({ onSelectTier }: TierMatrixProps) {
  return (
    <section id="pricing" className="py-16 sm:py-24 border-b border-border bg-background">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="max-w-2xl text-left space-y-3 mb-16">
          <Badge variant="outline" className="font-mono text-xs uppercase tracking-wider text-muted-foreground">
            Tenant Subscription Tiers
          </Badge>
          <h2 className="font-display text-3xl sm:text-4xl font-extrabold tracking-tight text-foreground leading-[1.08]">
            Transparent operational quotas with zero hidden add-ons.
          </h2>
          <p className="text-base text-muted-foreground">
            Every plan includes our complete dual-engine architecture: physical database-per-tenant isolation, atomic GL posting, and continuous AVCO stock reconciliation.
          </p>
        </div>

        {/* Pricing Cards Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 items-stretch">
          {TIERS.map((tier) => (
            <div
              key={tier.id}
              className={cn(
                'relative flex flex-col justify-between rounded-lg border bg-card p-6 sm:p-8 transition-shadow',
                tier.highlighted
                  ? 'border-2 border-primary shadow-md ring-1 ring-primary/20'
                  : 'border-border shadow-xs'
              )}
            >
              {tier.badge && (
                <div className="absolute -top-3 left-6">
                  <Badge variant="default" className="text-[10px] font-mono uppercase tracking-wider px-2 py-0.5">
                    {tier.badge}
                  </Badge>
                </div>
              )}

              <div>
                {/* Plan Header */}
                <div className="border-b border-border/40 pb-6 mb-6">
                  <h3 className="font-display text-xl font-bold text-foreground mb-1">
                    {tier.name}
                  </h3>
                  <p className="text-xs text-muted-foreground leading-relaxed mb-4 min-h-[36px]">
                    {tier.description}
                  </p>

                  <div className="flex items-baseline gap-1">
                    <span className="font-display text-4xl font-extrabold text-foreground tracking-tight tabular-nums">
                      {tier.priceMonthly}
                    </span>
                    {tier.priceMonthly !== 'Custom' && (
                      <span className="text-xs text-muted-foreground">/mo</span>
                    )}
                  </div>
                  <div className="text-[11px] text-muted-foreground mt-1">
                    {tier.billedNote}
                  </div>
                </div>

                {/* Hard Quotas Box */}
                <div className="rounded border border-border/60 bg-muted/30 p-3.5 mb-6 space-y-2 text-xs font-mono">
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">Warehouses:</span>
                    <span className="font-semibold text-foreground">{tier.quotas.warehouses}</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">User Seats:</span>
                    <span className="font-semibold text-foreground">{tier.quotas.users}</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">Throughput:</span>
                    <span className="font-semibold text-foreground">{tier.quotas.documentsPerMonth}</span>
                  </div>
                  <div className="flex items-center justify-between border-t border-border/20 pt-1.5">
                    <span className="text-muted-foreground">Isolation:</span>
                    <span className="font-semibold text-secondary">{tier.quotas.databaseIsolation}</span>
                  </div>
                </div>

                {/* Feature Bullet List */}
                <div className="space-y-3 mb-8">
                  <span className="text-xs font-semibold text-foreground uppercase tracking-wider font-mono block">
                    Core Capabilities:
                  </span>
                  {tier.features.map((feature, fIdx) => (
                    <div key={fIdx} className="flex items-start gap-2.5 text-xs text-muted-foreground">
                      <Check className="h-4 w-4 text-success shrink-0 mt-0.5" />
                      <span>{feature}</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Action Button */}
              <div className="pt-4 border-t border-border/40">
                <Button
                  variant={tier.highlighted ? 'primary' : 'secondary'}
                  className="w-full justify-center font-semibold text-xs tracking-wide uppercase h-10 group"
                  onClick={() => onSelectTier?.(tier.id)}
                >
                  <span>{tier.ctaLabel}</span>
                  <ArrowRight className="ml-1.5 h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5" />
                </Button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
