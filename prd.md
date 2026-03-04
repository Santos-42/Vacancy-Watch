### Product Requirements Document (PRD) - Local Phase

**1. MVP Vision (Minimum Viable Product)**
Build a "Vacancy Watch" prototype that runs entirely on a local machine, capable of pulling data from Montgomery Open Data via the internet, storing it into a local MySQL database, finding cross-anomalies (Vacant vs. Violations/Construction), and displaying them on a local web map.

**2. Core Modules & Local Mechanism**

- **Data Ingestion Module (Sync Script):**
- Since you don't have an automated trigger function yet (AWS _cron job_), create a dedicated _backend_ script (e.g., `sync_montgomery.php`).
- When you execute this script in a _browser_ or local terminal, the script will pull JSON data from the Montgomery public API, format it, and perform _INSERT/UPDATE_ operations into your local MySQL table.

- **Anomaly Correlation Engine (Internal API Endpoint):**
- Create an endpoint (e.g., `api/get_anomalies.php`).
- This script runs heavy SQL queries (JOIN) in local MySQL to find: "Vacant" properties that intersect with addresses having the most "Code Violations".
- Output: Only returns JSON data containing a list of addresses, _latitude/longitude_ coordinates, and anomaly types.

- **Visual Dashboard (Local Client):**
- A single `index.html` page that loads the ArcGIS base map.
- A JavaScript script calls `localhost/api/get_anomalies.php`, fetches JSON data, and renders pins on the map based on those coordinates.
