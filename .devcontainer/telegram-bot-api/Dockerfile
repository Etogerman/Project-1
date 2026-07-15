FROM debian:bookworm AS builder

ARG TELEGRAM_BOT_API_COMMIT=adfd7f6a8e990272851777eeb3ae0def4216f161

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

RUN cmake -S . -B build \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_INSTALL_PREFIX=/opt/telegram-bot-api \
    && cmake --build build --target install --parallel 2

FROM debian:bookworm-slim

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
