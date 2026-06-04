#!/bin/sh
set -e

service cron start
exec apache2-foreground
