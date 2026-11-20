import * as React from 'react';
import { PublicNavbar } from '../components/PublicNavbar';
import { HeroSection } from '../components/HeroSection';
import { OperationalPillars } from '../components/OperationalPillars';
import { WorkflowSimulator } from '../components/WorkflowSimulator';
import { ProofDataTable } from '../components/ProofDataTable';
import { TierMatrix } from '../components/TierMatrix';
import { FaqAccordion } from '../components/FaqAccordion';
import { PublicFooter } from '../components/PublicFooter';
import { DemoRequestModal } from '../components/DemoRequestModal';

export interface LandingPageProps {
  appName?: string;
}

export function LandingPage({ appName = 'NexusERP' }: LandingPageProps) {
  const [demoOpen, setDemoOpen] = React.useState(false);
  const [selectedTierId, setSelectedTierId] = React.useState('distribution');

  const handleOpenDemo = (tierId = 'distribution') => {
    setSelectedTierId(tierId);
    setDemoOpen(true);
  };

  return (
    <div
      data-app-name={appName}
      className="min-h-screen flex flex-col bg-background text-foreground antialiased selection:bg-primary/30 selection:text-foreground"
    >
      {/* Public Header / Navigation */}
      <PublicNavbar onOpenDemo={() => handleOpenDemo('distribution')} />

      {/* Main Content Area */}
      <main className="flex-1">
        {/* Hero Section */}
        <HeroSection onOpenDemo={() => handleOpenDemo('distribution')} />

        {/* Operational Subsystems (Asymmetric 2-Column Splits) */}
        <OperationalPillars />

        {/* Dual-Engine Workflow Simulator */}
        <WorkflowSimulator />

        {/* Structured Proof & Architecture Comparison Table */}
        <ProofDataTable />

        {/* Transparent Tenant Tiers & Quotas */}
        <TierMatrix onSelectTier={(tierId) => handleOpenDemo(tierId)} />

        {/* FAQ Accordion */}
        <FaqAccordion />
      </main>

      {/* Public Footer */}
      <PublicFooter />

      {/* Interactive Provisioning Modal */}
      <DemoRequestModal
        open={demoOpen}
        onOpenChange={setDemoOpen}
        selectedTierId={selectedTierId}
      />
    </div>
  );
}

export default LandingPage;
