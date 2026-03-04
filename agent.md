# "Vacancy Watch" Development Guidelines (Local Phase)

This document is the absolute guideline for all code writing in the **Vacancy Watch** project. Every line of code written must adhere to the architectural rules and constraints set out below.

> **MANDATORY:** Before writing any code that interacts with external APIs, SDKs, or data sources, you **must** first read [`documentation.md`](file:///d:/Vacancy%20Watch/documentation.md). That file is the **single source of truth** for all API endpoint URLs, SDK documentation links, and data portal references used in this project. Never hardcode or assume API URLs — always pull them from `documentation.md`.
>
> Current references in `documentation.md`:
>
> - **ArcGIS Maps SDK for JavaScript** — Frontend map rendering library (tutorials, samples, components)
> - **ArcGIS REST APIs** — Backend data querying format (Feature Service `/query` endpoint)
> - **City of Montgomery Open Data Portal** — Primary data source for all datasets (ArcGIS Hub)

## 1. Vision & Primary Goal

Build a Minimum Viable Product (MVP) prototype that runs entirely in a local environment. The system will pull data from Montgomery Open Data, store it in MySQL, process anomalies (vacant properties vs. violations/construction), and display them on a local web map using ArcGIS.

## 2. Technology Stack & Architecture (AWS 3-Tier Simulation)

This project simulates a 3-tier AWS architecture locally. Components must be strictly separated:

### Tier 1: Presentation (Frontend)

- **Technology:** HTML, CSS, Vanilla JavaScript, ArcGIS Maps SDK for JavaScript.
- **Simulation (S3):** The frontend must run on a separate static server (e.g., port `5500`).
- **Rules:** Do not mix frontend _files_ into the backend directory. Communication with the backend must only go through HTTP Requests (`fetch` API).

### Tier 2: Logic (Backend & API)

- **Technology:** Pure PHP (or Node.js).
- **Environment (EC2 Simulation):** XAMPP, MAMP, or Docker (e.g., port `8080`).
- **Absolute Rule:** The backend is **STRICTLY FORBIDDEN** from rendering HTML. It is only tasked with receiving data requests, executing queries, and returning responses in **pure JSON** format, no exceptions.

### Tier 3: Data (Database)

- **Technology:** MySQL.
- **Environment (RDS Simulation):** MySQL via XAMPP/MAMP or a Docker container.
- **Absolute Rule:** It is **strictly forbidden to _hardcode_** _database_ credentials (`root`, `localhost`, _password_) in PHP/Node.js scripts. All environment variable configurations must use a `.env` _file_.

## 3. Core Modules

1. **Data Ingestion Script (Synchronization):**
   - Example: `sync_montgomery.php` / `sync.js`.
   - Task: Pull JSON data from the Montgomery public API, format the data, and perform `INSERT`/`UPDATE` operations into local MySQL. The script is executed manually via HTTP or a local terminal.

2. **Anomaly Correlation Engine (Internal API):**
   - Example Endpoint: `api/get_anomalies.php`.
   - Task: Run heavy SQL queries (`JOIN`) directly on the _database_ to look for data intersections (Vacant properties that have multiple code violations).
   - Output: Returns a compact JSON format containing addresses, coordinates (_latitude_/_longitude_), and anomaly types.

3. **Visual Dashboard (Client):**
   - Main file: `index.html`.
   - Task: Load the ArcGIS Base Map, call the anomaly _endpoint_ via _fetch_, and render _pins_ (location points) based on coordinates received from the backend JSON.

## 4. Coding Best Practices Rules

1. **Filtering Load on Database (Not Frontend/UI):**
   Never send thousands of rows of data to the client interface and then filter it using JavaScript. Use SQL queries (`WHERE`, `JOIN`, `GROUP BY`) optimally to filter anomalies in the backend. The frontend only receives the final JSON _payload_.

2. **Readiness of Mock Data:**
   Always prepare one static JSON sample (saved locally) as _mock data_ from the Montgomery portal API response. Use this sample to build the MySQL table schema precisely in the early stages and to overcome third-party _downtime_.

3. **Port Discipline & CORS Handling:**
   The Frontend and Backend are **required** to run on two different ports from the very first commit. Developers must solve CORS (Cross-Origin Resource Sharing) challenges via _headers_ on the local API responses, so identical code can be directly moved to separate infrastructure (e.g., AWS S3 and EC2) without logic breakage.

## 5. Performance Architecture (Non-Negotiable)

### 5.1 Generated Columns (Database-Level Integrity)

The `geom_location` column in the `properties` table must be a **`GENERATED ALWAYS AS ... STORED`** column. MySQL auto-computes the `POINT` geometry from `latitude`/`longitude` on every `INSERT`/`UPDATE`. **No application code may manually set this column.** This eliminates human error entirely.

```sql
geom_location POINT GENERATED ALWAYS AS (ST_SRID(POINT(longitude, latitude), 4326)) STORED NOT NULL
```

### 5.2 JSON Caching Layer (Backend Buffer)

The architecture is **not** `Frontend → Backend → Database` on every request. A scheduled script (cron job) must run nightly to:

1. Execute all heavy anomaly-correlation SQL queries.
2. Write the final result to a static `anomalies_cache.json` file on the server.

The public-facing API endpoint (`api/get_anomalies.php`) **reads and serves this cached JSON file only** — it must never hit MySQL directly. This drops response time from ~2s to ~10ms and keeps server CPU at ~1%.

### 5.3 Mandatory Query Limits (Pagination & Radius Cap)

Every SQL query that returns results to a client **must** include:

- **`LIMIT 50`** (or a configurable ceiling) — unbounded result sets are forbidden.
- **Maximum radius of 1 km** for spatial proximity searches (`ST_Distance_Sphere`).

Any endpoint or script missing these safeguards is considered a production defect.
