---
name: Logistics & Inventory Operations
colors:
  surface: '#f8f9ff'
  surface-dim: '#cbdbf5'
  surface-bright: '#f8f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#eff4ff'
  surface-container: '#e5eeff'
  surface-container-high: '#dce9ff'
  surface-container-highest: '#d3e4fe'
  on-surface: '#0b1c30'
  on-surface-variant: '#43474e'
  inverse-surface: '#213145'
  inverse-on-surface: '#eaf1ff'
  outline: '#74777f'
  outline-variant: '#c4c6cf'
  surface-tint: '#455f87'
  primary: '#022448'
  on-primary: '#ffffff'
  primary-container: '#1e3a5f'
  on-primary-container: '#8aa4cf'
  inverse-primary: '#adc8f5'
  secondary: '#a73a00'
  on-secondary: '#ffffff'
  secondary-container: '#fd651e'
  on-secondary-container: '#571a00'
  tertiary: '#1c2337'
  on-tertiary: '#ffffff'
  tertiary-container: '#31394e'
  on-tertiary-container: '#9ba3bb'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d5e3ff'
  primary-fixed-dim: '#adc8f5'
  on-primary-fixed: '#001c3b'
  on-primary-fixed-variant: '#2d486d'
  secondary-fixed: '#ffdbce'
  secondary-fixed-dim: '#ffb599'
  on-secondary-fixed: '#370e00'
  on-secondary-fixed-variant: '#7f2b00'
  tertiary-fixed: '#dae2fd'
  tertiary-fixed-dim: '#bec6e0'
  on-tertiary-fixed: '#131b2e'
  on-tertiary-fixed-variant: '#3f465c'
  background: '#f8f9ff'
  on-background: '#0b1c30'
  surface-variant: '#d3e4fe'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.015em
  headline-md:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
    letterSpacing: '0'
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
    letterSpacing: '0'
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
    letterSpacing: '0'
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
    letterSpacing: '0'
  label-lg:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
    letterSpacing: 0.01em
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.02em
  label-mono:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '600'
    lineHeight: 18px
    letterSpacing: 0.05em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  gutter: 1rem
  gutter-desktop: 1.5rem
  margin: 1rem
  margin-desktop: 2rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 0.75rem
  space-lg: 1rem
  space-xl: 1.5rem
  space-2xl: 2rem
---

## Brand & Style

This design system is tailored specifically for high-velocity, high-accuracy warehouse operations, logistics dispatch, and material tracking. The visual philosophy is **Clean Enterprise Functionalism**: an operational interface stripped of all visual ornamentation, abstract illustrations, and gratuitous charts.

### Target Environment & User
- **Audience:** Warehouse operators, inventory clerks, shift supervisors, and pick-and-pack technicians, many of whom have minimal software training or use mobile handheld scanning tablets and ruggedized desktop terminals.
- **Operating Context:** High-glare overhead fluorescent warehouse lighting, rapid physical movements, and repetitive high-volume transaction processing where scanning speed and error prevention supersede all aesthetic novelty.
- **Emotional Resonance:** Calm, systematic, infallible, and direct. Every visual cue must communicate state, count, or operational action instantly without hesitation or visual fatigue.

## Colors

The palette establishes an unambiguous visual hierarchy where operational state takes center stage over decorative brand elements.

### Base Structural Palette
- **Canvas / Background:** `#F8FAFC` (Slate 50) — A calm, eye-resting light tint that reduces contrast glare under fluorescent bulbs.
- **Surface / Card Background:** `#FFFFFF` — Crisp, pure white surface containers providing clear boundaries.
- **Primary Borders:** `#E2E8F0` (Slate 200) — Low-noise structural borders providing clear container separation without visual clutter.
- **Primary Text:** `#0F172A` (Slate 900) — Maximum legibility for SKU numbers, bar-codes, shelf coordinates, and quantity values.
- **Secondary / Supporting Text:** `#64748B` (Slate 500) — Subdued metadata, field labels, and secondary timestamps.
- **Brand Navy:** `#1E3A5F` — Anchor navigation, primary system actions, active filter states, and batch dispatch buttons.

### Transaction & Operational State System
Operational roles require immediate visual triage:
- **Transaction Issue / Consumption:** `#EA580C` (Orange 600) — Reserved strictly for warehouse issues, stock outflows, inventory pulls, and primary material adjustments.
- **Completed:** Background `#ECFDF5` (Emerald 50), Text `#047857` (Emerald 700), Border `#A7F3D0` (Emerald 200).
- **In Transit / Active:** Background `#EFF6FF` (Blue 50), Text `#1D4ED8` (Blue 700), Border `#BFDBFE` (Blue 200).
- **Pending / In Queue:** Background `#FFFBEB` (Amber 50), Text `#B45309` (Amber 700), Border `#FDE68A` (Amber 200).
- **Rejected / Error:** Background `#FEF2F2` (Red 50), Text `#B91C1C` (Red 700), Border `#FECACA` (Red 200).
- **Cancelled / Neutral:** Background `#F1F5F9` (Slate 100), Text `#475569` (Slate 600), Border `#E2E8F0` (Slate 200).

## Typography

Inter is applied globally across all textual elements to leverage its tall x-height, clear letter distinction, and robust legibility under varied angles and viewing distances.

### Key Rules
- **Monospaced Features for Numerical Data:** Numerical tabular data (SKUs, serial numbers, lot codes, bin locations, and physical piece counts) must utilize font-feature settings `"tnum"` (tabular figures) and `"zero"` (slashed zero) to guarantee uniform column scanning across data grids.
- **Form & Input Clarity:** Form input text uses `body-md` (14px) or `body-lg` (16px) with distinct medium/semibold weights to ensure entered quantities are clearly visible from arm's length.
- **Uppercase Usage:** Labels for statuses, physical zones (e.g., `ZONE-A-04`), and transaction codes should use `label-md` or `label-mono` in uppercase with slight tracking (`letterSpacing: 0.02em - 0.05em`) for swift scan recognition.

