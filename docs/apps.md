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

## When Docker is down

The panel runs outside Docker, so it keeps working when Docker does not. Every page then shows a warning, and Server > Docker shows the state of the Docker service and its latest log lines. People with the "Manage Docker and nginx" permission can restart Docker from there after confirming their password. Restarting Docker stops every container for a moment; they come back on their own.
