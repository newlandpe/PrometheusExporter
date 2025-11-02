# PrometheusExporter for PocketMine-MP

PrometheusExporter exposes basic PocketMine-MP metrics over an HTTP endpoint that Prometheus can scrape. It provides live telemetry for players, TPS, tick usage, and PHP memory, and keeps cumulative counters in `plugin_data` so joins/quits survive restarts.

## Features

- Lightweight HTTP server serving `/metrics` in Prometheus text format
- Gauges for online players, TPS (current & average), tick usage, and PHP memory stats
- Counters for player joins/quits with last join ping (persisted across restarts)
- Configurable bind address, port, tick interval, and memory metrics toggle
- No external extensions required; uses PHP stream sockets and PocketMine scheduler

## Installation

Follow these quick steps to get the exporter running on your server:

1. Copy this folder into your PocketMine-MP `plugins/` directory as `PrometheusExporter`.
2. Ensure the plugin directory layout:
   ```
   plugins/PrometheusExporter/
     |-- plugin.yml
     |-- resources/config.yml
     `-- src/ChernegaSergiy/PrometheusExporter/*.php
   ```
3. Start or reload PocketMine-MP. The console should log:
   ```
   [PrometheusExporter] Prometheus endpoint on http://0.0.0.0:9100/metrics
   ```
4. Visit `http://<server-ip>:9100/metrics` to confirm Prometheus text output.

## Configuration

`resources/config.yml` is copied to `plugin_data/PrometheusExporter/config.yml` on first run.

```yaml
exporter:
  address: 0.0.0.0   # Interface to bind the exporter
  port: 9100         # HTTP port for /metrics
  tick_interval: 20  # Scheduler ticks between metric refreshes (20 ticks ≈ 1s)
  socket_backlog: 16 # Backlog for pending TCP connections
metrics:
  include_memory_details: true # Toggle PHP memory gauges
```

After changing config values, reload or restart the server to apply them.

## Persistence

The plugin stores counters in `plugin_data/PrometheusExporter/metrics.json`. It updates automatically whenever joins/quits occur and on clean shutdown. Delete this file if you want to reset the counters.

## Exposed Metrics

Metric names follow `pocketmine_` prefix. Current set:

- `pocketmine_players_online` (gauge)
- `pocketmine_joins_total` (counter)
- `pocketmine_quits_total` (counter)
- `pocketmine_last_join_ping_ms` (gauge, optional if no joins yet)
- `pocketmine_tps_current` (gauge)
- `pocketmine_tps_average` (gauge)
- `pocketmine_tick_usage_current` (gauge)
- `pocketmine_tick_usage_average` (gauge)
- `pocketmine_memory_usage_bytes` (gauge, optional)
- `pocketmine_memory_real_bytes` (gauge, optional)
- `pocketmine_memory_peak_bytes` (gauge, optional)

## Prometheus Setup (Docker example)

Once the plugin is live, connect it to Prometheus as follows:

1. Edit your Prometheus config (`prometheus.yml`) and add:
   ```yaml
   scrape_configs:
     - job_name: "pocketmine"
       scrape_interval: 15s
       static_configs:
         - targets: ["<host-ip>:9100"]
   ```
2. Run Prometheus (host networking ensures access to localhost metrics):
   ```bash
   docker run -d \
     --name prometheus \
     --network host \
     -v /path/to/prometheus.yml:/etc/prometheus/prometheus.yml:ro \
     -v prometheus_data:/prometheus \
     prom/prometheus
   ```
3. Open `http://localhost:9090`, check **Status → Targets** for job `pocketmine` (state `UP`).
4. Run queries such as `pocketmine_players_online` or `pocketmine_tps_current`.

> [!TIP]
> Bind `exporter.address` to `127.0.0.1` when Prometheus runs on the same host, or keep `0.0.0.0` if the endpoint should be reachable across the network. To add more stats, extend `MetricsStore::update()` and `MetricsStore::render()`.

## Contributing

Contributions are welcome and appreciated! Here's how you can contribute:

1. Fork the project on GitHub.
2. Create your feature branch (`git checkout -b feature/AmazingFeature`).
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`).
4. Push to the branch (`git push origin feature/AmazingFeature`).
5. Open a Pull Request.

Please make sure to update tests as appropriate and adhere to the existing coding style.

## License

This project is licensed under the CSSM Unlimited License v2.0 (CSSM-ULv2). Please note that this is a custom license. See the [LICENSE](LICENSE) file for details.
