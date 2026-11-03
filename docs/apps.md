# Running apps

How a deployed app behaves and what you can do with it from its page.

## Private repositories

CommyPanel needs read access to a private repository. Use one of these:

- **Deploy key.** CommyPanel creates an SSH key; add the public half to the repository as a read-only deploy key. Deployments then clone over SSH.
- **Access token.** Create a read-only token on your Git host and paste it under Settings > Access token (or in step 2 of the setup). CommyPanel tests it against the repository before saving it. Deployments then clone over HTTPS.
    - GitHub: a fine-grained personal access token limited to the repository, with Contents set to Read-only.
    - GitLab: a project access token with the Reporter role and the `read_repository` scope.
    - Bitbucket: a repository access token with Repositories: Read.
    - Other Git servers: any token that can read the repository, together with the username it belongs to.

If both are set, the token is used. Tokens are stored encrypted and never shown again. CommyPanel never puts a token in a URL or on a command line; it is handed to Git through its environment, and Git is told not to follow redirects so the token is never sent to another host. This needs Git 2.31 or newer, which every supported system has.

## Release command

An optional command that runs once per deployment, for example database migrations (`npm run migrate`). It runs in a fresh container made from the new image, with the app's environment variables, after the build and before the new version starts.

- If it fails or runs longer than 10 minutes (`DEPLOY_RELEASE_TIMEOUT`), the new version is not started and the old one keeps serving. Its output is in the deployment log.
- The old version keeps serving while it runs, so the changes it makes must still work for the old version.
- It runs without a shell: quotes work, but `&&`, `|`, `;`, `>` and `$VARIABLES` do not. To chain commands, run them through the shell in your image: `sh -c 'npm run migrate && npm run seed'`.
- Rollbacks do not run it again.

## Locking

An app, or one of its volumes, can be **locked**. A locked app cannot be deleted, and neither can its volumes' files with it; a locked volume cannot be removed from its app, and deleting the app is refused while one of its volumes is locked. The delete button stays visible but is disabled, with a note saying why, and a lock icon shows next to the name in the lists and beside the title.

Locking is a safety catch against a slip, such as deleting the wrong app. It does not stop deployments, restarts or any other change, and anyone who may change the app (`apps.update`) can unlock it. Locking and unlocking are recorded in the audit log. Lock an app on its **Advanced** tab, and a volume on the **Volumes** tab.

## Start, stop and restart

The **Container** menu on the app page restarts or stops the live version. A stopped app keeps its release and domains; visitors get an error page until you press **Start**, which brings it back at once without a deployment. Pushes do not deploy a stopped app.

This differs from **Deactivate** (Advanced tab), which removes the container and takes the domains offline until you activate the app again.

## Persistent volumes

Everything an app writes inside its container is gone when the next deployment replaces the container. To keep files (uploads, a SQLite database), add a **persistent volume** on the app's **Volumes** tab with **Add volume**: the path inside the container, such as `/app/uploads`. The files are stored on the server under `/var/lib/commypanel/volumes/<app>/<folder>`. The **How volumes work** button on the tab explains this in the panel.

Two names are involved, and only one has to match your app:

- The **path** (`/app/uploads`) is where the app sees the folder. It must be the path your app reads and writes, and it is case-sensitive. In the images CommyPanel builds for .NET and Node apps the app lives in `/app`, so that is its root folder, and a relative path in your code (`uploads/`) starts there. If you bring your own Dockerfile, use the folder its `WORKDIR` names. A Go app is built into a single file called `/app`, so give it another folder such as `/data`.
- The **folder** on the server (`app-uploads`) is made up from the path and is only a label. It does not have to match anything.

Also worth knowing:

- A change applies from the **next deployment**, to the app and to its release command.
- The old and the new container **share** a volume while a deployment switches over, so both can read and write it. Make sure two versions can run against the same files for a moment.
- A **rollback does not roll back volume data.** The files stay as the running version left them.
- Whatever the image has at that path is **hidden** by the volume, which starts empty.
- **Remove** (on the tab; you type the path to confirm) stops mounting a path but leaves its files on the server, as a volume that is **not attached**. **Deleting the app deletes them.**
- A volume can be **locked**, which stops it being removed; see Locking.
- Up to 8 volumes per app. `/proc`, `/sys` and `/dev` cannot be used.

The folder is writable for whichever user the app runs as, since that differs from image to image. It cannot be reached from anywhere else on the server, because the folders above it belong to root and are closed to everybody else.

### Looking at volumes

