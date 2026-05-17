#!/bin/sh
set -u

queue="${1:-default}"
sleep_seconds="${2:-1}"
tries="${3:-1}"
restart_after="${DEV_QUEUE_WORKER_RESTART_AFTER:-2}"
max_time="${DEV_QUEUE_WORKER_MAX_TIME:-30}"
max_jobs="${DEV_QUEUE_WORKER_MAX_JOBS:-100}"

echo "Starting local queue worker for [${queue}] with max time [${max_time}s] and max jobs [${max_jobs}]"

while true; do
    php artisan queue:work --queue="${queue}" --tries="${tries}" --sleep="${sleep_seconds}" --max-time="${max_time}" --max-jobs="${max_jobs}"
    status=$?

    echo "Queue worker [${queue}] exited with status ${status}; restarting in ${restart_after}s"
    sleep "${restart_after}"
done
