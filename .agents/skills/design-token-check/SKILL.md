---
name: design-token-check
description: Audit frontend React components and Tailwind styling against Design.md tokens and WCAG 2.1 AA rules. Use whenever writing or reviewing React components, tables, modals, or form fields.
---

# Design System & Token Enforcer

Audit React components against `Design.md` design language tokens.

## Checklist

### 1. Color Tokens (Light Mode Only)

- Backgrounds: `bg-background` (`#F5F0E9`), `bg-card` (`#FFFFFF`), `bg-muted` (`#EFE9DF`).
- Text & Contrast:
  - Body/Headings: `text-foreground` (`#112258`).
  - Muted: `text-muted-foreground` (`#4B5B78`).
  - Actions/Primary: `bg-primary` (`#E0C58F`) with `text-primary-foreground` (`#112258`). **Never use white text on gold.**
  - Outlines & Borders: `border-border` (`#325070`).

### 2. Tabular Numbers & Financial Data

- Ensure `tabular-nums` class is applied to all numbers, quantities, prices, and table totals.
- Currency amounts must be right-aligned (`text-right`).
- Negative balances must be rendered in crimson (`text-destructive`) with an explicit minus sign.

### 3. Component Standards

- Buttons: Height must be at least 36px (40px in forms), with an explicit focus ring (`focus-visible:ring-2 focus-visible:ring-ring`).
- Modals & Dialogs: Destructive actions (void, delete, cancel) must use a confirmation modal.
- Empty & Loading States: Every asynchronous table/view must provide skeleton loaders (`<Skeleton />`) and designed empty states with a call-to-action.
