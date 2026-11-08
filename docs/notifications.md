# Notifications

Settings > Notifications sends alerts to chats and services of your choice. You can add up to 10
**targets**; each one has its own address and its own list of events. Nothing is sent until you add a
target. Email is not supported.

## Targets

| Kind                                 | What you give                                         | What is sent                                                                                                                        |
| ------------------------------------ | ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Slack (also Mattermost, Rocket.Chat) | Incoming webhook address                              | `{"text": ...}`                                                                                                                     |
| Discord                              | Channel webhook address                               | `{"content": ..., "allowed_mentions": {"parse": []}}`                                                                               |
| Microsoft Teams                      | Address of a Workflows webhook                        | An Adaptive Card in the `message` / `attachments` envelope, with an "Open in CommyPanel" button                                     |
| ntfy                                 | Full topic address (https)                            | A plain-text POST with the headers `Title`, `Priority` (`high` for problems), `Tags` and `Click`                                    |
| Telegram                             | Bot token and a chat id (a number, or `@channelname`) | JSON to `https://api.telegram.org/bot<token>/sendMessage`; the panel builds this address itself, so the host is fixed               |
| Generic JSON                         | Any https address                                     | The same text plus `event`, `app`, `url` and the fields of the event (for deployments `deployment`, `succeeded`, `commit`, `error`) |

Each target can be switched off without deleting it, has a "Send a test message" button (it sends to the
saved target at once and shows the error if it fails), and shows when the last message arrived or why the
last one did not.

An existing single webhook from an earlier version became one target called "Default" when you upgraded,
with the same address and format; "failures only" became _deployment failed_ and _rolled back_, "every
result" also _deployment succeeded_.

## Events

A new target listens to the events marked "on".

| Event                    | Default | When                                                                                      |
| ------------------------ | ------- | ----------------------------------------------------------------------------------------- |
| `deployment.failed`      | on      | A deployment failed                                                                       |
| `deployment.rolled_back` | on      | A release was rolled back automatically (watch or gradual rollout)                        |
| `deployment.succeeded`   | off     | A deployment went live                                                                    |
| `app.down`               | on      | A running app did not answer its health path for 3 minutes in a row                       |
| `app.recovered`          | off     | The app answers again (says how long it was down)                                         |
| `certificate.failed`     | on      | A certificate (an app's or the panel's own) could not be issued or renewed                |
| `certificate.expiring`   | on      | A certificate expires within 14 days and is still not renewed                             |
| `disk.warning`           | on      | The disk reached the warning level (85% full)                                             |
| `disk.critical`          | on      | The disk reached the critical level (95% full)                                            |
| `disk.recovered`         | off     | The disk is back under its warning level                                                  |
| `backup.failed`          | on      | The nightly panel backup, a database dump, a volume archive or the off-server copy failed |
| `database.stopped`       | on      | A database server the panel thinks is running has no running container                    |
| `database.recovered`     | off     | It runs again                                                                             |

## How detection works

A check runs every minute (`commypanel:monitor`, started by the scheduler). A message is sent when
something **changes**, never on every check:

- **Apps**: the health path is asked once a minute on the live container's port (3 second timeout). Three
  failed checks in a row make the app "down" and send one message; the first good check afterwards sends
  "recovered". Apps that are stopped, being deployed, watched after a release or in a gradual rollout are
  not checked, and a deploy never counts towards "down".
- **Database servers**: a server whose container is not running for 2 checks in a row sends one message,
  and one more when it runs again. If Docker itself is down nothing is reported about servers (the panel
  already shows that on every page).
- **Disk**: one message when a level is crossed upwards, and one when it falls back. It must fall a few
  points under the limit before it counts as recovered, so a disk hovering at the limit stays quiet.
- **Certificates**: a failed attempt sends one message, and the same failure is not repeated for 24 hours
  (the panel retries every few hours). A certificate that expires within 14 days sends `certificate.expiring`
  at most once every 3 days. The reason is the readable one the panel already shows.
- **Backups**: each failure sends one message that names what failed.

## Muting one app

An app's Settings page has a switch for its alerts. When it is off, nothing is sent about that app (down,
recovered, deployments, its certificate). Alerts about the server (disk, databases, the panel's own
certificate, backups) are not affected.

## What a message holds

The app, what happened, a short reason where there is one, and a link to the panel. **Never** the deployment
log, environment variables, an address or any other secret. Text that came from the repo (a commit
message) is made harmless: no mentions (`@everyone`, `<!channel>`) and no clickable links.

## Safety

- A target's address is the secret of the channel (a webhook address, a topic or a bot token), so it is
  stored encrypted and never shown again; the page shows only its host name. Creating, changing and removing
  a target asks for your password and is audited (the name, kind and host, never the address).
- Every address must be https. The name is resolved when sending, and the panel refuses this server itself,
  reserved ranges and the link-local range (cloud metadata); the checked address is the one connected to, so
  the name cannot change its answer in between. A chat server in your private network is allowed. This
  applies to every kind, Telegram included.
- Redirects are never followed.
- A failed delivery is retried twice (after 30 seconds and 2 minutes), then shown on the target. It never
  affects what raised the alert.
- This is one of the few calls the panel makes by itself, next to update checks, the S3 copy and the server
  address lookup, and only once you add a target.

Needs the permission `notifications.manage` (the per-app switch needs `apps.update`).
