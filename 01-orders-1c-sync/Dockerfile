FROM php:8.1-cli-alpine
WORKDIR /app
COPY . /app
RUN mkdir -p /app/reports /app/logs
CMD ["php", "bin/sync.php"]
