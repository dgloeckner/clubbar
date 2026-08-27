# Club Bar Admin Frontend - Build and serve via Apache
# Multi-stage build: Node for building, Apache for serving

# Stage 1: Build the React application
FROM node:22-alpine AS builder

WORKDIR /app

# orval's postinstall (#725) needs the OAS spec at a path relative to
# admin-frontend/ (orval.config.ts: '../api/admin.yaml') and the mutator
# file orval.config.ts points at (src/api/client.ts, which itself imports
# from src/utils/), so the full source tree has to be in place before
# `npm ci` runs — package files alone are not enough anymore.
COPY api/ /api/
COPY admin-frontend/ ./

# Install dependencies (also regenerates src/api/generated/ via postinstall)
RUN npm ci

# Build for production
RUN npm run build

# Stage 2: Serve with Apache
FROM httpd:2.4-alpine

# Copy built files to Apache document root
COPY --from=builder /app/dist/ /usr/local/apache2/htdocs/

# Configure Apache for SPA routing (all routes -> index.html)
# printf, not echo: BusyBox echo does not expand \n, which corrupts httpd.conf
RUN printf '%s\n' \
    '<Directory "/usr/local/apache2/htdocs">' \
    '    Options Indexes FollowSymLinks' \
    '    AllowOverride All' \
    '    Require all granted' \
    '    FallbackResource /index.html' \
    '</Directory>' >> /usr/local/apache2/conf/httpd.conf

# Enable mod_rewrite
RUN sed -i 's/#LoadModule rewrite_module/LoadModule rewrite_module/' /usr/local/apache2/conf/httpd.conf

EXPOSE 80
