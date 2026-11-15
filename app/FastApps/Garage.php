<?php

namespace App\FastApps;

use App\Models\App;

/**
 * Garage, S3-compatible object storage for the apps linked to it (uploads, backups). One node, with
 * an access key, a secret and a first bucket made for you; the key may also make more buckets. MinIO
 * was passed over because its community edition is archived and ships no images. Garage's own image
 * has no shell, so its binary is copied into a small Alpine image that runs the setup script.
 *
 * The secrets (the cluster secret, the admin token, the access key and its secret) come in as
 * variables; Garage reads the first two from its environment. The key is handed to Garage's command
 * line tool once, inside the container, when it is first made.
 *
 * Two Garage nodes must never share one data folder, and a new release starts beside the old one,
 * so the start script takes a lock on the folder: a second one refuses to start and says why.
 */
class Garage extends QuickApp
{
    public const S3_PORT = 3900;

    public const REGION = 'garage';

    public const BUCKET = 'main';

    public function id(): string
    {
        return 'garage';
    }

    public function name(): string
    {
        return 'Garage';
    }

    public function description(): string
    {
        return __('S3-compatible storage for the files of your apps, such as uploads and backups. Reached with an access key.');
    }

    protected function image(): string
    {
        return 'dxflrs/garage:v2.5.0';
    }

    public function containerPort(): int
    {
        return self::S3_PORT;
    }

    public function healthPath(): string
    {
        // Without a signature the S3 API answers 403: below 500 means it is up.
        return '/';
    }

    public function healthTimeout(): ?int
    {
        return 120;
    }

    public function volumePaths(): array
    {
        return ['/data'];
    }

    public function warnings(): array
    {
        return [];
    }

    public function environment(array $settings): array
    {
        return [
            'GARAGE_RPC_SECRET' => $this->hex(32),
            'GARAGE_ADMIN_TOKEN' => $this->secret(40),
            'COMMYPANEL_ACCESS_KEY_ID' => 'GK'.$this->hex(12),
            'COMMYPANEL_SECRET_ACCESS_KEY' => $this->hex(32),
            'COMMYPANEL_BUCKET' => self::BUCKET,
        ];
    }

    public function files(App $app): array
    {
        $dockerfile = <<<'DOCKER'
        FROM dxflrs/garage:v2.5.0 AS garage
        FROM alpine:3.22
        COPY --from=garage /garage /usr/local/bin/garage
        COPY garage.toml /etc/garage.toml
        COPY commypanel-start.sh /usr/local/bin/commypanel-start.sh
        CMD ["sh", "/usr/local/bin/commypanel-start.sh"]

        DOCKER;

        return ['Dockerfile' => $dockerfile, 'garage.toml' => self::CONFIG, 'commypanel-start.sh' => self::START_SCRIPT];
    }

    /** No secrets in here: Garage takes the cluster secret and the admin token from its environment. */
    private const CONFIG = <<<'TOML'
    metadata_dir = "/data/meta"
    data_dir = "/data/data"
    db_engine = "sqlite"
    replication_factor = 1

    rpc_bind_addr = "127.0.0.1:3901"
    rpc_public_addr = "127.0.0.1:3901"

    [s3_api]
    s3_region = "garage"
    api_bind_addr = "0.0.0.0:3900"

    [admin]
    api_bind_addr = "127.0.0.1:3903"

    TOML;

    private const START_SCRIPT = <<<'SH'
    #!/bin/sh
    # Starts one Garage node, gives it its storage layout the first time and makes the access key and
    # bucket the panel generated. Safe to run on every start.
    set -eu

    : "${GARAGE_RPC_SECRET:?}" "${GARAGE_ADMIN_TOKEN:?}" "${COMMYPANEL_ACCESS_KEY_ID:?}" "${COMMYPANEL_SECRET_ACCESS_KEY:?}"
    bucket="${COMMYPANEL_BUCKET:-main}"

    mkdir -p /data/meta /data/data

    # Two Garage nodes on one folder would damage it, and the next release starts beside this one:
    # with the old one still running this start fails with a clear line instead.
    exec 9>/data/.commypanel.lock
    if ! flock -n 9; then
        echo "Another Garage is still using /data. Stop the app, then deploy again." >&2
        exit 1
    fi

    garage server &
    pid=$!
    trap 'kill -TERM "$pid" 2>/dev/null || true' TERM INT

    tries=0
    until garage status >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -gt 90 ] || ! kill -0 "$pid" 2>/dev/null; then
            echo "Garage did not come up." >&2
            exit 1
        fi
        sleep 1
    done

    # A single node needs a role before it stores anything.
    if garage layout show 2>/dev/null | grep -q 'No nodes currently have a role'; then
        node="$(garage node id -q | cut -d@ -f1)"
        garage layout assign -z commypanel -c 10G "$node"
        garage layout apply --version 1
    fi

    if ! garage key info "$COMMYPANEL_ACCESS_KEY_ID" >/dev/null 2>&1; then
        garage key import --yes -n commypanel "$COMMYPANEL_ACCESS_KEY_ID" "$COMMYPANEL_SECRET_ACCESS_KEY" >/dev/null
    fi

    garage key allow --create-bucket "$COMMYPANEL_ACCESS_KEY_ID" >/dev/null

    if ! garage bucket info "$bucket" >/dev/null 2>&1; then
        garage bucket create "$bucket" >/dev/null
    fi

    garage bucket allow --read --write --owner "$bucket" --key "$COMMYPANEL_ACCESS_KEY_ID" >/dev/null
    echo "Garage is ready: bucket $bucket."

    while kill -0 "$pid" 2>/dev/null; do
        wait "$pid" || true
    done

    SH;

    public function linkEnvironment(App $target, string $prefix): array
    {
        return [
            "{$prefix}_ACCESS_KEY_ID" => $this->variable($target, 'COMMYPANEL_ACCESS_KEY_ID'),
            "{$prefix}_SECRET_ACCESS_KEY" => $this->variable($target, 'COMMYPANEL_SECRET_ACCESS_KEY'),
            "{$prefix}_REGION" => self::REGION,
            "{$prefix}_BUCKET" => $this->variable($target, 'COMMYPANEL_BUCKET') ?: self::BUCKET,
        ];
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
            $this->row(__('Region'), self::REGION),
            $this->row(__('Bucket'), $this->variable($app, 'COMMYPANEL_BUCKET') ?: self::BUCKET),
            $this->row(__('Access key'), $this->variable($app, 'COMMYPANEL_ACCESS_KEY_ID')),
            $this->row(__('Secret key'), $this->variable($app, 'COMMYPANEL_SECRET_ACCESS_KEY'), true),
        ];
    }
}
