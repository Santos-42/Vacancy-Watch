# Design Review Results: Vacancy Watch — index.html

**Review Date**: 2026-03-05  
**Route**: http://localhost/vacancy-watch/frontend/index.html  
**Focus Areas**: Visual Design · UX/Usability · Micro-interactions · Accessibility  
**Context**: B2G product used by municipal decision-makers. Precision and data density are paramount.

---

## Summary

The application shell is clean and the dark palette is consistent, but six specific problems degrade the executive experience: native OS chrome bleeds into the filter dropdowns, numeric columns lack monospace alignment, the empty-state fires a floating toast (reads as a system error, not an intentional state), the table hover is nearly invisible, map pins are plain circles with no urgency cues for critical anomalies, and there is one silent JavaScript bug that prevents map-pin-click from ever reaching the detail panel.

---

## Issues

| # | Issue | Criticality | Category | Location |
|---|-------|-------------|----------|----------|
| 1 | **Native OS dropdown arrow not suppressed** — `appearance: auto` (confirmed via computed style) leaves the browser's grey/OS-native chevron and background bleeding through the custom dark style. `appearance: none` is not set, and no custom chevron SVG is provided. | 🔴 Critical | Visual Design | `frontend/css/index.css:444–455` |
| 2 | **Functional bug: `graphicsLayer` is undefined** — `app.js:579` references `graphicsLayer` (undeclared), but the layer variable is `mainGraphicsLayer`. Map pin clicks will silently fail the `hitTest` find — the `graphicHit` will always be `undefined`, so the detail panel never opens from a pin click. | 🔴 Critical | UX/Usability | `frontend/js/app.js:579` |
| 3 | **Empty state rendered as a floating toast** — When filters return 0 anomalies, `showOverlay("loading", "No anomalies match…")` is called, creating a dark semi-transparent box floating over the centre of the map (`position: fixed; bottom: 24px`). This reads visually as a system-level error, not an intentional empty state. The `#anomaly-tbody` is left empty with no inline feedback at all. | 🔴 Critical | UX/Usability | `frontend/js/app.js:504–507` · `frontend/css/index.css:319–344` |
| 4 | **No monospace font on numeric columns** — `#`, `SCORE`, and `Parcel:` detail values use the body font `"Avenir Next"` (proportional). Digit widths vary, making column scanning error-prone for municipal auditors comparing 50 rows. No Google Font or system monospace import exists. | 🟠 High | Visual Design | `frontend/index.html:15–16` · `frontend/css/index.css:418–432` |
| 5 | **Score column center-aligned, not right-aligned** — `th:last-child` and `td:last-child` use `text-align: center`. For a numeric score column, right-alignment with a fixed-width font is the executive data standard (aligns units/tens digits vertically). | 🟠 High | Visual Design | `frontend/css/index.css:413–415` · `frontend/css/index.css:429–432` |
| 6 | **Table row hover nearly invisible** — `tbody tr:hover` background is `rgba(255,255,255,0.04)` — only 4% white opacity on a `#16213e` base. On a calibrated monitor this is imperceptible. Users have no visual affordance that rows are clickable before they click. | 🟠 High | UX/Usability | `frontend/css/index.css:466–469` |
| 7 | **Map markers: `simple-marker` with no urgency hierarchy** — All anomaly pins use the same `simple-marker` type at `12px`. Critical anomalies (score 7–10) are visually identical to low-risk ones. The `priority-pulse` animation exists in CSS for the detail card but is never applied to map pins. ArcGIS `picture-marker` supports custom SVG symbols; a pulsing DOM ring overlay (absolutely positioned over the map canvas) would provide the urgency cue. | 🟡 Medium | Micro-interactions | `frontend/js/app.js:122–130` · `frontend/css/index.css:290–302` |
| 8 | **CLS 0.117 — above Google's "good" threshold (0.1)** — The map element (`arcgis-map`) renders without a declared height placeholder, causing layout shift as the ArcGIS SDK initialises. This is a measurable user experience regression. The `#map-view` block should have `contain: strict` or an explicit `min-height` to reserve space before JS fires. | 🟡 Medium | Performance | `frontend/css/index.css:38–43` |
| 9 | **`#` column uses `color: #8892a4` but no monospace font or right-alignment** — The row index (`1`, `2` … `50`) shares the muted colour treatment but is left-aligned and proportional. For a data-dense executive table, index numbers and scores should both be monospace + right-aligned for fast vertical scanning. | 🟡 Medium | Visual Design | `frontend/css/index.css:424–427` |
| 10 | **`detail-parcel` (Parcel ID) uses proportional font** — In `renderDetailPanel`, the parcel number is rendered inside `.detail-parcel` with `font-size: 12px` and the body font. Parcel IDs are alphanumeric codes that benefit from monospace treatment for readability and copy-paste accuracy. | ⚪ Low | Visual Design | `frontend/css/index.css:154–157` · `frontend/js/app.js:173` |
| 11 | **`exec-filters` padding top is 0 (`padding: 0 16px 12px 16px`)** — There is no top padding between the `exec-header` border-bottom and the filter row. The selects appear glued to the header with no breathing room. | ⚪ Low | Visual Design | `frontend/css/index.css:436–441` |
| 12 | **No `cursor: pointer` on `.filter-select`** — The CSS has `cursor: pointer` for `tbody tr` but not for the filter selects, which are interactive controls. Technically the browser shows a default cursor on `<select>`, but explicitly declaring it matches the rest of the interactive surface. | ⚪ Low | UX/Usability | `frontend/css/index.css:444–455` |

