# Nuxt web app for production: build once, run the Nitro server.
# NUXT_PUBLIC_* variables are read at RUNTIME, so one image works anywhere.
FROM node:22-alpine AS build
WORKDIR /app
# Web Awesome Pro registry token — used only in this discarded build stage,
# so it never reaches the final image.
ARG WEBAWESOME_NPM_TOKEN
ENV WEBAWESOME_NPM_TOKEN=${WEBAWESOME_NPM_TOKEN}
COPY web/package*.json web/.npmrc ./
RUN npm ci --no-fund --no-audit
COPY web .
RUN npm run build

FROM node:22-alpine
WORKDIR /app
COPY --from=build /app/.output ./.output
ENV NITRO_HOST=0.0.0.0 \
    NITRO_PORT=3000
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
