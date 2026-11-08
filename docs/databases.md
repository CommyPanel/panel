# Databases and links

CommyPanel runs **MariaDB, MySQL and PostgreSQL** servers for your apps, as containers on the same server, and lets you **link** an app to a database (or to another app) over a private network. Open **Databases** in the sidebar.

## Database servers

**Add server** takes a name, the database (MariaDB, MySQL or PostgreSQL) and a version. The server runs from the official image, downloaded from Docker Hub, so the first start can take a few minutes; the page updates by itself. If it fails, the page says why and offers **Try again**.

- **It is not reachable from the internet unless you open it.** No port is published. Only an app that is linked to one of its databases can reach it, until you use [Remote access](#remote-access).
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
- **A link reaches the running app when you apply the changes** (see below), like an environment variable. The release command (for example a migration) joins the same networks, so it can reach the database too.
- A variable you add yourself with the same name wins over the one a link adds.
- **Remove** a link on the same tab; it applies the same way. Deleting an app removes its links.
- Two apps with domains can also call each other over their public addresses (HTTPS through nginx). A link is for traffic that should stay inside the server.

Linking needs the `apps.links` permission. Linking a database lets that app use it, and only that one.

## Remote access

**Remote access** (the `databases.remote` permission, a recent password, audited in red) opens a database server to the internet **for a limited time**, so you can connect from your own network with a desktop client, a backup job or a script. It is off unless you open it.

- You list the **real public IP addresses** (or ranges, nothing wider than /16 or /48) that may connect; there is no "everybody". You choose how long: 15 minutes to 7 days, or **never**, which shows a red warning and needs a confirmation. At the end time the port closes completely and open connections are cut.
- The port is **random** (10000 to 19999), not the database's usual one. The dialog shows the range, so you can allow it in your firewall beforehand. The agent opens it itself and refuses every other source address without a reply.
- **TLS is required.** The server gets its own certificate authority and a certificate for its public addresses; the first time, it restarts once to use it (you confirm this in the dialog; apps reconnect by themselves). Download **ca.pem** from the card and point your client at it (`sslmode=verify-full` for PostgreSQL, `--ssl-mode=VERIFY_IDENTITY` for MySQL). Clients that do not ask for TLS are refused.
- Cloudflare's proxy does not carry database traffic: connect to the server's own address. Open the port in your firewall for the allowed addresses.
- Change the addresses or the time while it is open, or **Close now**. After an agent restart the panel opens the port again within a minute; deleting a server closes it first.

Prefer a VPN or an SSH tunnel for anything permanent: the address list is the only protection in front of the login.

## A database on another server

A database the panel does not run, such as one on another machine in your private network, is used like any outside service: put its address in the app's environment variables, for example `DATABASE_URL=postgresql://user:pass@10.0.0.5:5432/shop`, and deploy again. Apps can reach other machines; only other apps are kept out. The database must listen on its private address and allow the **panel server's** address (the app's traffic leaves through the server, so the database never sees the container's own address). The panel does not list, back up or show credentials for such a database.

## Opening a database in phpMyAdmin or pgAdmin

Next to each database there is a button named after its tool: **phpMyAdmin** for MySQL and MariaDB servers, **pgAdmin** for PostgreSQL (phpMyAdmin does not support PostgreSQL). pgAdmin is the larger of the two: its image is about 600 MB and it takes around ten seconds to start, so the first opening downloads it and every later one waits a little longer than phpMyAdmin does. It opens the database in a new tab, **already logged in**, and nothing needs to be installed or linked.

- **What happens when you press it.** The new tab asks for your password if it was not confirmed recently, then shows its progress: getting the tool ready (the first time it downloads the official image, 100 MB for phpMyAdmin and about 600 MB for pgAdmin; after that a moment), starting it next to your database, waiting for it to answer, and opening it in the same tab. If something fails it says why and offers **Try again**.
- **What you get.** The tool is logged in as that database's **own user**, so you see this database and nothing else on the server (not the other databases, and not the administrator account). You can browse and edit data, run SQL (pgAdmin's Query Tool), and import or export dumps up to 128 MB. Everything you do happens to the real database.
- **It closes by itself.** The tool is a small container started for you alone. After **15 minutes without activity** (and after four hours at most, however busy) it is stopped and deleted; nothing stays installed or running. Opening the database again starts a fresh one. A database has one open tool at a time: opening it again replaces the first.
- **Permission.** It needs `databases.tools`, a separate permission that the built-in admin role has and custom roles do not until you grant it. Treat it like the database's password: whoever holds it can read and change the data. It asks for your password, and every start and opening is recorded in the audit log.
- **How pgAdmin is set up.** It runs in single-user mode, with its mail server, PSQL shell and update check switched off. It starts with no server in it; when you open the database, the agent adds this one database as a server and connects it with the database user's password, which pgAdmin keeps only in memory for your browser session and never saves. The password is in no file or environment variable of the container, and anything else that reaches the container's port sees an empty pgAdmin with no connection. Its PSQL shell is off because it needs a WebSocket, which the proxy does not pass.
- **Not for the whole server.** There is no administrator login to the database server here, only each database's own user. To work as the administrator, use the command line on the server.

