# Personal Task Manager — Design Standard

Approved visual direction: user-provided screenshot image(20261001-214605).png, finance dashboard by Halo UI/UX for HALO LAB (Twisty). Use its visual language for the productivity application; do not copy finance content, logos, or statistics.

## Consistency rule
All application pages and reusable components use these tokens. Changes to the palette, typography, spacing, or component style require an explicit design decision. Authentication pages use the same visual language with a simpler shell.

## Palette
These are chosen implementation tokens approximating the screenshot, not extracted source-design values.

| Token | Value | Usage |
|---|---|---|
| canvas | #8C97AF | Desktop outer backdrop |
| app | #E9EAEC | Application background and sidebar |
| surface | #FFFFFF | Cards, inputs, popovers |
| text | #252B3D | Headings and primary text |
| muted | #626976 | Secondary text |
| border | #D6D9DE | Dividers, input borders |
| accent | #E64B27 | Primary actions and emphasis |
| secondary | #79A7CF | Secondary chart/data accents |
| soft | #D3D8DF | Neutral badges and icon backgrounds |
| focus | #2563EB | Visible keyboard focus |

Use dark text for normal content. White on orange is restricted to sufficiently large/bold text or decorative icons; use dark primary buttons with white text for reliable readable small labels. Do not use pale secondary colors for essential small text.

## Typography and spacing
- System sans-serif initially; consistent font throughout.
- Page titles 28–36px, medium weight, tight tracking.
- Section titles 18–22px; body 14–16px; labels 12–14px.
- Spacing scale: 4, 8, 12, 16, 24, 32, 48px.
- Cards: white, radius 24px, padding 24px, restrained shadow.
- Buttons/inputs: radius 12px; pill controls for filters/badges.
- Border 1px; no heavy gradients or excessive shadows.

## Layout
- Retain the requested sidebar even though the reference uses top navigation.
- Desktop sidebar 240–256px; compact top header; content grid with a broad primary column and narrower supporting column where useful.
- Gray application shell, white rounded panels, ample whitespace.
- Mobile single-column content and collapsible accessible navigation.
- Upcoming modules are noninteractive labels until their routes exist.

## Shared components
AppLayout, Sidebar, PageHeader, Card, Button, TextInput, Textarea, Select, Badge, EmptyState, TaskRow, ResponsibilityCard, FormError.
Keep shell/navigation in AppLayout and visual tokens in resources/css/app.css. Pages compose shared components rather than repeating styling.

## Behavior
- Visible keyboard focus, associated labels, readable validation errors.
- Hover and disabled states follow the same palette.
- Status never communicated by color alone.
- Forms preserve entered data on validation errors.
- Charts only show real data; no invented activity or metrics.

## Implementation sequence
1. Add CSS tokens and shared controls.
2. Restyle AppLayout and navigation.
3. Apply to home and responsibility details without changing working data flows.
4. Apply to auth pages.
5. Use the same components for tasks, calendar, spiritual tracker, resources, school, workouts, and watch later.

This document records the visual baseline. It does not modify the local Laravel repository by itself. Add it to that repository as docs/design-system.md to keep the rules alongside source control.
