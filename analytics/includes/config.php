<?php

declare(strict_types=1);

/**
 * Analytics configuration. Adjust paths and secrets for your deployment.
 */

define('ANALYTICS_ROOT', dirname(__DIR__));

define('ANALYTICS_DATA', ANALYTICS_ROOT . '/data');

define('DB_PATH', ANALYTICS_DATA . '/analytics.sqlite');

/** Change this to a long random secret in production; used when hashing visitor IPs. */
const IP_HASH_SALT = 'change-me-in-install-or-config';

const MAX_SITE_LEN = 255;

const MAX_EVENT_NAME_LEN = 128;

const MAX_URL_LEN = 2048;

const MAX_UA_LEN = 512;

const TRACK_RATE_LIMIT_WINDOW_SECONDS = 60;

const TRACK_RATE_LIMIT_MAX_EVENTS = 120;

const MAX_VISITOR_ID_LEN = 64;

const DASH_SESSION_NAME = 'analytics_dash_sess';
