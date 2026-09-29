# syntax=docker/dockerfile:1
# Signing runner image (see README). Stages:
#   build   - compiles storefront-runner
#   test    - `docker build --target test runner` runs the test suite, zsign included
#   runtime - the default target: runner + zsign + openssl + unzip, non-root

ARG SWIFT_IMAGE=swift:6.4-bookworm
ARG RUNTIME_IMAGE=swift:6.4-bookworm-slim

# zsign is pinned by version and checksum (github.com/zhlynn/zsign, MIT).
FROM debian:bookworm-slim AS zsign
ARG TARGETARCH
ARG ZSIGN_VERSION=1.1.2
ARG ZSIGN_SHA256_AMD64=d55e6a8650949a260892a9687ac1dfe4a9c79c2e3ecfe00a0eeb09b7b694282e
ARG ZSIGN_SHA256_ARM64=801527987276bcfb30c61c0106882845d1840da25ef0cd9118ef7ef6927ae80c
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl && rm -rf /var/lib/apt/lists/*
RUN set -eu; \
    case "${TARGETARCH:-amd64}" in \
      amd64) asset=x86_64;  sum="$ZSIGN_SHA256_AMD64" ;; \
      arm64) asset=aarch64; sum="$ZSIGN_SHA256_ARM64" ;; \
      *) echo "unsupported architecture ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/zsign.tgz "https://github.com/zhlynn/zsign/releases/download/v${ZSIGN_VERSION}/zsign-linux-${asset}.tar.gz"; \
    echo "${sum}  /tmp/zsign.tgz" | sha256sum -c -; \
    tar -xzf /tmp/zsign.tgz -C /usr/local/bin zsign; \
    chmod 0755 /usr/local/bin/zsign

FROM ${SWIFT_IMAGE} AS build
RUN apt-get update && apt-get install -y --no-install-recommends libssl-dev && rm -rf /var/lib/apt/lists/*
WORKDIR /src
COPY Package.swift ./
COPY Sources Sources
COPY Tests Tests
RUN swift build -c release --product storefront-runner \
 && install -m 0755 "$(swift build -c release --show-bin-path)/storefront-runner" /usr/local/bin/storefront-runner

FROM build AS test
RUN apt-get update && apt-get install -y --no-install-recommends openssl unzip zip && rm -rf /var/lib/apt/lists/*
COPY --from=zsign /usr/local/bin/zsign /usr/local/bin/zsign
RUN swift test

# The slim Swift image carries the Swift runtime libraries (FoundationNetworking
# does not link statically), without the compiler.
FROM ${RUNTIME_IMAGE} AS runtime
# libcurl4/libxml2: URLSession on Linux; libssl3: zsign, openssl and the runner's hashes.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates openssl unzip libcurl4 libxml2 libssl3 \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --system --uid 10001 --home-dir /var/lib/storefront-runner --create-home --shell /usr/sbin/nologin runner \
 && install -d -m 0700 -o runner -g runner /var/lib/storefront-runner/work
COPY --from=zsign /usr/local/bin/zsign /usr/local/bin/zsign
COPY --from=build /usr/local/bin/storefront-runner /usr/local/bin/storefront-runner
USER runner
ENV STOREFRONT_RUNNER_IDENTITIES_DIR=/run/secrets/signing \
    STOREFRONT_RUNNER_WORK_DIR=/var/lib/storefront-runner/work
ENTRYPOINT ["/usr/local/bin/storefront-runner"]
