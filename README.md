# Vacancy Watch

**Autonomous Data Intelligence Engine for Municipal Oversight**

Vacancy Watch is not just a map with red dots. It is an autonomous data intelligence engine that silently monitors municipal negligence, analyzes bureaucratic failures, and presents them as actionable targets for investigation.

Built on a highly optimized, free-tier cloud infrastructure, the system automatically pulls data from the **City of Montgomery Open Data Portal**, processes it through a strict correlation engine, and delivers a data-light, map-based interface using the **ArcGIS Maps SDK for JavaScript**.

---

## 🎯 The Anomalies (Intersections of Negligence)

The system does not simply dump thousands of vacant records onto a screen. It filters the noise and prioritizes the most critical intersections of negligence, scoring them from 1 to 10:

1. **👻 Ghost Permits:** Construction or electrical permits actively issued on properties that are flagged for legal violations.
2. **🧟 Zombie Properties:** Registered vacant homes left to decay, accumulating severe code violations with no real action taken.
3. **📍 Government Blind Spots:** City-owned assets that violate the city's own municipal codes.

By mathematically filtering out thousands of irrelevant data points, the system presents a heavily curated list of maximum 50 highly critical intelligence targets.

---

## 🏗️ Architectural Pillars

The architecture is designed to be resilient, efficient, and fully autonomous, bypassing the limitations of free-tier cloud resources.

### 1. The Harvester (Extraction Pillar)

Rather than overloading fragile server memory, the ETL pipeline utilizes **Data Streaming** and `UPSERT` operations into the **Aiven MySQL** database. It aggressively pulls thousands of rows (Vacant Properties, Building Permits, Code Violations, City Assets) but only updates what has changed, ensuring maximum database efficiency and zero memory overload.

### 2. The Brain (Analytics Pillar)

This is the core correlation engine (`generate_anomalies.php`). It calculates the Priority Score, throws away noise, and focuses purely on actionable intelligence.

### 3. The Cache & Auto-Heal (Defense Pillar)

The frontend visitors **never** interact directly with the database. The API serves a single, static JSON file (`anomalies_cache.json`).

- **Resilience:** If the application goes viral, the traffic is absorbed entirely by **Vercel's** CDN edge network.
- **Auto-Healing:** If the backend (**Render**) wipes the temporary file due to a reboot ("Docker Amnesia"), an auto-heal mechanism forces a silent, fraction-of-a-second recalculation to generate a new cache and heal the system.

### 4. The Ghost Operator (Autonomy Pillar)

Fully automated without human intervention or paid server instances. A secure backdoor (`trigger.php` with a secret key) is delegated to a third-party service (**cron-job.org**). It wakes up weekly, harvests the previous days' bureaucratic data, updates the cache, and goes back to sleep.

---

## 🚀 Infrastructure & Deployment

- **Frontend:** [Vercel](https://vercel.com) (Static HTML/JS hosting, Edge network caching)
- **Backend API & ETL:** [Render](https://render.com) (PHP Web Service)
- **Database:** [Aiven](https://aiven.io) (Managed MySQL)
- **Automation Trigger:** [cron-job.org](https://cron-job.org)

---

## 💻 Setup & Installation (Local Development)

**Prerequisites:** PHP 8+, MySQL 8+, and a local web server (XAMPP/MAMP).

1. **Clone & Configure:**
   - Clone the repository into your local server's web root.
   - Copy `.env.example` to `.env` and configure your local MySQL credentials.
   - Insert your `ARCGIS_API_KEY` into the `.env` file.

2. **Database Initialization:**
   - Run the provided `schema.sql` (if available) to build the required tables.

3. **Data Sync & Anomaly Generation:**
   - Run the data fetcher to harvest Montgomery Open Data:
     ```bash
     php backend/scripts/fetch_montgomery.php
     ```
   - Run the correlation engine to crunch data and publish the JSON cache:
     ```bash
     php backend/scripts/generate_anomalies.php
     ```

4. **Launch Application:**
   - Access the API at `https://vacancy-watch.onrender.com/backend/api/get_anomalies.php` to verify the cache output.
   - Access the Frontend dashboard at `https://vacancy-watch.vercel.app`.

---

_Developed as an autonomous data intelligence tool designed to produce lethal, actionable insights._
