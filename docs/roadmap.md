# Roadmap

What is built, what is next, and in which order. Updated with every step.

## 0.3.0 (in progress)

Seeing and shaping what your apps do on the server.

| #   | Step                                                                                                   | State   |
| --- | ------------------------------------------------------------------------------------------------------ | ------- |
| 1   | **Log viewer**: search, follow, download, per-app permission `apps.logs`                               | done    |
| 2   | **Persistent volumes**: an app declares container paths to keep; both blue/green containers mount them | next    |
| 3   | **Volumes view and file browser**: list volumes with their size, look at files read-only               | planned |
| 4   | **Containers and networks overview**: what is connected to an app, and on which networks               | planned |
| 5   | **Browser terminal**: a shell in a managed container, ticket-based over a second agent socket          | planned |

## After 0.3.0

- **Database servers** (MariaDB, MySQL, PostgreSQL in several versions) and creating and managing databases and users.
- **API tokens** (Sanctum) for CI deploy triggers and a CLI.
- **GitHub App** one-click connection through the manifest flow.
- Backups of databases and volumes.

## Released

- **0.2.2**: fixes the upgrade failure in 0.2.1 (nginx buffers).
- **0.2.1**: PHP security check (PHP2Shell), 502 on reload fixed. Could not upgrade; use 0.2.2.
- **0.2.0**: release command, start/stop/restart, HTTPS tokens, deploy on push, live usage, Docker-down state, nginx editor.

Not planned: Laravel, Django, WordPress and phpMyAdmin stacks, Podman, multiple servers. See `CLAUDE.md` for why.
