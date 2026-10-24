# Visual & UI/UX Design System: NexusERP

> **Agent note:** Use only the tokens, components, and patterns below. Do not invent colors, fonts, or one-off styles. Style exclusively with Tailwind utilities and shadcn/ui variants (see `Rules.md` §7). Light mode only in v1.

## 1. Design Language
Premium corporate luxury for a modern inventory/ERP product: calm, precise, spacious. Sharp visual hierarchy, strict grid alignment, generous whitespace between sections, dense-but-readable data tables. Restraint over decoration: gold is used sparingly so it always signals "primary".

**Principles:** 1) Clarity over cleverness. 2) Numbers are first-class (tabular, right-aligned). 3) One primary action per view. 4) Every state is designed (loading, empty, error, success). 5) Keyboard and screen-reader friendly.

## 2. Color Tokens (Light Mode)

| Token | Hex | Usage |
|---|---|---|
| `background` | `#F5F0E9` | Main workspace (swan wing off-white) |
| `surface` | `#FFFFFF` | Cards, tables, dialogs, inputs |
| `surface-muted` | `#EFE9DF` | Table header, hover rows, subtle panels |
| `border` | `#325070` | Structural outlines, card/table/input borders (sapphire) |
| `border-subtle` | `#325070` @ 20% | Row dividers inside tables |
| `sidebar` | `#112258` | Left navigation (royal blue) |
| `sidebar-foreground` | `#F5F0E9` | Sidebar text/icons |
| `sidebar-active` | `#E0C58F` @ 16% bg + gold left bar | Active nav item |
| `primary` | `#E0C58F` | Key actions & priority badges (quicksand gold) |
| `primary-foreground` | `#112258` | Text on gold (**never white on gold**) |
| `foreground` | `#112258` | Body text and headings |
| `muted-foreground` | `#4B5B78` | Secondary text (≥ 4.5:1 on background) |
| `success` / `success-bg` | `#1E5128` / `#E8F5E9` | In stock, paid, posted |
| `warning` / `warning-bg` | `#B45309` / `#FEF3C7` | Low stock, due soon, draft |
| `destructive` / `destructive-bg` | `#991B1B` / `#FEE2E2` | Errors, overdue, void, delete |
| `ring` | `#325070` | Focus ring (2px + 2px offset) |

**Tokens file:** declare as CSS variables in the single `app.css` (shadcn convention) and map them in Tailwind theme so utilities like `bg-primary`, `border-border`, `text-success` work.

```css
:root {
  --background: #F5F0E9;  --foreground: #112258;
  --card: #FFFFFF;        --card-foreground: #112258;
  --muted: #EFE9DF;       --muted-foreground: #4B5B78;
  --border: #325070;      --input: #325070;  --ring: #325070;
  --primary: #E0C58F;     --primary-foreground: #112258;
  --secondary: #112258;   --secondary-foreground: #F5F0E9;
  --destructive: #991B1B; --destructive-foreground: #FFFFFF;
  --success: #1E5128;     --success-bg: #E8F5E9;
  --warning: #B45309;     --warning-bg: #FEF3C7;
  --danger-bg: #FEE2E2;
  --sidebar: #112258;     --sidebar-foreground: #F5F0E9;
  --radius: 0.5rem;
}
```

## 3. Typography
- **Font:** Inter (primary) or Geist Sans. Enable `font-variant-numeric: tabular-nums` (Tailwind `tabular-nums`) on all numbers, tables, and totals.
- **Scale:** Page title `text-2xl font-semibold` · Section title `text-lg font-semibold` · Body `text-sm` (14px) · Table cell `text-sm` · Caption/help `text-xs text-muted-foreground`.
- Letter-spacing slightly tight on headings (`tracking-tight`). Line height relaxed for prose, compact for tables.

