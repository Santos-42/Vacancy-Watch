/**
 * =============================================================================
 * Vacancy Watch — Frontend Application Logic (Module 3.2)
 * =============================================================================
 * 1. Fetches lightweight anomaly data (?mode=light) to render map pins
 * 2. On pin click: lazily fetches FULL anomaly data (with details), caches it,
 *    then populates the Executive Detail Panel with investigative context
 *
 * Data flow:
 *   Pin load  → GET ?mode=light  → coordinates only → fast map render
 *   Pin click → GET (full)       → details object   → sidebar panel
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
// Click Handler: Pin click → fetch full data → populate panel
// ---------------------------------------------------------------------------

async function handlePinClick(parcelId) {
  if (!parcelId) return;

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

async function main() {
  showOverlay("loading", "Loading anomaly data…");

  // 1. Import ArcGIS modules
  const [Graphic, Map, GraphicsLayer] = await $arcgis.import([
    "@arcgis/core/Graphic.js",
    "@arcgis/core/Map.js",
    "@arcgis/core/layers/GraphicsLayer.js",
  ]);

  // 2. Grab the map element
  const mapElem = document.querySelector("arcgis-map");

  // 3. Create GraphicsLayer + Map
  const graphicsLayer = new GraphicsLayer({ title: "Anomalies" });
  mapElem.map = new Map({
    basemap: "arcgis/topographic",
    layers: [graphicsLayer],
  });

  // 4. Fetch lightweight data for pins
  try {
    const lightData = await fetchAPI(ANOMALIES_LIGHT_URL);

    if (lightData.anomalies.length === 0) {
      showOverlay("loading", "No anomalies found. Run the anomaly generator first.");
      return;
    }

    const graphics = buildGraphics(Graphic, lightData.anomalies);
    graphicsLayer.addMany(graphics);

    console.log(
      `[Vacancy Watch] ${graphics.length} pin(s) loaded (cache: ${lightData.generated_at})`
    );
    showOverlay("hide");
  } catch (err) {
    console.error("[Vacancy Watch] Failed to load anomalies:", err);
    showOverlay(
      "error",
      `Failed to load anomalies: ${err.message}. Is the backend running on port 8080?`
    );
    return;
  }

  // 5. Wire up click handler: pin click → panel population
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
    } else {
      resetPanel();
    }
  });
}

main();