## Layout & Spacing

Layouts follow a structured fluid grid optimized for high-density administrative views and structured data capture workflows.

### Grid & Page Scaffolding
- **Application Frame:** 12-column desktop grid with a fixed collapsible operational left navigation rail (240px expanded, 64px compact icon mode).
- **Gutters & Padding:** Compact 16px (`gutter`) to 24px (`gutter-desktop`) gutters to minimize dead space while maintaining distinct column alignment.
- **Container Density:** Strict tabular density. Table cells and form structures prioritize horizontal efficiency to avoid accidental off-screen data clipping.

### Responsiveness & Touch Targets
- **Tablet / Mounted Terminals (768px – 1024px):** Single-column to dual-column reflow for forms; horizontal table scroll with fixed leading columns (SKU / Action buttons).
- **Target Sizes:** Touch/click areas strictly meet or exceed 44px vertical height to facilitate quick interaction via ruggedized stylus or gloved fingers without miss-clicks.

## Elevation & Depth

Depth in this system is utilitarian, serving purely to separate resting card containers from the light `#F8FAFC` background and elevate interactive popovers or modals above busy data sheets.

### Elevation Levels
- **Level 0 (Flat Canvas):** `#F8FAFC`. Zero elevation.
- **Level 1 (Cards, Form Containers, Table Shells):** Solid `#FFFFFF` fill with a crisp 1px solid border in `#E2E8F0` coupled with a subtle, diffuse ambient shadow:
  `box-shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 1px 2px -1px rgba(15, 23, 42, 0.04);`
- **Level 2 (Dropdown Menus, Autocomplete Trays, Tooltips):** 
  `box-shadow: 0 4px 6px -1px rgba(15, 23, 42, 0.07), 0 2px 4px -2px rgba(15, 23, 42, 0.05);`
  Border: 1px solid `#CBD5E1`.
- **Level 3 (Confirmation Dialogs, Modal Overlays):** 
  `box-shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.1), 0 8px 10px -6px rgba(15, 23, 42, 0.08);`
  Surrounded by a high-opacity barrier scrim: `rgba(15, 23, 42, 0.45)`.

## Shapes

The geometric framework is calculated and uniform:
- **Cards & Data Panels:** Exactly 12px border radius (`rounded-xl` in Tailwind nomenclature) to frame information cleanly without appearing boxy or juvenile.
- **Inputs, Buttons, and Select Triggers:** 8px border radius (`rounded-lg`), delivering functional crispness that pairs directly with the 44px fixed component height.
- **Badges and Status Pills:** 6px border radius (`rounded-md`) to ensure badge labels read as compact, solid status tokens rather than clickable circular pills.

## Components

### Form Inputs & Buttons (Height: 44px / `h-11`)
- **Inputs (Text, Numerical, Date, Dropdowns):**
  - Height: Exactly 44px (`h-11`).
  - Background: `#FFFFFF`.
  - Border: 1px solid `#E2E8F0`. Focus state: 1.5px solid `#1E3A5F` with 2px ring offset in `#1E3A5F`/10%.
  - Padding: Horizontal 12px (`px-3`), vertical centered.
  - Text: 14px Regular (`#0F172A`). Placeholder: `#94A3B8`.
- **Primary Buttons:**
  - Height: Exactly 44px (`h-11`), minimum width 120px.
  - Navy Primary: Background `#1E3A5F`, text `#FFFFFF`, font weight Semibold (`600`). Hover: `#162C48`.
  - Transaction Issue Button: Background `#EA580C`, text `#FFFFFF`, font weight Semibold (`600`). Hover: `#C2410C`.
- **Secondary / Ghost Buttons:**
  - Height: Exactly 44px (`h-11`).
  - Background: `#FFFFFF`, border 1px solid `#E2E8F0`, text `#0F172A`. Hover: Background `#F1F5F9`.

### Enterprise Data Tables
- **Header Cells (`<th>`):** Height 40px, background `#F8FAFC`, border-bottom 1px solid `#E2E8F0`, typography `label-md` in `#64748B` uppercase.
- **Data Rows (`<tr>`):** Height 52px (standard) or 44px (dense), border-bottom 1px solid `#F1F5F9`. Hover background `#F8FAFC`.
- **Selected Row:** Background `#EFF6FF` (Blue 50/50), left-edge indicator bar 3px solid `#1E3A5F`.

### Status Badges
- **Dimensions:** Height 24px, inline-flex, align-center, padding 2px 8px, border-radius 6px, border 1px solid, font-size 12px, font-weight 600.
- **Completed:** `bg-[#ECFDF5] text-[#047857] border-[#A7F3D0]`.
- **Cancelled:** `bg-[#F1F5F9] text-[#475569] border-[#E2E8F0]`.
- **Pending:** `bg-[#FFFBEB] text-[#B45309] border-[#FDE68A]`.
- **In Transit:** `bg-[#EFF6FF] text-[#1D4ED8] border-[#BFDBFE]`.
- **Rejected:** `bg-[#FEF2F2] text-[#B91C1C] border-[#FECACA]`.

### Cards & Data Panels
- Background `#FFFFFF`, border 1px solid `#E2E8F0`, corner radius 12px (`rounded-xl`), padding 16px to 24px.
- Card headers strictly divide with a subtle bottom divider border `#F1F5F9` when paired with tabular listings.

### Selection Controls
- **Checkboxes & Radios:** Fixed 20px x 20px box size (centered inside a 44px click target). Border `#CBD5E1`. Selected state `#1E3A5F` fill with crisp white indicator check.