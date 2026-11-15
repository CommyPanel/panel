# Ready-made apps

Besides your own code, **Apps > New app > A ready-made app** installs software you only need to run: **Meilisearch**, **Typesense**, **Grafana**, **Elasticsearch** and **Kibana**. You choose the app and a name, optionally add a domain, and CommyPanel builds and starts it like any other app: the same releases, rollbacks, logs, variables, volumes, domains and HTTPS.

Each one runs the **official image** at a version CommyPanel pins (shown on the app's page), so a new CommyPanel release never changes the version under your data by itself. Their data lives in a persistent volume, which survives deployments and rollbacks.

| App           | Image                                                  | Port | Data volume                     | Memory limit |
| ------------- | ------------------------------------------------------ | ---- | ------------------------------- | ------------ |
| Meilisearch   | `getmeili/meilisearch:v1.15`                           | 7700 | `/meili_data`                   | default      |
| Typesense     | `typesense/typesense:28.0`                             | 8108 | `/data`                         | default      |
| Grafana       | `grafana/grafana:12.2.0`                               | 3000 | `/var/lib/grafana`              | default      |
| Elasticsearch | `docker.elastic.co/elasticsearch/elasticsearch:8.19.0` | 9200 | `/usr/share/elasticsearch/data` | 2048 MB      |
| Kibana        | `docker.elastic.co/kibana/kibana:8.19.0`               | 5601 | none                            | 2048 MB      |

## Passwords and keys

A password or key is generated for each app and stored encrypted, like the app's other variables. Nobody sees it on the create page. A person who holds the **See quick-install passwords** permission presses **Show access details** on the app's page; that asks for their password and is recorded in the audit log. The values are the ones the app is set to run with: if you change a password inside the app itself (for example Grafana's), the page keeps showing the original.

- **Meilisearch**: the master key (`MEILI_MASTER_KEY`). Send it as `Authorization: Bearer <key>`; create narrower keys through its API for your apps.
- **Typesense**: the API key (`TYPESENSE_API_KEY`), sent as the `X-TYPESENSE-API-KEY` header.
- **Grafana**: user `admin` and a generated password.
- **Elasticsearch**: user `elastic` and a generated password. Security is on; nothing is reachable without it.
- **Kibana**: sign in with the `elastic` user and the password of its Elasticsearch app.

## Reaching them from your own apps

The apps listen on a private port, so use the **Links** tab of your app to link it to the search app: your app then gets `<PREFIX>_URL`, `<PREFIX>_HOST` and `<PREFIX>_PORT` variables and reaches it over a private network, by the app's name (for example `http://search:7700`). Nothing is opened to the internet. To reach one from a browser (Grafana, Kibana, a search API used by a web page), add a domain to it.

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
- Only the five listed apps exist; more recipes are added in CommyPanel releases. Your own code is still deployed from Git or an upload.
