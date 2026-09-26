# Multi-stage: build the static React app, then serve it (plus reverse-
# proxy /api/* to the backend) from one nginx container -- frontend and
# API share a single origin in production, so the backend needs no CORS
# config at all (see DEPLOY.md).
FROM node:20-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# The version shown on the login screen (deploy/version.sh -> compose).
ARG APP_VERSION=
ARG APP_VERSION_DATE=
RUN npm run build

FROM nginx:1.27-alpine
COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