### The port, and why it is a different one

The tools are served on **port 2053** (set `COMMYPANEL_TOOLS_PORT` to change it), on the same address as the panel: `https://panel.example.com:2053` once the panel has a domain, with the same certificate, or `https://<server address>:2053` with the panel's self-signed one (your browser warns once more for that port). **Open that port in your firewall**, next to 80, 443 and 8443; the installer prints the reminder.

The port is not a detail. A browser treats the same address on another port as **another website**. phpMyAdmin and pgAdmin are third-party web applications that show whatever is in your database, and that can include text a stranger typed into a form on your site. If such text ever fooled a tool into running a script, that script would be in the tool's website, not the panel's, and the browser would not let it read the panel's pages or send it requests as you. The agent's proxy also never passes the panel's login cookie on to a tool, never lets a tool set a cookie outside its own session, drops headers that reach beyond the tool's own page (such as `Strict-Transport-Security` and `Clear-Site-Data`), and does not follow a redirect to anywhere else. The tool is also never logged in from its own container: neither tool holds the database password, the agent logs in for the browser it lets in, so a process on the server that finds the tool's local port meets a login form.

**What a port does not separate: cookies.** Browsers key cookies by host name, not by port, so the panel's cookies are sent to the tools port too (the proxy removes them before they reach a tool, and the login cookie is HttpOnly, so a script cannot read it). A script that ran in a tool could still fill the browser's cookie storage for that host, which would make the browser's requests to the panel fail until its cookies are cleared (a nuisance, not a way in), and could read the panel's one cookie that is not HttpOnly, which is of no use from another origin. A tool would have to be fooled first, which is the part we rely on phpMyAdmin and pgAdmin for. A separate host name would remove even this; it needs a DNS record and a certificate for it, and is planned together with wildcard certificates.

If you put the panel behind **Cloudflare's proxy** (the orange cloud), keep a port Cloudflare passes: **443, 2053, 2083, 2087, 2096 or 8443**. 2053 is one of them. Cloudflare's free plan limits uploads to 100 MB and cuts requests after about 100 seconds, so a very large import through the tool can fail there; switch the record to DNS only for it, or load the dump from the command line.

## Backups

