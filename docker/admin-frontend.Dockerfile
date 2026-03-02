# Club Bar Admin Frontend - Build and serve via Apache
# Multi-stage build: Node for building, Apache for serving

# Stage 1: Build the React application
FROM node:20-alpine AS builder

WORKDIR /app

# Copy package files
COPY admin-frontend/package*.json ./

# Install dependencies
RUN npm ci

# Copy source code
COPY admin-frontend/ ./

# Build for production
RUN npm run build

# Stage 2: Serve with Apache
FROM httpd:2.4-alpine

# Copy built files to Apache document root
COPY --from=builder /app/dist/ /usr/local/apache2/htdocs/

# Configure Apache for SPA routing (all routes -> index.html)
RUN echo '<Directory "/usr/local/apache2/htdocs">\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
    FallbackResource /index.html\n\
</Directory>' >> /usr/local/apache2/conf/httpd.conf

# Enable mod_rewrite
RUN sed -i 's/#LoadModule rewrite_module/LoadModule rewrite_module/' /usr/local/apache2/conf/httpd.conf

EXPOSE 80