The **Volumes** tab lists the app's volumes with a search box, how much space each uses (with a bar to compare them), where it is mounted and whether it is locked. **Browse** opens the volume on a page of its own, read only: the totals (files in the current folder, files in the whole volume, disk space used), the folder as a table with an icon per file type, a `..` row to go up, a preview of text files (the first 256 KB) and a download for anything up to 25 MB. Each folder has its own address, so the back button and links work. Links and special files inside a volume are listed but never opened, because the app that wrote them is not trusted and could point them at the server's own files.

**Backing up a volume.** Below the volumes, the **Backups** card has a **Back up** button for each volume. It makes one compressed archive (a gzipped tar) of the volume's folders and files in `/var/lib/commypanel/backups/volumes/<app>/`, in the background, since a large volume takes a while; the newest 3 of each are kept (`BACKUPS_KEEP_VOLUMES`). It is only made when you ask (nothing is backed up by itself), because volumes can be large. Links, pipes, sockets and devices are left out, owners are kept as numbers so files go back to the same users, and a volume over 50 GB is refused. Downloading or deleting an archive needs your password and is recorded. The archives stay on this server, so copy them somewhere else now and then. To put one back, stop the app and run `tar -xzpf FILE -C /var/lib/commypanel/volumes/<app>/<folder>` as root on the server; CommyPanel never overwrites a volume that may be in use. Archives are deleted with the app.

Volume files are private data (databases, uploads), so browsing needs its own permission, `apps.volumes`. **Roles you made yourself do not have it** until you grant it; the built-in admin role and the super user do. Every download is recorded in the audit log.

A volume you removed shows as **Not attached**, with its files. **Delete data** removes them (it asks for your password first, and is recorded). The agent refuses while any container still has that volume mounted, so deploy the app again first.

## Domains and the server's address

To connect a domain, CommyPanel shows the DNS records to create (an **A** record, and an **AAAA** record if the server has an IPv6 address) and checks that they point at this server. For that it has to know the server's public address, and it **finds it by itself**, in the background, the first time a page needs it. You do not press anything.

- **First it reads the server's own network connection.** Most servers (Hetzner, DigitalOcean, bare metal) carry their public address on their network interface, so nothing leaves the machine. Private ranges (10.x, 172.16-31.x, 192.168.x), Docker's and VPN addresses, and carrier-grade NAT addresses are ignored.
- **Only if there is no public address there** (a server behind a NAT, as on some clouds such as AWS or Oracle, where the provider's router owns the public address) it asks `api.ipify.org` once which address it connects from. This is the one place besides update checks and the S3 copy where the panel contacts the outside by itself. Set `COMMYPANEL_ADDRESS_LOOKUP=false` in `/etc/commypanel/env` to never do that and enter the address by hand.
- **If nothing can be found**, the page says so and shows the two fields to fill in. It does not try again for ten minutes.
- **Wrong address?** Everywhere the address is used (Domains > Server address, an app's Domains tab, the first-run page, a domain's page) there is a **Wrong IPs detected?** button that opens the two fields. What you enter is kept as yours and is never replaced by a detection; **Detect again** in the same form looks it up anew. Changing it is recorded in the audit log, with the address it replaced.

## Containers and networks

The **Usage** tab lists the app's containers: the live release and, for a moment during a deployment, the previous one. For each it shows the state, the release, the network and address it has, the ports it publishes (only on the server's loopback address; nginx is what reaches them) and the volumes mounted into it. Below that are the networks those containers are on and everything attached to them. **Server > Docker** shows the same for every container, plus all networks CommyPanel created.

### Each app has its own network

Every app's containers join a Docker network of their own, `commypanel-<app>`, created with its first container and removed when the app is deleted. Containers of different apps are on different networks, so one app cannot reach another's containers. The old and the new release of the same app share their network, so a deployment switch needs no change. nginx reaches the app through a port published on the server's loopback address, which does not depend on the network.

Apps deployed before this existed stay on the old shared network, called `commypanel`, until their next deployment. The Usage tab says so, and both pages warn when a network holds more than one app. Deploy those apps again to move them. The old network stays as an empty entry afterwards.

What this does not do: a container can still reach the internet, and the host's own services that listen on all addresses (such as the panel's port or SSH) through the network's gateway address. Services that listen only on `127.0.0.1`, including the ports the apps are published on, cannot be reached from a container.

## Logs

