# Vacancy Watch

**Montgomery Property Anomaly Map**

Vacancy Watch is a localized, full-stack Minimum Viable Product (MVP) aimed at helping municipal operators in Montgomery, Alabama identify problematic properties. By correlating disparate civic datasets (e.g. vacant registry, code violations, active building permits) using geospatial intelligence, the system automatically detects, categorizes, and flags "anomalous" properties on an interactive dashboard.

## Vision & Primary Goal

The primary objective is to move away from static spreadsheets and manual data cross-referencing. Vacancy Watch automatically ingests data from the **City of Montgomery Open Data Portal** and processes them through an internal Correlation Engine. The frontend delivers an actionable, data-light, map-based interface built with the **ArcGIS Maps SDK for JavaScript**.

## Anomalies Detected

The platform tracks three critical types of property anomalies:

1. **🧟 Zombie Properties:** Registered vacant properties that continue to generate active, severe code violations. Indicates neglect and potential civic hazard.
2. **👻 Ghost Permits:** Properties officially marked as vacant, but possessing recently issued, active construction or electrical permits. Indicates unauthorized flipping or tax evasion.
3. **📍 Government Blind Spots:** Properties that are _not_ on the official vacancy registry, but exhibit the footprint of prolonged abandonment (no water service, overgrown vegetation citations).

## System Architecture (AWS 3-Tier Simulation)

Vacancy Watch is built simulating an enterprise 3-tier AWS architecture (S3 for frontend, EC2/API Gateway for backend, RDS for DB) running locally:

### 1. Presentation Tier (Frontend)

- **Tech Stack:** HTML5, CSS3, Vanilla JavaScript, ArcGIS Maps SDK (v5.0)
- **Concept:** Acts purely as a dumb client. It loads the map, fetches lightweight point data from the API, and renders custom `picture-marker` SVG pins.
- **Features:** Synchronized executive table, mobile responsive tabs, dynamic UI filtering, and lazy-loading of heavy detail records only when specific pins are interacted with.

### 2. Logic Tier (Backend/API)

- **Tech Stack:** Pure PHP
- **Concept:** Strict separation of concerns. The backend _never_ renders HTML; it is only responsible for exposing JSON payloads (`api/get_anomalies.php`) and running ETL scripts.
- **Cache Layer:** To protect the database from public traffic, the API serves a static, pre-computed `anomalies_cache.json` file. Direct DB connections on public endpoints are strictly forbidden.

### 3. Data Tier (Database)

- **Tech Stack:** MySQL
- **Concept:** Handles all heavy filtering and spatial processing. Features generated spatial columns (`geom_location POINT GENERATED ALWAYS AS ... STORED`) to ensure geospatial integrity at the hardware level.

## Setup & Installation

**Prerequisites:** PHP 8+, MySQL 8+, and a local web server (XAMPP/MAMP).

1. **Clone & Configure:**
   - Clone the repository into your local server's web root (e.g., `htdocs/vacancy-watch`).
   - Copy `.env.example` to `.env` and fill in your local MySQL credentials.
   - Insert your `ARCGIS_API_KEY` into the `.env` file.

2. **Database Initialization:**
   - Run the provided `schema.sql` (if available) to build the `properties` and `code_violations` table structures.

3. **Data Sync & Anomaly Generation:**
   - Open a terminal and navigate to the project root.
   - Run the data fetcher from Montgomery Open Data:
     ```bash
     php backend/scripts/fetch_montgomery.php
     ```
   - Run the correlation engine to crunch the data, generate scores, and publish the JSON cache:
     ```bash
     php backend/scripts/generate_anomalies.php
     ```

4. **Launch Application:**
   - Start your local web server.
   - Access the API at `http://localhost/vacancy-watch/backend/api/get_anomalies.php` to verify the cache is readable.
   - Access the Frontend at `http://localhost/vacancy-watch/frontend/index.html` (Use a designated local port like `5500` if simulating S3 CORS rules).

## Performance & Hardening

The project features several enterprise-grade optimizations:

- **Streaming ETL Disk Writes:** Overcomes PHP integer RAM limits by streaming incoming JSON pages directly into flat storage files instead of holding giant arrays in memory.
- **Atomic Cache Writes:** Prevents API reading collisions via temp-file buildup and native `rename()` transactions.
- **Dynamic Time Anchoring:** Prevents "Null Traps" on empty databases or delayed external syncs by anchoring all time-based anomaly algorithms to `MAX(date_filed)` instead of `CURDATE()`.
- **Zero I/O API Environment:** Reduces server overhead by prioritizing memory-based `getenv()` calls for variables like CORS over parsing localized `.env` files.

---

_Built following the strict architectural guidelines defined in `agent.md`._
