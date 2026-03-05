/**
 * =============================================================================
 * Vacancy Watch — Frontend Application Logic (Module 3.2 + 3.4)
 * =============================================================================
 * 1. Fetches lightweight anomaly data (?mode=light) to render map pins
 * 2. On pin click: lazily fetches FULL anomaly data (with details), caches it,
 *    then populates the Executive Detail Panel with investigative context
 * 3. Renders an executive anomaly table (Module 3.4) from the same light data,
 *    sorted by priority_score descending. Row click → map goTo + detail panel.
 *    Pin click → table row highlight (bidirectional sync).
 *
 * Data flow:
 *   Pin load  → GET ?mode=light  → coordinates + score → fast map render + table
 *   Pin click → GET (full)       → details object      → sidebar panel
 *
 * NEVER calls Montgomery Open Data directly — agent.md 3-tier rule.
 * =============================================================================
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const API_BASE = "http://localhost/vacancy-watch/backend/api";
const ANOMALIES_LIGHT_URL = `${API_BASE}/get_anomalies.php?mode=light`;
const ANOMALIES_FULL_URL = `${API_BASE}/get_anomalies.php`;

/** Color map per anomaly type */
const ANOMALY_COLORS = {
  zombie_property: [220, 53, 69],
  ghost_permit: [255, 193, 7],
  government_blind_spot: [13, 110, 253],
};
const DEFAULT_COLOR = [108, 117, 125];

/** Human-readable labels for anomaly types */
const ANOMALY_LABELS = {
  zombie_property: "Zombie Property",
  ghost_permit: "Ghost Permit",
  government_blind_spot: "Gov. Blind Spot",
};

/** Cached full anomaly data (fetched lazily on first pin click) */
let fullAnomalyCache = null;

// ---------------------------------------------------------------------------
// UI: Status Overlay
// ---------------------------------------------------------------------------

function showOverlay(state, message = "") {
  let overlay = document.getElementById("status-overlay");

  if (state === "hide") {
    if (overlay) overlay.remove();
    return;
  }

  if (!overlay) {
    overlay = document.createElement("div");
    overlay.id = "status-overlay";
    document.body.appendChild(overlay);
  }

  overlay.className = `status-overlay status-${state}`;
  overlay.textContent = message;
}

// ---------------------------------------------------------------------------
// Data Fetching
// ---------------------------------------------------------------------------

/**
 * Fetch anomaly data from the backend API.
 * @param {string} url — endpoint (light or full)
 */
async function fetchAPI(url) {
  const response = await fetch(url);

  if (!response.ok) {
    let detail = `HTTP ${response.status}`;
    try {
      const body = await response.json();
      if (body.error) detail += `: ${body.error}`;
    } catch {
      detail += ` ${response.statusText}`;
    }
    throw new Error(detail);
  }

  const data = await response.json();
  if (!data.anomalies || !Array.isArray(data.anomalies)) {
    throw new Error("Invalid API response: missing anomalies array");
  }
  return data;
}

/**
 * Get full anomaly data, fetching and caching on first call.
 * Since max anomalies = 50, this is safe to hold in browser memory.
 */
async function getFullAnomalyData() {
  if (!fullAnomalyCache) {
    fullAnomalyCache = await fetchAPI(ANOMALIES_FULL_URL);
  }
  return fullAnomalyCache;
}

// ---------------------------------------------------------------------------
// Map: Build pin graphics (lightweight — for fast initial render)
// ---------------------------------------------------------------------------

