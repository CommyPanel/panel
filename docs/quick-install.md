# Ready-made apps

Besides your own code, **Apps > New app > A ready-made app** installs software you only need to run: **Meilisearch**, **Typesense**, **Grafana**, **Elasticsearch**, **Kibana**, **Uptime Kuma**, **Mailpit**, **Gotenberg**, **Redis**, **n8n** and **Garage**. You pick the app from the list (with its logo); a short guide appears under it with what CommyPanel does for you, what to do once it runs (such as creating the first account) and how to upgrade it. You then choose a name, optionally add a domain, and CommyPanel builds and starts it like any other app: the same releases, rollbacks, logs, variables, volumes, domains and HTTPS.

Each one runs the **official image** at a version CommyPanel pins (shown on the app's page), so a new CommyPanel release never changes the version under your data by itself. Their data lives in a persistent volume, which survives deployments and rollbacks.

| App           | Image                                                  | Port | Data volume                     | Memory limit |
| ------------- | ------------------------------------------------------ | ---- | ------------------------------- | ------------ |
| Meilisearch   | `getmeili/meilisearch:v1.15`                           | 7700 | `/meili_data`                   | default      |
| Typesense     | `typesense/typesense:28.0`                             | 8108 | `/data`                         | default      |
| Grafana       | `grafana/grafana:12.2.0`                               | 3000 | `/var/lib/grafana`              | default      |
| Elasticsearch | `docker.elastic.co/elasticsearch/elasticsearch:8.19.0` | 9200 | `/usr/share/elasticsearch/data` | 2048 MB      |
| Kibana        | `docker.elastic.co/kibana/kibana:8.19.0`               | 5601 | none                            | 2048 MB      |
| Uptime Kuma   | `louislam/uptime-kuma:2.5.11`                          | 3001 | `/app/data`                     | default      |
| Mailpit       | `axllent/mailpit:v1.33`                                | 8025 | `/data`                         | default      |
| Gotenberg     | `gotenberg/gotenberg:9.5`                              | 3000 | none                            | 1024 MB      |
| Redis         | `redis:8.10`                                           | 6379 | `/data`                         | default      |
| n8n           | `docker.n8n.io/n8nio/n8n:3.2.2`                        | 5678 | `/home/node/.n8n`               | 2048 MB      |
| Garage        | `dxflrs/garage:v2.5.0`                                 | 3900 | `/data`                         | default      |

## Passwords and keys

A password or key is generated for each app and stored encrypted, like the app's other variables. Nobody sees it on the create page. A person who holds the **See quick-install passwords** permission presses **Show access details** on the app's page; that asks for their password and is recorded in the audit log. The values are the ones the app is set to run with: if you change a password inside the app itself (for example Grafana's), the page keeps showing the original.

- **Meilisearch**: the master key (`MEILI_MASTER_KEY`). Send it as `Authorization: Bearer <key>`; create narrower keys through its API for your apps.
- **Typesense**: the API key (`TYPESENSE_API_KEY`), sent as the `X-TYPESENSE-API-KEY` header.
- **Grafana**: user `admin` and a generated password.
- **Elasticsearch**: user `elastic` and a generated password. Security is on; nothing is reachable without it.
- **Kibana**: sign in with the `elastic` user and the password of its Elasticsearch app.
- **Uptime Kuma**: no generated password. The first person to open it creates the admin account, so open it right after it is running.
- **Mailpit**: user `admin` and a generated password for the web inbox.
- **Gotenberg**: user `gotenberg` and a generated password for its API (HTTP basic authentication).
- **Redis**: a generated password. Redis has **no web address**: the app takes no domain and is reached only by linked apps.
- **n8n**: the first person to open it creates the owner account. A generated encryption key (`N8N_ENCRYPTION_KEY`) protects the credentials you save in n8n; keep a copy of it, because without it they cannot be read again.
- **Garage**: a generated access key and secret key, and a first bucket called `main`.

## Reaching them from your own apps

The apps listen on a private port, so use the **Links** tab of your app to link it to the search app: your app then gets `<PREFIX>_URL`, `<PREFIX>_HOST` and `<PREFIX>_PORT` variables and reaches it over a private network, by the app's name (for example `http://search:7700`). Nothing is opened to the internet. To reach one from a browser (Grafana, Kibana, a search API used by a web page), add a domain to it.

## What a linked app is given

Linking an app to a ready-made app adds `<PREFIX>_URL`, `<PREFIX>_HOST` and `<PREFIX>_PORT` as above, and for some of them more (your own variables with the same name still win):

| Linked to | Extra variables                                                                                                  | Use                                                                                                                              |
| --------- | ---------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Redis     | `<PREFIX>_URL` is `redis://:<password>@<name>:6379`, plus `<PREFIX>_PASSWORD`                                    | Point your Redis client at the URL.                                                                                              |
| Mailpit   | `<PREFIX>_SMTP_HOST`, `<PREFIX>_SMTP_PORT` (1025)                                                                | Send mail to that SMTP address with no login and no TLS; it stays inside the server. The web inbox is `<PREFIX>_URL`.            |
| Garage    | `<PREFIX>_ACCESS_KEY_ID`, `<PREFIX>_SECRET_ACCESS_KEY`, `<PREFIX>_REGION` (`garage`), `<PREFIX>_BUCKET` (`main`) | Use any S3 client with the endpoint `<PREFIX>_URL`, path-style addressing and that region. The key may also create more buckets. |
| Gotenberg | `<PREFIX>_USER`, `<PREFIX>_PASSWORD`                                                                             | Call its API at `<PREFIX>_URL` with basic authentication, for example `POST /forms/chromium/convert/html`.                       |

## Redis and Garage: upgrade by stopping first

Two Redis servers, or two Garage nodes, must never write to the same data. A normal deployment starts the new version next to the old one, so these two refuse to start while the old one still runs and the deployment fails with "Another Redis (Garage) is still using /data" in its log; the old version keeps serving. To upgrade, or to change their variables, **stop the app first** (Settings > Stop), then deploy again; the data is kept. Redis is checked with a PING instead of an HTTP request, because it does not speak HTTP.

## Garage

- One node, no replication, so keep backups of its volume (Volumes tab). It has no web interface; the access key can create buckets itself, and the app's terminal has the `garage` command for the rest (`garage bucket list`).
- It needs no domain. Add one only if browsers or other servers must reach the S3 address; then use path-style URLs.
- MinIO is not offered: its community edition is archived and no longer ships images.

## Mailpit, Gotenberg and n8n

- **Mailpit** keeps up to 5,000 messages. Give your test apps its SMTP address instead of a real mail server; nothing is delivered outside the server.
- **Gotenberg** runs a browser and an office suite and is given 1 GB of memory. Link it to the apps that use it and leave it without a domain; its API is behind a password either way.
- **n8n** only lets you sign in over HTTPS, so add a domain and wait for the certificate. Then add the variable `WEBHOOK_URL` (`https://` and the domain, ending with a slash) on the Variables tab and apply, so webhook addresses are shown correctly.

## Elasticsearch

- **Memory.** It runs one node with a 1 GB Java heap and a 2 GB limit for the container, so give the server about 2 GB to spare. On a smaller server the system may stop other apps. The limit can be changed on the app's Settings tab (keep it at least twice the heap in `ES_JAVA_OPTS`).
- **`vm.max_map_count`.** Elasticsearch prefers the host setting `vm.max_map_count=262144`, which a container cannot change. CommyPanel's Elasticsearch **starts without it**, because memory mapping is switched off in its settings, but reads its files more slowly. For the best speed run `sudo sysctl -w vm.max_map_count=262144` and add `vm.max_map_count=262144` to `/etc/sysctl.d/99-elasticsearch.conf`.
- **Start time.** The first start can take a minute or two; the deployment waits up to four minutes.
- It is a single node, with no replicas and no snapshots set up. Back up its volume (Volumes tab) if the data matters.

## Kibana

Choose one of your finished Elasticsearch apps when you install Kibana. CommyPanel links the two over a private network, points Kibana at that app and gives it its own `kibana_system` user: Kibana refuses to run as `elastic`. A small script inside the Kibana container sets that user's password through Elasticsearch's security API before Kibana starts, using the `elastic` password handed over **for the first start only**; that variable is deleted once Kibana has gone live.

- Elasticsearch has to be **running** when Kibana starts for the first time. If it is not, the deployment waits up to six minutes and then fails with a clear line in its log; start Elasticsearch and deploy again.
- Kibana needs about 1 GB of memory and several minutes for its first start; the deployment waits up to about seven minutes.
- If you delete the Elasticsearch app, Kibana loses its connection.

## Limits

- Updating the pinned versions is done by a CommyPanel release, never automatically. Data formats can change between major versions of these programs, so read their upgrade notes before moving a new CommyPanel release's version over existing data.
- Only the listed apps exist; more recipes are added in CommyPanel releases. Your own code is still deployed from Git or an upload.
