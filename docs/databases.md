# Databases and links

CommyPanel runs **MariaDB, MySQL and PostgreSQL** servers for your apps, as containers on the same server, and lets you **link** an app to a database (or to another app) over a private network. Open **Databases** in the sidebar.

## Database servers

**Add server** takes a name, the database (MariaDB, MySQL or PostgreSQL) and a version. The server runs from the official image, downloaded from Docker Hub, so the first start can take a few minutes; the page updates by itself. If it fails, the page says why and offers **Try again**.

- **It is never reachable from the internet.** No port is published. Only an app that is linked to one of its databases can reach it.
- Its data is kept on this server, in `/var/lib/commypanel/databases/<server>`, and survives restarts and upgrades. The server has a memory limit of 1 GB by default (`DATABASE_MEMORY_MB`).
- You can **stop**, **start** and **restart** a server. **Delete server** removes it together with the data of every database on it; it asks you to type its name and your password, and is refused while an app uses one of its databases.
- Versions offered: MariaDB 11.4 and 10.11, MySQL 8.4 and 8.0, PostgreSQL 17, 16 and 15.

## Databases

**Add database** creates a database and a **user with the same name that can use that database and nothing else** on the server. A long random password is generated and kept encrypted.

- **Connection** shows the address, database, user, password and connection URL. It needs the `databases.credentials` permission and a recent password confirmation, the password stays hidden until you press **Show**, and every look is recorded in the audit log.
- **New password** changes it. Apps linked to the database keep using the old password, and stop working, until they are deployed again.
- **Delete** removes the database and its user after you type its name. It is refused while an app is linked to it.

## Links

Apps are separate from each other by default: every app has its own Docker network (`commypanel-<app>`). A **link** lets one app reach one database, or one other app, over a private network made for that link alone. Open the app's **Links** tab.

- **Link a database:** choose a database and the names of the environment variables (`DATABASE` by default). The app gets `DATABASE_URL`, `DATABASE_HOST`, `DATABASE_PORT`, `DATABASE_NAME`, `DATABASE_USER` and `DATABASE_PASSWORD` (or your prefix instead of `DATABASE`). The host is the server's short name, which only linked apps can resolve. For example `postgresql://shop:...@main:5432/shop` or `mysql://...`.
- **Link an app:** the app can reach the other one at `http://<its name>:<its port>` inside the server, and gets `<PREFIX>_URL`, `_HOST` and `_PORT`. Both apps need a new deployment: this one to get the address, the other to answer to its name.
- **A link applies from the next deployment**, like an environment variable. The release command (for example a migration) joins the same networks, so it can reach the database too.
- A variable you add yourself with the same name wins over the one a link adds.
- **Remove** a link on the same tab; it applies from the next deployment. Deleting an app removes its links.
- Two apps with domains can also call each other over their public addresses (HTTPS through nginx). A link is for traffic that should stay inside the server.

Linking needs the `apps.links` permission. Linking a database lets that app use it, and only that one.

## A database on another server

A database the panel does not run, such as one on another machine in your private network, is used like any outside service: put its address in the app's environment variables, for example `DATABASE_URL=postgresql://user:pass@10.0.0.5:5432/shop`, and deploy again. Apps can reach other machines; only other apps are kept out. The database must listen on its private address and allow the **panel server's** address (the app's traffic leaves through the server, so the database never sees the container's own address). The panel does not list, back up or show credentials for such a database.

## Backups

- **Databases.** Every database on a running server is dumped each night at 04:00, and from the **Back up** button next to it, with the engine's own tool, to one compressed file in `/var/lib/commypanel/backups/databases/<server>/`. The newest 7 of each are kept (`BACKUPS_KEEP_DATABASES`). The server page lists them; download and delete need a recent password, and downloads are audited. To load a dump back, on the server: `gunzip -c FILE | docker exec -i db-<server> psql -U postgres -d NAME` for PostgreSQL, or `gunzip -c FILE | docker exec -i db-<server> sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" NAME'` (`mysql` and `MYSQL_ROOT_PASSWORD` for MySQL) after creating an empty database `NAME`.
- **The panel.** The panel's own database is backed up every night at 03:30 (newest 7 kept, `BACKUPS_KEEP`) from **Settings > Backups**, which also takes one now, downloads and deletes. See [Installing](installing.md#backing-up-and-restoring-the-panel).
- Dumps and backups are stored **on this server**, so copy them somewhere else now and then: a backup on the same disk is lost with it.

## Permissions

`databases.view` (see servers and databases), `databases.manage` (create, start, stop, delete, back up), `databases.credentials` (show passwords), `apps.links` (link apps), `backups.view` and `backups.manage`. The built-in admin role and the super user have them; **roles you created yourself do not** until you grant them.

## Security notes

- A database server is a container with the same restrictions as an app: not privileged, a memory limit, no published port. Databases and users are created by the agent, which accepts only plain names and long letters-and-digits passwords, so nothing typed in the panel can alter the SQL. The administrator password and each user's password are kept encrypted and are never written to the audit log.
- The agent can only start the official `mariadb`, `mysql` and `postgres` images with a plain version tag (no `latest`, no other repository), besides the images CommyPanel builds.
- Apps are isolated by networks, not by the database: a linked app gets the one database it was linked to, and its user cannot open another database on the same server.
