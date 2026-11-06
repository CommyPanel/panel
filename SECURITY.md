# Security policy

CommyPanel controls Docker, nginx and certificates on the server it runs on, so a security flaw in it can be serious. We take reports seriously and are grateful for them.

## Reporting a vulnerability

**Please do not open a public issue, pull request or discussion for a security problem.**

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. If that is not available to you, contact the repository owner through their GitHub profile and ask for a private way to send the details.

Please include:

- what you found and why it matters,
- the version (`sudo commypanel status`) and the distribution,
- steps to reproduce it, or a proof of concept,
- what you think the impact is, and any fix you have in mind.

## What to expect

This is a small project, so these are honest targets rather than promises:

- We acknowledge your report within about **7 days**.
- We confirm whether we can reproduce it and tell you how we plan to handle it.
- We fix confirmed issues as quickly as we reasonably can, release a patch version, and say so in the release notes. We credit you if you want to be credited.
- We ask you to keep the details private until a fix is released, and we will agree a disclosure date with you.

## Supported versions

Security fixes are made for the **latest stable release**. Release candidates and older releases do not get separate fixes; the fix is to upgrade (`sudo commypanel upgrade`). CommyPanel is pre-1.0 and changes quickly.

## What is in scope

- The panel (authentication, sessions, permissions and roles, password confirmation, the API and its tokens, webhooks).
- The agent: bypassing its validation, getting it to run something it should not, reading or writing files it should not, escaping the constraints it puts on containers.
- The installer, the upgrade path and the release process (for example a way to make the installer accept a file whose checksum does not match).
- The database tools proxy, the browser terminal, and the nginx configuration the panel generates (including the custom directives parser).
- Leaking secrets: environment variables, database passwords, tokens, keys, logs.
- Server-side request forgery, injection of any kind, and cross-site scripting or request forgery in the panel.
- Privilege escalation between panel users and roles, and from a user's app to the host or to other apps.

## What is out of scope

- A malicious or compromised **administrator** doing what an administrator is allowed to do. An admin can deploy containers; that is the product.
- Problems in the apps you deploy, or in a server you misconfigured (open firewall, weak SSH, outdated operating system).
- Denial of service that needs a valid admin account, or that only exhausts your own server's resources.
- Reports that need physical access, or a stolen and unlocked session.
- Findings that are already documented as known limitations, such as browsers sharing cookies between ports for the database tools.
- Automated scanner output with no demonstrated impact.

## Safe harbour

If you act in good faith, stay within the scope above, avoid privacy violations and data destruction, and give us a reasonable chance to fix the issue before disclosing it, we will not take or support any action against you. Test on your own server, never on someone else's.

## Staying secure

- Keep CommyPanel up to date, and the operating system too (`sudo apt-get update && sudo apt-get upgrade`).
- Turn on two-factor authentication for every administrator.
- Give people and API tokens only the permissions they need, and rotate tokens regularly.
- Keep copies of your backups somewhere other than the server, and keep a copy of the encryption key (`APP_KEY` in `/etc/commypanel/env`) apart from the backups.
