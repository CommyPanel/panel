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

## Start, stop and restart

The **Container** menu on the app page restarts or stops the live version. A stopped app keeps its release and domains; visitors get an error page until you press **Start**, which brings it back at once without a deployment. Pushes do not deploy a stopped app.

This differs from **Deactivate** (Advanced tab), which removes the container and takes the domains offline until you activate the app again.

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
