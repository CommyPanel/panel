# A throw-away machine with systemd for scripts/ci-install-smoke.sh.
#   docker build -f tests/ci/systemd.Dockerfile --build-arg IMAGE=debian:13 -t cp-smoke .
ARG IMAGE=debian:13
FROM ${IMAGE}
ENV DEBIAN_FRONTEND=noninteractive container=docker
RUN apt-get update \
 && apt-get install -y --no-install-recommends systemd systemd-sysv dbus ca-certificates curl sudo iproute2 procps \
 && rm -rf /var/lib/apt/lists/*
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
