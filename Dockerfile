# ---------- Base stage (env-only: full runtime environment) ----------
FROM debian:bookworm-slim AS env-only

ENV DEBIAN_FRONTEND=noninteractive

# Install Apache, PHP, ffmpeg, Python, curl, unzip
RUN apt-get update && apt-get install -y \
    apache2 \
    php \
    libapache2-mod-php \
    ffmpeg \
    python3 \
    python3-pip \
    python3-venv \
    curl \
    unzip \
    ca-certificates \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install yt-dlp using a virtual environment (Safer Debian approach)
RUN python3 -m venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH"
RUN pip install --no-cache-dir "yt-dlp[default]"

# Install Deno
ENV DENO_INSTALL=/usr/local
RUN curl -fsSL https://deno.land/install.sh | sh

# Fix Apache warning (Logs remain as actual files in /var/log/apache2/)
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Remove default Apache page and set base permissions
RUN rm -f /var/www/html/index.html \
    && chown -R www-data:www-data /var/www/html

# Expose port
EXPOSE 80

# Make env-only runnable
CMD ["apachectl", "-D", "FOREGROUND"]

# ---------- Full stage (adds your app) ----------
FROM env-only AS full

# Copy your PHP app with correct permissions
COPY --chown=www-data:www-data index.php /var/www/html/index.php
