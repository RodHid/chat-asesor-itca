#!/bin/bash

# Wait for database to be ready
echo "🔄 Waiting for database connection..."
sleep 5

# Test database connection
echo "🔍 Testing database connection..."
php artisan tinker --execute="DB::connection()->getPdo(); echo 'Database connected successfully!';" || {
    echo "❌ Database connection failed. Waiting longer..."
    sleep 10
}

# Run migrations
echo "🗄️ Running database migrations..."
php artisan migrate --force

# Clear any cached configurations
echo "🧹 Clearing caches..."
php artisan config:cache
php artisan route:cache

# Start the application
echo "🚀 Starting Laravel application on port 5000..."
php artisan serve --host=0.0.0.0 --port=5000
