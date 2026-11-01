> **Release candidate for testing. Not for production.** This build has not yet been run end to end on a real server. If you try it, please use a test server and tell us what breaks.

**Install this candidate** (the normal one-line installer only installs the latest stable release):

```bash
curl -fsSL https://github.com/@REPO@/releases/download/@TAG@/install.sh | sudo bash -s -- --version @VERSION@
```

**Upgrade an existing install to it:**

```bash
sudo commypanel upgrade --version @VERSION@
```

**Most useful to check:** the upgrade itself, nginx reloads and the new nginx editor, a first Let's Encrypt certificate, a deploy with a release command, deploy on push from your Git host, and the live usage figures against `docker stats`.

---

