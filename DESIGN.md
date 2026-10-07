---
name: Personal Data Sheet (PDS) Form
description: Philippine PDS form web interface - CRUD UI with Bootstrap 5 + thin custom CSS
colors:
  primary: "#0a58ca"
  neutral-bg: "#f4f6f8"
  panel-bg: "#fff"
  panel-border: "#d6dce2"
  text: "#212529"
  form-border: "#6c757d"
  eyebrow: "#495057"
---
# Design System: Personal Data Sheet (PDS) Form

## Overview

**Creative North Star: "The Clear Desk"**

The interface exists so a user can complete a Philippine Personal Data Sheet online instead of on paper, with the ability to search, edit, and remove prior submissions. The visual world is quiet, unhurried, and deliberately restrained - the form gets out of the way so the fields are the focus. There are no decorative flourishes, no playful interactions, and no flash beyond a single focus-visible outline to confirm interaction. The page uses soft off-white background and near-black text so the form feels like a printed document rendered on screen.

**Key Characteristics:**

- Quiet authority - professional, calm, trustworthy
- Form-first - every pixel serves a field, not decoration
- Tactile feedback - focus ring confirms interaction without drawing focus away from the data
- Print-faithful - what fits on screen fits on the official printed form

## Colors

The palette is cool, flat, and minimal ("Studio Neutral"): soft off-white page background with near-black text, and a single primary accent reserved exclusively for keyboard focus.

### Primary

- **Paper and Ink** (`#f4f6f8` soft off-white + `#212529` near-black text): used across the entire page; like a well-printed form rendered on screen. The off-white is cool-leaning and minimal, never warm or decorative.

### Neutral

- **Panel Surface** (`#fff`): pure white login panel and card backgrounds, contrasted against the page background.
- **Border Gray** (`#d6dce2`): panel borders and divider lines; separates the panel from the page background without drawing focus.
- **Form Border** (`#6c757d`): default state for form-control inputs, buttons, and selects.
- **Eyebrow** (`#495057`): login-eyebrow tag and secondary text.
- **Text** (`#212529`): body text and labels.

### Named Rules: Accent Usage

**The One Accent Rule.** The primary accent `#0a58ca` is used on <=10% of any given screen and exclusively as a `:focus-visible` outline. Its rarity is the point - it exists only to confirm interaction, never to decorate.

## Typography

**Display Font:** Bootstrap's default sans-serif (system font stack: `system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif`).

**Body Font:** Same as display - Bootstrap's default. No custom font pairing.

**Character:** Neutral and highly legible - the font family is chosen for rapid on-screen reading and print compatibility. Labels and body text use the same weight so the eye does not jump between two typefaces.

### Hierarchy

- **Body** (weight 400, ~1rem / 16px, line-height 1.5): form labels, help text, form-control text, table cells, alert text. Max line length 65-75 characters.
- **Label** (weight 500, ~0.875rem / 14px): form field labels associated via `for` attribute; title-case field names natively (SURNAME, DATE OF BIRTH).

## Layout

Bootstrap 5 grid system: container -> row -> column. The form lives inside `.container.my-5 > .row > .col-12`. Section headers use `.section mb-4` for vertical rhythm (4x the `mb-3` base unit = 16px). Rows use `.row mb-3`; columns use `.col-md-6` for two-column fields, `.col-md-4` for denser groups, `.col-md-12` for full-width fields. On viewports <=576px all columns stack to `.col-12`; the form never forces horizontal scrolling.

### Named Rules: Grid Behavior

**The Mobile-First Grid Rule.** On viewports <=576px every column becomes `.col-12`; the form reflows to a single column without horizontal overflow. No custom media queries beyond Bootstrap's default breakpoints.

## Elevation & Depth

One paragraph: This system uses flat depth - no shadows, no lift, no ambient layering. Depth is conveyed solely through the single focus-visible outline (`3px solid #0a58ca`, `outline-offset: 3px`) and the panel's border (`1px solid #d6dce2`). No box-shadows anywhere, not even on hover or active. If a future component needs depth, it must be approved as a Named Rule change.

