# Contributing to CommyPanel

Thank you for wanting to help. CommyPanel is a young, open-source project under heavy development, and every kind of contribution matters: bug reports, documentation, tests, design feedback and code.

This page explains how to get set up, what we look for in a change, the **terms you agree to when you contribute**, and where to start.

- [Ways to help](#ways-to-help)
- [Terms of contribution](#terms-of-contribution)
- [Your first contribution](#your-first-contribution)
- [Run it on your machine](#run-it-on-your-machine)
- [How the code is laid out](#how-the-code-is-laid-out)
- [Making a change](#making-a-change)
- [What we look for in a pull request](#what-we-look-for-in-a-pull-request)
- [Security-sensitive changes](#security-sensitive-changes)
- [Interface conventions](#interface-conventions)
- [Reporting bugs and suggesting features](#reporting-bugs-and-suggesting-features)

## Ways to help

- **Try it and tell us what broke.** Install it on a test server, deploy something, and open an issue for anything confusing or wrong. Real-server feedback is the most valuable thing right now.
- **Improve the docs.** If something took you longer than it should have, a documentation fix is a great first pull request.
- **Fix a bug or build a small feature** from an issue labelled [`good first issue`](https://github.com/commypanel/panel/labels/good%20first%20issue) or [`help wanted`](https://github.com/commypanel/panel/labels/help%20wanted).
- **Review.** Reading a pull request and asking questions is a real contribution.

For anything bigger than a small fix, please **open an issue first** so we can agree on the approach before you spend your time. CommyPanel is deliberately focused (one server, stateless apps first, a small privileged part), and a quick conversation saves everyone a rejected pull request.

## Terms of contribution

By submitting a contribution (a pull request, a patch, documentation or anything else) you agree to the following.

1. **License.** Your contribution is licensed under the same terms as the project, the [MIT License](LICENSE). You keep the copyright in your work; you grant everyone the rights the MIT License gives. There is no separate contributor license agreement to sign.
2. **Your right to contribute.** You wrote the contribution yourself, or you have the right to submit it under the MIT License. Do not submit code you copied from somewhere with an incompatible license (for example GPL or a proprietary source), or anything you are not allowed to share, such as an employer's confidential code. New dependencies must have an MIT-compatible license.
3. **Developer Certificate of Origin.** Sign off every commit to certify the statement below (the [Developer Certificate of Origin 1.1](https://developercertificate.org)). Adding `-s` to `git commit` does it for you:

    ```bash
    git commit -s -m "Fix the health check timeout"
    ```

    which adds a line like `Signed-off-by: Your Name <you@example.com>` using your Git name and email. Pull requests with unsigned commits cannot be merged; if you forgot, `git rebase --signoff main` fixes the whole branch.

    > Developer Certificate of Origin, Version 1.1
    >
    > By making a contribution to this project, I certify that:
    >
    > (a) The contribution was created in whole or in part by me and I have the right to submit it under the open source license indicated in the file; or
    >
    > (b) The contribution is based upon previous work that, to the best of my knowledge, is covered under an appropriate open source license and I have the right under that license to submit that work with modifications, whether created in whole or in part by me, under the same open source license (unless I am permitted to submit under a different license), as indicated in the file; or
    >
    > (c) The contribution was provided directly to me by some other person who certified (a), (b) or (c) and I have not modified it.
    >
    > (d) I understand and agree that this project and the contribution are public and that a record of the contribution (including all personal information I submit with it, including my sign-off) is maintained indefinitely and may be redistributed consistent with this project or the open source license(s) involved.

4. **Public record.** Contributions, your name and your commit email are public and stay in the project's history.
5. **Conduct.** You agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).
6. **Security issues** are reported privately, not as public issues or pull requests. See [SECURITY.md](SECURITY.md).
7. **No warranty, no obligation.** Maintainers decide what is merged. A pull request that is not merged is not a judgement of you, and contributing does not create any obligation on either side.

## Your first contribution

Look for issues labelled [`good first issue`](https://github.com/commypanel/panel/labels/good%20first%20issue). Each one is meant to be small, to say which files to look at, and to say how we will know it is done. To take one:

1. Comment on the issue to say you would like to work on it, so two people do not do the same thing.
2. Fork the repository, create a branch, and follow [Run it on your machine](#run-it-on-your-machine).
3. Open a pull request early (a draft is fine) if you want feedback. Asking questions is welcome.

If nothing is open that suits you, these are good places to start. Ask in an issue first and we will confirm and label it:

| Idea                                                                                                                                                               | Where to look                                                                                                                                                                                                           |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Add another format for deployment messages** (Telegram, ntfy, Microsoft Teams). Slack, Discord and generic JSON exist; a new one is a small, well-tested change. | `app/Alerts/AlertSettings.php` (the formats), `app/Alerts/DeployAlerts.php` (the message bodies), `resources/js/pages/settings/notifications.tsx`, `tests/Feature/Alerts/DeployAlertsTest.php`, `docs/notifications.md` |
| **API: list an app's deployments** (`GET /api/v1/apps/{slug}/deployments`). The API can start a deployment and read one; listing is the natural next call.         | `routes/api.php`, `app/Http/Controllers/Api/V1/DeploymentsController.php`, `app/Api/ApiPresenter.php`, `tests/Feature/Api/ApiTest.php`, `docs/api.md`                                                                   |
| **Link a deployment's commit to GitHub** on the Deployments tab for apps connected through the GitHub App.                                                         | `app/Presenters/AppPresenter.php`, `resources/js/components/apps/tabs/deployments-tab.tsx`                                                                                                                              |
| **Write a walkthrough** that deploys a small Node app and a small .NET app, step by step, with a sample repository.                                                | `docs/`                                                                                                                                                                                                                 |
| **Collect common install problems** (a port already in use, a firewall, DNS not pointing yet) into a troubleshooting guide.                                        | `docs/installing.md`                                                                                                                                                                                                    |
| **Improve a message** that confused you. Error and status text lives in controllers, jobs and `resources/js`; clearer wording is always welcome.                   | search for the text you saw                                                                                                                                                                                             |

## Run it on your machine

You do not need Docker, nginx or a server to work on most of the panel. Locally, the panel uses a **fake host driver** that records what it would have done instead of touching Docker, nginx or Let's Encrypt, so it is safe to run on a laptop.

**You need:** PHP 8.3 or newer (CI runs 8.3 and 8.5) with the SQLite extension, [Composer](https://getcomposer.org), Node.js 22 or newer with npm, and Git. For the agent you also need Go 1.26 or newer.

```bash
git clone https://github.com/<your-fork>/commypanel.git
cd commypanel

composer setup        # installs dependencies, creates .env and the key, migrates, builds assets
composer dev          # runs the development environment
```

The first account is created through a one-time setup link. Print it with:

```bash
php artisan commypanel:setup-url
```

`.env.example` already sets `HOST_DRIVER=fake`. Never run the real driver on your own computer; it refuses to start outside a supported Linux server.

**Running the checks.** Please run these before you push; CI runs the same ones.

```bash
composer test          # code style, static analysis (PHPStan) and the PHP tests
npm run types:check    # TypeScript
npm run check          # JavaScript lint and format (npm run check:fix to fix)

cd agent && go vet ./... && go test ./...    # the Go agent
```

`php artisan test --filter=SomeTest` runs one test. **Tests must never touch real Docker or the real host.** A few opt-in tests that talk to a real Docker daemon (for example the database tools) are behind the `integration` build tag and are not part of the normal run.

**Trying it for real.** Changes to the installer, the agent or anything that runs on a server should also be tried on a real Debian 13 or Ubuntu 24.04 machine or VM (OrbStack, Multipass, Lima and UTM all work). `scripts/ci-install-smoke.sh` installs a built release inside a throwaway systemd container and checks that the panel comes up; read its header before using it. Use the Let's Encrypt staging CA, never production, when you test certificates.

## How the code is laid out

| Path                     | What lives there                                                                                                                                                                                       |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `app/`                   | The panel's PHP code. Business logic is in service and action classes (`app/Deploy`, `app/Databases`, `app/Dns`, `app/Tls`, `app/Alerts`, `app/GitHub`, `app/Api`, ...), not in controllers or models. |
| `app/Host/`              | The `HostDriver` interface and its drivers: the real one that calls the agent, and the fake one used for development and tests. Every Docker, nginx or system operation goes through it.               |
| `resources/js/`          | The React and TypeScript interface (Inertia pages in `pages/`, shared pieces in `components/`).                                                                                                        |
| `agent/`                 | `commypanel-agent`, the small Go program that runs as root and performs validated host actions.                                                                                                        |
| `installer/`, `scripts/` | The installer, systemd units and nginx templates, and the release and smoke-test scripts.                                                                                                              |
| `tests/`                 | Pest tests (`Feature`, `Unit`, `Integration`).                                                                                                                                                         |
| `docs/`                  | User documentation and release notes.                                                                                                                                                                  |

## Making a change

1. Fork, then branch from `main`: `git switch -c fix-health-check-timeout`.
2. Keep the change focused: one fix or feature per pull request, and no unrelated reformatting.
3. Add or update **tests** for new behaviour, and **documentation** if people will notice the change (`docs/`, and the release notes only if a maintainer asks).
4. Run the checks above.
5. Commit with a short, plain subject (about 60 characters, in the imperative: "Fix the health check timeout") and, when it helps, a short body that says why. Sign off the commit (`git commit -s`).
6. Open the pull request and fill in the template. Mention the issue it closes (`Closes #123`).

## What we look for in a pull request

- **It does one thing and says why.** The description explains the problem, not only the change.
- **Tests.** New behaviour has tests; a bug fix has a test that fails without the fix.
- **Checks pass:** `composer test`, `npm run types:check`, `npm run check`, and `go vet` and `go test` if you touched the agent.
- **No secrets, ever.** Never commit `.env`, tokens, keys or real credentials.
- **Small and readable.** Follow the style around you. PHP is formatted by Pint, JavaScript by the project's formatter, Go by `gofmt`.
- **Docs for visible changes**, written for the person using the panel.
- **A threat-model sentence for anything that runs on a user's server** (agent actions, installer changes, anything that executes commands, touches files, networks or containers): who could abuse this, and what stops them.

## Security-sensitive changes

The panel holds root-equivalent control of the server it runs on, so some areas get extra care:

- **The agent** (`agent/`) accepts a fixed list of typed actions and validates every input itself, independent of the PHP side. Never add a "run this command" action. A new action needs a validator and tests. Commands run with argument arrays, never through a shell.
- **Containers** are started only with the constraints the agent enforces (unprivileged, `no-new-privileges`, own network, limits, localhost-only ports). Do not loosen them.
- **nginx configuration** is rendered by the agent from templates and validated fields. The custom directives parser (`agent/internal/nginx/custom.go`) works from an allow-list, and its fuzz test must keep passing.
- **Outbound calls** from the panel (update checks, S3 copies, webhooks, GitHub) go through the existing guarded clients: https only, never to this server or reserved addresses, no redirects followed. Do not add a new outbound call without discussing it first.
- **Secrets** are encrypted at rest, never logged, never put in a URL or on a command line.
- **Every route** needs a permission check (`can:<permission>`), and dangerous actions need password confirmation and an audit-log entry.

If you are unsure whether your change touches one of these, say so in the pull request and we will help.

## Interface conventions

- The UI uses the **FlyonUI CSS classes only** (never its JavaScript plugins) and our React components in `resources/js/components/ui/`. New interactive components follow the same pattern, with keyboard and ARIA support.
- Icons come from **`lucide-react`**, imported one at a time.
- Use the **theme tokens** (`bg-base-100`, `text-base-content`, `border-base-300`, `text-primary`, ...), never hard-coded colours, and check your change in all three themes (light, dim, dark) and at about 500 px wide.
- Form fields always fill the card they are in; stack fields vertically.
- Use the generated route helpers (Laravel Wayfinder) instead of hard-coded URLs.

## Translating the panel

The panel's interface is available in English, French, German, Spanish, Russian, Ukrainian and Turkish. Translating it, or improving a translation, needs no coding: it is a JSON file. The command line, the agent, the documentation site and the audit-log action codes stay in English.

**How it works.** The English sentence is the key. `lang/<code>.json` maps each English sentence to its translation, and one file serves both the React pages and the PHP messages. A sentence that is missing from a file shows in English, so a partial translation is fine. Each person chooses their own language in **Settings → Appearance**; the first-run setup page and the login page have a language box too.

**Fix or improve a translation.** Edit the value in `lang/<code>.json` and open a pull request. The statuses of the languages are in `lang/README.md`; a native speaker's review is the most valuable contribution.

**Add a language.**

1. Add a line to `locales` in `config/commypanel.php`: the code and the language's own name, for example `'it' => 'Italiano'`.
2. Create `lang/it.json` containing `{}`. Laravel's own messages (validation errors, sign-in, passwords, pagination) live in `lang/it/validation.php`, `auth.php`, `passwords.php` and `pagination.php`: take them from the MIT-licensed [laravel-lang/lang](https://github.com/Laravel-Lang/lang) project (its `locales/it/php.json`, split by file) and add your language's names for the form fields under `attributes` in `validation.php`.
3. Fill it in. `node scripts/i18n.mjs todo it` prints every sentence that is still missing, as JSON to fill in. For a big first pass, `chunk` and `merge` work 250 sentences at a time (see the comments at the top of `scripts/i18n.mjs`).
4. Run `npm run i18n:check`. It must report 0 errors. `node scripts/i18n.mjs check --strict` also fails while sentences are missing.
5. Open a pull request. Say whether you are a native speaker and whether the translation was drafted with a tool.

**The rules**

- Keep `:placeholders` (`:name`, `:count`, `:app`...) exactly as they are. You may move them where your grammar needs them.
- Keep the tags (`<b>…</b>`, `<code>…</code>`, `<link>…</link>`, `<br/>`) and translate the words inside them. A sentence with two `<code>` parts uses `<code>` and `<code2>`.
- Plural sentences look like `one::count app|other::count apps`: forms separated by `|`, each `form:text`. List the forms your language has (`one`, `few`, `many`, `other`; `other` is required). Russian and Ukrainian need `one`, `few`, `many` and `other`.
- Text that only looks like a placeholder, such as `git@github.com:acme/app.git`, stays as it is.
- Keep product and technology names, commands, paths and environment variable names in English.
- Fragments such as `" and "` are joined in code: keep their leading and trailing spaces.
- Be consistent with the terms already used in the file.

**When you write code.** Every sentence a person reads goes through the helpers, with a string literal as the key, so `npm run i18n:check` can find it. In a React component use `const { t, tn } = useT();` (from `@/hooks/use-t`) and `t('Save settings')`, `t('Deleted :name', { name })`, `tn('one::count app|other::count apps', n)`, or `<Trans text="Open <b>Domains</b>." components={{ b: <strong /> }} />` for a sentence with markup. In PHP use `__('Deleted :name', ['name' => $name])`. Do not build sentences from pieces, because word order differs between languages. Do not translate text that is stored and shown later (deployment logs and errors, audit entries, webhook texts, API errors); it stays English.

## Reporting bugs and suggesting features

- **Bug?** [Open an issue](https://github.com/commypanel/panel/issues/new/choose) with the version (`sudo commypanel status`), your distribution, what you did, what you expected and what happened. Logs help (`sudo commypanel logs`), but remove anything private first.
- **Feature idea?** Open a feature request and describe the problem you are trying to solve, not only the solution. We will say honestly whether it fits.
- **Security vulnerability?** Do not open a public issue. See [SECURITY.md](SECURITY.md).
- **Question?** Open an issue with the `question` label.

Thank you for helping make CommyPanel better.
