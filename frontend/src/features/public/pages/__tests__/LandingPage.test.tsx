import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { LandingPage } from '../LandingPage';

describe('LandingPage (Public SaaS Marketing)', () => {
  it('renders headline, architectural guarantees, and dual-engine subsystems', () => {
    render(<LandingPage appName="NexusERP" />);

    // Check Hero headline
    expect(
      screen.getByRole('heading', {
        name: /Multi-tenant ERP with atomic inventory & zero-imbalance ledger/i,
      })
    ).toBeInTheDocument();

    // Check Architectural Guarantees section
    expect(
      screen.getByRole('heading', {
        name: /Four core subsystems built for operational auditability/i,
      })
    ).toBeInTheDocument();

    // Check Dual-Engine Workflow section
    expect(
      screen.getByRole('heading', {
        name: /From procurement to bank reconciliation in six synchronized steps/i,
      })
    ).toBeInTheDocument();

    // Check Structured Comparison section
    expect(
      screen.getByRole('heading', {
        name: /Built as a unified operational core, not a patch of SaaS plugins/i,
      })
    ).toBeInTheDocument();

    // Check Pricing Tiers section
    expect(
      screen.getByRole('heading', {
        name: /Transparent operational quotas with zero hidden add-ons/i,
      })
    ).toBeInTheDocument();
  });

  it('strictly adheres to anti-AI copywriting rules and contains no banned buzzwords', () => {
    const { container } = render(<LandingPage appName="NexusERP" />);
    const pageText = container.textContent?.toLowerCase() || '';

    const bannedWords = [
      'elevate',
      'unleash',
      'seamless',
      'revolutionize',
      'cutting-edge',
      'next-generation',
      'unlock the power of',
      "in today's fast-paced world",
    ];

    bannedWords.forEach((word) => {
      expect(pageText).not.toContain(word);
    });
  });

  it('allows interacting with the live Ledger Preview Card scenario tabs', () => {
    render(<LandingPage appName="NexusERP" />);

    // Default scenario is 'Posted Sales Invoice'
    expect(screen.getAllByText('INV-2026-00104').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Finished Goods Revenue')).toBeInTheDocument();

    // Switch to Customer Payment Allocation scenario
    const paymentTab = screen.getByRole('button', { name: /Customer Payment Allocation/i });
    fireEvent.click(paymentTab);

    expect(screen.getAllByText('RCP-2026-00088').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Operating Account (GBP)')).toBeInTheDocument();

    // Switch to Goods Receipt scenario
    const grnTab = screen.getByRole('button', { name: /Goods Receipt & AVCO Cost Update/i });
    fireEvent.click(grnTab);

    expect(screen.getAllByText('GRN-2026-00319').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Inventory Valuation')).toBeInTheDocument();
  });

  it('allows stepping through the workflow simulator', () => {
    render(<LandingPage appName="NexusERP" />);

    // Click on Goods Receipt step in simulator
    const grnStepButton = screen.getByRole('button', { name: /Goods Receipt & AVCO Costing/i });
    fireEvent.click(grnStepButton);

    expect(screen.getAllByText(/GRN-2026-00319/i).length).toBeGreaterThanOrEqual(1);
    expect(
      screen.getByText(/Physical warehouse gate inspection verifies 50 units/i)
    ).toBeInTheDocument();
  });

  it('toggles the FAQ accordion items with accessible ARIA state', () => {
    render(<LandingPage appName="NexusERP" />);

    // Find first FAQ trigger
    const faqTrigger = screen.getByRole('button', {
      name: /How does database-per-tenant isolation prevent cross-tenant data leakage\?/i,
    });
    expect(faqTrigger).toBeInTheDocument();
    expect(faqTrigger).toHaveAttribute('aria-expanded', 'true');

    // Click to collapse
    fireEvent.click(faqTrigger);
    expect(faqTrigger).toHaveAttribute('aria-expanded', 'false');

    // Click another FAQ to expand
    const avcoTrigger = screen.getByRole('button', {
      name: /When and how does continuous AVCO inventory costing recalculate unit cost\?/i,
    });
    expect(avcoTrigger).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(avcoTrigger);
    expect(avcoTrigger).toHaveAttribute('aria-expanded', 'true');
  });

  it('opens demo provisioning modal and validates required fields', async () => {
    render(<LandingPage appName="NexusERP" />);

    // Open demo modal via Hero CTA
    const ctaButton = screen.getByRole('button', { name: /Provision Test Workspace/i });
    fireEvent.click(ctaButton);

    expect(
      screen.getByRole('heading', { name: /Provision Isolated Tenant Sandbox/i })
    ).toBeInTheDocument();

    // Submit without filling fields -> triggers validation error
    const submitButton = screen.getByRole('button', { name: /Create Workspace/i });
    fireEvent.click(submitButton);

    expect(screen.getByText(/Organization name is required/i)).toBeInTheDocument();
  });
});