### Named Rules: Depth & Shadows

**The Flat-By-Default Rule.** Surfaces are flat at rest. Shadows appear only as a response to state (hover, elevation, focus). No ambient shadows, no lift, no depth conveyed through opacity.

## Shapes

The form language is quietly rounded: the login panel has `border-radius: 0.5rem` (8px); form inputs, buttons, and selects use the browser's default corner radius. No custom clipping, no decorative silhouettes, no recurring geometry beyond the panel's soft corner. The overall feel is "softened edges, not rounded corners."

## Components

### Login Panel

- **Shape:** `0.5rem` radius on the panel container only; inputs and buttons use the browser's default radius.
- **Background:** `#fff` - pure white, contrasted against `#f4f6f8`.
- **Border:** `1px solid #d6dce2` - a single divider that separates the panel from the page background.
- **Internal Padding:** `clamp(1.5rem, 5vw, 2.5rem)` - responsive padding that shrinks on narrow viewports and expands on wide ones.

#### Primary actions (Submit / Clear buttons)

- **Background:** transparent (inherits panel `#fff`)
- **Text color:** `#212529`
- **Border:** `1px solid #6c757d`
- **Hover:** background `#e9ecef`, border and text unchanged
- **Focus-visible:** `3px solid #0a58ca` outline, `outline-offset: 3px`
- **Disabled:** opacity 0.65, `cursor: not-allowed`

### Form Controls

- **Style:** `border: 1px solid #6c757d`, full-width `.form-control`, `min-height: 2.75rem`
- **Background:** `#fff` inside the bordered input area
- **Focus:** `3px solid #0a58ca` outline, `outline-offset: 3px`; no background shift, no box-shadow
- **Error:** `border-color: #dc3545`, text `#dc3545`; focus outline stays `#0a58ca`
- **Disabled:** opacity 0.65, `cursor: not-allowed`

### Named Rules: Focus Treatment

**The Focus-Visible Rule.** Any interactive element that can receive keyboard focus must have a `:focus-visible` outline of `3px solid #0a58ca` with `outline-offset: 3px`. No other focus treatment is permitted without a Named Rule change.

### Alerts

- **Background:** `.alert-warning` = `#fff3cd`, `.alert-info` = `#d1ecf1`
- **Border:** none by default
- **Text color:** `#212529`
- **Padding/margin:** `1rem` vertical margin to separate from form sections
- **Color Fixity Rule:** alert colors are fixed Bootstrap values; neither may be restyled with the primary accent `#0a58ca`.

## Do's and Don'ts

### Do

- **Keep the primary accent reserved for `:focus-visible` outlines** - never on buttons, badges, or decorative elements.
- **Let the form reflow to a single column on mobile** (<=576px) - the Bootstrap grid handles this automatically; do not force a fixed width.
- **Use the login panel's `clamp(1.5rem, 5vw, 2.5rem)` padding** so the form breathes on all viewport sizes.
- **Associate every `<label>` with its input** via the `for` attribute matching the input's `id`.
- **Keep alert colors as Bootstrap defaults** (`#fff3cd` warning, `#d1ecf1` info).

### Don't

- **Use `#0a58ca` on any element outside a `:focus-visible` rule** - not on buttons, links, hover states, or as a page accent.
- **Add box-shadows anywhere in the system** - the flat-by-default rule means no ambient lift, no hover shadows, no active depth.
- **Force a multi-column layout on mobile** - the grid always stacks to `.col-12`; overriding creates horizontal overflow on narrow viewports.
- **Style `.alert-warning` or `.alert-info` with the primary accent** - alert colors are fixed Bootstrap values.
- **Use uppercase on form labels** - the field names are already title-case natively; styling them uppercase adds unnecessary visual weight.
