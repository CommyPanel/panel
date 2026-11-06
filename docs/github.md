# GitHub

Settings > GitHub connects the panel to GitHub with a **GitHub App the panel creates for itself**, so you do
not paste tokens or deploy keys. New apps can then pick a repository from a list, and every push to the app's
branch deploys it.

## Set up

1. The panel needs its own domain with a certificate (Domains > Automatic HTTPS), because GitHub must reach
   `/webhooks/github` and the return address over https.
2. Settings > GitHub > Connect GitHub (asks for your password). Choose your account or an organization you own,
   continue to GitHub, confirm the name. GitHub sends the app's key and webhook secret straight back to the
   panel.
3. Install the app on your account and choose which repositories it may read. Come back to the panel and
   press "I installed it" (GitHub also sends you back by itself).
4. Create an app > GitHub repository, pick the repository.
5. **Deploy on push** is a switch when you create the app (off unless you turn it on) and later on the app's
   Settings tab, where the panel asks you to confirm turning it on or off. Every other app has it off.

## What it can do

- The app has **read-only** access to repository contents (and metadata), nothing else.
- Each clone uses a token for **one repository** that GitHub expires after about an hour. It is made for the
  deployment and never stored.
- The private key and webhook secret are stored encrypted in the panel's database and never shown.
- The webhook is checked with the secret GitHub generated (`X-Hub-Signature-256` only). Only pushes from this
  app's own installation to a branch an app uses are acted on, and a stopped app, or one with a deployment
  already waiting, is skipped.
- Anyone who may create apps can deploy any repository the app may read, so give it access only to what should
  be deployable. Connecting and disconnecting need the `github.manage` permission and are audited.

## Network

The panel talks to `github.com` and `api.github.com` (fixed addresses, https, redirects never followed), only
when you use the GitHub page, create an app, or deploy. Nothing is polled. GitHub Enterprise Server is not
supported.

## Disconnect

Settings > GitHub > Disconnect forgets the key and secret. Apps set up through GitHub then fail to fetch
their code with a clear message until you connect again; change them to a Git address if you prefer. Delete
the app on github.com too (App settings on GitHub) if you no longer want it there.
