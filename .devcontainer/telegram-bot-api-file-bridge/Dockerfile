FROM nginx:1.31.3-alpine

ARG TELEGRAM_MEDIA_GID=20000

RUN apk add --no-cache apache2-utils \
    && addgroup -S -g "${TELEGRAM_MEDIA_GID}" telegram-media \
    && grep -Fxq 'user  nginx;' /etc/nginx/nginx.conf \
    && sed -i 's/^user  nginx;$/user  nginx telegram-media;/' /etc/nginx/nginx.conf \
    && grep -Fxq 'user  nginx telegram-media;' /etc/nginx/nginx.conf

COPY default.conf /etc/nginx/conf.d/default.conf
COPY 40-create-basic-auth.sh /docker-entrypoint.d/40-create-basic-auth.sh
COPY --chmod=755 repair-existing-media-permissions.sh /usr/local/bin/repair-existing-media-permissions

EXPOSE 8082
