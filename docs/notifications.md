# Notifications

Settings > Notifications sends the result of a deployment to a chat or any service that takes a webhook.

- **Formats**: Slack (also Mattermost and Rocket.Chat), Discord, or generic JSON (the same text plus
  `event`, `app`, `deployment`, `succeeded`, `commit`, `error` and `url` fields).
- **When**: only on failures (default), or on every result.
- **What a message holds**: the app, the deployment number, the short commit and its first line, on a failure
  the reason, and a link to the panel. Never the log, environment variables or any secret. Text that came from
  the repo is made harmless: no mentions (`@everyone`, `<!channel>`) and no clickable links.

## Safety

- The address is the secret of the channel, so it is stored encrypted and never shown again. Saving or
  removing it asks for your password and is audited (the address itself is not written to the audit log).
- It must be https. The name is resolved when sending, and the panel refuses this server itself, reserved
  ranges and the link-local range (cloud metadata); the checked address is the one connected to, so the
  name cannot change its answer in between. A chat server in your private network is allowed.
- Redirects are never followed.
- The panel sends nothing until you switch it on. This is one of the few calls it makes by itself, next to
  update checks, the S3 copy and the server address lookup.
- A failed delivery is retried twice, then shown on the page. It never affects the deployment.
- The "Send a test message" button uses what is in the form and saves nothing.

Needs the permission `notifications.manage`.