The **Logs** tab shows what the running container printed, newest at the bottom. Choose how many lines to load (200 to 5,000). **Follow** refreshes every 3 seconds and keeps the view at the end; switch it off to read in peace. Search the log with the box above it, or press Cmd/Ctrl+F while the log is focused: matches are highlighted, and Enter and Shift+Enter move between them. **Download** saves the last 5,000 lines as a text file.

Apps often print secrets into their logs, so reading them needs its own permission, `apps.logs`. The built-in admin role and the super user have it; **roles you made yourself do not** until you grant it. Every download is recorded in the audit log. Only the live container's output is shown, not that of earlier releases.

## Deploy on push

Under Settings > Deploy on push, turn on the webhook and add its URL and secret to your repository (GitHub, GitLab, Bitbucket Cloud, Gitea and Forgejo are supported). Every push to the app's branch then starts a deployment.

- Every call must be signed with the secret (HMAC-SHA256, or GitLab's secret token). Anything else is refused.
- Pushes to other branches, tags and other events are ignored, and so are pushes while the app is stopped or a deployment is already waiting.
- The URL contains a random id, not the app's name. **New secret** replaces the secret at once; turning the webhook off and on gives it a new URL.
- Your Git host must be able to reach the panel. Give the panel its own domain (on the `/welcome` page) so the URL is a stable HTTPS address.

## Custom nginx directives

The **Nginx** tab (permission "Edit nginx directives") lets you add your own nginx directives to an app's site: upload limits, timeouts, headers, redirects and extra locations. They are added to the site CommyPanel generates, so they stay when you deploy, change domains or renew the certificate.

- **Save and reload nginx** checks the text, lets nginx test the whole configuration and reloads it. If anything is refused, nothing is saved and the previous configuration keeps running. **Cancel** throws your edits away. Saving needs a recent password confirmation and is recorded in the audit log with the old and new text.
- If the app has no domain or no live release yet, there is no site to apply it to. Your text is saved and applied as soon as there is one.
- The tab shows the generated configuration, read only, so you can see exactly where your directives end up.

nginx runs as root, so the text is never written as typed. It is parsed, every directive and value is checked against a list, and the site is rendered from the result. Anything not on the list, such as `root`, `alias`, `include`, `access_log`, `ssl_*` or a `proxy_pass` to anywhere but this app, is refused with the line it is on.

Supported:

- Limits and timeouts: `client_max_body_size`, `client_body_buffer_size`, `client_header_buffer_size`, `large_client_header_buffers`, `client_body_timeout`, `client_header_timeout`, `send_timeout`, `keepalive_timeout`, `proxy_connect_timeout`, `proxy_read_timeout`, `proxy_send_timeout`, `proxy_buffering`, `proxy_request_buffering`, `proxy_buffer_size`, `proxy_buffers`, `proxy_busy_buffers_size`, `proxy_max_temp_file_size`, `proxy_intercept_errors`.
- Compression: `gzip`, `gzip_vary`, `gzip_min_length`, `gzip_comp_level`, `gzip_types`, `gzip_proxied`.
- Headers and errors: `add_header`, `expires`, `error_page`, `proxy_hide_header`, `charset`.
- Access: `allow`, `deny`, `set_real_ip_from`, `real_ip_header`, `real_ip_recursive`.
- Server behaviour: `server_tokens`, `merge_slashes`, `underscores_in_headers`, `ignore_invalid_headers`, `set`.
- `location` blocks. Inside them you can also use `return`, `rewrite`, `if`, `proxy_set_header`, `proxy_redirect` and `proxy_pass $commypanel_upstream`, which is the address of this app.

`return`, `rewrite` and `if` work only inside a location: at the top level they would run before nginx picks a location and would also catch the Let's Encrypt validation requests. The main `location /` is generated and cannot be redefined, and the Let's Encrypt location always wins over your own. A `proxy_set_header` inside a location replaces the generated ones (`Host`, `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto`) for that location, so repeat the ones you need.

```nginx
# Allow uploads up to 50 MB
client_max_body_size 50m;
proxy_read_timeout 120s;
add_header X-Frame-Options "DENY" always;

# A path with its own timeout that still goes to the app
location /api/ {
    proxy_read_timeout 300s;
    proxy_pass $commypanel_upstream;
}

# Redirect an old page
location = /old-page {
    return 301 /new-page;
}
```

## When Docker is down

The panel runs outside Docker, so it keeps working when Docker does not. Every page then shows a warning, and Server > Docker shows the state of the Docker service and its latest log lines. People with the "Manage Docker and nginx" permission can restart Docker from there after confirming their password. Restarting Docker stops every container for a moment; they come back on their own.
