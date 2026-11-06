## What does this change, and why?

<!-- The problem first, then the change. Link the issue: "Closes #123". -->

## How was it tested?

<!-- Tests you added or ran, and anything you tried by hand. For interface changes, a screenshot in the light and a dark theme helps. -->

## Does this run on a user's server or handle secrets?

<!--
If the change touches the agent, the installer, nginx configuration, containers, files, networks, authentication or secrets, add one or two sentences: who could abuse this, and what stops them.
If it does not, write "No".
-->

## Checklist

- [ ] My commits are signed off (`git commit -s`), and I agree to the [terms of contribution](../blob/main/CONTRIBUTING.md#terms-of-contribution).
- [ ] `composer test`, `npm run types:check` and `npm run check` pass (and `go vet ./... && go test ./...` in `agent/` if I touched it).
- [ ] I added or updated tests for new behaviour.
- [ ] I updated the documentation if people will notice the change.
- [ ] I did not commit secrets, `.env` files or personal data.
