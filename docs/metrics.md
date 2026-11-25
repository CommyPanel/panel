# Metrics for Prometheus and Grafana

CommyPanel can tell a Prometheus how busy the server and its containers are, so you can chart them in Grafana, on this server or on any other. The panel publishes the numbers at **`/api/metrics`**; a Prometheus collects them on a schedule (it pulls, nothing is pushed). Nothing new runs on your server and no container gets access to Docker.

## What you get

All values are gauges. Names and numbers only: no variables, logs, addresses or secrets.

| Metric                                                                                   | Meaning                                            |
| ---------------------------------------------------------------------------------------- | -------------------------------------------------- |
| `commypanel_info{version}`                                                               | the CommyPanel version (always 1)                  |
| `commypanel_agent_up`                                                                    | 1 when the host agent answered, 0 when it did not  |
| `commypanel_host_cpu_usage_ratio`, `commypanel_host_cpus`                                | how busy the processors are (0 to 1), and how many |
| `commypanel_host_memory_total_bytes`, `commypanel_host_memory_used_bytes`                | the server's memory                                |
| `commypanel_host_disk_total_bytes`, `commypanel_host_disk_used_bytes`                    | the disk that holds the data                       |
| `commypanel_host_network_receive_bytes_per_second`, `..._transmit_bytes_per_second`      | the server's network traffic                       |
| `commypanel_container_cpu_usage_ratio{container,app,kind}`                               | CPU of one container, where 1 is one full core     |
| `commypanel_container_memory_used_bytes`, `commypanel_container_memory_limit_bytes`      | memory of one container, and its limit             |
| `commypanel_container_network_receive_bytes_per_second`, `..._transmit_bytes_per_second` | network traffic of one container                   |
| `commypanel_app_up{app}`                                                                 | 1 when the app is running                          |
| `commypanel_app_status_info{app,status}`                                                 | the app's state in the label (always 1)            |
| `commypanel_app_certificate_expiry_timestamp_seconds{app}`                               | when the app's HTTPS certificate ends (Unix time)  |
| `commypanel_database_server_up{server,engine,version}`                                   | 1 when the database server is running              |

`kind` is `app`, `database` or `other`. CPU and network values are the same readings the Usage tab shows, taken from the host agent and reused for 10 seconds, so several scrapers never cost more than one. Database internals (queries per second, connections) are not included.

## Make a token

In **Settings > API tokens** make a token with the single ability **Read server metrics** (`metrics:read`). It needs the **View the server** permission, like the Server page. A token that is limited to some apps only sees those apps' series: the server's own, the database servers' and the containers that belong to no app need a token that is not limited. Give it an expiry and a name such as `prometheus`; revoke it there at any time.

## Point a Prometheus at it

```yaml
scrape_configs:
    - job_name: commypanel
      scheme: https
      metrics_path: /api/metrics
      scrape_interval: 15s
      authorization:
          credentials: cpt_your_token_here
      static_configs:
          - targets: ['panel.example.com']
```

Prefer `credentials_file` so the token is not in the configuration file. The panel limits a token to 60 calls a minute; every 15 seconds is 4. A wrong or missing token is refused with a `401` and counts toward the failed-attempts limit.

Then add that Prometheus as a data source in Grafana. A Grafana on another server works the same way: it only needs to reach your Prometheus.

## Good to know

- The data is only as detailed as the agent's readings: CPU and network are rates over the last few seconds, not counters, so use them as they are (no `rate()`).
- An app that is stopped, deactivated or failing shows `commypanel_app_up 0`; its container series disappear while it has no container.
- If the host agent is down, the panel still answers with `commypanel_agent_up 0` and the app and database server states, so you can alert on it.
- Collecting metrics is not recorded in the audit log (it would fill it). Making and revoking the token is.
