FROM nginx:1.31.3-alpine

RUN apk add --no-cache apache2-utils

COPY default.conf /etc/nginx/conf.d/default.conf
COPY 40-create-basic-auth.sh /docker-entrypoint.d/40-create-basic-auth.sh

EXPOSE 8082