---

## Criticality Legend

- 🔴 **Critical** — Breaks functionality or is visually broken/misread as a system error
- 🟠 **High** — Significantly impacts data readability or interaction clarity for executive users
- 🟡 **Medium** — Noticeable degradation in experience or performance
- ⚪ **Low** — Polish-level refinement

---

## Recommended Fixes (Priority Order)

### 🔴 1 — Fix `graphicsLayer` bug (5 min)

```js
// frontend/js/app.js:579
// BEFORE:
(r) => r.graphic && r.graphic.layer === graphicsLayer

// AFTER:
(r) => r.graphic && r.graphic.layer === mainGraphicsLayer
```

### 🔴 2 — Suppress native select appearance + custom chevron

```css
/* frontend/css/index.css — .filter-select */
.filter-select {
  /* existing props... */
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238892a4' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 10px center;
  padding-right: 30px;
}
```

### 🔴 3 — Replace empty-state toast with inline watermark

```js
// frontend/js/app.js — inside loadMapData(), replace lines 504–507:
if (lightAnomalies.length === 0) {
  document.getElementById("exec-count").textContent = "0 anomalies";
  const tbody = document.getElementById("anomaly-tbody");
  if (tbody) {
    tbody.innerHTML = `
      <tr class="empty-state-row">
        <td colspan="4">
          <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
              <line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/>
            </svg>
            <p>0 Anomalies detected for this criteria.</p>
          </div>
        </td>
      </tr>`;
  }
  showOverlay("hide");
  return;
}
```

```css
/* frontend/css/index.css */
.empty-state-row td { border: none; }
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 32px 0;
  color: rgba(136, 146, 164, 0.35);
  font-size: 13px;
  letter-spacing: 0.3px;
}
.empty-state svg { opacity: 0.25; }
```

### 🟠 4 — Add monospace font + right-align numeric columns

```html
<!-- frontend/index.html <head> -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
```

```css
/* frontend/css/index.css */

/* Index (#) and Score columns — monospace + right-align */
#anomaly-table th:first-child,
#anomaly-table td:first-child {
  font-family: 'Roboto Mono', monospace;
  text-align: right;
  width: 44px;
  padding-right: 16px;
}

#anomaly-table th:last-child,
#anomaly-table td:last-child {
  font-family: 'Roboto Mono', monospace;
  text-align: right;  /* change from center */
  width: 70px;
  padding-right: 16px;
}

/* Parcel ID in detail panel */
.detail-parcel {
  font-family: 'Roboto Mono', monospace;
}
```

### 🟠 5 — Strengthen table row hover

```css
/* frontend/css/index.css:466–469 */
#anomaly-table tbody tr:hover {
  background: rgba(255, 255, 255, 0.07); /* raise from 0.04 → 0.07 */
  transition: background 0.12s ease;
}
```

### 🟡 6 — Map marker urgency: SVG picture-marker + critical pulse

```js
// frontend/js/app.js — buildGraphics() function
// Replace simple-marker with picture-marker using inline SVG data URL

function getPinSvg(color, isCritical) {
  const [r, g, b] = color;
  const fill = `rgb(${r},${g},${b})`;
  return `data:image/svg+xml,${encodeURIComponent(`
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="30" viewBox="0 0 22 30">
      <filter id="shadow"><feDropShadow dx="0" dy="2" stdDeviation="2" flood-opacity="0.5"/></filter>
      <ellipse cx="11" cy="28" rx="4" ry="2" fill="rgba(0,0,0,0.3)"/>
      <path d="M11 0 C5 0 0 5 0 11 C0 19 11 30 11 30 C11 30 22 19 22 11 C22 5 17 0 11 0Z"
            fill="${fill}" filter="url(#shadow)"/>
      <circle cx="11" cy="11" r="4" fill="rgba(255,255,255,0.35)"/>
    </svg>
  `)}`;
}

// In buildGraphics(), replace symbol block:
symbol: {
  type: "picture-marker",
  url: getPinSvg(color, score >= 7),
  width: "22px",
  height: "30px",
},
```

> **Note on CSS pulse for critical pins**: ArcGIS renders markers on a `<canvas>` element — CSS `@keyframes` cannot be applied directly. For the pulsing ring on critical anomalies, the recommended approach is to create a transparent DOM overlay `<div>` absolutely positioned over the map for each critical pin, using the MapView's `toScreen()` method to convert geographic coordinates to screen XY, then updating positions on `mapView.watch('stationary')`. This is a more involved implementation and should be treated as a separate engineering task.

### 🟡 7 — Fix CLS: Reserve map height

```css
/* frontend/css/index.css:38–43 */
#map-view {
  display: block;
  height: 100%;
  min-width: 0;
  contain: strict; /* prevents layout shift during ArcGIS SDK init */
}
```

---

## Architecture Reminder

Per the engineer's memo: **do not touch the two-fetch architecture** (`mode=light` + lazy full fetch). All fixes above are purely DOM/CSS manipulations — no API structure changes are made.
