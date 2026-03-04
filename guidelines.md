### Local Technology Stack (3-Tier Simulation)

**Tier 1: Presentation (Local Frontend)**

- **Technology:** HTML, CSS, Vanilla JavaScript, ArcGIS Maps SDK for JavaScript.
- **AWS S3 Simulation:** Do not place these _files_ in the same _backend_ folder. Run a separate local static server (e.g., using the Live Server extension in VS Code, or `python -m http.server`). This _frontend_ application must only communicate with the _backend_ via HTTP requests (`fetch` API), exactly as it would when _hosted_ on S3 later.

**Tier 2: Logic (Local Backend & API)**

- **Technology:** Pure PHP (or Node.js).
- **AWS EC2 Simulation:** Use environments like XAMPP, MAMP, or Docker.
- **Absolute Rule:** This layer is **strictly forbidden** from rendering HTML. Your local _backend_ must only receive data requests, execute queries, and spit out responses in pure JSON format. This is the brain of your engine, not an interface builder.

**Tier 3: Data (Local Database)**

- **Technology:** MySQL.
- **AWS RDS Simulation:** Use a MySQL engine running in XAMPP/MAMP or a local Docker container.
- **Absolute Rule:** Local _database_ credentials (`root`, `localhost`) must not be _hardcoded_ directly into PHP/Node.js code. Use an environment variable configuration _file_ (`.env`). When you move to AWS later, you will only need to replace the contents of this `.env` _file_ with your RDS address without touching a single line of logic code.

--

### Development Guidelines (Mindset Shift)

1. **Stop Relying on UI for Logic:** Do not filter anomalies using JavaScript in the _frontend_. If your local _database_ has 5,000 rows of property data, do not send those 5,000 rows to the _browser_. Use SQL queries (`WHERE`, `JOIN`, `GROUP BY`) in the _backend_ to shrink the data down to just 50 anomaly points, then send the rest to the _frontend_.
2. **Prepare Mock Data if API Fails:** Government APIs sometimes go down or change their format. For unhindered local development, save one sample JSON response from the Montgomery portal locally. Build your MySQL table structure based on this static sample first before trying to connect it _live_.
3. **Port Discipline:** Ensure the _frontend_ runs on a different port (e.g., 5500) than the _backend_ (e.g., 8080). This forces you to handle CORS (Cross-Origin Resource Sharing) issues from day one locally. If you ignore CORS now, your application will break when deployed to separate cloud infrastructure.
