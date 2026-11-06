# API

CommyPanel has a small HTTP API for scripts and CI: list apps, start a deployment, follow it. It uses
bearer tokens, never your password or a browser session.

## Create a token

Settings > API tokens > New token (needs the `tokens.manage` permission). Choose:

- **What it may do**: read apps and deployments (`apps:read`), start deployments (`apps:deploy`). You can
  only give what you hold yourself.
- **Which apps**: all, or only the ones you tick. A token restricted to some apps gets a 404 for any other.
- **Valid for**: 30 days, 90 days (the default), 6 months, 1 year, or **Never expires**. A token that never
  expires works until you revoke it or its owner is deleted (and stops if its owner loses the permissions it
  uses), so treat it like a password and rotate it regularly: make a new token, switch your pipeline over,
  then revoke the old one. The form says so when you choose it.

The token (it starts with `cpt_`) is shown once. Only a hash is stored. Creating and revoking ask for your
password and are written to the audit log, as is every deployment a token starts.

A token is checked against its owner's **current** permissions on every call: if the owner loses
`apps.deploy`, the token stops being able to deploy. Deleting the owner deletes their tokens.

## Calls

All calls are under `/api/v1`, send `Accept: application/json` and `Authorization: Bearer <token>`.

| Call                                    | Ability       | Answer                                                      |
| --------------------------------------- | ------------- | ----------------------------------------------------------- |
| `GET /apps`                             | `apps:read`   | the apps the token may see                                  |
| `GET /apps/{slug}`                      | `apps:read`   | one app: status, stack, domains, live and latest deployment |
| `POST /apps/{slug}/deployments`         | `apps:deploy` | `202` and the new deployment                                |
| `GET /apps/{slug}/deployments/{number}` | `apps:read`   | the deployment; add `?log=1` for the build log              |

Answers hold no secrets: no environment variables, keys or webhook secrets.

A deployment has `status`, `stage`, `finished` and `succeeded`. Poll it until `finished` is `true`, then
check `succeeded`. Starting a deployment while one runs answers `409` with the running one; a stopped app
answers `409` too.

```bash
BASE=https://panel.example.com
TOKEN=cpt_...

# start
curl -sS -X POST "$BASE/api/v1/apps/shop/deployments" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

# follow
curl -sS "$BASE/api/v1/apps/shop/deployments/12?log=1" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## GitHub Actions

Store the token as a repository secret (`COMMYPANEL_TOKEN`) and the panel address as a variable.

```yaml
- name: Deploy
  env:
      BASE: ${{ vars.COMMYPANEL_URL }}
      TOKEN: ${{ secrets.COMMYPANEL_TOKEN }}
  run: |
      n=$(curl -fsS -X POST "$BASE/api/v1/apps/shop/deployments" \
        -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" | jq -r .data.number)
      while :; do
        d=$(curl -fsS "$BASE/api/v1/apps/shop/deployments/$n" \
          -H "Authorization: Bearer $TOKEN" -H "Accept: application/json")
        [ "$(echo "$d" | jq -r .data.finished)" = true ] && break
        sleep 5
      done
      [ "$(echo "$d" | jq -r .data.succeeded)" = true ]
```

Use a token restricted to that one app, with only `apps:deploy` and `apps:read`.

## Limits

- 60 calls a minute per token.
- Calls without a valid token get 20 a minute per address, and failed attempts are counted before the
  token is looked up, so guessing is slow from the first try.
- No CORS: browsers on other sites cannot call it. Keep tokens out of front-end code.

Revoke a token on the same page. It stops working at once.