function buildGraphics(GraphicClass, anomalies) {
  return anomalies
    .filter((a) => a.latitude != null && a.longitude != null)
    .map((a) => {
      const color = ANOMALY_COLORS[a.anomaly_type] || DEFAULT_COLOR;

      return new GraphicClass({
        geometry: {
          type: "point",
          longitude: a.longitude,
          latitude: a.latitude,
        },
        symbol: {
          type: "simple-marker",
          color,
          size: "12px",
          outline: { color: [255, 255, 255], width: 1.5 },
        },
        attributes: {
          parcel_id: a.parcel_id,
          street_address: a.street_address,
          anomaly_type: a.anomaly_type,
        },
        // Popup is minimal — just a trigger for the detail panel
        popupTemplate: {
          title: ANOMALY_LABELS[a.anomaly_type] || a.anomaly_type,
          content: "{street_address}",
          actions: [],
        },
      });
    });
}

// ---------------------------------------------------------------------------
// Panel: Render detail card from full anomaly data
// ---------------------------------------------------------------------------

/**
 * Build the detail panel HTML for a single anomaly.
 * @param {Object} anomaly — full anomaly object (with details)
 */
function renderDetailPanel(anomaly) {
  const panel = document.getElementById("panel-content");
  if (!panel) return;

  const type = anomaly.anomaly_type || "unknown";
  const label = ANOMALY_LABELS[type] || type;
  const details = anomaly.details || {};
  const score = anomaly.priority_score ?? 0;
  let levelClass = 'low';
  let levelLabel = 'Low Risk';
  if (score >= 7) { levelClass = 'high'; levelLabel = 'Critical'; }
  else if (score >= 4) { levelClass = 'medium'; levelLabel = 'Elevated'; }

  let html = `
    <div class="detail-card">
      <div class="detail-card-title">
        <span class="anomaly-badge badge-${type}">${label}</span>
      </div>
      <div class="detail-address">${anomaly.street_address || "Address unavailable"}</div>
      <div class="detail-parcel">Parcel: ${anomaly.parcel_id || "—"}</div>
  `;

  // Priority score — SVG donut ring
  const circumference = 2 * Math.PI * 22;
  const offset = circumference - (score / 10) * circumference;
  const pulseClass = score >= 7 ? ' priority-pulse' : '';

  html += `
      <div class="priority-wrapper${pulseClass}">
        <div class="priority-score-ring">
          <svg viewBox="0 0 48 48">
            <circle class="ring-bg" cx="24" cy="24" r="22"/>
            <circle class="ring-fill ring-${levelClass}" cx="24" cy="24" r="22"
                    stroke-dasharray="${circumference.toFixed(1)}"
                    stroke-dashoffset="${offset.toFixed(1)}"/>
          </svg>
          <div class="score-text">${score}</div>
        </div>
        <div class="priority-info">
          <div class="priority-label">Priority Score</div>
          <div class="priority-level level-${levelClass}">${levelLabel}</div>
        </div>
      </div>
  `;

  // Type-specific detail sections
  if (type === "zombie_property") {
    html += buildSection("Vacancy & Violations", [
      ["Vacancy Status", details.vacancy_status],
      ["Violation Count", details.violation_count, details.violation_count >= 3],
      ["Latest Violation", formatDate(details.latest_violation_date)],
      ["Violation Type", details.latest_violation_type],
      ["Detection Window", details.window_days ? `${details.window_days} days` : null],
    ]);
  } else if (type === "ghost_permit") {
    html += buildSection("Permit Details", [
      ["Permit #", details.permit_number],
      ["Permit Type", details.permit_type],
      ["Issue Date", formatDate(details.issue_date)],
      ["Permit Status", details.permit_status],
      ["Is Vacant?", details.is_vacant ? "Yes" : "No", details.is_vacant],
      ["Open Violations", details.open_violations, details.open_violations >= 1],
      ["Detection Window", details.window_days ? `${details.window_days} days` : null],
    ]);
  } else if (type === "government_blind_spot") {
    html += buildSection("Surplus Property Details", [
      ["Lot Size (sqft)", details.lot_size_sqft ? details.lot_size_sqft.toLocaleString() : null],
      ["Surplus Status", details.surplus_status],
      ["Violation Count", details.violation_count, details.violation_count >= 3],
      ["Latest Violation", formatDate(details.latest_violation_date)],
    ]);
  }

  html += `</div>`;
  panel.innerHTML = html;
}