## 4. Layout & Spacing
- **App shell:** fixed left sidebar (256px, collapsible to 72px icon rail) + top bar (breadcrumbs, global search `⌘K`, notifications, user menu) + scrollable content area.
- **Content width:** max `1440px`, page padding `px-8 py-8` (desktop), `px-4 py-4` (mobile).
- **Spacing scale:** 4-pt grid (Tailwind default). Section gap `gap-8`, card padding `p-6`, form field gap `gap-4`.
- **Radius:** `rounded-lg` cards, `rounded-md` inputs/buttons. **Shadows:** minimal (`shadow-sm`); hierarchy comes from borders and whitespace.
- **Responsive:** desktop-first; tablet collapses sidebar; mobile uses a sheet nav and card-style table rows for key lists.

## 5. Components (shadcn/ui based)

**Buttons**
- *Primary:* gold bg, navy text, used for the single main action ("Add New Item", "Export Report", "Post Invoice").
- *Secondary:* white bg, sapphire border, navy text. *Ghost:* text only. *Destructive:* crimson, always behind a confirmation dialog.
- Min height 36px (40px in forms), visible focus ring, loading state with spinner and disabled click.

**Inputs & forms:** white bg, 1px sapphire border, label above, help text below, inline error in crimson with icon. Use React Hook Form + Zod. Money inputs: right-aligned, currency prefix, entered in major units, stored as cents. Required fields marked with `*`.

**Badges / status chips** (rounded-full, `text-xs font-medium`, bg + text pairs from tokens)
| Status | Style |
|---|---|
| In stock, Paid, Posted, Active | success |
| Low stock, Draft, Partially paid, Due soon | warning |
| Out of stock, Overdue, Void, Failed, Suspended | destructive |
| Priority / Premium plan / New | gold (`primary`) |

**Data tables (TanStack Table)**
- White surface, sapphire outer border, row dividers `border-subtle`, **sticky header** on `surface-muted`.
- Row height compact (40px) with density toggle (comfortable 52px). Hover row: `surface-muted`. Selected row: gold 12% tint.
- Numeric columns right-aligned with `tabular-nums`; negative amounts in crimson with minus sign; status columns use badges.
- Toolbar: search, filter chips, column visibility, export (gold primary only when it is *the* action). Footer: page size, range, pagination.
- Server-side sorting/filter/pagination; skeleton rows while loading; designed empty state with CTA.

**Cards & KPIs:** white surface, sapphire border, icon in navy, big number (`text-3xl tabular-nums`), delta chip (success/destructive). Low-stock card uses warning accents.

**Navigation:** sidebar groups (Dashboard · Inventory · Sales · Purchasing · Contacts · Accounting · Reports · Settings). Line-art icons (lucide, 1.5px stroke). Active item: gold left bar + tinted bg. Items hidden when the user lacks permission.

**Feedback:** toasts (bottom-right) for success/info; inline alerts for page-level errors; confirmation dialogs for destructive/irreversible actions (posting, voiding, deleting); skeletons over spinners for page loads.

**Document layouts (PDF):** same palette; navy header band, gold accent rule, tabular totals right-aligned, company logo slot, footer with page numbers.

## 6. Page Patterns
1. **List page:** title + primary action → filters/toolbar → table → pagination.
2. **Detail page:** header (title, status badge, actions) → summary cards → tabs (Details, Lines, Payments, History/Audit).
3. **Form page:** sectioned cards, sticky bottom action bar (Save draft · Post), unsaved-changes guard.
4. **Document editor (invoice/PO):** header fields, editable line grid, live totals panel (subtotal, VAT, total, balance) computed with integer math.
5. **Dashboard:** KPI row → charts → low-stock and overdue lists, live-updating via SSE.

## 7. Accessibility & Quality Bar
- WCAG 2.1 AA contrast; gold-on-navy and navy-on-gold pairs verified. Never convey state by color alone (pair with icon/text).
- Full keyboard navigation, logical tab order, `aria-` labels on icon buttons, focus trapped in dialogs, `prefers-reduced-motion` respected.
- Motion: 150–200 ms ease-out for hover/open/close; no decorative animation.
- Formatting: dates `DD MMM YYYY` (tenant locale override), money via `Intl.NumberFormat` with tenant currency, quantities with UoM.
- Every screen ships with loading, empty, error, and permission-denied states.
