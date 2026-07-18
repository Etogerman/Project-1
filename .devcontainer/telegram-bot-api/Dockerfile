FROM debian:bookworm@sha256:9344f8b8992482f80cba753f323adeaf17690076c095ccff6cc9536be98185dc AS builder

ARG TELEGRAM_BOT_API_COMMIT=adfd7f6a8e990272851777eeb3ae0def4216f161
ARG TELEGRAM_TDLIB_COMMIT=a9966eb3704a3351568c28013fed67d797c17828

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        cmake \
        g++ \
        git \
        gperf \
        libssl-dev \
        make \
        zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /src

RUN git init \
    && git remote add origin https://github.com/tdlib/telegram-bot-api.git \
    && git fetch --depth=1 origin "${TELEGRAM_BOT_API_COMMIT}" \
    && git checkout --detach FETCH_HEAD \
    && git submodule update --init --recursive --depth=1

COPY storage-optimizer-cadence.patch /tmp/storage-optimizer-cadence.patch
COPY verify-storage-optimizer-contract.sh /tmp/verify-storage-optimizer-contract.sh

RUN echo "6ffce6b3a46b67a11fc07a37bcf75bff42267f956c90a337d492c6ef40b8cdfd  /tmp/storage-optimizer-cadence.patch" | sha256sum --check - \
    && git -C td apply --check /tmp/storage-optimizer-cadence.patch \
    && git -C td apply /tmp/storage-optimizer-cadence.patch \
    && bash /tmp/verify-storage-optimizer-contract.sh \
        /src \
        "${TELEGRAM_BOT_API_COMMIT}" \
        "${TELEGRAM_TDLIB_COMMIT}"

RUN cmake -S . -B build \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_INSTALL_PREFIX=/opt/telegram-bot-api \
    && cmake --build build --target install --parallel 2

FROM debian:bookworm-slim@sha256:7b140f374b289a7c2befc338f42ebe6441b7ea838a042bbd5acbfca6ec875818

ARG TELEGRAM_BOT_API_COMMIT=adfd7f6a8e990272851777eeb3ae0def4216f161
ARG TELEGRAM_TDLIB_COMMIT=a9966eb3704a3351568c28013fed67d797c17828

LABEL org.opencontainers.image.source="https://github.com/Etogerman/Project-1" \
    io.abrikosoff.telegram-bot-api.commit="${TELEGRAM_BOT_API_COMMIT}" \
    io.abrikosoff.telegram-tdlib.commit="${TELEGRAM_TDLIB_COMMIT}" \
    io.abrikosoff.telegram-storage-optimizer.patch-sha256="6ffce6b3a46b67a11fc07a37bcf75bff42267f956c90a337d492c6ef40b8cdfd" \
    io.abrikosoff.telegram-storage-optimizer.cadence="15m+5-60s"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libssl3 \
        zlib1g \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/www/html/storage/app/telegram-bot-api/tmp

COPY --from=builder /opt/telegram-bot-api/bin/telegram-bot-api /usr/local/bin/telegram-bot-api

EXPOSE 8081

ENTRYPOINT ["telegram-bot-api"]