/**
 * Build a detail section with rows.
 * @param {string} title — section title
 * @param {Array} rows  — [[label, value, highlight?], ...]
 */
function buildSection(title, rows) {
  let html = `
    <div class="detail-section">
      <div class="detail-section-title">${title}</div>
  `;
  for (const [label, value, highlight] of rows) {
    const displayVal = value != null && value !== "" ? value : "—";
    const cls = highlight ? ' highlight' : '';
    html += `
      <div class="detail-row">
        <span class="detail-label">${label}</span>
        <span class="detail-value${cls}">${displayVal}</span>
      </div>
    `;
  }
  html += `</div>`;
  return html;
}

/**
 * Format a date string for display (or return "—").
 */
function formatDate(dateStr) {
  if (!dateStr) return null;
  try {
    const d = new Date(dateStr);
    return d.toLocaleDateString("en-US", {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  } catch {
    return dateStr;
  }
}

/**
 * Reset the panel to placeholder state.
 */
function resetPanel() {
  const panel = document.getElementById("panel-content");
  if (panel) {
    panel.innerHTML = `<p class="panel-placeholder">Click a pin on the map to inspect anomaly details.</p>`;
  }
}

// ---------------------------------------------------------------------------
// Mobile Tabbed UI (Fix 5)
// ---------------------------------------------------------------------------

function initMobileTabs() {
  const tabs = document.querySelectorAll(".tab-btn");
  if (!tabs.length) return;

  // Initial state for mobile
  if (window.innerWidth <= 768) {
    document.getElementById("detail-panel").classList.add("panel-hidden");
  }

  // Window resize handler to clean up classes if returning to desktop
  window.addEventListener("resize", () => {
    if (window.innerWidth > 768) {
      document.getElementById("detail-panel").classList.remove("panel-hidden");
      document.getElementById("exec-panel").classList.remove("panel-hidden");
    } else {
      // Re-apply mobile state based on active tab
      const activeTab = document.querySelector(".tab-active");
      if (activeTab) {
        switchMobileTab(activeTab.dataset.panel);
      }
    }
  });

  tabs.forEach(btn => {
    btn.addEventListener("click", () => switchMobileTab(btn.dataset.panel));
  });
}

function switchMobileTab(targetPanelId) {
  if (window.innerWidth > 768) return;

  // Update buttons
  document.querySelectorAll(".tab-btn").forEach(btn => {
    btn.classList.toggle("tab-active", btn.dataset.panel === targetPanelId);
  });

  // Update panels
  document.getElementById("exec-panel").classList.toggle("panel-hidden", targetPanelId !== "exec-panel");
  document.getElementById("detail-panel").classList.toggle("panel-hidden", targetPanelId !== "detail-panel");
}

// ---------------------------------------------------------------------------
// Executive Table (Module 3.4): Render + Bidirectional Sync
// ---------------------------------------------------------------------------

/** Reference to the ArcGIS MapView — set once in main() for goTo calls */
let mapViewRef = null;

/**
 * Render the executive anomaly table from lightweight data.
 * Sorts by priority_score descending so highest-risk items are at top.
 * @param {Array} anomalies — light-mode anomaly array (has priority_score)
 */
function renderExecTable(anomalies) {
  const tbody = document.getElementById("anomaly-tbody");
  const countEl = document.getElementById("exec-count");
  if (!tbody) return;

  // Sort descending by priority_score, then by address as tiebreaker
  const sorted = [...anomalies]
    .filter((a) => a.latitude != null && a.longitude != null)
    .sort((a, b) => {
      const scoreDiff = (b.priority_score ?? 0) - (a.priority_score ?? 0);
      if (scoreDiff !== 0) return scoreDiff;
      return (a.street_address || "").localeCompare(b.street_address || "");
    });

  if (countEl) {
    countEl.textContent = `${sorted.length} anomalies`;
  }

  tbody.innerHTML = sorted
    .map((a, i) => {
      const score = a.priority_score ?? 0;
      let scoreClass = "score-low";
      if (score >= 7) scoreClass = "score-high";
      else if (score >= 4) scoreClass = "score-medium";

      const typeLabel = ANOMALY_LABELS[a.anomaly_type] || a.anomaly_type;
      const badgeClass = `table-badge table-badge-${a.anomaly_type}`;

      return `<tr data-parcel-id="${a.parcel_id}"
                  data-lat="${a.latitude}"
                  data-lng="${a.longitude}">
        <td>${i + 1}</td>
        <td>${a.street_address || "—"}</td>
        <td><span class="${badgeClass}">${typeLabel}</span></td>
        <td class="${scoreClass}">${score}</td>
      </tr>`;
    })
    .join("");

  // Delegate click handler on tbody
  tbody.addEventListener("click", (e) => {
    const row = e.target.closest("tr");
    if (!row) return;
    const parcelId = row.dataset.parcelId;
    const lat = parseFloat(row.dataset.lat);
    const lng = parseFloat(row.dataset.lng);
    execRowClick(parcelId, lat, lng);
  });
}

/**
 * Handle executive table row click: fly map to coordinates + load detail.
 */
function execRowClick(parcelId, lat, lng) {
  if (!parcelId || !mapViewRef) return;

  // 1. Fly the map to the property
  mapViewRef.goTo(
    { center: [lng, lat], zoom: 17 },
    { duration: 800 }
  );

  // 2. Highlight the row
  highlightTableRow(parcelId);

  // 3. Populate the detail panel (reuses existing lazy-fetch pattern)
  handlePinClick(parcelId);
}

/**
 * Highlight a table row by parcel_id and scroll it into view.
 * Called from both row click and map pin click (bidirectional sync).
 */
function highlightTableRow(parcelId) {
  const tbody = document.getElementById("anomaly-tbody");
  if (!tbody) return;

  // Remove active class from all rows
  tbody.querySelectorAll("tr.row-active").forEach((r) => r.classList.remove("row-active"));

  if (!parcelId) return;

  // Find matching row(s) — parcel_id may appear more than once
  const target = tbody.querySelector(`tr[data-parcel-id="${parcelId}"]`);
  if (target) {
    target.classList.add("row-active");
    target.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }
}

// ---------------------------------------------------------------------------
// Click Handler: Pin click → fetch full data → populate panel
// ---------------------------------------------------------------------------

async function handlePinClick(parcelId) {
  if (!parcelId) return;

  // Mobile UX: Auto-switch to detail tab when a pin is clicked
  switchMobileTab("detail-panel");

  const panel = document.getElementById("panel-content");
  if (panel) {
    panel.innerHTML = `<div class="panel-spinner"><span class="panel-placeholder">Fetching details…</span></div>`;
  }

  try {
    const fullData = await getFullAnomalyData();

    // Find the matching anomaly by parcel_id in the full dataset
    const anomaly = fullData.anomalies.find(
      (a) => a.parcel_id === parcelId
    );

    if (!anomaly) {
      if (panel) {
        panel.innerHTML = `<p class="panel-placeholder">No detail data found for parcel ${parcelId}.</p>`;
      }
      return;
    }

    renderDetailPanel(anomaly);
  } catch (err) {
    console.error("[Vacancy Watch] Detail fetch failed:", err);
    if (panel) {
      panel.innerHTML = `<p class="panel-placeholder" style="color: #ff6b6b;">
        Failed to load details: ${err.message}
      </p>`;
    }
  }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

let arcgisGraphic = null;
let mainGraphicsLayer = null;

async function loadMapData(typeFilter = "", minScoreFilter = "") {
  showOverlay("loading", "Loading anomaly data…");

  // 1. Reset state (The Canvas Purification)
  if (mainGraphicsLayer) {
    mainGraphicsLayer.removeAll();
  }
  fullAnomalyCache = null; // Clear detail cache
  resetPanel();            // Clear the detail side panel
  
  const tbody = document.getElementById("anomaly-tbody");
  if (tbody) tbody.innerHTML = "";
  
  // 2. Build URL
  let url = ANOMALIES_LIGHT_URL;
  const params = [];
  if (typeFilter) params.push(`type=${encodeURIComponent(typeFilter)}`);
  if (minScoreFilter) params.push(`min_score=${encodeURIComponent(minScoreFilter)}`);
  if (params.length > 0) {
    url += (url.includes("?") ? "&" : "?") + params.join("&");
  }

  // 3. Fetch Data
  let lightAnomalies = [];
  try {
    const lightData = await fetchAPI(url);
    lightAnomalies = lightData.anomalies || [];

    if (lightAnomalies.length === 0) {
      showOverlay("loading", "No anomalies match the current filters.");
      document.getElementById("exec-count").textContent = "(0)";
      return;
    }

    if (arcgisGraphic && mainGraphicsLayer) {
      const graphics = buildGraphics(arcgisGraphic, lightAnomalies);
      mainGraphicsLayer.addMany(graphics);
      console.log(`[Vacancy Watch] ${graphics.length} pin(s) loaded using filter type=${typeFilter} score=${minScoreFilter}`);
    }

    // 4. Render Table
    renderExecTable(lightAnomalies);
    showOverlay("hide");
    
  } catch (err) {
    console.error("[Vacancy Watch] Failed to load filtered anomalies:", err);
    showOverlay("error", `Failed to load anomalies: ${err.message}`);
  }
}

async function main() {
  showOverlay("loading", "Initializing map…");

  // 1. Import ArcGIS modules
  const [Graphic, Map, GraphicsLayer] = await $arcgis.import([
    "@arcgis/core/Graphic.js",
    "@arcgis/core/Map.js",
    "@arcgis/core/layers/GraphicsLayer.js",
  ]);
  
  arcgisGraphic = Graphic;
  mainGraphicsLayer = new GraphicsLayer({ title: "Anomalies" });

  // 2. Grab the map element
  const mapElem = document.querySelector("arcgis-map");

  // 3. Create Map
  mapElem.map = new Map({
    basemap: "arcgis/topographic",
    layers: [mainGraphicsLayer],
  });

  // 4. Store MapView reference
  mapViewRef = mapElem.view;
  if (!mapViewRef) {
    await new Promise((resolve) => {
      mapElem.addEventListener("arcgisViewReadyChange", () => {
        mapViewRef = mapElem.view;
        resolve();
      }, { once: true });
    });
  }

  // 5. Initial Data Load
  await loadMapData();

  // 6. Wire up Filter Listeners
  const typeSelect = document.getElementById("filter-type");
  const scoreSelect = document.getElementById("filter-score");
  
  const handleFilterChange = () => {
    loadMapData(typeSelect.value, scoreSelect.value);
  };
  
  if (typeSelect) typeSelect.addEventListener("change", handleFilterChange);
  if (scoreSelect) scoreSelect.addEventListener("change", handleFilterChange);

  // 7. Wire up click handler: pin click → panel + table sync
  mapElem.addEventListener("arcgisViewClick", async (event) => {
    const view = mapElem.view;
    if (!view) return;

    const hitResult = await view.hitTest(event.detail.screenPoint);
    const graphicHit = hitResult.results.find(
      (r) => r.graphic && r.graphic.layer === graphicsLayer
    );

    if (graphicHit) {
      const parcelId = graphicHit.graphic.getAttribute("parcel_id");
      handlePinClick(parcelId);
      highlightTableRow(parcelId);   // ← Module 3.4: sync table row
    } else {
      resetPanel();
      highlightTableRow(null);       // ← clear table highlight
    }
  });
  // 7. Initialize mobile tabs
  initMobileTabs();
}

main();