- **Databases.** Every database on a running server is dumped each night at 04:00, and from the **Back up** button next to it, with the engine's own tool, to one compressed file in `/var/lib/commypanel/backups/databases/<server>/`. The newest 7 of each are kept (`BACKUPS_KEEP_DATABASES`). The server page lists them; download and delete need a recent password, and downloads are audited. To load a dump back, press **Restore** next to it. It is loaded into a **new** database (you choose its name, such as `shop_restored`), so nothing that exists is changed or overwritten. The new database has its own user and password, shows "Restoring" until it is done, and if loading fails it stays, marked as failed with the reason, so you can look at it or delete it. When it looks right, link it to the app on its **Links** tab, deploy again, and delete the old database. The agent only loads dumps it made itself: it remembers a checksum of each dump it makes and refuses any other file, or one that was changed since, so a restore cannot be used to run someone else's SQL.
- **The panel.** The panel's own database is backed up every night at 03:30 (newest 7 kept, `BACKUPS_KEEP`) from **Settings > Backups**, which also takes one now, downloads and deletes. See [Installing](installing.md#backing-up-and-restoring-the-panel).
- **Copying backups off the server.** Dumps and backups are first stored **on this server**, so a lost disk loses them too. Under **Settings > Backups > Copy to another place (S3)** you can have every new panel backup, database dump and volume archive copied to any **S3-compatible storage**: Hetzner Object Storage, Cloudflare R2, Backblaze B2, Wasabi, MinIO, Amazon S3 and others. Enter the https address of the storage (for Hetzner `https://fsn1.your-objectstorage.com`, or `nbg1` / `hel1`), the region (Hetzner: `fsn1`, `nbg1` or `hel1`; Cloudflare R2: `auto`), an existing bucket, an optional folder inside it, and an access key and secret key; **Test the connection** writes and deletes a small file so you see problems at once. Use a key that may only write to that bucket. The secret key is stored encrypted and never shown again. Copies are made in the background right after each backup, with up to three tries, and the newest 14 copies of each backup (changeable) are kept in the storage; older ones are deleted, and files that are not CommyPanel backups are never touched. A failed copy shows on the page. This is a copy to **your** storage: this is the one place besides the update check where the panel contacts the outside by itself, and only once you switch it on. The address must be https and cannot be this server itself or a link-local address; a storage in your private network is fine. One upload is limited to 5 GB (a limit of S3), so a bigger file is not copied and says so. **Copies on remote storage** (Settings > Backups, shown while the copy is on) lists what is on the storage when you press **Refresh**, grouped by panel backups, database dumps per server and volume archives per app, newest first (up to 500 files), and **Download** streams a file through the panel (your password, recorded). Restoring from it is still by hand, the panel never restores from the storage by itself. For a lost server: install CommyPanel, put the **same** `APP_KEY` into `/etc/commypanel/env` (backups never hold it, so keep your own copy) and run `sudo commypanel restore FILE` for the panel backup; load dumps as below; unpack volume archives with `tar` with the app stopped. **Getting a dump back from the storage** works like this. Download the file from your provider, then on the server create an empty database with the **Add database** button and load the dump into it: `gunzip -c FILE | docker exec -i db-<server> psql -U postgres -d NAME` for PostgreSQL, or `gunzip -c FILE | docker exec -i db-<server> sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" NAME'` (`mysql` and `MYSQL_ROOT_PASSWORD` for MySQL). This is also how to restore onto a **new server**: the **Restore** button only loads dumps that this server's agent made itself, so a dump from another server (or from before a reinstall) has to be loaded this way.

## Permissions

`databases.view` (see servers and databases), `databases.manage` (create, start, stop, delete, back up), `databases.credentials` (show passwords), `apps.links` (link apps), `backups.view` and `backups.manage`. The built-in admin role and the super user have them; **roles you created yourself do not** until you grant them.

## Security notes

- A database server is a container with the same restrictions as an app: not privileged, a memory limit, no published port. The agent runs its database tools only in containers it started from an official database image. Databases and users are created by the agent, which accepts only plain names and long letters-and-digits passwords, so nothing typed in the panel can alter the SQL. The administrator password and each user's password are kept encrypted and are never written to the audit log.
- The agent can only start the official `mariadb`, `mysql` and `postgres` images with a plain version tag (no `latest`, no other repository), besides the images CommyPanel builds.
- Apps are isolated by networks, not by the database: a linked app gets the one database it was linked to, and its user cannot open another database on the same server.

## Restoring over an existing database

Next to **Restore** (which loads a dump into a NEW database) each dump in the server's **Backups** card has **Restore over...**: pick the database to replace (it defaults to the one the dump came from), tick that you understand, type the database's name and confirm your password (permission `databases.manage`, recorded as `database.restore_replaced`). The apps that use it are listed because they see errors while it runs. In the background CommyPanel takes a **safety dump** of the database as it is now (it stays in the list; the dump you are loading is never pruned by it), has the agent drop and recreate the database and load the dump, and if that fails puts the safety dump back automatically. The database shows **Restoring...**, then nothing, or **Restore failed, database kept** with what happened (put back as it was), or **Restore failed** if even the put-back failed, in which case the message names the safety dump. Only dumps made by this server's agent, from the same engine, can be loaded.
